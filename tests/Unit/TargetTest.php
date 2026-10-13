<?php

use Phattarachai\RemotePrompt\Binding;
use Phattarachai\RemotePrompt\Commands\Add;
use Phattarachai\RemotePrompt\Target;

it('normalizes and resolves remote paths', function () {
    $target = new Target('qas', 'qas', '/var/www/shop/');

    expect($target->path)->toBe('/var/www/shop/')
        ->and(Target::fromArray(['host' => 'qas', 'path' => '/var/www//shop/'])->path)->toBe('/var/www/shop')
        ->and($target->resolve('storage/../.env'))->toBe('/var/www/shop/.env')
        ->and($target->resolve('/etc/nginx'))->toBe('/etc/nginx')
        ->and((new Target('box', 'box', '.'))->resolve('backups/db.sql'))->toBe('backups/db.sql');
});

it('maps a local file to its deployed path', function () {
    $target = new Target('uat', 'uat', '/var/www/uat-shop');
    $binding = new Binding('~/www/shop', '/Users/me/www/shop', [$target]);

    expect($binding->remotePath($target, '/Users/me/www/shop/app/Models/User.php'))->toBe('/var/www/uat-shop/app/Models/User.php')
        ->and($binding->remotePath($target, '/Users/me/www/shop-other/x.php'))->toBeNull()
        ->and($binding->find('UAT'))->toBe($target);
});

it('guesses the environment from a name', function (string $name, string $env) {
    expect(Add::guessEnv($name))->toBe($env);
})->with([
    ['Shop-Production', 'production'],
    ['2 Shop-UAT', 'uat'],
    ['ERP-QAS', 'qas'],
    ['shop-dev', 'dev'],
    ['phattarachai.dev', 'production'],
    ['devops-box', 'production'],
]);

it('spots a server folder named like the project', function () {
    expect(Add::resembles('oun_jai', 'oun-jai'))->toBeTrue()
        ->and(Add::resembles('shop-api-v2', 'shop-api'))->toBeTrue()
        ->and(Add::resembles('html', 'oun-jai'))->toBeFalse()
        ->and(Add::resembles('a', 'shop-api'))->toBeFalse();
});

it('names a second target on the same host after its environment', function () {
    expect(Add::defaultName('ounjai-prod', 'production', []))->toBe('ounjai-prod')
        ->and(Add::defaultName('ounjai-prod', 'uat', ['ounjai-prod']))->toBe('ounjai-uat')
        ->and(Add::defaultName('ounjai-prod', 'uat', []))->toBe('ounjai-uat')
        ->and(Add::defaultName('box', 'production', []))->toBe('box')
        ->and(Add::defaultName('box', 'uat', ['box']))->toBe('box-uat')
        ->and(Add::defaultName('box', 'uat', ['box', 'box-uat']))->toBe('box-uat-2');
});
