<?php declare(strict_types=1);

namespace App\Database;

use App\Core\Config;
use PDO;
use RuntimeException;

class Connection
{
    private static ?PDO $instance = null;

    /**
     * Get the singleton PDO connection.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }

    /**
     * Create a new PDO connection based on the configured driver.
     */
    private static function createConnection(): PDO
    {
        $driver = Config::getString('db_driver', 'sqlite');

        $pdo = match ($driver) {
            'sqlite' => self::connectSqlite(),
            'pgsql'  => self::connectPgsql(),
            'mysql'  => self::connectMysql(),
            default  => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };

        // Common PDO settings
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        return $pdo;
    }

    /**
     * Connect to SQLite. Auto-creates the database file if it doesn't exist.
     */
    private static function connectSqlite(): PDO
    {
        $path = Config::getString('db_path');

        // Resolve relative paths against project root
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:/', $path)) {
            $path = dirname(__DIR__, 2) . '/' . $path;
        }

        // Ensure the directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdo = new PDO('sqlite:' . $path);

        // Enable WAL mode for better concurrent read performance
        $pdo->exec('PRAGMA journal_mode=WAL');
        // Enable foreign keys (off by default in SQLite)
        $pdo->exec('PRAGMA foreign_keys=ON');

        return $pdo;
    }

    /**
     * Connect to PostgreSQL.
     */
    private static function connectPgsql(): PDO
    {
        $host = Config::getString('db_host', '127.0.0.1');
        $port = Config::getInt('db_port', 5432);
        $name = Config::getString('db_name', 'litecms');
        $user = Config::getString('db_user', 'root');
        $pass = Config::getString('db_pass', '');

        $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
        $pdo = new PDO($dsn, $user, $pass);

        // Ensure UTF-8
        $pdo->exec("SET NAMES 'UTF8'");
        $pdo->exec("SET client_encoding TO 'UTF8'");

        return $pdo;
    }

    /**
     * Connect to MariaDB/MySQL.
     */
    private static function connectMysql(): PDO
    {
        $host = Config::getString('db_host', '127.0.0.1');
        $port = Config::getInt('db_port', 3306);
        $name = Config::getString('db_name', 'litecms');
        $user = Config::getString('db_user', 'root');
        $pass = Config::getString('db_pass', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass);

        // Ensure UTF-8
        $pdo->exec("SET NAMES utf8mb4");

        return $pdo;
    }

    /**
     * Get the current driver name ('sqlite', 'pgsql', or 'mysql').
     */
    public static function getDriver(): string
    {
        return Config::getString('db_driver', 'sqlite');
    }

    /**
     * Reset the singleton (used in testing or when switching databases).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
