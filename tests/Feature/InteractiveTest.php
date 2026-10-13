<?php

use Laravel\Prompts\Key;
use Phattarachai\RemotePrompt\Config;
use Phattarachai\RemotePrompt\Target;

beforeEach(function () {
    $this->project = tempDir('project');
    file_in($this->server, 'app/User.php', "<?php // deployed\n");
    file_in($this->server, '.env', "APP_KEY=secret\n");

    writeConfig([$this->project => [['name' => 'uat', 'host' => 'uat-box', 'path' => $this->server, 'env' => 'uat']]]);
});

it('browses into a folder and downloads a file', function () {
    answer(['app', Key::ENTER, 'User', Key::ENTER, 'Download', Key::ENTER, Key::ESCAPE]);

    [$code] = rh(['browse'], $this->project);

    expect($code)->toBe(0)
        ->and(file_get_contents($this->project.'/User.php'))->toBe("<?php // deployed\n");
});

it('edits a remote file in place after a diff and a confirm', function () {
    putenv('EDITOR=sed -i.bak s/secret/rotated/');
    answer([Key::ENTER]);

    [$code, $output] = rh(['edit', 'uat:.env'], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toContain('-APP_KEY=secret', '+APP_KEY=rotated', 'Saved uat:')
        ->and(file_get_contents($this->server.'/.env'))->toBe("APP_KEY=rotated\n");
});

it('opens the shell of the target picked on the first screen, then quits', function () {
    putenv('SHELL=/usr/bin/true');
    answer([Key::ENTER]);

    [$code, $output] = rh([], $this->project);

    expect($code)->toBe(0)
        ->and(substr_count($output, $this->project))->toBe(1)
        ->and($this->runner->commands[0])->toContain('LogLevel=ERROR', '-t');
});

it('opens the panel on the only target and leaves on Esc', function () {
    answer([Key::ESCAPE]);

    [$code, $output] = rh([], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toContain('Targets', 'Actions', 'Setup', 'uat   uat-box:');
});

it('switches to the Setup tab and comes back to it after an action', function () {
    answer([Key::TAB, Key::TAB, 'Test', Key::ENTER, Key::ESCAPE]);

    [$code, $output] = rh([], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toContain('Setup', 'ok');
});

it('adds a target by picking the server folder, then lands on the panel', function () {
    file_put_contents($this->home.'/.ssh/config', "Host test-box\n  HostName 203.0.113.9\n");
    mkdir($this->server.'/other');
    mkdir($this->server.'/my_shop');
    $project = tempDir('local').'/my-shop';
    mkdir($project);
    chdir($this->server);

    answer(['Add', Key::ENTER, 'test-box', Key::ENTER, 'sho', Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::ESCAPE]);

    [$code, $output] = rh([], $project);

    expect($code)->toBe(0)
        ->and($output)->toContain('No targets for', '★ my_shop/', 'Added test-box → test-box:'.$this->server.'/my_shop', 'test-box   test-box:');
});

it('adds uat beside production on the same host without replacing it', function () {
    file_put_contents($this->home.'/.ssh/config', "Host shop-prod\n  HostName 203.0.113.9\n");
    mkdir($this->server.'/uat');
    writeConfig([$this->project => [['name' => 'shop-prod', 'host' => 'shop-prod', 'path' => $this->server, 'env' => 'production']]]);
    chdir($this->server);

    answer([Key::TAB, Key::TAB, 'Add', Key::ENTER, 'shop-prod', Key::ENTER, 'uat', Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::ESCAPE]);

    [$code] = rh([], $this->project);

    expect($code)->toBe(0)
        ->and(Config::load()->projects()[$this->project])->toEqual([
            new Target('shop-prod', 'shop-prod', $this->server, 'production'),
            new Target('shop-uat', 'shop-prod', $this->server.'/uat', 'uat'),
        ]);
});

it('runs an action on the target picked after it, starting from the last one used', function () {
    writeConfig([$this->project => [
        ['name' => 'uat', 'host' => 'uat-box', 'path' => $this->server, 'env' => 'uat'],
        ['name' => 'prod', 'host' => 'prod-box', 'path' => $this->server.'/missing', 'env' => 'production'],
    ]]);

    answer([Key::TAB, 'View .env', Key::ENTER, Key::ENTER, Key::ESCAPE]);

    [$code, $output] = rh([], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toContain('Which target?', 'APP_KEY=secret');
});
