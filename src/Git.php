<?php

namespace Phattarachai\RemotePrompt;

use Symfony\Component\Process\Process;

final class Git
{
    /**
     * The worktree's own root and the main checkout's root.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function worktreeRoots(string $cwd): array
    {
        $process = new Process(['git', 'rev-parse', '--path-format=absolute', '--show-toplevel', '--git-common-dir'], is_dir($cwd) ? $cwd : null);
        $process->run();

        if (! $process->isSuccessful()) {
            return [null, null];
        }

        [$top, $common] = array_pad(explode("\n", trim($process->getOutput())), 2, '');

        return [$top ?: null, basename($common) === '.git' ? dirname($common) : null];
    }

    /**
     * The folder a new project is registered under: the main checkout when inside git, else the folder itself.
     */
    public static function projectRoot(string $cwd): string
    {
        [$top, $main] = self::worktreeRoots($cwd);

        return $main ?? $top ?? $cwd;
    }
}
