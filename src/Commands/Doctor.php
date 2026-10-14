<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\ProjectFile;
use Phattarachai\RemotePrompt\Runner;
use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\SshHost;
use Phattarachai\RemotePrompt\Target;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Connect to every target (this project's, or `--all`) and check its folder exists.
 * IAP hosts check the gcloud login first, since an expired one looks like a dead server. An alias missing from
 * ~/.ssh/config that the project's .rh.json describes gets its Host block shown, and written on request.
 */
class Doctor extends Command
{
    public function __invoke(array $args): int
    {
        $binding = $this->context->binding();
        $targets = self::flag($args, '--all')
            ? array_merge($binding->targets ?? [], ...array_values($this->context->config->projects()))
            : ($binding->targets ?? []);
        $targets = array_values(array_unique($targets, SORT_REGULAR));

        if ($targets === []) {
            warning('Nothing to check — no targets here (try --all).');

            return 0;
        }

        $gcloud = null;
        $rows = [];
        $failed = false;
        $missing = [];

        foreach ($targets as $target) {
            $host = $this->context->sshConfig->find($target->host);

            if ($host === null && ($described = $binding?->file?->host($target->host)) !== null) {
                $missing[$target->host] = $described;
                $failed = true;
                $rows[] = [$target->name, $target->host.' (not in ~/.ssh/config)', $target->path, 'alias missing — block below'];

                continue;
            }

            if ($host?->viaGcloud() && $gcloud === null) {
                $gcloud = spin(fn () => $this->context->runner()->run(['gcloud', 'auth', 'print-access-token', '--quiet'])->successful(), 'Checking gcloud login');

                if (! $gcloud) {
                    error('gcloud is not logged in — run `gcloud auth login`, then `rh doctor` again.');
                }
            }

            $status = $host?->viaGcloud() && $gcloud === false ? 'skipped — gcloud login' : $this->check($target);
            $failed = $failed || $status !== 'ok';

            $rows[] = [$target->name, $host?->describe() ?? $target->host.' (not in ~/.ssh/config)', $target->path, $status];
        }

        table(['Target', 'Host', 'Path', 'Status'], $rows);

        $this->offerHosts($missing);
        $this->checkTunnels($binding?->root);

        return $failed ? 1 : 0;
    }

    /**
     * @param  array<string, SshHost>  $hosts
     */
    private function offerHosts(array $hosts): void
    {
        if ($hosts === []) {
            return;
        }

        $this->context->say('  '.ProjectFile::NAME.' describes '.implode(', ', array_keys($hosts)).':');
        $this->context->say();

        foreach ($hosts as $host) {
            $this->context->say('<fg=gray>'.rtrim(preg_replace('/^/m', '    ', $host->block()) ?? '').'</>');
            $this->context->say();
        }

        if (! Runner::interactive()) {
            $this->context->say('  Run `rh doctor` in a terminal to add them, or paste them into ~/.ssh/config.');

            return;
        }

        if (! confirm('Add them to '.Home::contract($this->context->sshConfig->managedFile()).'?', default: true)) {
            return;
        }

        foreach ($hosts as $host) {
            $this->context->sshConfig->add($host);
        }

        if (! $this->context->sshConfig->included()) {
            $this->context->sshConfig->addInclude();
        }

        info('Added — run `rh doctor` again to connect.');
    }

    /**
     * laravel-db-tunnel tunnels ride ssh aliases too; one missing here fails the same way.
     */
    private function checkTunnels(?string $root): void
    {
        $file = $root === null ? null : $root.'/config/db-tunnel.php';

        if ($file === null || ! is_file($file)) {
            return;
        }

        $source = (string) file_get_contents($file);
        preg_match_all("/'alias'\s*=>\s*'([^']+)'/", $source, $matches);
        $missing = array_values(array_unique(array_filter($matches[1], fn (string $alias) => $this->context->sshConfig->find($alias) === null)));

        if ($missing !== []) {
            warning('config/db-tunnel.php uses aliases not in ~/.ssh/config: '.implode(', ', $missing).(str_contains($source, 'gcp_iap') ? ' (fine for gcp_iap tunnels, which dial IAP themselves)' : '').'.');
        }
    }

    private function check(Target $target): string
    {
        $result = spin(fn () => $this->context->ssh->exec($target->host, 'test -d '.Ssh::quote($target->path).' && echo ok || echo missing'), "Connecting to {$target->name}");

        return match (true) {
            $result->failed() => 'unreachable: '.strtok($result->message() ?: 'exit '.$result->exitCode, "\n"),
            trim($result->output) === 'ok' => 'ok',
            default => 'folder missing',
        };
    }
}
