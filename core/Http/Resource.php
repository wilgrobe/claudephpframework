<?php
// core/Http/Resource.php
namespace Core\Http;

/**
 * Base for JSON resource transformers. The controller layer deals in models
 * or DB rows; the Resource layer decides what shape goes over the wire.
 * That lets you:
 *   - hide sensitive columns (password_hash, api_secret, etc.)
 *   - stabilize field names across schema changes
 *   - version your API (v1 UserResource vs v2 UserResource)
 *
 * Usage:
 *
 *   class UserResource extends Resource {
 *       public function toArray(): array {
 *           $u = $this->resource;
 *           return [
 *               'id'    => (int) $u['id'],
 *               'email' => $u['email'],
 *               'name'  => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
 *           ];
 *       }
 *   }
 *
 *   // Single:
 *   return Response::json(['data' => UserResource::from($user)]);
 *
 *   // Collection:
 *   return Response::json(['data' => UserResource::collection($users)]);
 *
 *   // Paginated (matches the shape Database::paginate returns):
 *   return Response::json(UserResource::paginated($paginator));
 *
 * `$resource` holds an array row, a Model instance, or whatever you pass
 * in — toArray() accesses it however it likes.
 */
abstract class Resource
{
    protected mixed $resource;

    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /** Transform $this->resource into the wire shape. */
    abstract public function toArray(): array;

    /** Serialize a single resource. */
    public static function from(mixed $resource): array
    {
        return (new static($resource))->toArray();
    }

    /**
     * Serialize an iterable of resources. Accepts arrays, generators, or
     * anything iterable — returns a plain list.
     *
     * @param iterable<mixed> $resources
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $resources): array
    {
        $out = [];
        foreach ($resources as $r) $out[] = static::from($r);
        return $out;
    }

    /**
     * Serialize a paginator result (from Database::paginate or
     * QueryBuilder::paginate). Preserves the pagination metadata; items
     * get transformed through this resource.
     *
     * @param array{items: iterable, total: int, per_page: int, current_page: int, last_page: int} $paginator
     */
    public static function paginated(array $paginator): array
    {
        return [
            'data' => static::collection($paginator['items'] ?? []),
            'meta' => [
                'total'        => $paginator['total']        ?? 0,
                'per_page'     => $paginator['per_page']     ?? 0,
                'current_page' => $paginator['current_page'] ?? 1,
                'last_page'    => $paginator['last_page']    ?? 1,
            ],
        ];
    }
}
