<?php

namespace Phattarachai\RemotePrompt;

/**
 * A private (0700) folder for files pulled off a server — an .env being edited, the remote side of a diff —
 * removed when rh exits.
 */
final class Temp
{
    private static ?string $dir = null;

    public static function file(string $name): string
    {
        $file = self::dir().'/'.bin2hex(random_bytes(4)).'-'.basename($name);
        touch($file);
        chmod($file, 0600);

        return $file;
    }

    public static function dir(): string
    {
        if (self::$dir !== null) {
            return self::$dir;
        }

        $dir = rtrim(sys_get_temp_dir(), '/').'/rh-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        register_shutdown_function(fn () => self::clean());

        return self::$dir = $dir;
    }

    public static function keep(string $file): string
    {
        $kept = Home::path('.rh-kept-'.basename($file));
        rename($file, $kept);

        return $kept;
    }

    public static function clean(): void
    {
        if (self::$dir === null || ! is_dir(self::$dir)) {
            return;
        }

        foreach (glob(self::$dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir(self::$dir);
        self::$dir = null;
    }
}
