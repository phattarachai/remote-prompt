<?php

namespace Phattarachai\RemotePrompt;

use RuntimeException;

/**
 * ~/.config/rh/config.json — projects keyed by local path (or glob), each with its targets.
 * Connection details stay in ~/.ssh/config; a target only names the ssh alias.
 */
final class Config
{
    /**
     * @var array<string, list<Target>>
     */
    private array $projects = [];

    public function __construct(private readonly string $file)
    {
        $this->reload();
    }

    public function reload(): void
    {
        $this->projects = [];

        if (! is_file($this->file)) {
            return;
        }

        $data = json_decode((string) file_get_contents($this->file), true);

        if (! is_array($data)) {
            throw new RuntimeException("{$this->file} is not valid JSON.");
        }

        foreach ($data['projects'] ?? [] as $key => $project) {
            $this->projects[(string) $key] = array_map(Target::fromArray(...), array_values($project['targets'] ?? []));
        }
    }

    public static function load(): self
    {
        return new self(Home::configFile());
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * @return array<string, list<Target>>
     */
    public function projects(): array
    {
        return $this->projects;
    }

    /**
     * @param  list<Target>  $targets
     */
    public function setProject(string $key, array $targets): void
    {
        if ($targets === []) {
            unset($this->projects[$key]);

            return;
        }

        $this->projects[$key] = $targets;
    }

    public function addTarget(string $key, Target $target): void
    {
        $targets = array_values(array_filter($this->projects[$key] ?? [], fn (Target $existing) => strcasecmp($existing->name, $target->name) !== 0));
        $targets[] = $target;

        $this->projects[$key] = $targets;
    }

    public function removeTarget(string $key, string $name): void
    {
        $this->setProject($key, array_values(array_filter($this->projects[$key] ?? [], fn (Target $target) => strcasecmp($target->name, $name) !== 0)));
    }

    public function save(): void
    {
        $dir = dirname($this->file);

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        ksort($this->projects);

        $data = ['projects' => array_map(fn (array $targets) => ['targets' => array_map(fn (Target $target) => $target->toArray(), $targets)], $this->projects)];

        file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    /**
     * The deepest project whose key matches the folder or one of its parents. A git worktree
     * that matches nothing falls back to its main checkout, so worktrees inherit the targets.
     */
    public function bind(string $cwd): ?Binding
    {
        $binding = $this->match($cwd);

        if ($binding !== null) {
            return $binding;
        }

        [$top, $main] = Git::worktreeRoots($cwd);

        if ($top === null || $main === null || $top === $main) {
            return null;
        }

        $mainBinding = $this->match($main);

        return $mainBinding === null ? null : new Binding($mainBinding->key, $top, $mainBinding->targets);
    }

    private function match(string $dir): ?Binding
    {
        $projects = $this->projects;
        uksort($projects, fn (string $a, string $b) => [(bool) preg_match('/[*?\[]/', $a), $a] <=> [(bool) preg_match('/[*?\[]/', $b), $b]);

        for ($current = rtrim($dir, '/') ?: '/'; ; $current = dirname($current)) {
            foreach ($projects as $key => $targets) {
                if (fnmatch(rtrim(Home::expand($key), '/'), $current, FNM_PATHNAME)) {
                    return new Binding($key, $current, $targets);
                }
            }

            if ($current === '/' || $current === '.') {
                return null;
            }
        }
    }
}
