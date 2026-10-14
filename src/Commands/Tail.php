<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Runner;
use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\UserError;

/**
 * `rh tail [target[:file]] [--lines=200]` — follows the newest log in a terminal; without one (a script, an agent)
 * prints the last lines and exits instead of following forever.
 */
class Tail extends Command
{
    public function __invoke(array $args): int
    {
        $lines = max(1, (int) (self::option($args, 'lines') ?? 200));
        [$target, $path] = $this->context->target($args[0] ?? null);
        $remote = $this->context->remote($target);
        $file = $path !== null ? $target->resolve($path) : $remote->latestLog();

        if ($file === null) {
            throw new UserError("No *.log in {$target->path}/storage/logs on {$target->name} — give a file: rh tail {$target->name}:<path>");
        }

        if (! Runner::interactive()) {
            $result = $this->context->ssh->exec($target->host, "tail -n {$lines} -- ".Ssh::quote($file));

            echo $result->output;
            fwrite(STDERR, $result->errorOutput);

            return $result->exitCode;
        }

        $this->context->step("tail -f {$target->host}:{$file}  (Ctrl+C to stop)");

        return $remote->interactive("tail -n {$lines} -F -- ".Ssh::quote($file));
    }
}
