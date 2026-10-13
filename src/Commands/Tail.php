<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\UserError;

class Tail extends Command
{
    public function __invoke(array $args): int
    {
        [$target, $path] = $this->context->target($args[0] ?? null);
        $remote = $this->context->remote($target);
        $file = $path !== null ? $target->resolve($path) : $remote->latestLog();

        if ($file === null) {
            throw new UserError("No *.log in {$target->path}/storage/logs on {$target->name} — give a file: rh tail {$target->name}:<path>");
        }

        $this->context->step("tail -f {$target->host}:{$file}  (Ctrl+C to stop)");

        return $remote->interactive('tail -n 200 -F -- '.Ssh::quote($file));
    }
}
