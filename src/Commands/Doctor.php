<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\Target;

use function Laravel\Prompts\error;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Connect to every target (this project's, or `--all`) and check its folder exists.
 * IAP hosts check the gcloud login first, since an expired one looks like a dead server.
 */
class Doctor extends Command
{
    public function __invoke(array $args): int
    {
        $targets = self::flag($args, '--all')
            ? array_merge(...array_values($this->context->config->projects()))
            : ($this->context->binding()->targets ?? []);

        if ($targets === []) {
            warning('Nothing to check — no targets here (try --all).');

            return 0;
        }

        $gcloud = null;
        $rows = [];
        $failed = false;

        foreach ($targets as $target) {
            $host = $this->context->sshConfig->find($target->host);

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

        return $failed ? 1 : 0;
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
