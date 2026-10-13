<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Context;
use Phattarachai\RemotePrompt\UserError;

abstract class Command
{
    public function __construct(protected Context $context) {}

    /**
     * @param  list<string>  $args
     */
    abstract public function __invoke(array $args): int;

    /**
     * @param  list<string>  $args
     */
    protected static function flag(array &$args, string $flag): bool
    {
        $found = in_array($flag, $args, true);
        $args = array_values(array_filter($args, fn (string $arg) => $arg !== $flag));

        return $found;
    }

    protected static function localPath(string $cwd, string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : $cwd.'/'.$path;
        $real = realpath($absolute);

        if ($real === false) {
            throw new UserError("{$path} does not exist.");
        }

        return $real;
    }
}
