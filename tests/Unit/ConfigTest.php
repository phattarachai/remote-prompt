<?php

use Phattarachai\RemotePrompt\Config;
use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\Target;

it('binds a folder to the deepest matching project, globs included', function () {
    $www = tempDir('www');
    mkdir("{$www}/shop-api/app", recursive: true);
    mkdir("{$www}/shop-web");

    writeConfig([
        "{$www}/shop-*" => [['name' => 'qas', 'host' => 'qas', 'path' => '/var/www/shop']],
        "{$www}/shop-api" => [['name' => 'prod', 'host' => 'prod', 'path' => '/var/www/api']],
    ]);

    $config = Config::load();

    expect($config->bind("{$www}/shop-api/app")->root)->toBe("{$www}/shop-api")
        ->and($config->bind("{$www}/shop-api/app")->targets[0]->name)->toBe('prod')
        ->and($config->bind("{$www}/shop-web")->targets[0]->name)->toBe('qas')
        ->and($config->bind($www))->toBeNull();
});

it('lets a git worktree inherit its main checkout\'s targets', function () {
    $main = tempDir('main');
    $worktree = tempDir('wt').'/feature';

    exec(implode(' && ', [
        'cd '.escapeshellarg($main),
        'git init -q -b main',
        'git -c user.email=t@t -c user.name=t commit -q --allow-empty -m init',
        'git worktree add -q '.escapeshellarg($worktree).' -b feature',
    ]));

    writeConfig([$main => [['name' => 'qas', 'host' => 'qas', 'path' => '/var/www/shop']]]);

    $binding = Config::load()->bind((string) realpath($worktree));

    expect($binding->key)->toBe($main)
        ->and($binding->root)->toBe((string) realpath($worktree));
});

it('saves projects keyed by path with the home folder contracted', function () {
    $config = Config::load();
    $config->addTarget(Home::contract(getenv('HOME').'/www/shop'), new Target('qas', 'qas', '/var/www/shop', 'qas'));
    $config->addTarget('~/www/shop', new Target('QAS', 'qas', '/var/www/shop2', 'qas'));
    $config->save();

    expect(json_decode((string) file_get_contents(Home::configFile()), true))->toBe([
        'projects' => ['~/www/shop' => ['targets' => [['name' => 'QAS', 'host' => 'qas', 'path' => '/var/www/shop2', 'env' => 'qas']]]],
    ]);
});
