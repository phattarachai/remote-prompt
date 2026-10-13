<?php

namespace Phattarachai\RemotePrompt\Prompts;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;

/**
 * Esc backs out of the current screen. Prompts only knows Ctrl+C, so the
 * terminal reports a bare Esc as Ctrl+C and the cancel hook tells the two apart:
 * Esc throws {@see Cancelled}, Ctrl+C still quits.
 */
final class Escape extends Terminal
{
    private static bool $pressed = false;

    public function __construct(private readonly Terminal $inner) {}

    public static function install(): void
    {
        Output::wrapTerminal(fn (Terminal $terminal) => $terminal instanceof self ? $terminal : new self($terminal));

        Prompt::cancelUsing(function (): void {
            if (self::$pressed) {
                self::$pressed = false;

                throw new Cancelled;
            }

            Prompt::terminal()->exit();
        });
    }

    public function read(): string
    {
        $key = $this->inner->read();

        if ($key === Key::ESCAPE) {
            self::$pressed = true;

            return Key::CTRL_C;
        }

        return $key;
    }

    public function setTty(string $mode): void
    {
        $this->inner->setTty($mode);
    }

    public function restoreTty(): void
    {
        $this->inner->restoreTty();
    }

    public function cols(): int
    {
        return $this->inner->cols();
    }

    public function lines(): int
    {
        return $this->inner->lines();
    }

    public function initDimensions(): void
    {
        $this->inner->initDimensions();
    }

    public function exit(): void
    {
        $this->inner->exit();
    }

    public function supportsTrueColor(): bool
    {
        return $this->inner->supportsTrueColor();
    }

    public function foregroundColor(): array
    {
        return $this->inner->foregroundColor();
    }

    public function backgroundColor(): array
    {
        return $this->inner->backgroundColor();
    }
}
