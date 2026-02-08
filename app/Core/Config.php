<?php declare(strict_types=1);

namespace App\Core;

use App\Database\Connection;

class Config
{
    private static ?array $config = null;
    private static ?array $dbSettings = null;

    /** Keys that must always come from the file config (not DB). */
    private static array $protectedKeys = [
        'db_driver', 'db_path', 'db_host', 'db_port',
        'db_name', 'db_user', 'db_pass', 'app_secret',
    ];

    private static function load(): void
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../../config/app.php';
        }
    }

    /**
     * Load settings from the database to override file config.
     * Called once during App bootstrap, after DB connection is available.
     * Silently skips if DB is not ready (e.g., first run before migrations).
     */
    public static function loadDbSettings(): void
    {
        if (self::$dbSettings !== null) {
            return;
        }

        try {
            $pdo = Connection::getInstance();
            $stmt = $pdo->query('SELECT key, value FROM settings');
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            self::$dbSettings = [];
            foreach ($rows as $row) {
                self::$dbSettings[$row['key']] = $row['value'];
            }
        } catch (\Throwable $e) {
            // DB not ready (no table, no connection, etc.) — skip silently
            self::$dbSettings = [];
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        // DB settings override file config (except protected keys)
        if (self::$dbSettings !== null
            && !in_array($key, self::$protectedKeys, true)
            && array_key_exists($key, self::$dbSettings)) {
            return self::$dbSettings[$key];
        }

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
        $merged = self::$config;

        if (self::$dbSettings !== null) {
            foreach (self::$dbSettings as $key => $value) {
                if (!in_array($key, self::$protectedKeys, true)) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * Reset cached state. Used primarily in tests.
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$dbSettings = null;
    }
}
