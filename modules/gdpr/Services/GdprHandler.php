<?php
// modules/gdpr/Services/GdprHandler.php
namespace Modules\Gdpr\Services;

/**
 * Value object describing one module's PII / user-data handling.
 *
 * A module's module.php returns an array of these from `gdprHandlers()`.
 * The GdprRegistry collects them all, the DataExporter uses each
 * handler's tables (or customExport callable) to produce the user's
 * data zip, and the DataPurger uses the same to wipe or anonymize the
 * user when erasure fires.
 *
 * Two modes are supported:
 *
 *   1. Simple table mode — declare `tables` with one entry per relevant
 *      DB table. Each entry has `table`, `user_column`, `action`, and
 *      optionally `anonymize_columns` and `legal_hold_reason`.
 *
 *      Actions:
 *        - 'erase'      DELETE rows where user_column = user_id.
 *        - 'anonymize'  UPDATE rows: NULL the user_column, replace any
 *                       columns listed in anonymize_columns with the
 *                       given replacement value, leave the row otherwise
 *                       intact. Use for legal-hold tables (invoices,
 *                       audit_log, etc.) and for content that the
 *                       framework should keep visible to other users
 *                       (e.g. forum threads where mid-thread deletes
 *                       break readability — anonymise the author, leave
 *                       the post body).
 *        - 'keep'       Leave the row entirely alone. Reserved for
 *                       financial / regulatory cases where even the
 *                       user_column FK has to stay populated.
 *
 *      Optional `keep_link` (anonymize only): scrub anonymize_columns but
 *      leave user_column as it is — for a NOT NULL link, where setting it
 *      to NULL fails the whole UPDATE. The users row is scrubbed anyway.
 *
 *      Optional `match` says what user_column holds (default 'id'):
 *        - 'id'            the user's id
 *        - 'email'         the user's email address (compared lowercased)
 *        - 'phone'         the user's phone number
 *        - 'email_sha256'  `key_prefix` . sha256(lowercased, trimmed email) —
 *                          the RateLimiter's `email:<hash>` attempt keys
 *      Some framework tables never stored a user id — password resets,
 *      login attempts and the message log are keyed by who they were
 *      SENT to — so matching them by id erased nothing, on every site.
 *      A user with no email/phone on file simply has nothing to match.
 *
 *   2. Custom mode — supply customExport and/or customErase callables
 *      for shapes the simple mode can't express (encrypted blobs,
 *      attachment files on disk, S3 keys, multi-table joins).
 *
 * Either mode is valid; both can co-exist on the same handler if some
 * tables fit the simple shape and others need custom logic.
 */
final class GdprHandler
{
    public const ACTION_ERASE     = 'erase';
    public const ACTION_ANONYMIZE = 'anonymize';
    public const ACTION_KEEP      = 'keep';

    public const MATCH_ID           = 'id';
    public const MATCH_EMAIL        = 'email';
    public const MATCH_PHONE        = 'phone';
    public const MATCH_EMAIL_SHA256 = 'email_sha256';

    /**
     * @param string              $module       Owning module name (auto-set by registry from ModuleProvider::name())
     * @param string              $description  Human description shown to the user in the export bundle README
     * @param array<int, array{
     *     table: string,
     *     user_column: string,
     *     action: string,
     *     anonymize_columns?: array<string,string|int|null>,
     *     export_select?: string,
     *     legal_hold_reason?: string,
     *     export?: bool,
     *     match?: string,
     *     key_prefix?: string,
     *     keep_link?: bool
     * }> $tables
     * @param ?\Closure  $customExport function(int $userId): array  — keys are filenames inside the export zip, values are payloads (string or array → JSON)
     * @param ?\Closure  $customErase  function(int $userId, string $marker): void
     */
    public function __construct(
        public string  $module,
        public string  $description,
        public array   $tables       = [],
        public ?\Closure $customExport = null,
        public ?\Closure $customErase  = null,
    ) {}

    /**
     * Replacement marker used by anonymize_columns. Each module can
     * override per-column; this is the default the registry passes.
     */
    public static function defaultMarker(int $userId): string
    {
        return '[erased user #' . $userId . ']';
    }

    /**
     * What a user can be matched by. Read BEFORE the purge scrubs the
     * users row — afterwards the email is `erased-<id>@invalid.local`.
     *
     * @return array{id:int, email:string, phone:string}
     */
    public static function identityFor(\Core\Database\Database $db, int $userId): array
    {
        $row = null;
        try {
            $row = $db->fetchOne('SELECT email, phone FROM users WHERE id = ?', [$userId]);
        } catch (\Throwable $e) {
            // An install without users.phone still matches by email.
            try { $row = $db->fetchOne('SELECT email FROM users WHERE id = ?', [$userId]); } catch (\Throwable $e2) {}
        }
        return [
            'id'    => $userId,
            'email' => strtolower(trim((string) ($row['email'] ?? ''))),
            'phone' => trim((string) ($row['phone'] ?? '')),
        ];
    }

    /**
     * The WHERE clause and bound value for one declared table.
     *
     * Null when the user has nothing on file to match by (no phone, say),
     * or when `match` is a value this version does not know — a table is
     * never matched against the wrong identifier.
     *
     * @param array<string,mixed> $tbl
     * @param array{id:int, email:string, phone:string} $identity
     * @return array{0:string, 1:int|string}|null
     */
    public static function matchFor(array $tbl, array $identity): ?array
    {
        $col = '`' . str_replace('`', '', (string) $tbl['user_column']) . '`';
        switch ((string) ($tbl['match'] ?? self::MATCH_ID)) {
            case self::MATCH_ID:
                return ["$col = ?", $identity['id']];
            case self::MATCH_EMAIL:
                return $identity['email'] === '' ? null : ["LOWER($col) = ?", $identity['email']];
            case self::MATCH_PHONE:
                return $identity['phone'] === '' ? null : ["$col = ?", $identity['phone']];
            case self::MATCH_EMAIL_SHA256:
                return $identity['email'] === '' ? null
                    : ["$col = ?", (string) ($tbl['key_prefix'] ?? '') . hash('sha256', $identity['email'])];
            default:
                error_log("GdprHandler: unknown match '" . (string) $tbl['match'] . "' on {$tbl['table']} — table skipped");
                return null;
        }
    }
}
