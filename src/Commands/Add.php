<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Git;
use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\Prompts\Menu;
use Phattarachai\RemotePrompt\RemoteEntry;
use Phattarachai\RemotePrompt\Runner;
use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\SshConfig;
use Phattarachai\RemotePrompt\SshHost;
use Phattarachai\RemotePrompt\Target;
use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Add a target to the current project: pick an ssh alias (or describe a new host, written to rh.conf),
 * then the path on it, a name and its environment.
 */
class Add extends Command
{
    /**
     * @var list<string> where the folder picker starts, first one that exists; else the login folder
     */
    public static array $startDirs = ['/var/www'];

    public function __invoke(array $args): int
    {
        if (! Runner::interactive()) {
            throw new UserError('rh add needs a terminal.');
        }

        $key = $this->context->binding()->key ?? Home::contract(Git::projectRoot($this->context->cwd));
        $root = $this->context->binding()->root ?? Git::projectRoot($this->context->cwd);

        $this->context->say("  Project <options=bold>{$key}</>");

        $host = $this->host();
        $path = $this->pickPath($host, basename($root)) ?? $this->typePath($host, '/var/www/'.basename($root));

        if ($path === null) {
            return 1;
        }

        $guess = self::guessEnv(basename($path));
        $env = (string) select('Environment', array_combine(Target::ENVIRONMENTS, Target::ENVIRONMENTS), default: $guess === 'production' ? self::guessEnv($host) : $guess, hint: 'production asks before every upload or edit.');
        $taken = array_map(fn (Target $target) => strtolower($target->name), $this->context->config->projects()[$key] ?? []);
        $name = text(
            'Name',
            default: self::defaultName($host, $env, $taken),
            required: true,
            validate: fn (string $value) => in_array(strtolower(trim($value)), $taken, true) ? 'This project already has a target with that name — pick another (or remove that one first).' : null,
            hint: 'Short — you type it as rh ssh <name>, rh get <name>:<path>.',
        );

        $this->context->config->addTarget($key, new Target(trim($name), $host, Target::normalize($path), $env));
        $this->context->config->save();
        $this->context->rebind();

        info("Added {$name} → {$host}:{$path} for {$key}");

        return 0;
    }

    /**
     * Walk the server's folders from /var/www (else the login folder) and pick one; folders named like the project
     * come first with a ★. Null when the host can't be listed or the user would rather type.
     */
    private function pickPath(string $host, string $project): ?string
    {
        $remote = $this->context->remote(new Target($host, $host, '.'));

        try {
            $dir = spin(fn () => array_values(array_filter(self::$startDirs, $remote->isDir(...)))[0] ?? trim($this->context->ssh->exec($host, 'pwd')->output), "Connecting to {$host}");
        } catch (\RuntimeException) {
            $dir = '';
        }

        while ($dir !== '') {
            try {
                $folders = array_values(array_filter(spin(fn () => $remote->list($dir), "Listing {$host}:{$dir}"), fn (RemoteEntry $entry) => $entry->isDir));
            } catch (\RuntimeException $exception) {
                warning($exception->getMessage());

                return null;
            }

            usort($folders, fn (RemoteEntry $a, RemoteEntry $b) => [! self::resembles($a->name, $project), strtolower($a->name)] <=> [! self::resembles($b->name, $project), strtolower($b->name)]);

            $options = ['use' => "✓  Use {$dir}", 'type' => '✎  Type a path…'];

            if ($dir !== '/') {
                $options['up'] = '../';
            }

            foreach ($folders as $index => $folder) {
                $options["d{$index}"] = (self::resembles($folder->name, $project) ? '★ ' : '  ').$folder->name.'/';
            }

            $choice = Menu::choose("Path on {$host}  {$dir}", $options, 'Type to filter · Enter open folder · pick ✓ to use it · Esc cancel');

            if ($choice === 'type') {
                return null;
            }

            if ($choice === 'use') {
                return $dir;
            }

            $dir = $choice === 'up' ? Target::normalize(dirname($dir)) : Target::normalize($dir.'/'.$folders[(int) substr($choice, 1)]->name);
        }

        return null;
    }

