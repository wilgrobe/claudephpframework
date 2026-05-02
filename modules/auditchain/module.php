<?php
// modules/auditchain/module.php
use Core\Module\ModuleProvider;

/**
 * Audit-chain module — HMAC-SHA256 tamper-detection over `audit_log`.
 *
 * Hooks into the framework via:
 *
 *   1. Auth::auditLog — patched to route through
 *      AuditChainService::sealAndInsert when this module is installed.
 *      Computes prev_hash + row_hash before the row hits the table so
 *      every audit_log entry is sealed at write time.
 *
 *   2. DataPurger + RetentionSweepJob — both also patched to route
 *      through AuditChainService for their direct audit_log inserts,
 *      so the chain stays continuous regardless of which writer
 *      produced the row.
 *
 *   3. AuditChainVerifyJob — daily job that re-verifies the last 7 days
 *      and dispatches a SA notification on detected breaks. Wire with:
 *
 *        php artisan schedule:create auditchain.verify daily
 *
 *   4. /admin/audit-chain — health overview + on-demand verify of a
 *      date range. /admin/audit-chain/breaks — break log + ack flow.
 *
 * Per-day chains: each calendar day starts at a deterministic genesis
 * hash (HMAC of date + APP_KEY). A break on day X doesn't propagate
 * past midnight UTC; verification runs in O(rows-per-day), not the
 * whole log.
 *
 * IMPORTANT: rotating APP_KEY invalidates every existing row's
 * row_hash (the HMAC was computed under the old key). Plan for this:
 * before rotating, run a final verify, then either accept that all
 * pre-rotation rows will report `hash_mismatch` (and acknowledge them
 * all en masse), or pre-compute a re-seal pass with the old key.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'auditchain'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function gdprHandlers(): array { return []; }

    /**
     * Retention rules — chain runs + breaks accumulate over time.
     * Both can be safely purged after a couple of years; the breaks
     * that mattered will have been acknowledged + investigated long
     * before then.
     */
    public function retentionRules(): array
    {
        if (!class_exists(\Modules\Retention\Services\RetentionRule::class)) return [];

        return [
            new \Modules\Retention\Services\RetentionRule(
                key:         'auditchain.runs.old',
                module:      'auditchain',
                label:       'Old chain-verify runs',
                tableName:   'audit_chain_runs',
                whereClause: 'started_at < {cutoff}',
                daysKeep:    365,
                action:      \Modules\Retention\Services\RetentionRule::ACTION_PURGE,
                dateColumn:  'started_at',
                description: 'Verify-run history. 1y is plenty — if a sweep ran years ago and reported clean, that fact is no longer load-bearing.',
            ),
            new \Modules\Retention\Services\RetentionRule(
                key:         'auditchain.breaks.acknowledged_old',
                module:      'auditchain',
                label:       'Old acknowledged breaks',
                tableName:   'audit_chain_breaks',
                whereClause: 'acknowledged_at IS NOT NULL AND acknowledged_at < {cutoff}',
                daysKeep:    1095, // 3 years
                action:      \Modules\Retention\Services\RetentionRule::ACTION_PURGE,
                dateColumn:  'acknowledged_at',
                description: 'Acknowledged breaks older than 3 years. Unacknowledged breaks are NEVER purged automatically — they remain visible until handled.',
            ),
        ];
    }
};
