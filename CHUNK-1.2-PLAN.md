# Chunk 1.2 — Database Layer & Migrations
## Detailed Implementation Plan

---

## Overview

This chunk builds the database foundation for LiteCMS: a PDO connection factory supporting three drivers (SQLite, PostgreSQL, MariaDB) selected via a single config value, a fluent query builder that abstracts SQL dialect differences, and a migration system that tracks and applies numbered SQL files. The initial migration creates all 7 schema tables. At completion, the app can connect to any of the three databases, run migrations idempotently, and perform CRUD operations via the query builder.

**Depends on**: Chunk 1.1 (Config system, App container, Request/Response, Router)

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it (and Chunk 1.1 classes).

---

### 1. `app/Database/Connection.php`

**Purpose**: PDO connection factory. Reads the database driver from config and creates the appropriate PDO connection with correct DSN, UTF-8 encoding, and error mode settings.

```php
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
```

**Notes**:
- Singleton pattern ensures one connection per request (lightweight CMS, no connection pooling needed).
- SQLite enables WAL mode for performance and foreign keys (disabled by default).
- PostgreSQL default port is 5432, but the config defaults to 3306 (MySQL default) — the `connectPgsql()` method uses 5432 as its internal default.
- The `reset()` method exists for testing scenarios where a fresh connection is needed.
- Relative paths for SQLite are resolved against the project root directory.

---

### 2. `app/Database/QueryBuilder.php`

**Purpose**: Fluent query builder that constructs parameterized SQL compatible with all three database drivers. Abstracts auto-increment, boolean, and datetime syntax differences.

