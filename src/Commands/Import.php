<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\PhpStorm;
use Phattarachai\RemotePrompt\Runner;
use Phattarachai\RemotePrompt\SshConfig;
use Phattarachai\RemotePrompt\SshHost;
use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * `rh import [folder]` — PHPStorm's Deployment servers of this project, or of every project in this folder.
 * Re-running replaces those projects' targets; other projects are left alone.
 */
class Import extends Command
{
    public function __invoke(array $args): int
    {
        $args = array_values(array_filter($args, fn (string $arg) => $arg !== 'phpstorm'));
        $yes = self::flag($args, '--yes');
        $dir = rtrim(isset($args[0]) ? self::localPath($this->context->cwd, $args[0]) : $this->context->cwd, '/');
        $projects = PhpStorm::projects($dir);

        if ($projects === []) {
            throw new UserError('No .idea/deployment.xml in '.Home::contract($dir).' or its sub-folders.');
        }

        $phpStorm = new PhpStorm($this->context->sshConfig, PhpStorm::optionsDir());
        $plan = [];
        $rows = [];

        foreach ($projects as $project) {
            $targets = $phpStorm->targets($project);

            if ($targets === []) {
                continue;
            }

            $key = Home::contract($project);
            $plan[$key] = $targets;
            $replacing = isset($this->context->config->projects()[$key]);

            foreach ($targets as $index => $target) {
                $rows[] = [$index === 0 ? $key.($replacing ? ' (replace)' : '') : '', $target->name, $target->host, $target->path, $target->env];
            }
        }

        foreach ($phpStorm->warnings as $line) {
            warning($line);
        }

        if ($plan === []) {
            info('Nothing to import — no SFTP servers mapped to a project root.');

            return 0;
        }

        table(['Project', 'Target', 'Host', 'Path', 'Env'], $rows);

        $newHosts = $phpStorm->newHosts();

        if ($newHosts !== []) {
            $this->context->say('  New aliases for '.Home::contract($this->context->sshConfig->managedFile()).': '.implode(', ', array_map(fn (SshHost $host) => $host->alias().' ('.$host->describe().')', $newHosts)));
        }

        if (! $yes && (! Runner::interactive() || ! confirm('Import '.count($plan).' project(s)?', default: true))) {
            return Runner::interactive() ? 0 : throw new UserError('Pass --yes to import without a terminal.');
        }

        foreach ($newHosts as $host) {
            $this->context->sshConfig->add($host);
        }

        if ($newHosts !== [] && ! $this->context->sshConfig->included() && ($yes || confirm('Add `'.SshConfig::INCLUDE_LINE.'` to the top of ~/.ssh/config (a backup is kept)?', default: true))) {
            $this->context->sshConfig->addInclude();
        }

        foreach ($plan as $key => $targets) {
            $this->context->config->setProject($key, $targets);
        }

        $this->context->config->save();
        $this->context->rebind();

        info('Imported into '.Home::contract($this->context->config->file()).' — `rh doctor --all` checks every connection.');

        return 0;
    }
}
