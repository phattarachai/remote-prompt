<?php

namespace Phattarachai\RemotePrompt;

use RuntimeException;

/**
 * .rh.json at the root of a repository — the project's targets, committed so every checkout, worktree and machine
 * (and every agent working in it) knows where the project is deployed. Optional `hosts` describe how to reach an
 * alias, so `rh doctor` can hand a new machine the ~/.ssh/config block it lacks.
 */
final class ProjectFile
{
    public const string NAME = '.rh.json';

    /**
     * @param  list<Target>  $targets
     * @param  array<string, array<string, mixed>>  $hosts
     */
    public function __construct(
        public readonly string $path,
        private array $targets = [],
        private array $hosts = [],
    ) {}

    /**
     * The nearest .rh.json in the folder or a parent. A worktree whose own copy doesn't exist yet (the file isn't
     * committed) borrows its main checkout's; the second value is the project root the folder belongs to.
     *
     * @return array{0: ?self, 1: ?string}
     */
    public static function locate(string $cwd): array
    {
        for ($dir = rtrim($cwd, '/') ?: '/'; ; $dir = dirname($dir)) {
            if (is_file($dir.'/'.self::NAME)) {
                return [self::load($dir.'/'.self::NAME), $dir];
            }

            if ($dir === '/' || $dir === '.') {
                break;
            }
        }

        [$top, $main] = Git::worktreeRoots($cwd);

        if ($top !== null && $main !== null && $top !== $main && is_file($main.'/'.self::NAME)) {
            return [self::load($main.'/'.self::NAME), $top];
        }

        return [null, null];
    }

    public static function load(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new RuntimeException(Home::contract($path).' is not valid JSON.');
        }

        return new self($path, array_map(Target::fromArray(...), array_values($data['targets'] ?? [])), $data['hosts'] ?? []);
    }

    /**
     * The file at a project root, loaded when it exists, else empty until saved.
     */
    public static function at(string $root): self
    {
        $path = rtrim($root, '/').'/'.self::NAME;

        return is_file($path) ? self::load($path) : new self($path);
    }

    public function root(): string
    {
        return dirname($this->path);
    }

    /**
     * @return list<Target>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    public function find(string $name): ?Target
    {
        foreach ($this->targets as $target) {
            if (strcasecmp($target->name, $name) === 0) {
                return $target;
            }
        }

        return null;
    }

    public function add(Target $target): void
    {
        $this->remove($target->name);
        $this->targets[] = $target;
    }

    public function remove(string $name): void
    {
        $this->targets = array_values(array_filter($this->targets, fn (Target $target) => strcasecmp($target->name, $name) !== 0));
    }

    /**
     * The ssh config block the file describes for an alias, or null when it says nothing about it.
     * `gcp_iap` ({instance, project, zone}) rides Identity-Aware Proxy with gcloud's key; otherwise hostname/user/port.
     */
    public function host(string $alias): ?SshHost
    {
        $host = $this->hosts[$alias] ?? null;

        if (! is_array($host)) {
            return null;
        }

        $iap = $host['gcp_iap'] ?? null;

        if (is_array($iap) && isset($iap['instance'])) {
            $flags = implode('', array_map(fn (string $key) => isset($iap[$key]) ? " --{$key}={$iap[$key]}" : '', ['project', 'zone']));

            return new SshHost(
                [$alias],
                user: $host['user'] ?? null,
                identityFile: $host['identity_file'] ?? '~/.ssh/google_compute_engine',
                proxyCommand: "gcloud compute start-iap-tunnel {$iap['instance']} 22 --listen-on-stdin{$flags} --verbosity=error",
            );
        }

        return new SshHost([$alias], $host['hostname'] ?? null, $host['user'] ?? null, isset($host['port']) ? (string) $host['port'] : null, $host['identity_file'] ?? null);
    }

    public function save(): void
    {
        $data = ['targets' => array_map(fn (Target $target) => $target->toArray(), $this->targets)];

        if ($this->hosts !== []) {
            $data['hosts'] = $this->hosts;
        }

        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }
}