```php
<?php declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOStatement;

class QueryBuilder
{
    private PDO $pdo;
    private string $driver;

    // Query state
    private string $type = '';           // 'select', 'insert', 'update', 'delete'
    private string $table = '';
    private array $columns = ['*'];      // SELECT columns
    private array $wheres = [];          // WHERE clauses [{sql, params}, ...]
    private array $orderBys = [];        // ORDER BY clauses ['column ASC', ...]
    private ?int $limitVal = null;
    private ?int $offsetVal = null;
    private array $joins = [];           // JOIN clauses [full SQL string, ...]
    private array $insertData = [];      // Key => value for INSERT
    private array $updateData = [];      // Key => value for UPDATE
    private array $params = [];          // Accumulated bound parameters

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::getInstance();
        $this->driver = Connection::getDriver();
    }

    /**
     * Start a SELECT query.
     */
    public function select(string ...$columns): self
    {
        $this->type = 'select';
        if (!empty($columns)) {
            $this->columns = $columns;
        }
        return $this;
    }

    /**
     * Set the table to query.
     */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Alias: set the table via from().
     */
    public function from(string $table): self
    {
        return $this->table($table);
    }

    /**
     * Add a WHERE clause.
     * Supports: where('id', 5), where('id', '=', 5), where('status', 'IN', ['a','b'])
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if ($value === null) {
            // Two-argument form: where('id', 5) — implies '='
            $value = $operatorOrValue;
            $operator = '=';
        } else {
            $operator = strtoupper((string) $operatorOrValue);
        }

        $placeholder = ':w_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column) . '_' . count($this->wheres);

        if ($operator === 'IN' && is_array($value)) {
            $inPlaceholders = [];
            foreach ($value as $i => $v) {
                $ph = $placeholder . '_' . $i;
                $inPlaceholders[] = $ph;
                $this->params[$ph] = $v;
            }
            $this->wheres[] = $column . ' IN (' . implode(', ', $inPlaceholders) . ')';
        } elseif ($operator === 'IS' && $value === null) {
            $this->wheres[] = $column . ' IS NULL';
        } elseif ($operator === 'IS NOT' && $value === null) {
            $this->wheres[] = $column . ' IS NOT NULL';
        } else {
            $this->wheres[] = "{$column} {$operator} {$placeholder}";
            $this->params[$placeholder] = $value;
        }

        return $this;
    }

    /**
     * Add a raw WHERE clause with manual parameter binding.
     */
    public function whereRaw(string $sql, array $params = []): self
    {
        $this->wheres[] = $sql;
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    /**
     * Add an ORDER BY clause.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBys[] = "{$column} {$direction}";
        return $this;
    }

    /**
     * Set LIMIT.
     */
    public function limit(int $limit): self
    {
        $this->limitVal = $limit;
        return $this;
    }

    /**
     * Set OFFSET.
     */
    public function offset(int $offset): self
    {
        $this->offsetVal = $offset;
        return $this;
    }

    /**
     * Add a JOIN clause.
     */
    public function join(string $table, string $col1, string $operator, string $col2, string $type = 'INNER'): self
    {
        $type = strtoupper($type);
        $this->joins[] = "{$type} JOIN {$table} ON {$col1} {$operator} {$col2}";
        return $this;
    }

    /**
     * Add a LEFT JOIN clause.
     */
    public function leftJoin(string $table, string $col1, string $operator, string $col2): self
    {
        return $this->join($table, $col1, $operator, $col2, 'LEFT');
    }

    /**
     * Insert a row. Returns the last insert ID.
     */
    public function insert(array $data): string
    {
        $this->type = 'insert';
        $this->insertData = $data;

        $sql = $this->buildInsert();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);

        return $this->pdo->lastInsertId();
    }

    /**
     * Update rows matching the WHERE clauses. Returns affected row count.
     */
    public function update(array $data): int
    {
        $this->type = 'update';
        $this->updateData = $data;

        $sql = $this->buildUpdate();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);

        return $stmt->rowCount();
    }

    /**
     * Delete rows matching the WHERE clauses. Returns affected row count.
     */
    public function delete(): int
    {
        $this->type = 'delete';

        $sql = $this->buildDelete();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);

        return $stmt->rowCount();
    }

    /**
     * Execute the SELECT and return all matching rows.
     */
    public function get(): array
    {
        $sql = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);

        return $stmt->fetchAll();
    }

    /**
     * Execute the SELECT and return the first matching row, or null.
     */
    public function first(): ?array
    {
        $this->limitVal = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * Execute the SELECT and return a count of matching rows.
     */
    public function count(): int
    {
        $originalColumns = $this->columns;
        $this->columns = ['COUNT(*) as aggregate'];
        $result = $this->first();
        $this->columns = $originalColumns;

        return (int) ($result['aggregate'] ?? 0);
    }

    // -----------------------------------------------------------------------
    // SQL Building — private methods
    // -----------------------------------------------------------------------

    private function buildSelect(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $this->table;

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if (!empty($this->orderBys)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBys);
        }

        if ($this->limitVal !== null) {
            $sql .= ' LIMIT ' . $this->limitVal;
        }

        if ($this->offsetVal !== null) {
            $sql .= ' OFFSET ' . $this->offsetVal;
        }

        return $sql;
    }

    private function buildInsert(): string
    {
        $columns = array_keys($this->insertData);
        $placeholders = [];

        foreach ($this->insertData as $key => $value) {
            $placeholder = ':i_' . $key;
            $placeholders[] = $placeholder;
            $this->params[$placeholder] = $value;
        }

        return 'INSERT INTO ' . $this->table
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';
    }

    private function buildUpdate(): string
    {
        $sets = [];
        foreach ($this->updateData as $key => $value) {
            $placeholder = ':u_' . $key;
            $sets[] = "{$key} = {$placeholder}";
            $this->params[$placeholder] = $value;
        }

        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets);

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        return $sql;
    }

    private function buildDelete(): string
    {
        $sql = 'DELETE FROM ' . $this->table;

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        return $sql;
    }

    // -----------------------------------------------------------------------
    // Static convenience: create a new builder for a table
    // -----------------------------------------------------------------------

    /**
     * Start a new query builder for the given table.
     */
    public static function query(string $table): self
    {
        $qb = new self();
        $qb->table($table);
        return $qb;
    }

    /**
     * Execute a raw SQL query with parameters.
     * Returns the PDOStatement for custom fetching.
     */
    public static function raw(string $sql, array $params = []): PDOStatement
    {
        $pdo = Connection::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
```

