<?php
// core/Database/ModelQueryBuilder.php
namespace Core\Database;

/**
 * Sub-class of QueryBuilder that hydrates get()/first() results into Model
 * instances of the associated class. Everything else (where/orderBy/limit
 * /etc.) is inherited unchanged.
 *
 * Instantiated only via Model::query() — users shouldn't need to know about
 * this class directly.
 */
class ModelQueryBuilder extends QueryBuilder
{
    /** @var class-string<Model> */
    private string $modelClass;

    public function __construct(Database $db, string $table, string $modelClass)
    {
        parent::__construct($db, $table);
        $this->modelClass = $modelClass;
    }

    /** @return Model[] */
    public function get(): array
    {
        $rows = parent::get();
        return array_map(fn($row) => new ($this->modelClass)($row), $rows);
    }

    public function first(): ?Model
    {
        $row = parent::first();
        return $row === null ? null : new ($this->modelClass)($row);
    }

    /**
     * Paginate hydrates items too. Returns the standard paginator shape
     * Database::paginate uses, but 'items' is Model[] instead of array[].
     */
    public function paginate(int $perPage = 20, int $page = 1): array
    {
        $result = parent::paginate($perPage, $page);
        $result['items'] = array_map(fn($row) => new ($this->modelClass)($row), $result['items']);
        return $result;
    }
}
