<?php

namespace Phattarachai\RemotePrompt;

final readonly class Result
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput = '',
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function message(): string
    {
        return trim($this->errorOutput) !== '' ? trim($this->errorOutput) : trim($this->output);
    }
}
