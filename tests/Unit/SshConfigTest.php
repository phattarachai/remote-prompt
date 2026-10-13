<?php

use Phattarachai\RemotePrompt\SshConfig;
use Phattarachai\RemotePrompt\SshHost;

it('reads hosts across Include and finds them by alias or host name', function () {
    $ssh = getenv('HOME').'/.ssh';
    file_put_contents("{$ssh}/config", <<<'CONF'
        Include conf.d/*.conf

        Host *
            ServerAliveInterval 60

        Host qas qas-db
            HostName 203.0.113.10
            User deploy
            ProxyCommand gcloud compute start-iap-tunnel qas 22 --listen-on-stdin
        CONF);
    file_in($ssh, 'conf.d/extra.conf', "Host shop-prod\n  HostName=203.0.113.20\n  Port 2222\n");

    $config = new SshConfig($ssh);

    expect(array_map(fn (SshHost $host) => $host->alias(), $config->hosts()))->toBe(['shop-prod', 'qas'])
        ->and($config->find('203.0.113.10')->alias())->toBe('qas')
        ->and($config->find('qas-db')->viaGcloud())->toBeTrue()
        ->and($config->find('shop-prod')->describe())->toBe('203.0.113.20:2222')
        ->and($config->find('nope'))->toBeNull();
});

it('writes new hosts to its own file and includes it on top of the user config', function () {
    $ssh = getenv('HOME').'/.ssh';
    file_put_contents("{$ssh}/config", "Host old\n  HostName 203.0.113.1\n");

    $config = new SshConfig($ssh);
    $config->add(new SshHost(['shop-uat'], '203.0.113.30', 'root', null, '~/.ssh/id_ed25519'));

    expect($config->included())->toBeFalse()
        ->and($config->find('shop-uat'))->toBeNull();

    $config->addInclude();

    expect(file_get_contents("{$ssh}/config"))->toStartWith(SshConfig::INCLUDE_LINE)
        ->and(file_exists("{$ssh}/config.rh-backup"))->toBeTrue()
        ->and($config->included())->toBeTrue()
        ->and($config->find('203.0.113.30')->user)->toBe('root')
        ->and(file_get_contents($config->managedFile()))->toContain("Host shop-uat\n    HostName 203.0.113.30\n    User root\n    IdentityFile ~/.ssh/id_ed25519\n");
});
