<?php

namespace Phattarachai\RemotePrompt;

/**
 * One place a project is deployed: an ssh alias and the absolute path on that host.
 */
final readonly class Target
{
    public const array ENVIRONMENTS = ['production', 'uat', 'qas', 'staging', 'dev'];

    public function __construct(
        public string $name,
        public string $host,
        public string $path,
        public string $env = 'production',
    ) {}

    /**
     * @param  array{name?: string, host: string, path: string, env?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'] ?? $data['host'], $data['host'], self::normalize($data['path']), $data['env'] ?? 'production');
    }

    /**
     * @return array{name: string, host: string, path: string, env: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'host' => $this->host, 'path' => $this->path, 'env' => $this->env];
    }

    public function isProduction(): bool
    {
        return $this->env === 'production';
    }

    public function resolve(string $path): string
    {
        return self::normalize(str_starts_with($path, '/') || $this->path === '.' ? $path : $this->path.'/'.$path);
    }

    public function label(): string
    {
        $env = $this->isProduction() ? "<fg=red>{$this->env}</>" : "<fg=gray>{$this->env}</>";

        return "{$this->name}  <fg=gray>{$this->host}:{$this->path}</>  {$env}";
    }

    public function plainLabel(int $nameWidth = 0, int $whereWidth = 0): string
    {
        return sprintf("%-{$nameWidth}s   %-{$whereWidth}s   %s", $this->name, "{$this->host}:{$this->path}", $this->isProduction() ? '● production' : $this->env);
    }

    /**
     * Menu labels for several targets, in aligned columns.
     *
     * @param  list<Target>  $targets
     * @return list<string>
     */
    public static function labels(array $targets): array
    {
        $nameWidth = max(array_map(fn (self $target) => mb_strwidth($target->name), $targets) ?: [0]);
        $whereWidth = max(array_map(fn (self $target) => mb_strwidth("{$target->host}:{$target->path}"), $targets) ?: [0]);

        return array_map(fn (self $target) => $target->plainLabel($nameWidth, $whereWidth), $targets);
    }

    public static function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            match ($part) {
                '', '.' => null,
                '..' => array_pop($parts),
                default => $parts[] = $part,
            };
        }

        $normalized = (str_starts_with($path, '/') ? '/' : '').implode('/', $parts);

        return $normalized === '' ? '.' : $normalized;
    }
}
