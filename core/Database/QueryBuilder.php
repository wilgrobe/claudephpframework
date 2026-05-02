<?php
// core/Database/QueryBuilder.php
namespace Core\Database;

/**
 * Chainable SELECT / UPDATE / DELETE / INSERT builder over Database.
 *
 * Preserves the Database wrapper's prepared-statement guarantee — values
 * are always bound, never interpolated. Identifiers (table + column names)
 * are whitelisted against [A-Za-z0-9_].
 *
 * Examples:
 *
 *   $db->table('users')
 *       ->where('is_active', 1)
 *       ->whereIn('role_id', [1, 2, 3])
 *       ->whereNotNull('email_verified_at')
 *       ->orderBy('created_at', 'desc')
 *       ->limit(10)
 *       ->get();
 *
 *   $db->table('users')->where('email', $email)->first();
 *   $db->table('users')->where('id', 42)->update(['name' => 'New']);
 *   $db->table('users')->where('id', 42)->delete();
 *   $db->table('users')->insert(['email' => '...', 'first_name' => '...']);
 *   $db->table('content_items')->where('status','published')->paginate(20);
 *
 * For SQL the builder can't express, drop to Raw() or fall back to
 * Database::query(...) directly.
 */
class QueryBuilder
{
    private Database $db;
    private string   $table;

    /** @var array<int, string|Raw> */
    private array $columns = ['*'];

    /**
     * Where predicates, each with an internal shape produced by addWhere().
     * We keep boolean ('AND'/'OR') on each node so the caller can mix where()
     * and orWhere() freely.
     * @var array<int, array<string, mixed>>
     */
    private array $wheres = [];

    /** @var array<int, string> */
    private array $orderBy = [];

    private ?int $limit  = null;
    private ?int $offset = null;

    public function __construct(Database $db, string $table)
    {
        $this->assertIdent($table);
        $this->db    = $db;
        $this->table = $table;
    }

    // ── SELECT column list ────────────────────────────────────────────────────

    /** Restrict columns returned by get()/first(). Accepts strings or Raw. */
    public function select(string|Raw ...$cols): self
    {
        foreach ($cols as $c) {
            if (is_string($c)) $this->assertIdent($c, allowStar: true);
        }
        $this->columns = $cols ?: ['*'];
        return $this;
    }

    // ── Where ────────────────────────────────────────────────────────────────

    /**
     * Add an AND where condition. Two forms:
     *   where('col', $value)            → col = $value
     *   where('col', '=|!=|<|>|<=|>=|LIKE', $value)
     */
    public function where(string $column, mixed $opOrValue, mixed $value = null): self
    {
        return $this->addWhere('AND', $column, $opOrValue, $value, func_num_args());
    }

    public function orWhere(string $column, mixed $opOrValue, mixed $value = null): self
    {
        return $this->addWhere('OR', $column, $opOrValue, $value, func_num_args());
    }

