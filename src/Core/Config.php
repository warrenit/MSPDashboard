<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    public static function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function configDir(): string
    {
        return self::rootPath() . '/_config';
    }

    public static function configFile(): string
    {
        return self::configDir() . '/config.php';
    }

    public static function appKeyFile(): string
    {
        return self::configDir() . '/app.key';
    }

    public static function lockFile(): string
    {
        return self::configDir() . '/installed.lock';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    public static function load(): array
    {
        $file = self::configFile();
        if (!is_file($file)) {
            return [];
        }

        $config = require $file;
        return is_array($config) ? $config : [];
    }
}
