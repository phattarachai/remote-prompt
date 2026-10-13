<?php

namespace Phattarachai\RemotePrompt;

final readonly class RemoteEntry
{
    public function __construct(
        public string $name,
        public bool $isDir,
        public ?int $size = null,
        public string $modified = '',
    ) {}

    public static function parse(string $line): ?self
    {
        $fields = explode("\t", $line, 4);

        if (count($fields) !== 4 || $fields[3] === '' || $fields[3] === '.') {
            return null;
        }

        [$type, $size, $modified, $name] = $fields;

        return new self($name, $type === 'd', is_numeric($size) ? (int) $size : null, $modified);
    }

    public function label(): string
    {
        if ($this->isDir) {
            return "{$this->name}/";
        }

        return rtrim(sprintf('%s   %s   %s', $this->name, self::size($this->size), $this->modified));
    }

    public static function size(?int $bytes): string
    {
        if ($bytes === null) {
            return '';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return $power === 0 ? "{$bytes} B" : sprintf('%.1f %s', $bytes / 1024 ** $power, $units[$power]);
    }
}
