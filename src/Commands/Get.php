<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\info;

class Get extends Command
{
    public function __invoke(array $args): int
    {
        [$target, $path] = $this->context->target($args[0] ?? null, pathOnly: true);

        if ($path === null) {
            throw new UserError('Usage: rh get <target:path> [local-path]');
        }

        $remote = $this->context->remote($target);
        $from = $target->resolve($path);
        $to = $args[1] ?? $this->context->cwd;
        $recursive = $remote->isDir($from);

        $this->context->step('scp '.($recursive ? '-r ' : '')."{$target->host}:{$from} → ".Home::contract($to));

        if (! $remote->download($from, $to, $recursive)) {
            throw new UserError("Download of {$from} failed.");
        }

        info('Downloaded to '.Home::contract(is_dir($to) ? rtrim($to, '/').'/'.basename($from) : $to));

        return 0;
    }
}
