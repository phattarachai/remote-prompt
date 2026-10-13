<?php

namespace Phattarachai\RemotePrompt;

use Phattarachai\RemotePrompt\Prompts\Output;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class Diff
{
    /**
     * Print a coloured unified diff; false when the two sides are identical.
     */
    public static function show(Runner $runner, string $old, string $new, string $oldLabel, string $newLabel): bool
    {
        $oldFile = Temp::file('old');
        $newFile = Temp::file('new');
        file_put_contents($oldFile, $old);
        file_put_contents($newFile, $new);

        $result = $runner->run(['diff', '-u', '-L', $oldLabel, '-L', $newLabel, $oldFile, $newFile]);

        unlink($oldFile);
        unlink($newFile);

        if ($result->exitCode === 0) {
            return false;
        }

        foreach (explode("\n", rtrim($result->output)) as $line) {
            $escaped = OutputFormatter::escape($line);

            Output::writeln(match (true) {
                str_starts_with($line, '+++'), str_starts_with($line, '---') => "<options=bold>{$escaped}</>",
                str_starts_with($line, '@@') => "<fg=cyan>{$escaped}</>",
                str_starts_with($line, '+') => "<fg=green>{$escaped}</>",
                str_starts_with($line, '-') => "<fg=red>{$escaped}</>",
                default => $escaped,
            });
        }

        return true;
    }
}
