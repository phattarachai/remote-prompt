<?php

namespace Phattarachai\RemotePrompt;

/**
 * Reads the Host blocks of ~/.ssh/config (following Include) and owns one file of its own,
 * ~/.ssh/config.d/rh.conf, for hosts rh adds — the user's hand-written config is never rewritten.
 */
final class SshConfig
{
    public const string INCLUDE_LINE = 'Include ~/.ssh/config.d/rh.conf';

    /**
     * @var list<SshHost>|null
     */
    private ?array $hosts = null;

    public function __construct(private readonly string $dir) {}

    public static function load(): self
    {
        return new self(Home::path('.ssh'));
    }

    public function managedFile(): string
    {
        return $this->dir.'/config.d/rh.conf';
    }

    /**
     * @return list<SshHost>
     */
    public function hosts(): array
    {
        return $this->hosts ??= $this->parse($this->dir.'/config', []);
    }

    public function find(string $aliasOrHostName): ?SshHost
    {
        foreach ($this->hosts() as $host) {
            if (in_array($aliasOrHostName, $host->aliases, true)) {
                return $host;
            }
        }

        foreach ($this->hosts() as $host) {
            if ($host->answersTo($aliasOrHostName)) {
                return $host;
            }
        }

        return null;
    }

    public function add(SshHost $host): void
    {
        $lines = ['Host '.implode(' ', $host->aliases)];

        foreach (['HostName' => $host->hostName, 'User' => $host->user, 'Port' => $host->port, 'IdentityFile' => $host->identityFile] as $key => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = "    {$key} {$value}";
            }
        }

        $file = $this->managedFile();

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }

        $existing = is_file($file) ? rtrim((string) file_get_contents($file))."\n\n" : "# Managed by rh (phattarachai/remote-prompt). Hosts here are plain ssh config — edit freely.\n\n";

        file_put_contents($file, $existing.implode("\n", $lines)."\n");
        chmod($file, 0600);

        $this->hosts = null;
    }

    public function included(): bool
    {
        $config = $this->dir.'/config';

        return is_file($config) && preg_match('/^\s*Include\s+.*config\.d\/(rh\.conf|\*)/mi', (string) file_get_contents($config)) === 1;
    }

    /**
     * Include must come before the first Host block to apply globally, so it goes on top.
     */
    public function addInclude(): void
    {
        $config = $this->dir.'/config';
        $current = is_file($config) ? (string) file_get_contents($config) : '';

        if ($current !== '') {
            copy($config, $config.'.rh-backup');
        }

        file_put_contents($config, self::INCLUDE_LINE."\n\n".$current);
        chmod($config, 0600);

        $this->hosts = null;
    }

    /**
     * @param  list<string>  $seen
     * @return list<SshHost>
     */
    private function parse(string $file, array $seen): array
    {
        if (! is_file($file) || in_array(realpath($file), $seen, true)) {
            return [];
        }

        $seen[] = (string) realpath($file);
        $hosts = [];
        $block = null;

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! preg_match('/^\s*(\w+)\s*(?:=\s*|\s+)(.+?)\s*$/', $line, $match) || str_starts_with(trim($line), '#')) {
                continue;
            }

            $key = strtolower($match[1]);
            $value = trim($match[2], '"');

            if ($key === 'include') {
                foreach (preg_split('/\s+/', $value) ?: [] as $pattern) {
                    $pattern = Home::expand($pattern);
                    $pattern = str_starts_with($pattern, '/') ? $pattern : $this->dir.'/'.$pattern;

                    foreach (glob($pattern) ?: [] as $included) {
                        array_push($hosts, ...$this->parse($included, $seen));
                    }
                }

                continue;
            }

            if ($key === 'host' || $key === 'match') {
                $hosts = $this->push($hosts, $block);
                $aliases = array_values(array_filter(preg_split('/\s+/', $value) ?: [], fn (string $alias) => ! preg_match('/[*?!]/', $alias)));
                $block = $key === 'host' && $aliases !== [] ? ['aliases' => $aliases] : null;

                continue;
            }

            if ($block !== null && in_array($key, ['hostname', 'user', 'port', 'identityfile', 'proxycommand'], true)) {
                $block[$key] ??= $value;
            }
        }

        return $this->push($hosts, $block);
    }

    /**
     * @param  list<SshHost>  $hosts
     * @param  array<string, mixed>|null  $block
     * @return list<SshHost>
     */
    private function push(array $hosts, ?array $block): array
    {
        if ($block === null) {
            return $hosts;
        }

        $hosts[] = new SshHost(
            $block['aliases'],
            $block['hostname'] ?? null,
            $block['user'] ?? null,
            $block['port'] ?? null,
            $block['identityfile'] ?? null,
            $block['proxycommand'] ?? null,
        );

        return $hosts;
    }
}
