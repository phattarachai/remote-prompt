<?php

namespace Phattarachai\RemotePrompt\Tests;

use Phattarachai\RemotePrompt\Result;
use Phattarachai\RemotePrompt\Runner;

/**
 * Plays every host locally: an ssh command runs its remote script with sh, scp copies with cp.
 */
class FakeRunner extends Runner
{
    /**
     * @var list<list<string>>
     */
    public array $commands = [];

    public function run(array $command, ?string $input = null): Result
    {
        $this->commands[] = $command;

        return parent::run($this->local($command), $input);
    }

    public function tty(array $command): int
    {
        $this->commands[] = $command;
        $result = parent::run($this->local($command));

        echo $result->output;

        return $result->exitCode;
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function local(array $command): array
    {
        return match ($command[0]) {
            'ssh' => ['sh', '-c', $command[count($command) - 1]],
            'scp' => ['cp', ...(in_array('-r', $command, true) ? ['-R'] : []), ...array_map(fn (string $path) => (string) preg_replace('/^[\w.-]+:/', '', $path), array_slice($command, -2))],
            default => $command,
        };
    }
}
