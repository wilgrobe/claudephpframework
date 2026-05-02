<?php
// core/Database/Raw.php
namespace Core\Database;

/**
 * Wraps a raw SQL fragment so the query builder passes it through verbatim
 * instead of escaping it as an identifier or binding it as a value.
 *
 *   $db->table('content_items')
 *      ->whereRaw('MATCH(title, body) AGAINST(? IN BOOLEAN MODE)', [$q . '*'])
 *      ->orderBy(new Raw('RAND()'))
 *      ->get();
 *
 * Only use this when you control the input completely — anything that comes
 * from a user belongs in the query builder's normal where/value methods,
 * which always bind.
 */
final class Raw
{
    public function __construct(private string $sql) {}

    public function __toString(): string { return $this->sql; }

    public function sql(): string { return $this->sql; }
}
