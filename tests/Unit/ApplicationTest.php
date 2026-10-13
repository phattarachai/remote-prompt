<?php

it('prints the version', function () {
    [$code, $output] = rh(['--version']);

    expect($code)->toBe(0)
        ->and($output)->toBe('remote-prompt dev'.PHP_EOL);
});

it('prints help with no arguments when piped', function () {
    [$code, $output] = rh();

    expect($code)->toBe(0)
        ->and($output)->toContain('Usage:');
});

it('fails on an unknown command with help', function () {
    [$code, $output] = rh(['nope']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Usage:');
});
