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

it('prints a remote file when piped', function () {
    [$code, $output] = rh(['cat', 'prod:.env'], $this->project.'/app');

    expect($code)->toBe(0)
        ->and($output)->toBe("APP_KEY=secret\n")
        ->and($this->runner->commands[0])->toContain('prod-box', 'BatchMode=yes');
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
    [$code, $output] = rh(['cat', 'some-box:'.$this->server.'/.env'], tempDir('elsewhere'));

    expect($code)->toBe(0)
        ->and($output)->toBe("APP_KEY=secret\n")
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
