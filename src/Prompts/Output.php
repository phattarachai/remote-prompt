<?php

namespace Phattarachai\RemotePrompt\Prompts;

use Closure;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;

/**
 * Plain lines through the same output Prompts writes to, so a faked
 * terminal in tests captures them too.
 */
final class Output extends Prompt
{
    public static function writeln(string $message): void
    {
        self::output()->writeln($message);
    }

    /**
     * @param  Closure(Terminal): Terminal  $wrap
     */
    public static function wrapTerminal(Closure $wrap): void
    {
        self::$terminal = $wrap(self::terminal());
    }

    public function value(): mixed
    {
        return null;
    }
}
