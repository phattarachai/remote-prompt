<?php

namespace Phattarachai\RemotePrompt;

/**
 * The project a folder belongs to: its local root and where it is deployed.
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
    ) {}

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