    /** `col IN (?, ?, ?)` — empty list short-circuits to `WHERE 0` so the query never returns rows. */
    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        $this->assertIdent($column);
        $this->wheres[] = ['type' => 'in', 'boolean' => $boolean, 'column' => $column, 'values' => array_values($values)];
        return $this;
    }

    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        $this->assertIdent($column);
        $this->wheres[] = ['type' => 'not_in', 'boolean' => $boolean, 'column' => $column, 'values' => array_values($values)];
        return $this;
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->assertIdent($column);
        $this->wheres[] = ['type' => 'null', 'boolean' => $boolean, 'column' => $column];
        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $this->assertIdent($column);
        $this->wheres[] = ['type' => 'not_null', 'boolean' => $boolean, 'column' => $column];
        return $this;
    }

    /**
     * Escape hatch. $sql is a WHERE-clause fragment with '?' placeholders;
     * $bindings supplies the values. Use when the builder doesn't express
     * what you need (full-text MATCH, subqueries, vendor-specific syntax).
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->wheres[] = ['type' => 'raw', 'boolean' => $boolean, 'sql' => $sql, 'bindings' => $bindings];
        return $this;
    }

    // ── Order / Limit / Offset ───────────────────────────────────────────────

    public function orderBy(string|Raw $column, string $direction = 'ASC'): self
    {
        $dir = strtoupper($direction);
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid order direction: [$direction]");
        }
        if ($column instanceof Raw) {
            $this->orderBy[] = $column->sql() . " $dir";
        } else {
            $this->assertIdent($column);
            $this->orderBy[] = "`$column` $dir";
        }
        return $this;
    }

    public function limit(int $n): self
    {
        if ($n < 0) throw new \InvalidArgumentException('limit must be >= 0');
        $this->limit = $n;
        return $this;
    }

    public function offset(int $n): self
    {
        if ($n < 0) throw new \InvalidArgumentException('offset must be >= 0');
        $this->offset = $n;
        return $this;
    }

    // ── Terminal read methods ────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();
        return $this->db->fetchAll($sql, $bindings);
    }

    /**
     * Returns the first row as an associative array, or null.
     *
     * Return type is `mixed` rather than `?array` so ModelQueryBuilder can
     * LSP-safely narrow to `?Model` in its override. Concrete result is
     * always array|null for plain QueryBuilder callers.
     *
     * @return array<string, mixed>|null
     */
    public function first(): mixed
    {
        $saved = $this->limit;
        $this->limit = 1;
        try {
            [$sql, $bindings] = $this->compileSelect();
            return $this->db->fetchOne($sql, $bindings);
        } finally {
            $this->limit = $saved;
        }
    }

    /** @return array<int, mixed> — just the one column, flat */
    public function pluck(string $column): array
    {
        $this->assertIdent($column);
        $this->select($column);
        $rows = $this->get();
        return array_map(fn($r) => $r[$column] ?? null, $rows);
    }

    public function count(string $column = '*'): int
    {
        // Swap SELECT for COUNT; preserve everything else including WHERE.
        $prevColumns = $this->columns;
        $this->columns = [new Raw($column === '*' ? 'COUNT(*)' : 'COUNT(`' . $column . '`)')];
        try {
            [$sql, $bindings] = $this->compileSelect(countMode: true);
            return (int) $this->db->fetchColumn($sql, $bindings);
        } finally {
            $this->columns = $prevColumns;
        }
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * @return array{items: array, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function paginate(int $perPage = 20, int $page = 1): array
    {
        [$sql, $bindings] = $this->compileSelect(paginateMode: true);
        return $this->db->paginate($sql, $bindings, $page, $perPage);
    }

    // ── Terminal write methods ───────────────────────────────────────────────

    /** Returns the new row's id. */
    public function insert(array $data): int
    {
        return $this->db->insert($this->table, $data);
    }

    /** Returns the number of rows updated. Must have at least one where(). */
    public function update(array $data): int
    {
        if (empty($this->wheres)) {
            throw new \RuntimeException('Refusing to UPDATE without a WHERE clause — use whereRaw("1") to intentionally update all rows.');
        }
        [$whereSql, $whereBindings] = $this->compileWheres();
        return $this->db->update($this->table, $data, $whereSql, $whereBindings);
    }

    /** Returns the number of rows deleted. Must have at least one where(). */
    public function delete(): int
    {
        if (empty($this->wheres)) {
            throw new \RuntimeException('Refusing to DELETE without a WHERE clause — use whereRaw("1") to intentionally delete all rows.');
        }
        [$whereSql, $whereBindings] = $this->compileWheres();
        return $this->db->delete($this->table, $whereSql, $whereBindings);
    }

    // ── Compilation ──────────────────────────────────────────────────────────

    /** @return array{0: string, 1: array} */
    private function compileSelect(bool $countMode = false, bool $paginateMode = false): array
    {
        $cols = [];
        foreach ($this->columns as $c) {
            if ($c instanceof Raw)            $cols[] = $c->sql();
            elseif ($c === '*')                $cols[] = '*';
            else                               $cols[] = "`$c`";
        }
        $sql = 'SELECT ' . implode(', ', $cols) . " FROM `$this->table`";

        $bindings = [];
        if (!empty($this->wheres)) {
            [$whereSql, $whereBindings] = $this->compileWheres();
            $sql      .= " WHERE $whereSql";
            $bindings = $whereBindings;
        }

        // Paginate does its own ORDER/LIMIT wrapping; skip ours.
        if (!$paginateMode) {
            if (!empty($this->orderBy)) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
            }
            if ($this->limit !== null && !$countMode) {
                $sql .= ' LIMIT ' . (int) $this->limit;
            }
            if ($this->offset !== null && !$countMode) {
                $sql .= ' OFFSET ' . (int) $this->offset;
            }
        }
        return [$sql, $bindings];
    }

    /** @return array{0: string, 1: array} */
    private function compileWheres(): array
    {
        $parts    = [];
        $bindings = [];
        foreach ($this->wheres as $i => $w) {
            // Booleans go between clauses — ignore on the first
            $prefix = $i === 0 ? '' : ' ' . $w['boolean'] . ' ';

            switch ($w['type']) {
                case 'basic':
                    $parts[] = $prefix . "`{$w['column']}` {$w['operator']} ?";
                    $bindings[] = $w['value'];
                    break;

                case 'in':
                case 'not_in':
                    if (empty($w['values'])) {
                        // Empty IN list always fails the 'in' case and always
                        // succeeds the 'not_in' case — preserve those
                        // semantics without emitting invalid SQL.
                        $parts[] = $prefix . ($w['type'] === 'in' ? '0' : '1');
                        break;
                    }
                    $ph = rtrim(str_repeat('?,', count($w['values'])), ',');
                    $op = $w['type'] === 'in' ? 'IN' : 'NOT IN';
                    $parts[] = $prefix . "`{$w['column']}` $op ($ph)";
                    foreach ($w['values'] as $v) $bindings[] = $v;
                    break;

                case 'null':
                    $parts[] = $prefix . "`{$w['column']}` IS NULL";
                    break;

                case 'not_null':
                    $parts[] = $prefix . "`{$w['column']}` IS NOT NULL";
                    break;

                case 'raw':
                    $parts[] = $prefix . '(' . $w['sql'] . ')';
                    foreach ($w['bindings'] as $v) $bindings[] = $v;
                    break;
            }
        }
        return [implode('', $parts), $bindings];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function addWhere(string $boolean, string $column, mixed $opOrValue, mixed $value, int $argCount): self
    {
        $this->assertIdent($column);

        // 2-arg form: where('col', $value) → operator defaults to '='
        if ($argCount === 2) {
            $operator = '=';
            $value    = $opOrValue;
        } else {
            $operator = strtoupper((string) $opOrValue);
            if (!in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'], true)) {
                throw new \InvalidArgumentException("Invalid where operator: [$operator]");
            }
        }

        $this->wheres[] = [
            'type'     => 'basic',
            'boolean'  => $boolean,
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];
        return $this;
    }

    /** Whitelist identifier — same rule Database uses for its write helpers. */
    private function assertIdent(string $name, bool $allowStar = false): void
    {
        if ($allowStar && $name === '*') return;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: [$name]");
        }
    }
}
