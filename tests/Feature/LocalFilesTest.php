<?php

use Phattarachai\RemotePrompt\Commands\Panel;
use Phattarachai\RemotePrompt\Context;

it('offers ignored files like .env but not vendor or framework caches', function () {
    $root = tempDir('app');
    file_in($root, '.gitignore', "/vendor\n.env\n/storage/framework/views/\n.DS_Store\n");
    file_in($root, 'app/User.php');
    file_in($root, '.env', 'APP_KEY=x');
    file_in($root, 'vendor/x/y.php');
    file_in($root, 'storage/framework/views/abc.php');
    file_in($root, '.DS_Store');
    exec('git -C '.escapeshellarg($root).' init -q');

    $files = (new Panel(Context::make($root, $this->runner)))->localFiles($root);

    expect($files)->toBe(['.env', '.gitignore', 'app/User.php']);
});

it('falls back to find outside git', function () {
    $root = tempDir('plain');
    file_in($root, '.env');
    file_in($root, 'vendor/x.php');

    expect((new Panel(Context::make($root, $this->runner)))->localFiles($root))->toBe(['.env']);
});
