<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Runner;
use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\UserError;

/**
 * Read a remote file in the server's own pager, so secrets like .env never land on this disk. The pager says how to
 * leave (q), Ctrl+C leaves too, and a file that fits on one screen is just printed.
 * Piped (scripts, agents) it prints the content instead — a .env with its values masked unless --raw.
 */
class Cat extends Command
{
    public function __invoke(array $args): int
    {
        $raw = self::flag($args, '--raw');
        [$target, $path] = $this->context->target($args[0] ?? null, pathOnly: true);

        if ($path === null) {
            throw new UserError('Usage: rh cat <target:path>');
        }

        $file = $target->resolve($path);
        $remote = $this->context->remote($target);

        if (! Runner::interactive()) {
            $content = $remote->read($file);

            echo $raw || ! self::isEnv($file) ? $content : self::mask($content);

            return 0;
        }

        $quoted = Ssh::quote($file);
        $less = 'less -R -F -K -Ps'.Ssh::quote('?e(END) .q to quit, / to search, space for the next page');

        $this->context->step("{$target->host}:{$file}  (q to quit)");

        return $remote->interactive("if command -v less >/dev/null 2>&1; then {$less} -- {$quoted}; else cat -- {$quoted}; fi");
    }

    public static function isEnv(string $file): bool
    {
        return preg_match('/^\.env(\..+)?$/', basename($file)) === 1 && basename($file) !== '.env.example';
    }

    /**
     * Keys stay readable, values don't: piped output of a .env goes to scripts and agents, never its secrets.
     */
    public static function mask(string $content): string
    {
        return (string) preg_replace('/^(\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_.]*\s*=)[ \t]*(?!\s*$)[^\n]*/m', '$1<set>', $content);
    }
}
