<?php

use Phattarachai\RemotePrompt\Config;
use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\PhpStorm;
use Phattarachai\RemotePrompt\SshConfig;
use Phattarachai\RemotePrompt\Target;

beforeEach(function () {
    file_put_contents($this->home.'/.ssh/config', "Host qas\n  HostName 203.0.113.10\n  User deploy\n");

    $this->www = tempDir('www');
    $idea = $this->www.'/shop-api/.idea';

    file_in($idea, 'sshConfigs.xml', <<<'XML'
        <project version="4"><component name="SshConfigs"><configs>
          <sshConfig host="203.0.113.10" id="a1" port="22" customName="QAS" username="root" />
          <sshConfig authType="PASSWORD" host="203.0.113.20" id="b2" port="2222" customName="Production" username="pc" keyPath="$USER_HOME$/.ssh/id_prod" />
        </configs></component></project>
        XML);
    file_in($idea, 'webServers.xml', <<<'XML'
        <project version="4"><component name="WebServers"><option name="servers">
          <webServer id="w1" name="1 QAS"><fileTransfer rootFolder="/var/www/shop" accessType="SFTP" host="203.0.113.10" port="22" sshConfigId="a1" sshConfig="QAS" /></webServer>
          <webServer id="w2" name="2 Production"><fileTransfer rootFolder="/var/www" accessType="SFTP" host="203.0.113.20" port="2222" sshConfigId="b2" sshConfig="Production" /></webServer>
          <webServer id="w3" name="old-ftp"><fileTransfer rootFolder="/" accessType="FTP" host="198.51.100.5" port="21" /></webServer>
        </option></component></project>
        XML);
    file_in($idea, 'deployment.xml', <<<'XML'
        <project version="4"><component name="PublishConfigData" serverName="1 QAS"><serverData>
          <paths name="1 QAS"><serverdata><mappings><mapping deploy="/" local="$PROJECT_DIR$" web="/" /></mappings></serverdata></paths>
          <paths name="2 Production"><serverdata><mappings><mapping deploy="/shop" local="$PROJECT_DIR$" web="/" /></mappings></serverdata></paths>
          <paths name="old-ftp"><serverdata><mappings><mapping deploy="/" local="$PROJECT_DIR$" web="/" /></mappings></serverdata></paths>
        </serverData></component></project>
        XML);
    mkdir($this->www.'/no-idea');
});

it('joins root folder and deployment path, reusing ssh aliases by host name', function () {
    $phpStorm = new PhpStorm(SshConfig::load());

    expect(PhpStorm::projects($this->www))->toBe([$this->www.'/shop-api'])
        ->and($phpStorm->targets($this->www.'/shop-api'))->toEqual([
            new Target('QAS', 'qas', '/var/www/shop', 'qas'),
            new Target('Production', 'production', '/var/www/shop', 'production'),
        ])
        ->and($phpStorm->newHosts()[0]->describe())->toBe('pc@203.0.113.20:2222')
        ->and($phpStorm->warnings)->toHaveCount(2);
});

it('imports every project under a folder and writes new hosts to rh.conf', function () {
    [$code, $output] = rh(['import', 'phpstorm', '--yes'], $this->www);

    expect($code)->toBe(0)
        ->and($output)->toContain('used a password in PHPStorm')
        ->and(Config::load()->projects())->toHaveKey(Home::contract($this->www.'/shop-api'))
        ->and(file_get_contents($this->home.'/.ssh/config.d/rh.conf'))->toContain("Host production\n    HostName 203.0.113.20\n    User pc\n    Port 2222\n    IdentityFile ~/.ssh/id_prod")
        ->and(file_get_contents($this->home.'/.ssh/config'))->toStartWith(SshConfig::INCLUDE_LINE);

    [, $list] = rh(['list', '--json'], $this->www.'/shop-api');

    expect(json_decode($list, true)['projects'][Home::contract($this->www.'/shop-api')][1])
        ->toBe(['name' => 'Production', 'host' => 'production', 'path' => '/var/www/shop', 'env' => 'production', 'source' => 'machine']);
});
