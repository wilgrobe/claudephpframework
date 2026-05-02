<?php
// core/Http/Resources/UserResource.php
namespace Core\Http\Resources;

use Core\Http\Resource;

/**
 * Default user shape for /api/v1 responses. Deliberately minimal:
 *   - No password_hash, remember_token, or any auth secret
 *   - No two_factor_* columns (reveals whether 2FA is enabled)
 *   - created_at/updated_at formatted as ISO-8601
 *
 * Add fields here as API consumers need them; never just pass the raw row
 * through. The cost of over-exposing vs. adding a field later is one-way.
 */
class UserResource extends Resource
{
    public function toArray(): array
    {
        $u = $this->resource;

        // Accept both array rows (from QueryBuilder::get/first) and Model
        // instances transparently — callers shouldn't have to think about it.
        $get = fn(string $k) => is_array($u) ? ($u[$k] ?? null) : ($u->$k ?? null);

        return [
            'id'         => (int) $get('id'),
            'email'      => (string) $get('email'),
            'name'       => trim(((string) $get('first_name')) . ' ' . ((string) $get('last_name'))),
            'first_name' => $get('first_name'),
            'last_name'  => $get('last_name'),
            'avatar'     => $get('avatar'),
            'verified'   => !empty($get('email_verified_at')),
            'created_at' => $this->iso($get('created_at')),
        ];
    }

    /** MySQL DATETIME → ISO-8601. Null-safe. */
    private function iso(?string $datetime): ?string
    {
        if (!$datetime) return null;
        // Already ISO? Pass through. Otherwise parse + reformat.
        $ts = strtotime($datetime);
        return $ts === false ? $datetime : date('c', $ts);
    }
}
