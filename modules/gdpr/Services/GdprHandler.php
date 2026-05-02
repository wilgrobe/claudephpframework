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
     *     export?: bool
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
}
