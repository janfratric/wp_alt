<?php declare(strict_types=1);

namespace App\Database;

use App\Core\Config;
use PDO;
use RuntimeException;

class Migrator
{
    private PDO $pdo;
    private string $driver;
    private string $migrationsPath;

    public function __construct(?PDO $pdo = null, ?string $migrationsPath = null)
    {
        $this->pdo = $pdo ?? Connection::getInstance();
        $this->driver = Connection::getDriver();
        $this->migrationsPath = $migrationsPath ?? dirname(__DIR__, 2) . '/migrations';
    }

    /**
     * Run all pending migrations.
     * Returns an array of applied migration filenames.
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->getAppliedMigrations();
        $available = $this->getAvailableMigrations();
        $pending = array_diff($available, $applied);

        // Sort by filename to ensure order
        sort($pending);

        $newlyApplied = [];

        foreach ($pending as $migration) {
            $this->applyMigration($migration);
            $newlyApplied[] = $migration;
        }

        return $newlyApplied;
    }

    /**
     * Create the _migrations tracking table if it doesn't exist.
     */
    private function ensureMigrationsTable(): void
    {
        $sql = match ($this->driver) {
            'sqlite' => '
                CREATE TABLE IF NOT EXISTS _migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ',
            'pgsql' => '
                CREATE TABLE IF NOT EXISTS _migrations (
                    id SERIAL PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ',
            'mysql' => '
                CREATE TABLE IF NOT EXISTS _migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ',
            default => throw new RuntimeException("Unsupported driver: {$this->driver}"),
        };

        $this->pdo->exec($sql);
    }

    /**
     * Get list of already-applied migration filenames.
     */
    private function getAppliedMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT migration FROM _migrations ORDER BY id');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Scan the migrations directory for files matching the current driver.
     * Expected format: NNN_name.{driver}.sql (e.g., 001_initial.sqlite.sql)
     */
    private function getAvailableMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $driverSuffix = $this->getDriverSuffix();
        $files = [];

        foreach (scandir($this->migrationsPath) as $file) {
            if (str_ends_with($file, ".{$driverSuffix}.sql")) {
                $files[] = $file;
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Apply a single migration file inside a transaction.
     */
    private function applyMigration(string $filename): void
    {
        $path = $this->migrationsPath . '/' . $filename;

        if (!file_exists($path)) {
            throw new RuntimeException("Migration file not found: {$path}");
        }

        $sql = file_get_contents($path);

        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Migration file is empty or unreadable: {$path}");
        }

        // SQLite doesn't support transactional DDL the same way,
        // but wrapping in a transaction still helps with consistency
        $this->pdo->beginTransaction();

        try {
            // Execute the migration SQL (may contain multiple statements)
            $this->pdo->exec($sql);

            // Record the migration as applied
            $stmt = $this->pdo->prepare(
                'INSERT INTO _migrations (migration) VALUES (:migration)'
            );
            $stmt->execute([':migration' => $filename]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException(
                "Migration failed: {$filename} — {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Map config driver name to migration file suffix.
     */
    private function getDriverSuffix(): string
    {
        return match ($this->driver) {
            'sqlite' => 'sqlite',
            'pgsql'  => 'pgsql',
            'mysql'  => 'mysql',
            default  => throw new RuntimeException("Unsupported driver: {$this->driver}"),
        };
    }

    /**
     * Get the list of applied migrations (public accessor for testing).
     */
    public function getApplied(): array
    {
        $this->ensureMigrationsTable();
        return $this->getAppliedMigrations();
    }

    /**
     * Check if a specific migration has been applied.
     */
    public function hasBeenApplied(string $migration): bool
    {
        $this->ensureMigrationsTable();
        $applied = $this->getAppliedMigrations();
        return in_array($migration, $applied, true);
    }
}
