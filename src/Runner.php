<?php

namespace Phattarachai\RemotePrompt;

use Laravel\Prompts\Prompt;
use Symfony\Component\Process\Process;

/**
 * Every external program rh starts (ssh, scp, an editor, a pager) goes through here,
 * so tests can swap in a runner that plays the server locally.
 */
class Runner
{
    public static ?bool $interactive = null;

    /**
     * @param  list<string>  $command
     */
    public function run(array $command, ?string $input = null): Result
    {
        $process = new Process($command, timeout: null);
        $process->setInput($input);
        $process->run();

        return new Result((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * Run on this terminal: the child inherits rh's own stdin/stdout/stderr, which `ssh -t` needs to open a remote
     * shell (Symfony's TTY mode reopens /dev/tty and the session closes at once). A prompt still alive leaves the
     * terminal in -echo raw mode (which ssh copies to the remote pty) with the cursor hidden; both are restored first.
     * Without a terminal the output is echoed.
     *
     * @param  list<string>  $command
     */
    public function tty(array $command): int
    {
        if (self::interactive()) {
            Prompt::terminal()->restoreTty();
            fwrite(STDOUT, "\e[?25h");

            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

            return is_resource($process) ? proc_close($process) : 1;
        }

        return $this->stream($command);
    }

    /**
     * Pass the output through as it arrives, so a long command started by a script or an agent shows its progress.
     *
     * @param  list<string>  $command
     */
    public function stream(array $command): int
    {
        return (int) (new Process($command, timeout: null))->run(function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });
    }

    public static function interactive(): bool
    {
        return self::$interactive ??= stream_isatty(STDIN) && stream_isatty(STDOUT);
    }
}