**Notes**:
- Every query uses parameterized placeholders — SQL injection is impossible by design.
- The builder is stateful and single-use: each `QueryBuilder` instance builds one query. The static `query()` factory creates fresh instances.
- `where()` supports both 2-argument (`where('id', 5)`) and 3-argument (`where('id', '>', 5)`) forms.
- `IN` clauses expand into individual placeholders (PDO doesn't support binding arrays directly).
- `raw()` is available for complex queries that the builder can't express, but still uses parameterized binding.
- Column name quoting is intentionally omitted — column names are hardcoded in our codebase (not user-supplied), and quoting differs across drivers. If needed, it can be added in a future chunk.
- LIMIT/OFFSET syntax is the same across all three supported drivers.

**Dialect Differences Handled**:
The QueryBuilder itself uses standard SQL syntax that works across all three drivers. The dialect differences (autoincrement, booleans, datetime types) are handled in the **migration SQL files**, not in the query builder. This is by design — the query builder produces runtime queries (INSERT/SELECT/UPDATE/DELETE) which use compatible syntax, while schema DDL (CREATE TABLE) is driver-specific and lives in migration files.

| Concern | SQLite | PostgreSQL | MariaDB |
|---------|--------|------------|---------|
| Auto-increment PK | `INTEGER PRIMARY KEY AUTOINCREMENT` | `SERIAL PRIMARY KEY` | `INT AUTO_INCREMENT PRIMARY KEY` |
| Boolean | `INTEGER` (0/1) | `BOOLEAN` | `TINYINT(1)` |
| Timestamp default | `DATETIME DEFAULT CURRENT_TIMESTAMP` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |
| Text type | `TEXT` | `TEXT` | `TEXT` |
| VARCHAR | `VARCHAR(N)` | `VARCHAR(N)` | `VARCHAR(N)` |

---

### 3. `app/Database/Migrator.php`

**Purpose**: Reads numbered SQL migration files from `migrations/`, tracks which migrations have been applied in a `_migrations` table, and applies new migrations in order. Idempotent — running twice applies nothing the second time.

```php
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
```

**Notes**:
- The `_migrations` table uses the prefix underscore to avoid collision with application tables.
- Migrations are applied inside a transaction for atomicity. On failure, the transaction is rolled back and the migration is not recorded as applied.
- The `getAvailableMigrations()` method scans for files matching `*.{driver}.sql` — this means `001_initial.sqlite.sql` is only used when `db_driver=sqlite`.
- The `migrate()` method returns the list of newly applied migrations, which is useful for logging/display.
- `exec()` is used instead of `prepare/execute` for DDL because migration files may contain multiple SQL statements separated by semicolons.

---

### 4. `migrations/001_initial.sqlite.sql`

**Purpose**: Initial schema migration for SQLite — creates all 7 application tables.

```sql
-- LiteCMS Initial Schema — SQLite
-- Migration: 001_initial
-- Creates all 7 core tables

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'editor' CHECK (role IN ('admin', 'editor')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Content table (pages, posts, custom types)
CREATE TABLE IF NOT EXISTS content (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type VARCHAR(50) NOT NULL DEFAULT 'page',
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    body TEXT NOT NULL DEFAULT '',
    excerpt TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived')),
    author_id INTEGER NOT NULL,
    template VARCHAR(100),
    sort_order INTEGER NOT NULL DEFAULT 0,
    meta_title VARCHAR(255),
    meta_description TEXT,
    featured_image VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME,
    FOREIGN KEY (author_id) REFERENCES users(id)
);

-- Content types (custom content type definitions)
CREATE TABLE IF NOT EXISTS content_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    fields_json TEXT NOT NULL DEFAULT '[]',
    has_archive INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Custom fields (key-value store per content item)
CREATE TABLE IF NOT EXISTS custom_fields (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_id INTEGER NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    field_value TEXT,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
);

-- Media uploads
CREATE TABLE IF NOT EXISTS media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    uploaded_by INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

-- Settings (key-value configuration store)
CREATE TABLE IF NOT EXISTS settings (
    key VARCHAR(100) PRIMARY KEY,
    value TEXT
);

-- AI Conversations (chat history per content item)
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    content_id INTEGER,
    messages_json TEXT NOT NULL DEFAULT '[]',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (content_id) REFERENCES content(id)
);

-- Indexes for common queries
CREATE INDEX IF NOT EXISTS idx_content_slug ON content(slug);
CREATE INDEX IF NOT EXISTS idx_content_type ON content(type);
CREATE INDEX IF NOT EXISTS idx_content_status ON content(status);
CREATE INDEX IF NOT EXISTS idx_content_author ON content(author_id);
CREATE INDEX IF NOT EXISTS idx_content_published_at ON content(published_at);
CREATE INDEX IF NOT EXISTS idx_custom_fields_content ON custom_fields(content_id);
CREATE INDEX IF NOT EXISTS idx_media_uploaded_by ON media(uploaded_by);
CREATE INDEX IF NOT EXISTS idx_ai_conversations_user ON ai_conversations(user_id);
CREATE INDEX IF NOT EXISTS idx_ai_conversations_content ON ai_conversations(content_id);
```

**SQLite-specific notes**:
- `INTEGER PRIMARY KEY AUTOINCREMENT` is the correct SQLite syntax (not `INT`).
- Booleans are stored as `INTEGER` (0/1) — SQLite has no native boolean type.
- `DATETIME` is stored as text in ISO 8601 format — SQLite has no native datetime type but provides date/time functions.
- `IF NOT EXISTS` is used on all CREATE statements for safety, though the migration system prevents double-application.
- `FOREIGN KEY` constraints require `PRAGMA foreign_keys=ON` (set in Connection.php).

---

### 5. `migrations/001_initial.pgsql.sql`

**Purpose**: Initial schema migration for PostgreSQL — creates all 7 application tables.

```sql
-- LiteCMS Initial Schema — PostgreSQL
-- Migration: 001_initial
-- Creates all 7 core tables

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'editor' CHECK (role IN ('admin', 'editor')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Content table (pages, posts, custom types)
CREATE TABLE IF NOT EXISTS content (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL DEFAULT 'page',
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    body TEXT NOT NULL DEFAULT '',
    excerpt TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived')),
    author_id INTEGER NOT NULL REFERENCES users(id),
    template VARCHAR(100),
    sort_order INTEGER NOT NULL DEFAULT 0,
    meta_title VARCHAR(255),
    meta_description TEXT,
    featured_image VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMP
);

-- Content types (custom content type definitions)
CREATE TABLE IF NOT EXISTS content_types (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    fields_json TEXT NOT NULL DEFAULT '[]',
    has_archive BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Custom fields (key-value store per content item)
CREATE TABLE IF NOT EXISTS custom_fields (
    id SERIAL PRIMARY KEY,
    content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE,
    field_key VARCHAR(100) NOT NULL,
    field_value TEXT
);

-- Media uploads
CREATE TABLE IF NOT EXISTS media (
    id SERIAL PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    uploaded_by INTEGER NOT NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Settings (key-value configuration store)
CREATE TABLE IF NOT EXISTS settings (
    key VARCHAR(100) PRIMARY KEY,
    value TEXT
);

-- AI Conversations (chat history per content item)
CREATE TABLE IF NOT EXISTS ai_conversations (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    content_id INTEGER REFERENCES content(id),
    messages_json TEXT NOT NULL DEFAULT '[]',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for common queries
CREATE INDEX IF NOT EXISTS idx_content_slug ON content(slug);
CREATE INDEX IF NOT EXISTS idx_content_type ON content(type);
CREATE INDEX IF NOT EXISTS idx_content_status ON content(status);
CREATE INDEX IF NOT EXISTS idx_content_author ON content(author_id);
CREATE INDEX IF NOT EXISTS idx_content_published_at ON content(published_at);
CREATE INDEX IF NOT EXISTS idx_custom_fields_content ON custom_fields(content_id);
CREATE INDEX IF NOT EXISTS idx_media_uploaded_by ON media(uploaded_by);
CREATE INDEX IF NOT EXISTS idx_ai_conversations_user ON ai_conversations(user_id);
CREATE INDEX IF NOT EXISTS idx_ai_conversations_content ON ai_conversations(content_id);
```

**PostgreSQL-specific notes**:
- `SERIAL` is used for auto-incrementing primary keys (equivalent to `INTEGER + SEQUENCE + DEFAULT`).
- `BOOLEAN` is a native type in PostgreSQL with `TRUE`/`FALSE` literals.
- `TIMESTAMP` (without time zone) is used — all dates stored in UTC as per project spec.
- Foreign keys can be declared inline with `REFERENCES` syntax.
- `IF NOT EXISTS` on CREATE INDEX is supported in PostgreSQL 9.5+.

---

### 6. `migrations/001_initial.mysql.sql`

**Purpose**: Initial schema migration for MariaDB/MySQL — creates all 7 application tables.

```sql
-- LiteCMS Initial Schema — MariaDB/MySQL
-- Migration: 001_initial
-- Creates all 7 core tables

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'editor' CHECK (role IN ('admin', 'editor')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Content table (pages, posts, custom types)
CREATE TABLE IF NOT EXISTS content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL DEFAULT 'page',
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    body TEXT NOT NULL,
    excerpt TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived')),
    author_id INT NOT NULL,
    template VARCHAR(100),
    sort_order INT NOT NULL DEFAULT 0,
    meta_title VARCHAR(255),
    meta_description TEXT,
    featured_image VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Content types (custom content type definitions)
CREATE TABLE IF NOT EXISTS content_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    fields_json TEXT NOT NULL,
    has_archive TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom fields (key-value store per content item)
CREATE TABLE IF NOT EXISTS custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_id INT NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    field_value TEXT,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media uploads
CREATE TABLE IF NOT EXISTS media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT NOT NULL DEFAULT 0,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings (key-value configuration store)
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) PRIMARY KEY,
    value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Conversations (chat history per content item)
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content_id INT,
    messages_json TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (content_id) REFERENCES content(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for common queries
CREATE INDEX idx_content_slug ON content(slug);
CREATE INDEX idx_content_type ON content(type);
CREATE INDEX idx_content_status ON content(status);
CREATE INDEX idx_content_author ON content(author_id);
CREATE INDEX idx_content_published_at ON content(published_at);
CREATE INDEX idx_custom_fields_content ON custom_fields(content_id);
CREATE INDEX idx_media_uploaded_by ON media(uploaded_by);
CREATE INDEX idx_ai_conversations_user ON ai_conversations(user_id);
CREATE INDEX idx_ai_conversations_content ON ai_conversations(content_id);
```

**MariaDB/MySQL-specific notes**:
- `INT AUTO_INCREMENT PRIMARY KEY` is the standard MySQL auto-increment syntax.
- `TINYINT(1)` is used for boolean columns (MySQL convention).
- `ON UPDATE CURRENT_TIMESTAMP` is used for `updated_at` columns — MySQL/MariaDB can auto-update this.
- `ENGINE=InnoDB` ensures foreign key support and transactions.
- `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` ensures full Unicode support.
- `key` in the settings table is backtick-quoted because `key` is a MySQL reserved word.
- `published_at TIMESTAMP NULL DEFAULT NULL` — MySQL TIMESTAMP columns are NOT NULL by default, so explicit NULL is required.
- `IF NOT EXISTS` is NOT supported for `CREATE INDEX` in MySQL — these indexes will fail silently if the table already has them. Since migrations are idempotent (tracked), this scenario shouldn't arise.
- MariaDB 10.6+ does enforce `CHECK` constraints; older MySQL versions silently ignore them.

---

### 7. Modifications to `public/index.php`

**Purpose**: Add database initialization (connection + migrations) to the application bootstrap.

**Changes** (do not rewrite the file — add these lines after the `$app` is created):

```php
// --- Database bootstrap ---
// Run migrations on every request if needed (idempotent)
// This ensures the database is always up-to-date
use App\Database\Connection;
use App\Database\Migrator;

$db = Connection::getInstance();
$app->register('db', $db);

$migrator = new Migrator($db);
$migrator->migrate();
```

**Placement**: Insert after `$app = new App\Core\App();` and before route registration.

**Notes**:
- Migrations run on every request but are idempotent (already-applied migrations are skipped).
- The overhead of checking `_migrations` table is negligible (one `SELECT` query).
- The PDO connection is registered in the app container so controllers can access it.
- In production, you could add a flag to disable auto-migration and run it manually. For the lightweight CMS target (small business sites), auto-migration on request is acceptable and convenient.

The complete updated `public/index.php` should look like:

```php
<?php declare(strict_types=1);

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Database\Migrator;

// Bootstrap
$app = new App();
$request = new Request();

// --- Database bootstrap ---
$db = Connection::getInstance();
$app->register('db', $db);

$migrator = new Migrator($db);
$migrator->migrate();

// --- Register routes ---

$router = $app->router();

$router->get('/', function($request) use ($app) {
    return new Response(
        $app->template()->render('public/home', [
            'title' => Config::getString('site_name'),
        ])
    );
});

// Demo: admin group with placeholder
$router->group('/admin', function($router) use ($app) {
    $router->get('/dashboard', function($request) use ($app) {
        return new Response(
            $app->template()->render('admin/dashboard', [
                'title' => 'Dashboard',
            ])
        );
    });
});

// --- Register global middleware ---
// (Example: a simple timing/logging middleware for testing)

// --- Run ---
$app->run($request);
```

---

## Detailed Class Specifications

### `App\Database\Connection`

```
PROPERTIES:
  - private static ?PDO $instance = null

METHODS:
  - public static getInstance(): PDO
      Returns the singleton PDO instance. Creates it via createConnection()
      on first call.

  - private static createConnection(): PDO
      Reads 'db_driver' from Config. Calls the appropriate connect* method.
      Sets common PDO attributes:
        - ERRMODE_EXCEPTION (throw on errors)
        - FETCH_ASSOC (return associative arrays)
        - EMULATE_PREPARES = false (use real prepared statements)

  - private static connectSqlite(): PDO
      Reads 'db_path' from Config. Resolves relative paths against project root.
      Creates directory if needed. Connects via "sqlite:{path}".
      Enables: PRAGMA journal_mode=WAL, PRAGMA foreign_keys=ON.

  - private static connectPgsql(): PDO
      Reads host, port (default 5432), name, user, pass from Config.
      DSN: "pgsql:host={h};port={p};dbname={n}".
      Sets client_encoding to UTF8.

  - private static connectMysql(): PDO
      Reads host, port (default 3306), name, user, pass from Config.
      DSN: "mysql:host={h};port={p};dbname={n};charset=utf8mb4".
      Executes "SET NAMES utf8mb4".

  - public static getDriver(): string
      Returns Config::getString('db_driver', 'sqlite').

  - public static reset(): void
      Sets self::$instance = null. For testing only.
```

### `App\Database\QueryBuilder`

```
PROPERTIES:
  - private PDO $pdo
  - private string $driver
  - private string $type             // 'select', 'insert', 'update', 'delete'
  - private string $table
  - private array $columns = ['*']
  - private array $wheres = []       // SQL fragments
  - private array $orderBys = []
  - private ?int $limitVal = null
  - private ?int $offsetVal = null
  - private array $joins = []        // Full JOIN SQL strings
  - private array $insertData = []
  - private array $updateData = []
  - private array $params = []       // Named parameter bindings

CONSTRUCTOR:
  __construct(?PDO $pdo = null)
  Defaults to Connection::getInstance() if no PDO passed.
  Reads driver from Connection::getDriver().

PUBLIC QUERY METHODS:
  - select(string ...$columns): self
  - table(string $table): self
  - from(string $table): self                   — alias for table()
  - where(string $column, mixed $operatorOrValue, mixed $value = null): self
  - whereRaw(string $sql, array $params = []): self
  - orderBy(string $column, string $direction = 'ASC'): self
  - limit(int $limit): self
  - offset(int $offset): self
  - join(string $table, string $col1, string $operator, string $col2, string $type = 'INNER'): self
  - leftJoin(string $table, string $col1, string $operator, string $col2): self

PUBLIC EXECUTION METHODS:
  - insert(array $data): string                 — returns lastInsertId
  - update(array $data): int                    — returns affected rows
  - delete(): int                               — returns affected rows
  - get(): array                                — returns all rows
  - first(): ?array                             — returns first row or null
  - count(): int                                — returns COUNT(*) result

STATIC CONVENIENCE:
  - static query(string $table): self           — creates new builder for table
  - static raw(string $sql, array $params = []): PDOStatement

PRIVATE SQL BUILDERS:
  - buildSelect(): string
  - buildInsert(): string
  - buildUpdate(): string
  - buildDelete(): string
```

### `App\Database\Migrator`

```
PROPERTIES:
  - private PDO $pdo
  - private string $driver
  - private string $migrationsPath

CONSTRUCTOR:
  __construct(?PDO $pdo = null, ?string $migrationsPath = null)
  Defaults to Connection::getInstance() and project migrations/ dir.

PUBLIC METHODS:
  - migrate(): array
      Ensures _migrations table exists. Gets applied and available migrations.
      Applies pending ones in order. Returns array of newly applied filenames.

  - getApplied(): array
      Public accessor — returns list of applied migration filenames.

  - hasBeenApplied(string $migration): bool
      Returns true if the named migration has already been applied.

PRIVATE METHODS:
  - ensureMigrationsTable(): void
      Creates _migrations table if not exists (driver-specific DDL).

  - getAppliedMigrations(): array
      SELECT migration FROM _migrations ORDER BY id.

  - getAvailableMigrations(): array
      Scans migrations dir for files matching *.{driver_suffix}.sql.

  - applyMigration(string $filename): void
      Reads file, executes SQL in transaction, records in _migrations.

  - getDriverSuffix(): string
      Maps driver config to file suffix: sqlite/pgsql/mysql.
```

---

## Integration with Existing Code

### How the Database Layer Connects to Chunk 1.1 Classes

| Existing Class | Integration Point |
|---------------|-------------------|
| `Config` | `Connection` reads `db_driver`, `db_path`, `db_host`, `db_port`, `db_name`, `db_user`, `db_pass` from config |
| `App` | PDO instance is registered via `$app->register('db', $db)` so controllers can access it |
| `public/index.php` | Database bootstrap (connection + migration) added after app creation, before routes |
| `Router` | No changes — routes remain the same |
| `Request/Response` | No changes |
| `Middleware` | No changes |
| `TemplateEngine` | No changes |

### No Changes to Existing Classes

This chunk does NOT modify any existing Chunk 1.1 PHP class files. It only:
1. **Creates** 3 new PHP files in `app/Database/`
2. **Creates** 3 new SQL migration files in `migrations/`
3. **Modifies** `public/index.php` to add database bootstrap lines

---

## Acceptance Test Procedures

### Test 1: SQLite database auto-created and migrations run

```
1. Ensure db_driver=sqlite in config/app.php (default).
2. Delete storage/database.sqlite if it exists.
3. Run: php tests/chunk-1.2-verify.php
4. Verify: storage/database.sqlite is created.
5. Verify: All 7 tables exist (users, content, content_types, custom_fields,
   media, settings, ai_conversations) plus _migrations.
```

### Test 2: Query builder can INSERT and SELECT from users table

```
1. Use QueryBuilder to insert a row into users:
   $id = QueryBuilder::query('users')->insert([
       'username'      => 'testuser',
       'email'         => 'test@example.com',
       'password_hash' => 'hash123',
       'role'          => 'admin',
   ]);
2. Verify: $id is a non-empty string (the auto-generated ID).
3. Use QueryBuilder to select it back:
   $user = QueryBuilder::query('users')->select()->where('id', $id)->first();
4. Verify: $user['username'] === 'testuser'
5. Verify: $user['email'] === 'test@example.com'
6. Verify: $user['role'] === 'admin'
```

### Test 3: Query builder supports where(), orderBy(), limit() chains

```
1. Insert 3 test content rows with different statuses.
2. Query with where():
   $drafts = QueryBuilder::query('content')
       ->select()
       ->where('status', 'draft')
       ->get();
3. Verify: only draft rows returned.
4. Query with orderBy and limit:
   $recent = QueryBuilder::query('content')
       ->select()
       ->orderBy('created_at', 'DESC')
       ->limit(2)
       ->get();
5. Verify: exactly 2 rows returned, in descending order.
```

### Test 4: Query builder supports update() and delete()

```
1. Insert a test row.
2. Update it:
   QueryBuilder::query('users')
       ->where('id', $id)
       ->update(['email' => 'new@example.com']);
3. Select it back, verify email changed.
4. Delete it:
   QueryBuilder::query('users')
       ->where('id', $id)
       ->delete();
5. Select it back, verify null (not found).
```

### Test 5: Migrations are idempotent (running twice is safe)

```
1. Run $migrator->migrate() — capture returned list.
2. Run $migrator->migrate() again — capture returned list.
3. Verify: second call returns empty array (no new migrations applied).
4. Verify: _migrations table has exactly 1 row (001_initial).
```

### Test 6: QueryBuilder count() works

```
1. Insert 3 rows into content.
2. $count = QueryBuilder::query('content')->select()->count();
3. Verify: $count === 3.
```

### Test 7: All 7 tables exist with correct structure

```
1. For each table (users, content, content_types, custom_fields,
   media, settings, ai_conversations):
   - Query table metadata to verify existence.
   - For SQLite: SELECT name FROM sqlite_master WHERE type='table'.
2. Verify all 7 are present.
```

### Test 8: Connection driver detection works

```
1. Verify Connection::getDriver() returns 'sqlite' (default config).
2. Verify the PDO connection is valid and can execute a simple query.
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);`
- PSR-4 namespacing: `App\Database\Connection` → `app/Database/Connection.php`
- No `use` of any framework classes — only native PHP + PDO
- All classes are non-final (may be extended in tests or future chunks)
- Private properties by default, public only where needed

### Error Handling (Chunk 1.2 scope)
- Database connection failure → PDO throws `PDOException` (propagates up)
- Migration failure → `RuntimeException` with descriptive message, transaction rolled back
- Missing migration file → `RuntimeException`
- Unsupported driver → `RuntimeException`
- Query builder SQL errors → PDO throws `PDOException` (propagates up)
- No error page handling in this chunk — errors propagate to the caller

### Edge Cases

1. **SQLite file doesn't exist**: `Connection::connectSqlite()` auto-creates the directory and file. PDO creates the SQLite database file on first connect.

2. **Relative vs absolute db_path**: The Connection class resolves relative paths (e.g., `storage/database.sqlite`) against the project root. Absolute paths (e.g., `/var/data/litecms.db` or `C:\data\litecms.db`) are used as-is.

3. **Windows path detection**: The regex `preg_match('/^[A-Za-z]:/', $path)` detects Windows absolute paths like `C:\path`.

4. **MySQL reserved word `key`**: The settings table column `key` is a MySQL reserved word. The migration file backtick-quotes it in the MySQL variant. The QueryBuilder does not auto-quote column names — the migration DDL handles this. Runtime queries use parameterized values, not column names from user input.

5. **Multiple SQL statements in migration files**: The `exec()` method supports multiple semicolon-separated statements. This works in SQLite, PostgreSQL, and MySQL with `PDO::ATTR_EMULATE_PREPARES = false`.

6. **Transaction support for DDL**: SQLite and PostgreSQL support transactional DDL. MySQL does NOT — `CREATE TABLE` causes an implicit commit. The migration wrapper uses transactions anyway for consistency, but MySQL migrations are effectively non-transactional for DDL.

7. **`count()` method resets columns**: The `count()` method temporarily overrides `$this->columns` with `COUNT(*) as aggregate`, then restores the original — but since `count()` calls `first()` which executes the query, the builder is consumed. This is fine because QueryBuilder instances are single-use.

8. **`lastInsertId()` behavior**: SQLite and MySQL return the auto-generated ID as a string. PostgreSQL with `SERIAL` also returns it via `lastInsertId()`. All three behave consistently.

9. **Empty WHERE on UPDATE/DELETE**: The QueryBuilder allows `update()` and `delete()` without a `where()` clause — this updates/deletes all rows. This is intentional for administrative operations like "delete all cache entries". The caller is responsible for adding appropriate WHERE constraints.

10. **Boolean handling across drivers**: The app stores booleans as integers (0/1) in queries. SQLite and MySQL naturally use integers. PostgreSQL `BOOLEAN` columns accept integer values in PDO parameterized queries. No special handling is needed at the QueryBuilder level.

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `app/Database/Connection.php` | Class | Create |
| 2 | `app/Database/QueryBuilder.php` | Class | Create |
| 3 | `app/Database/Migrator.php` | Class | Create |
| 4 | `migrations/001_initial.sqlite.sql` | SQL | Create |
| 5 | `migrations/001_initial.pgsql.sql` | SQL | Create |
| 6 | `migrations/001_initial.mysql.sql` | SQL | Create |
| 7 | `public/index.php` | Entry point | Modify (add DB bootstrap) |

---

## Estimated Scope

- **PHP classes**: 3 (Connection, QueryBuilder, Migrator)
- **SQL migrations**: 3 (one per driver, each creating 7 tables)
- **Modified files**: 1 (public/index.php — ~8 new lines)
- **Approximate PHP LOC**: ~400-500 lines
- **Approximate SQL LOC**: ~300 lines (100 per driver)
