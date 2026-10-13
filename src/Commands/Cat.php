<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Runner;
use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\UserError;

/**
 * Read a remote file in the server's own pager, so secrets like .env never land on this disk. The pager says how to
 * leave (q), Ctrl+C leaves too, and a file that fits on one screen is just printed.
 * Piped (scripts, agents) it prints the content instead.
 */
class Cat extends Command
{
    public function __invoke(array $args): int
    {
        [$target, $path] = $this->context->target($args[0] ?? null, pathOnly: true);

        if ($path === null) {
            throw new UserError('Usage: rh cat <target:path>');
        }

        $file = $target->resolve($path);
        $remote = $this->context->remote($target);

        if (! Runner::interactive()) {
            echo $remote->read($file);

            return 0;
        }

        $quoted = Ssh::quote($file);
        $less = 'less -R -F -K -Ps'.Ssh::quote('?e(END) .q to quit, / to search, space for the next page');

        $this->context->step("{$target->host}:{$file}  (q to quit)");

        return $remote->interactive("if command -v less >/dev/null 2>&1; then {$less} -- {$quoted}; else cat -- {$quoted}; fi");
    }
}