    private function typePath(string $host, string $default): ?string
    {
        $path = text('Path on the server', default: $default, required: true, hint: 'Absolute — the folder this project is deployed in.');
        $exists = spin(fn () => $this->context->ssh->exec($host, 'test -d '.Ssh::quote($path)), "Checking {$host}:{$path}");

        if ($exists->successful()) {
            return $path;
        }

        warning($exists->exitCode === 1 ? "{$path} is not a folder on {$host} (yet)." : "Could not reach {$host}: ".$exists->message());

        return confirm('Add it anyway?', default: false) ? $path : null;
    }

    public static function resembles(string $folder, string $project): bool
    {
        $squash = fn (string $value) => (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));
        [$folder, $project] = [$squash($folder), $squash($project)];

        return strlen($folder) >= 3 && strlen($project) >= 3 && (str_contains($folder, $project) || str_contains($project, $folder));
    }

    private function host(): string
    {
        $options = ['new' => '＋  New host…'];

        foreach ($this->context->sshConfig->hosts() as $host) {
            $options[$host->alias()] = $host->alias().'   '.$host->describe();
        }

        $choice = Menu::choose('SSH host', $options, 'Aliases from ~/.ssh/config · Type to filter · Enter pick');

        return $choice === 'new' ? $this->newHost() : $choice;
    }

    private function newHost(): string
    {
        $hostName = text('Host name or IP', required: true);
        $user = text('User', default: 'root', required: true);
        $port = text('Port', default: '22', required: true);
        $keys = ['' => 'ssh-agent / default keys'];

        foreach (glob(Home::path('.ssh/*.pub')) ?: [] as $public) {
            $private = Home::contract(substr($public, 0, -4));
            $keys[$private] = $private;
        }

        $identity = (string) select('Key', $keys);
        $alias = text('Alias', default: self::slug($hostName), required: true, validate: fn (string $value) => $this->context->sshConfig->find($value) !== null ? 'Already an alias in your ssh config.' : null);

        $this->context->sshConfig->add(new SshHost([$alias], $hostName, $user, $port === '22' ? null : $port, $identity === '' ? null : $identity));

        if (! $this->context->sshConfig->included() && confirm('Add `'.SshConfig::INCLUDE_LINE.'` to the top of ~/.ssh/config so plain ssh knows the alias too?', default: true)) {
            $this->context->sshConfig->addInclude();
        }

        info("Wrote {$alias} to ".Home::contract($this->context->sshConfig->managedFile()));

        return $alias;
    }

    public static function guessEnv(string $name): string
    {
        return match (true) {
            preg_match('/prod/i', $name) === 1 => 'production',
            preg_match('/uat/i', $name) === 1 => 'uat',
            preg_match('/qas|\bqa\b/i', $name) === 1 => 'qas',
            preg_match('/stag/i', $name) === 1 => 'staging',
            preg_match('/(?<![\w.])(dev|test|testing|local)(?![\w.])/i', $name) === 1 => 'dev',
            default => 'production',
        };
    }

    /**
     * The alias when it fits the environment and is free, else the alias without its env suffix plus this env:
     * a uat folder on host ounjai-prod is "ounjai-uat".
     *
     * @param  list<string>  $taken  lower-cased names already in the project
     */
    public static function defaultName(string $host, string $env, array $taken): string
    {
        $base = (string) preg_replace('/[-_.](prod|production|uat|qas|qa|staging|stage|dev)$/i', '', $host);
        $short = $env === 'production' ? 'prod' : $env;
        $fits = $base === $host || self::guessEnv($host) === $env;
        $name = $fits && ! in_array(strtolower($host), $taken, true) ? $host : "{$base}-{$short}";

        for ($suffix = 2, $candidate = $name; in_array(strtolower($candidate), $taken, true); $suffix++) {
            $candidate = "{$name}-{$suffix}";
        }

        return $candidate;
    }

    public static function slug(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-') ?: 'host';
    }
}
