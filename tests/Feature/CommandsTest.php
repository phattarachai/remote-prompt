<?php

beforeEach(function () {
    $this->project = tempDir('project');
    file_in($this->project, 'app/User.php', "<?php // local\n");
    file_in($this->server, 'app/User.php', "<?php // deployed\n");
    file_in($this->server, '.env', "APP_KEY=secret\n");

    writeConfig([$this->project => [
        ['name' => 'uat', 'host' => 'uat-box', 'path' => $this->server, 'env' => 'uat'],
        ['name' => 'prod', 'host' => 'prod-box', 'path' => $this->server, 'env' => 'production'],
    ]]);
});

it('needs a target name when a project has several and there is no terminal', function () {
    [$code, $output] = rh(['cat', '.env'], $this->project);

    expect($code)->toBe(1)
        ->and($output)->toContain('Several targets here — name one: uat, prod.');
});

it('prints a remote file when piped, a .env with its values masked', function () {
    file_in($this->server, '.env', "# app\nAPP_KEY=secret\nAPP_DEBUG=\nexport DB_PASSWORD = \"p w\"\n");

    [$code, $output] = rh(['cat', 'prod:.env'], $this->project.'/app');

    expect($code)->toBe(0)
        ->and($output)->toBe("# app\nAPP_KEY=<set>\nAPP_DEBUG=\nexport DB_PASSWORD =<set>\n")
        ->and($this->runner->commands[0])->toContain('prod-box', 'BatchMode=yes');

    [, $output] = rh(['cat', 'prod:.env', '--raw'], $this->project);
    [, $plain] = rh(['cat', 'prod:app/User.php'], $this->project);

    expect($output)->toContain('APP_KEY=secret')
        ->and($plain)->toBe("<?php // deployed\n");
});

it('prints the last lines of a log without following it when piped', function () {
    file_in($this->server, 'storage/logs/laravel.log', implode("\n", range(1, 300))."\n");

    [$code, $output] = rh(['tail', 'uat', '--lines=3'], $this->project);

    expect($code)->toBe(0)->and($output)->toBe("298\n299\n300\n");
});

it('uploads a file to its mapped path after showing the diff', function () {
    [$code, $output] = rh(['put', 'User.php', 'uat'], $this->project.'/app');

    expect($code)->toBe(0)
        ->and($output)->toContain('-<?php // deployed', '+<?php // local', 'Uploaded to uat:'.$this->server.'/app/User.php')
        ->and(file_get_contents($this->server.'/app/User.php'))->toBe("<?php // local\n");
});

it('refuses to write to production without a terminal', function () {
    [$code, $output] = rh(['put', 'app/User.php', 'prod'], $this->project);

    expect($code)->toBe(1)
        ->and($output)->toContain('Refusing to Upload User.php on production target prod without a terminal')
        ->and(file_get_contents($this->server.'/app/User.php'))->toBe("<?php // deployed\n");
});

it('downloads into a given folder', function () {
    $here = tempDir('downloads');

    [$code, $output] = rh(['get', 'uat:.env', $here], $this->project);

    expect($output)->toContain('Downloaded to')
        ->and($code)->toBe(0)
        ->and(file_get_contents($here.'/.env'))->toBe("APP_KEY=secret\n");
});

it('compares a local file with its deployed copy', function () {
    [$code, $output] = rh(['diff', 'app/User.php', 'uat'], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toContain('--- uat:'.$this->server.'/app/User.php', '+<?php // local');
});

it('reaches any ssh alias ad hoc with an absolute path', function () {
    [$code, $output] = rh(['cat', 'some-box:'.$this->server.'/app/User.php'], tempDir('elsewhere'));

    expect($code)->toBe(0)
        ->and($output)->toBe("<?php // deployed\n")
        ->and($this->runner->commands[0])->toContain('some-box');
});

it('says where to start when a folder has no targets', function () {
    [$code, $output] = rh(['ssh'], tempDir('elsewhere'));

    expect($code)->toBe(1)
        ->and($output)->toContain('No targets for', 'rh add');
});

it('checks every target folder', function () {
    [$code, $output] = rh(['doctor'], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toContain('uat', 'prod', 'ok');
});

it('adds a target without questions for scripts and agents', function () {
    file_in($this->server, 'uat/.keep');
    $project = tempDir('scripted');

    [$code, $output] = rh(['add', '--host=shop-prod', '--path', $this->server.'/uat'], $project);

    expect($code)->toBe(0)
        ->and($output)->toContain('Added shop-uat → shop-prod:'.$this->server.'/uat (uat)');

    [$code, $output] = rh(['add', '--host=shop-prod', '--path='.$this->server.'/uat'], $project);

    expect($code)->toBe(1)
        ->and($output)->toContain('already has shop-uat → shop-prod:');

    [$code, $output] = rh(['add', '--host=other', '--path='.$this->server, '--name=SHOP-UAT'], $project);

    expect($code)->toBe(1)
        ->and($output)->toContain('already has a target named SHOP-UAT');

    [$code, $output] = rh(['add', '--host=shop-prod', '--path=/nowhere', '--name=x'], $project);

    expect($code)->toBe(1)
        ->and($output)->toContain('/nowhere is not a folder on shop-prod', '--force');
});
