<?php

use Laravel\Prompts\Key;
use Phattarachai\RemotePrompt\Commands\Artisan;
use Phattarachai\RemotePrompt\Commands\Panel;
use Phattarachai\RemotePrompt\Config;
use Phattarachai\RemotePrompt\Target;

function gitRepo(string $dir): string
{
    exec('cd '.escapeshellarg($dir).' && git init -q -b main && git -c user.email=t@t -c user.name=t commit -q --allow-empty -m init');

    return $dir;
}

/**
 * @param  list<array<string, string>>  $targets
 * @param  array<string, mixed>  $hosts
 */
function rhJson(string $dir, array $targets, array $hosts = []): void
{
    file_put_contents($dir.'/.rh.json', json_encode(['targets' => $targets] + ($hosts === [] ? [] : ['hosts' => $hosts])));
}

beforeEach(function () {
    $this->project = gitRepo(tempDir('project'));
    file_in($this->server, 'artisan', '<?php echo "artisan ", implode(" ", array_slice($argv, 1)), "\n";');
});

it('takes the targets of .rh.json first, then the ones this machine adds', function () {
    rhJson($this->project, [
        ['name' => 'prod', 'host' => 'shop-prod', 'path' => '/var/www/shop', 'env' => 'production', 'run_as' => 'www-data'],
    ]);
    writeConfig([$this->project => [
        ['name' => 'prod', 'host' => 'old-box', 'path' => '/srv/shop'],
        ['name' => 'mine', 'host' => 'my-box', 'path' => '/srv/shop', 'env' => 'dev'],
    ]]);

    $binding = Config::load()->bind($this->project.'/app');

    expect(array_map(fn (Target $target) => "{$target->name}@{$target->host}", $binding->targets))->toBe(['prod@shop-prod', 'mine@my-box'])
        ->and($binding->root)->toBe($this->project)
        ->and($binding->targets[0]->runAs)->toBe('www-data')
        ->and($binding->shared($binding->targets[0]))->toBeTrue()
        ->and($binding->shared($binding->targets[1]))->toBeFalse();
});

it('lets a worktree borrow its main checkout\'s .rh.json before it is committed', function () {
    $worktree = tempDir('wt').'/feature';
    exec('cd '.escapeshellarg($this->project).' && git worktree add -q '.escapeshellarg($worktree).' -b feature');
    rhJson($this->project, [['name' => 'uat', 'host' => 'shop-uat', 'path' => '/var/www/shop', 'env' => 'uat']]);

    $binding = Config::load()->bind((string) realpath($worktree));

    expect($binding->targets[0]->name)->toBe('uat')
        ->and($binding->root)->toBe((string) realpath($worktree));
});

it('adds a target to the repository\'s .rh.json, or to this machine with --save=machine', function () {
    [$code, $output] = rh(['add', '--host=uat-box', "--path={$this->server}", '--env=uat', '--run-as=www-data'], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toContain('to '.$this->project.'/.rh.json — commit it')
        ->and(json_decode((string) file_get_contents($this->project.'/.rh.json'), true)['targets'])
        ->toBe([['name' => 'uat-box', 'host' => 'uat-box', 'path' => $this->server, 'env' => 'uat', 'run_as' => 'www-data']]);

    [$code] = rh(['add', '--host=my-box', "--path={$this->server}", '--name=mine', '--env=dev', '--save=machine'], $this->project);
    [, $list] = rh(['list', '--json'], $this->project);
    $targets = json_decode($list, true)['projects'][$this->project];

    expect($code)->toBe(0)
        ->and(array_column($targets, 'source', 'name'))->toBe(['uat-box' => '.rh.json', 'mine' => 'machine'])
        ->and(json_decode((string) file_get_contents($this->project.'/.rh.json'), true)['targets'])->toHaveCount(1);
});

it('removes a shared target from .rh.json', function () {
    rhJson($this->project, [
        ['name' => 'uat', 'host' => 'shop-uat', 'path' => '/var/www/shop', 'env' => 'uat'],
        ['name' => 'prod', 'host' => 'shop-prod', 'path' => '/var/www/shop'],
    ]);

    answer(['y', Key::ENTER]);
    [$code, $output] = rh(['remove', 'uat'], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toContain('Removed uat from')
        ->and(array_column(json_decode((string) file_get_contents($this->project.'/.rh.json'), true)['targets'], 'name'))->toBe(['prod']);
});

it('runs artisan in the deploy path', function () {
    rhJson($this->project, [['name' => 'uat', 'host' => 'uat-box', 'path' => $this->server, 'env' => 'uat']]);

    [$code, $output] = rh(['artisan', 'uat', 'queue:retry', 'all'], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toBe("artisan queue:retry all\n");
});

it('runs only read-only artisan commands on production without a terminal', function () {
    rhJson($this->project, [['name' => 'prod', 'host' => 'prod-box', 'path' => $this->server]]);

    [$code, $output] = rh(['artisan', 'about'], $this->project);

    expect($code)->toBe(0)->and($output)->toBe("artisan about\n");

    [$code, $output] = rh(['artisan', 'prod', 'migrate', '--force'], $this->project);

    expect($code)->toBe(1)
        ->and($output)->toContain('Refusing to run php artisan migrate on production target prod without a terminal');
});

it('switches to the app user for artisan, never waiting on a sudo password without a terminal', function () {
    $target = new Target('qas', 'qas', '/var/www/shop', 'qas', 'www-data');

    expect(Artisan::script($target, ['tinker', '--execute=echo 1;'], false))
        ->toBe("cd -- '/var/www/shop' && sudo -n -u 'www-data' env HOME=/tmp php artisan 'tinker' '--execute=echo 1;'")
        ->and(Artisan::script($target, ['tinker'], true))->toContain("sudo -u 'www-data'");
});

it('shows the ssh config block .rh.json describes for an alias this machine lacks', function () {
    rhJson($this->project, [['name' => 'qas', 'host' => 'shop-qas', 'path' => '/var/www/shop', 'env' => 'qas']], [
        'shop-qas' => ['gcp_iap' => ['instance' => 'shop-qas-vm', 'project' => 'acme', 'zone' => 'asia-southeast1-b']],
    ]);

    [$code, $output] = rh(['doctor'], $this->project);

    expect($code)->toBe(1)
        ->and($output)->toContain('alias missing', 'Host shop-qas', 'IdentityFile ~/.ssh/google_compute_engine')
        ->and($output)->toContain('ProxyCommand gcloud compute start-iap-tunnel shop-qas-vm 22 --listen-on-stdin --project=acme --zone=asia-southeast1-b');
});

it('splits a typed artisan line into words', function () {
    expect(Panel::words('tinker --execute="echo 1;" \'a b\''))->toBe(['tinker', '--execute=echo 1;', 'a b']);
});

it('saves a target added from the panel to .rh.json by default inside a repository', function () {
    file_put_contents($this->home.'/.ssh/config', "Host test-box\n  HostName 203.0.113.9\n");
    mkdir($this->server.'/shop');
    chdir($this->server);

    answer(['Add', Key::ENTER, 'test-box', Key::ENTER, 'shop', Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::ENTER, Key::ESCAPE]);

    [$code, $output] = rh([], $this->project);

    expect($code)->toBe(0)
        ->and($output)->toContain('Save to', 'commit it')
        ->and(json_decode((string) file_get_contents($this->project.'/.rh.json'), true)['targets'][0])
        ->toBe(['name' => 'test-box', 'host' => 'test-box', 'path' => $this->server.'/shop', 'env' => 'dev']);
});
