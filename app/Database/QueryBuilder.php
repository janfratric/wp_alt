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
