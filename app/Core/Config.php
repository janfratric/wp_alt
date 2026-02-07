<?php declare(strict_types=1);

namespace App\Core;

class Config
{
    private static ?array $config = null;

    private static function load(): void
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../../config/app.php';
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$config[$key] ?? $default;
    }

    public static function getString(string $key, string $default = ''): string
    {
        return (string) self::get($key, $default);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    public static function all(): array
    {
        self::load();
        return self::$config;
    }
}
