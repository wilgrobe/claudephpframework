<?php
// core/Database/Model.php
namespace Core\Database;

/**
 * Thin Model base. Opt-in — existing plain-object models in app/Models/ keep
 * working unchanged; new code can extend this instead.
 *
 * Minimal example:
 *
 *   class Widget extends Model {
 *       protected static string $table = 'widgets';
 *       protected static array  $fillable = ['name', 'status', 'group_id'];
 *   }
 *
 *   Widget::find(42);                              // → Widget|null
 *   Widget::findOrFail(42);                        // → Widget (throws on miss)
 *   Widget::where('status', 'active')->get();      // → Widget[]
 *   Widget::create(['name' => 'New']);             // → Widget (inserts, returns hydrated)
 *
 *   $w = Widget::find(42);
 *   $w->name = 'Renamed';
 *   $w->save();                                    // → bool (UPDATE)
 *   $w->delete();                                  // → bool (soft if $softDeletes=true)
 *
 * Features:
 *   - Auto-updates created_at + updated_at when $timestamps = true
 *   - Soft deletes: set $softDeletes = true; delete() writes to deleted_at
 *     and the default query scope excludes soft-deleted rows. Include them
 *     with Widget::withTrashed()->get().
 *   - Mass-assignment protection via $fillable — create/fill ignore keys
 *     not listed.
 *
 * Not included (by design):
 *   - Relationships / eager loading (use explicit queries)
 *   - Observers / events (use the routing boot() hook or a future event bus)
 *   - Attribute accessors / mutators (compute in the view)
 */
abstract class Model
{
    /**
     * Database table name. Subclasses MUST set this.
     * Static because the query-builder entry points are static methods.
     */
    protected static string $table = '';

    protected static string $primaryKey = 'id';

    /** Enable auto-updates to created_at + updated_at on save/create. */
    protected static bool $timestamps = true;

    /** Enable soft delete semantics — delete() writes deleted_at instead of DROP. */
    protected static bool $softDeletes = false;

    /** Column name when $softDeletes = true. */
    protected static string $deletedAtColumn = 'deleted_at';

    /** Mass-assignment whitelist. Empty = allow all (unsafe for user input). */
    protected static array $fillable = [];

    /** @var array<string, mixed> the row's attributes */
    protected array $attributes = [];

    /** @var array<string, mixed> original DB row, for change tracking */
    protected array $original = [];

    // ── Hydration / array access ──────────────────────────────────────────────

    public function __construct(array $attributes = [])
    {
        // Raw internal hydration — bypasses fillable so the query layer can
        // build instances from DB rows without losing columns.
        $this->attributes = $attributes;
        $this->original   = $attributes;
    }

    public function __get(string $key): mixed      { return $this->attributes[$key] ?? null; }
    public function __set(string $key, mixed $v): void { $this->attributes[$key] = $v; }
    public function __isset(string $key): bool     { return isset($this->attributes[$key]); }

    public function toArray(): array   { return $this->attributes; }
    public function exists(): bool     { return !empty($this->attributes[static::$primaryKey]); }

    // ── Query builder forwarding ─────────────────────────────────────────────

    /**
     * Fresh builder scoped to this model's table + default scopes
     * (soft-delete filter when applicable). Terminal methods (get/first)
     * hydrate results into model instances.
     */
    public static function query(): ModelQueryBuilder
    {
        $db = Database::getInstance();
        $qb = new ModelQueryBuilder($db, static::$table, static::class);
        if (static::$softDeletes) {
            $qb->whereNull(static::$deletedAtColumn);
        }
        return $qb;
    }

    /** Bypass the soft-delete scope. */
    public static function withTrashed(): ModelQueryBuilder
    {
        $db = Database::getInstance();
        return new ModelQueryBuilder($db, static::$table, static::class);
    }

    /** Only soft-deleted rows. */
    public static function onlyTrashed(): ModelQueryBuilder
    {
        return static::withTrashed()->whereNotNull(static::$deletedAtColumn);
    }

