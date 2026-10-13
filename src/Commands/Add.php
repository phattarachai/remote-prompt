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
        $host = self::option($args, 'host');
        $path = self::option($args, 'path');

        if ($host !== null || $path !== null) {
            return $this->scripted($host, $path, self::option($args, 'name'), self::option($args, 'env'), self::option($args, 'project'), self::flag($args, '--force'));
        }

        if (! Runner::interactive()) {
            throw new UserError('Without a terminal: rh add --host=<ssh alias> --path=<absolute path> [--name=] [--env=] [--project=<folder>] [--force]');
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
     * `rh add --host=… --path=…` for scripts and agents: no questions, the same defaults as the interactive flow,
     * and a refusal (unless --force) when the folder can't be confirmed on the host.
     */
    private function scripted(?string $host, ?string $path, ?string $name, ?string $env, ?string $project, bool $force): int
    {
        if ($host === null || $path === null || ! str_starts_with($path, '/')) {
            throw new UserError('rh add needs both --host=<ssh alias> and an absolute --path=/…');
        }

        if ($env !== null && ! in_array($env, Target::ENVIRONMENTS, true)) {
            throw new UserError('--env must be one of: '.implode(', ', Target::ENVIRONMENTS).'.');
        }

        $folder = $project === null ? $this->context->cwd : self::localPath($this->context->cwd, $project);
        $binding = $project === null ? $this->context->binding() : $this->context->config->bind($folder);
        $key = $binding->key ?? Home::contract(Git::projectRoot($folder));
        $path = Target::normalize($path);
        $guess = self::guessEnv(basename($path));
        $env ??= $guess === 'production' ? self::guessEnv($host) : $guess;
        $existing = $this->context->config->projects()[$key] ?? [];

        foreach ($existing as $target) {
            if ($target->host === $host && $target->path === $path) {
                throw new UserError("{$key} already has {$target->name} → {$host}:{$path}.");
            }
        }

        $taken = array_map(fn (Target $target) => strtolower($target->name), $existing);
        $name ??= self::defaultName($host, $env, $taken);

        if (in_array(strtolower($name), $taken, true)) {
            throw new UserError("{$key} already has a target named {$name} — pass another --name, or rh remove it first.");
        }

        $check = $this->context->ssh->exec($host, 'test -d '.Ssh::quote($path));

        if ($check->failed() && ! $force) {
            throw new UserError(($check->exitCode === 1 ? "{$path} is not a folder on {$host}" : "Could not reach {$host}: ".$check->message()).' — fix it, or pass --force to add it anyway.');
        }

        $this->context->config->addTarget($key, new Target($name, $host, $path, $env));
        $this->context->config->save();
        $this->context->rebind();

        info("Added {$name} → {$host}:{$path} ({$env}) for {$key}");

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
        info("Wrote {$alias} to ".Home::contract($this->context->sshConfig->managedFile()));

        if (! $this->context->sshConfig->included()) {
            $this->context->sshConfig->addInclude();
            info('Added `'.SshConfig::INCLUDE_LINE.'` to the top of ~/.ssh/config (backup: ~/.ssh/config.rh-backup).');
        }

        $this->enrollKey($alias, $identity);

        return $alias;
    }

    /**
     * A new host that refuses this machine's key gets it once through ssh-copy-id — the server's own password
     * prompt, typed by the user into ssh, never seen or kept by rh.
     */
    private function enrollKey(string $alias, string $identity): void
    {
        $probe = spin(fn () => $this->context->ssh->exec($alias, 'true'), "Trying {$alias} with your key");

        if ($probe->successful()) {
            info("{$alias} accepts your key.");

            return;
        }

        warning("{$alias}: ".$probe->message());

        $public = Home::expand($identity === '' ? '~/.ssh/id_ed25519' : $identity).'.pub';

        if (! is_file($public) || ! str_contains($probe->message(), 'Permission denied')) {
            return;
        }

        if (confirm('Install '.Home::contract($public)." on {$alias} with ssh-copy-id? It asks the server's password once.", default: true)) {
            $this->context->runner()->tty(['ssh-copy-id', '-i', $public, $alias]);
        }
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
