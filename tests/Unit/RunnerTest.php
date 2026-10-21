<?php

use Symfony\Component\Process\Process;

it('passes a streamed command\'s output through while it is still running', function () {
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    $child = new Process([PHP_BINARY, '-r', 'require '.var_export($autoload, true).'; exit((new Phattarachai\RemotePrompt\Runner)->stream(["sh", "-c", "echo first; sleep 1; echo second; exit 3"]));']);
    $child->start();

    $child->waitUntil(fn () => str_contains($child->getOutput(), 'first'));

    expect($child->isRunning())->toBeTrue();

    $child->wait();

    expect($child->getOutput())->toBe("first\nsecond\n")
        ->and($child->getExitCode())->toBe(3);
});
