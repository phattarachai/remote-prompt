<?php

namespace Phattarachai\RemotePrompt;

use Phattarachai\RemotePrompt\Commands\Add;
use SimpleXMLElement;

/**
 * Reads PHPStorm's Deployment settings: .idea/{deployment,webServers,sshConfigs}.xml per project plus the IDE-wide
 * options folder. A server's path is its root folder joined with the mapping's deployment path; its host is
 * matched to an existing ~/.ssh/config alias by host name, or becomes a new alias.
 */
final class PhpStorm
{
    /**
     * @var array<string, SshHost> new aliases planned during this import, by "user@host:port"
     */
    private array $newHosts = [];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    public function __construct(
        private readonly SshConfig $sshConfig,
        private readonly ?string $optionsDir = null,
    ) {}

    public static function optionsDir(): ?string
    {
        $candidates = [
            ...(glob(Home::path('Library/Application Support/JetBrains/PhpStorm*/options')) ?: []),
            ...(glob(Home::path('.config/JetBrains/PhpStorm*/options')) ?: []),
        ];

        natsort($candidates);

        return array_values($candidates)[count($candidates) - 1] ?? null;
    }

    /**
     * The folder itself when it is a PHPStorm project, else each child folder that is.
     *
     * @return list<string>
     */
    public static function projects(string $dir): array
    {
        if (is_file($dir.'/.idea/deployment.xml')) {
            return [$dir];
        }

        return array_map('dirname', array_map('dirname', glob($dir.'/*/.idea/deployment.xml') ?: []));
    }

    /**
     * @return list<Target>
     */
    public function targets(string $project): array
    {
        $sshConfigs = [...$this->sshConfigs($this->optionsDir === null ? null : $this->optionsDir.'/sshConfigs.xml'), ...$this->sshConfigs($project.'/.idea/sshConfigs.xml')];
        $servers = [...$this->webServers($this->optionsDir === null ? null : $this->optionsDir.'/webServers.xml'), ...$this->webServers($project.'/.idea/webServers.xml')];
        $targets = [];

        foreach ($this->deployments($project) as $name => $deploy) {
            $server = $servers[$name] ?? null;

            if ($server === null) {
                $this->warnings[] = basename($project).": {$name} — not an SFTP server, skipped.";

                continue;
            }

            $ssh = $sshConfigs[$server['sshConfigId']] ?? $sshConfigs[$server['sshConfig']] ?? [];
            $hostName = $ssh['host'] ?? $server['host'];

            if ($hostName === '') {
                continue;
            }

            $label = trim((string) preg_replace('/^\d+\s+/', '', $name)) ?: $name;
            $alias = $this->alias($label, $hostName, $ssh['username'] ?? null, $ssh['port'] ?? $server['port'], $ssh['keyPath'] ?? null);

            if (($ssh['authType'] ?? '') === 'PASSWORD' && $this->sshConfig->find($hostName) === null) {
                $this->warnings[] = basename($project).": {$name} used a password in PHPStorm — rh connects with keys only: ssh-copy-id {$alias}";
            }
            $label = isset($targets[strtolower($label)]) ? "{$label}-{$alias}" : $label;

            $targets[strtolower($label)] = new Target($label, $alias, Target::normalize(rtrim($server['root'], '/').'/'.ltrim($deploy, '/')), Add::guessEnv($label));
        }

        return array_values($targets);
    }

    /**
     * @return list<SshHost>
     */
    public function newHosts(): array
    {
        return array_values($this->newHosts);
    }

    private function alias(string $label, string $hostName, ?string $user, ?string $port, ?string $keyPath): string
    {
        $existing = $this->sshConfig->find($hostName);

        if ($existing !== null) {
            return $existing->alias();
        }

        $key = "{$user}@{$hostName}:{$port}";

        if (isset($this->newHosts[$key])) {
            return $this->newHosts[$key]->alias();
        }

        $alias = Add::slug($label);
        $taken = fn (string $candidate) => $this->sshConfig->find($candidate) !== null || array_filter($this->newHosts, fn (SshHost $host) => $host->alias() === $candidate) !== [];

        for ($suffix = 2, $base = $alias; $taken($alias); $suffix++) {
            $alias = "{$base}-{$suffix}";
        }

        $this->newHosts[$key] = new SshHost([$alias], $hostName, $user, $port === null || $port === '22' ? null : $port, $keyPath === null ? null : Home::contract(str_replace('$USER_HOME$', Home::path(), $keyPath)));

        return $alias;
    }

    /**
     * @return array<string, array<string, string>> by id and by display name
     */
    private function sshConfigs(?string $file): array
    {
        $configs = [];

        foreach (self::xml($file)?->xpath('//sshConfig') ?: [] as $node) {
            $attributes = self::attributes($node);
            $configs[$attributes['id'] ?? ''] = $attributes;

            if (isset($attributes['customName'])) {
                $configs[$attributes['customName']] = $attributes;
            }

            if (isset($attributes['username'], $attributes['host'])) {
                $configs["{$attributes['username']}@{$attributes['host']}"] ??= $attributes;
            }
        }

        return $configs;
    }

    /**
     * @return array<string, array{root: string, host: string, port: ?string, sshConfigId: string, sshConfig: string}>
     */
    private function webServers(?string $file): array
    {
        $servers = [];

        foreach (self::xml($file)?->xpath('//webServer') ?: [] as $node) {
            $transfer = $node->fileTransfer;

            if ($transfer === null || (string) $transfer['accessType'] !== 'SFTP') {
                continue;
            }

            $servers[(string) $node['name']] = [
                'root' => (string) $transfer['rootFolder'] ?: '/',
                'host' => (string) $transfer['host'],
                'port' => ((string) $transfer['port']) ?: null,
                'sshConfigId' => (string) $transfer['sshConfigId'],
                'sshConfig' => (string) $transfer['sshConfig'],
            ];
        }

        return $servers;
    }

    /**
     * @return array<string, string> server name => deployment path of the mapping for the project root
     */
    private function deployments(string $project): array
    {
        $deployments = [];

        foreach (self::xml($project.'/.idea/deployment.xml')?->xpath('//paths') ?: [] as $paths) {
            foreach ($paths->xpath('.//mapping') ?: [] as $mapping) {
                if ((string) $mapping['local'] === '$PROJECT_DIR$') {
                    $deployments[(string) $paths['name']] = (string) $mapping['deploy'] ?: '/';
                }
            }
        }

        return $deployments;
    }

    private static function xml(?string $file): ?SimpleXMLElement
    {
        if ($file === null || ! is_file($file)) {
            return null;
        }

        return simplexml_load_file($file) ?: null;
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(SimpleXMLElement $node): array
    {
        $attributes = [];

        foreach ($node->attributes() ?? [] as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        return $attributes;
    }
}
