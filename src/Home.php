<?php

namespace Phattarachai\RemotePrompt;

final class Home
{
    public static function path(string $relative = ''): string
    {
        $home = rtrim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')), '/');

        return $relative === '' ? $home : $home.'/'.ltrim($relative, '/');
    }

    public static function expand(string $path): string
    {
        return str_starts_with($path, '~/') || $path === '~' ? self::path(substr($path, 2)) : $path;
    }

    public static function contract(string $path): string
    {
        $home = self::path();

        return $home !== '' && ($path === $home || str_starts_with($path, $home.'/')) ? '~'.substr($path, strlen($home)) : $path;
    }

    public static function configFile(): string
    {
        $base = getenv('XDG_CONFIG_HOME') ?: self::path('.config');

        return rtrim($base, '/').'/rh/config.json';
    }
}