    public static function where(string $column, mixed $opOrValue, mixed $value = null): ModelQueryBuilder
    {
        return func_num_args() === 2
            ? static::query()->where($column, $opOrValue)
            : static::query()->where($column, $opOrValue, $value);
    }

    public static function find(int|string $id): ?static
    {
        return static::query()
            ->where(static::$primaryKey, $id)
            ->first();
    }

    /** @throws \RuntimeException when no row matches. */
    public static function findOrFail(int|string $id): static
    {
        $m = static::find($id);
        if ($m === null) {
            throw new \RuntimeException(static::class . " not found (id=$id)");
        }
        return $m;
    }

    /** All rows (honors soft-delete scope). */
    public static function all(): array
    {
        return static::query()->get();
    }

    // ── Mass operations ──────────────────────────────────────────────────────

    /** Insert + return hydrated instance. Unfillable keys are silently dropped. */
    public static function create(array $attributes): static
    {
        $m = new static();
        $m->fill($attributes);
        $m->save();
        return $m;
    }

    /** Copy fillable keys into $this->attributes. */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (empty(static::$fillable) || in_array($key, static::$fillable, true)) {
                $this->attributes[$key] = $value;
            }
        }
        return $this;
    }

    // ── Persistence ──────────────────────────────────────────────────────────

    /** INSERT if primary key is empty, otherwise UPDATE. Returns true on success. */
    public function save(): bool
    {
        $db  = Database::getInstance();
        $now = date('Y-m-d H:i:s');

        if ($this->exists()) {
            $id       = $this->attributes[static::$primaryKey];
            $dirty    = $this->getDirty();
            if (empty($dirty)) return true; // nothing to do
            if (static::$timestamps) $dirty['updated_at'] = $now;

            $db->update(static::$table, $dirty, static::$primaryKey . ' = ?', [$id]);
            $this->original = $this->attributes;
            return true;
        }

        $data = $this->attributes;
        if (static::$timestamps) {
            $data['created_at'] ??= $now;
            $data['updated_at'] ??= $now;
        }
        $id = $db->insert(static::$table, $data);
        $this->attributes[static::$primaryKey] = $id;
        $this->attributes['created_at'] ??= $data['created_at'] ?? null;
        $this->attributes['updated_at'] ??= $data['updated_at'] ?? null;
        $this->original = $this->attributes;
        return true;
    }

    /** Returns true when delete (soft or hard) affected at least one row. */
    public function delete(): bool
    {
        if (!$this->exists()) return false;
        $db = Database::getInstance();
        $id = $this->attributes[static::$primaryKey];

        if (static::$softDeletes) {
            $now = date('Y-m-d H:i:s');
            $rows = $db->update(
                static::$table,
                [static::$deletedAtColumn => $now],
                static::$primaryKey . ' = ?',
                [$id]
            );
            $this->attributes[static::$deletedAtColumn] = $now;
            return $rows > 0;
        }

        $rows = $db->delete(static::$table, static::$primaryKey . ' = ?', [$id]);
        return $rows > 0;
    }

    /** Columns that differ from the originally-loaded row. */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $k => $v) {
            if (!array_key_exists($k, $this->original) || $this->original[$k] !== $v) {
                $dirty[$k] = $v;
            }
        }
        // Never attempt to UPDATE the primary key
        unset($dirty[static::$primaryKey]);
        return $dirty;
    }

    // ── Introspection hooks (for the ModelQueryBuilder) ──────────────────────

    /** @internal Exposes protected statics to ModelQueryBuilder without making them public. */
    public static function modelMeta(): array
    {
        return [
            'class'           => static::class,
            'table'           => static::$table,
            'primaryKey'      => static::$primaryKey,
            'softDeletes'     => static::$softDeletes,
            'deletedAtColumn' => static::$deletedAtColumn,
        ];
    }
}
