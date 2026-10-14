<?php

namespace Phattarachai\RemotePrompt;

/**
 * The project a folder belongs to: its local root and where it is deployed — the targets of its .rh.json first,
 * then any this machine adds under the same project in ~/.config/rh.
 */
final readonly class Binding
{
    /**
     * @param  list<Target>  $targets
     */
    public function __construct(
        public string $key,
        public string $root,
        public array $targets,
        public ?ProjectFile $file = null,
    ) {}

    /**
     * Whether a target comes from the project's .rh.json (shared) rather than this machine's config.
     */
    public function shared(Target $target): bool
    {
        return $this->file?->find($target->name) !== null;
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

    /**
     * The remote path of a local file, or null when the file is outside the project.
     */
    public function remotePath(Target $target, string $localPath): ?string
    {
        $root = rtrim($this->root, '/');

        if ($localPath !== $root && ! str_starts_with($localPath, $root.'/')) {
            return null;
        }

        return $target->resolve(ltrim(substr($localPath, strlen($root)), '/'));
    }
}
