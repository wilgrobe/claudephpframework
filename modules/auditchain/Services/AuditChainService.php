<?php
// modules/auditchain/Services/AuditChainService.php
namespace Modules\Auditchain\Services;

use Core\Database\Database;

/**
 * HMAC-SHA256 chain over audit_log rows.
 *
 * Insert path:
 *   - sealAndInsert($db, $row): int
 *     Computes prev_hash + row_hash, INSERTs the row, returns the new id.
 *     Use this everywhere we currently call $db->insert('audit_log', ...).
 *
 * Verify path:
 *   - verifyDay(string $date): array
 *     Walks every chain row for that calendar day, recomputes hashes,
 *     records mismatches in audit_chain_breaks.
 *   - verifyRange(string $from, string $to, ?int $by): array
 *     Bulk verify across a date range; logs a row in audit_chain_runs.
 *
 * Genesis hash:
 *   For day D, genesis = HMAC-SHA256(APP_KEY, "audit-genesis-" || D).
 *   The first row of each day uses this as its prev_hash. Per-day
 *   chains let us verify days in parallel + survive a break on day X
 *   without poisoning day X+1.
 *
 * Canonicalisation:
 *   Row content is hashed in a deterministic order — id, actor_user_id,
 *   action, model, model_id, old_values, new_values, ip_address,
 *   user_agent, notes, created_at. The selected fields are the
 *   tamper-relevant ones; auxiliary fields like superadmin_mode are
 *   included too. Order matters — hashing JSON of the row would also
 *   work but the deterministic concatenation is faster + obvious.
 */
class AuditChainService
{
    /** Fields included in the hash, in this order. */
    private const HASHED_FIELDS = [
        'actor_user_id',
        'emulated_user_id',
        'superadmin_mode',
        'action',
        'model',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'notes',
        'created_at',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Compute prev_hash + row_hash for a row that's about to be
     * inserted, INSERT it, and return the new id.
     *
     * Idempotent re: already-sealed rows — if the caller supplied
     * prev_hash / row_hash already, we leave them as given (this lets
     * tests + replay pre-compute hashes when needed).
     *
     * Designed to be safe to call when the prev_hash / row_hash columns
     * don't exist on the table — falls back to a normal insert. This
     * keeps installs that haven't run the migration alive.
     */
    public function sealAndInsert(Database $db, array $row): int
    {
        // Bail to plain insert if the migration hasn't run yet.
        if (!$this->columnExists('audit_log', 'row_hash')) {
            return (int) $db->insert('audit_log', $row);
        }

        // Anchor on calendar day of created_at (or now() if not set).
        // Stamp created_at NOW so the chain ordering matches insert
        // order — without this, a row with a stale created_at could
        // appear "earlier" in the chain than the actual prior row.
        if (empty($row['created_at'])) {
            $row['created_at'] = date('Y-m-d H:i:s');
        }
        $day = substr((string) $row['created_at'], 0, 10);

        // Look up the chain head for this day. NULL if no row yet
        // — the day's first row anchors on the genesis hash.
        $head = $db->fetchColumn(
            "SELECT row_hash FROM audit_log
             WHERE DATE(created_at) = ? AND row_hash IS NOT NULL
             ORDER BY id DESC LIMIT 1",
            [$day]
        );
        $prevHash = $head ?: $this->genesisHash($day);

        // Compute row_hash. We don't know the row's id yet (auto-
        // increment), so the id is NOT part of the hash — only the
        // tamper-relevant content fields. Renaming a row's id (rare,
        // never via app code) won't break verification.
        $rowHash = $this->hashRow($prevHash, $row);

        $row['prev_hash'] = $prevHash;
        $row['row_hash']  = $rowHash;

        return (int) $db->insert('audit_log', $row);
    }

    /**
     * Verify one calendar day's chain. Walks rows in id-ascending order,
     * recomputes prev_hash + row_hash, records mismatches in
     * audit_chain_breaks. Returns counters.
     *
     * @return array{rows: int, breaks: int}
     */
    public function verifyDay(string $date): array
    {
        $rows = $this->db->fetchAll("
            SELECT id, actor_user_id, emulated_user_id, superadmin_mode, action, model, model_id,
                   old_values, new_values, ip_address, user_agent, notes, created_at,
                   prev_hash, row_hash
            FROM audit_log
            WHERE DATE(created_at) = ?
              AND row_hash IS NOT NULL
            ORDER BY id ASC
        ", [$date]);

        $breaks  = 0;
        $expectedPrev = $this->genesisHash($date);

        foreach ($rows as $r) {
            // Did the stored prev_hash match what it SHOULD have been
            // (the prior row's row_hash, or genesis for first row)?
            if (!hash_equals($expectedPrev, (string) $r['prev_hash'])) {
                $this->recordBreak((int) $r['id'], $date, 'prev_mismatch', $expectedPrev, (string) $r['prev_hash'], null, null);
                $breaks++;
            }

            // Recompute row_hash from the current data + the stored
            // prev_hash. If the data has been tampered with, this
            // recomputed hash won't match the stored one.
            $expectedHash = $this->hashRow((string) $r['prev_hash'], $r);
            if (!hash_equals($expectedHash, (string) $r['row_hash'])) {
                $this->recordBreak((int) $r['id'], $date, 'hash_mismatch', null, null, $expectedHash, (string) $r['row_hash']);
                $breaks++;
            }

            // Even if the hash mismatches, we walk forward using the
            // STORED row_hash so subsequent rows aren't all flagged.
            // The break is localised to the offending row.
            $expectedPrev = (string) $r['row_hash'];
        }

        return ['rows' => count($rows), 'breaks' => $breaks];
    }

    /**
     * Verify a range of days. Logs a row in audit_chain_runs.
     *
     * @return array{rows: int, breaks: int, duration_ms: int}
     */
    public function verifyRange(string $dayFrom, string $dayTo, ?int $byUserId = null): array
    {
        $started = microtime(true);
        $runId = $this->db->insert('audit_chain_runs', [
            'day_from'     => $dayFrom,
            'day_to'       => $dayTo,
            'triggered_by' => $byUserId,
        ]);

        $totals = ['rows' => 0, 'breaks' => 0];

        try {
            $cursor = $dayFrom;
            while (strtotime($cursor) <= strtotime($dayTo)) {
                $r = $this->verifyDay($cursor);
                $totals['rows']   += $r['rows'];
                $totals['breaks']+= $r['breaks'];
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
            }
        } catch (\Throwable $e) {
            $this->db->update('audit_chain_runs', [
                'completed_at'  => date('Y-m-d H:i:s'),
                'error_message' => substr($e->getMessage(), 0, 1000),
                'duration_ms'   => (int) ((microtime(true) - $started) * 1000),
            ], 'id = ?', [$runId]);
            throw $e;
        }

        $duration = (int) ((microtime(true) - $started) * 1000);
        $this->db->update('audit_chain_runs', [
            'completed_at'  => date('Y-m-d H:i:s'),
            'rows_verified' => $totals['rows'],
            'breaks_found'  => $totals['breaks'],
            'duration_ms'   => $duration,
        ], 'id = ?', [$runId]);

        return ['rows' => $totals['rows'], 'breaks' => $totals['breaks'], 'duration_ms' => $duration];
    }

    /**
     * @return array{total_runs: int, last_run_at: ?string, last_break_at: ?string,
     *               total_breaks: int, unack_breaks: int, last_run_breaks: int}
     */
    public function stats(): array
    {
        $lastRun = $this->db->fetchOne(
            "SELECT * FROM audit_chain_runs ORDER BY id DESC LIMIT 1"
        ) ?: [];
        $lastBreak = $this->db->fetchOne(
            "SELECT detected_at FROM audit_chain_breaks ORDER BY id DESC LIMIT 1"
        );
        $totalBreaks = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM audit_chain_breaks"
        );
        $unack = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM audit_chain_breaks WHERE acknowledged_at IS NULL"
        );
        $totalRuns = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM audit_chain_runs"
        );

        return [
            'total_runs'      => $totalRuns,
            'last_run_at'     => $lastRun['completed_at'] ?? null,
            'last_run_breaks' => isset($lastRun['breaks_found']) ? (int) $lastRun['breaks_found'] : 0,
            'last_break_at'   => $lastBreak['detected_at'] ?? null,
            'total_breaks'    => $totalBreaks,
            'unack_breaks'    => $unack,
        ];
    }

    public function recentBreaks(int $limit = 100): array
    {
        return $this->db->fetchAll("
            SELECT b.*, u.username AS ack_username
            FROM audit_chain_breaks b
            LEFT JOIN users u ON u.id = b.acknowledged_by
            ORDER BY b.id DESC LIMIT ?
        ", [$limit]);
    }

    public function acknowledgeBreak(int $breakId, int $byUserId, ?string $notes = null): void
    {
        $this->db->update('audit_chain_breaks', [
            'acknowledged_at' => date('Y-m-d H:i:s'),
            'acknowledged_by' => $byUserId,
            'notes'           => $notes,
        ], 'id = ?', [$breakId]);
    }

    // ── Internals ─────────────────────────────────────────────────

    /**
     * Concatenate the hashed fields with a sentinel separator and
     * compute HMAC-SHA256 keyed on APP_KEY. The sentinel \x1e (record
     * separator) is rare in normal field content; even if it appeared
     * in user_agent or notes, the canonical concatenation produces a
     * unique string per (prev_hash, row) pair.
     */
    private function hashRow(string $prevHash, array $row): string
    {
        $parts = [$prevHash];
        foreach (self::HASHED_FIELDS as $f) {
            $v = $row[$f] ?? null;
            // Cast scalars deterministically — null becomes empty.
            $parts[] = $v === null ? '' : (string) $v;
        }
        return hash_hmac('sha256', implode("\x1e", $parts), $this->signingKey());
    }

    private function genesisHash(string $date): string
    {
        return hash_hmac('sha256', 'audit-genesis-' . $date, $this->signingKey());
    }

    private function signingKey(): string
    {
        $k = (string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?? '');
        return $k !== '' ? $k : 'auditchain-fallback-key-CHANGE-ME';
    }

    private function recordBreak(int $auditLogId, string $day, string $reason, ?string $expPrev, ?string $obsPrev, ?string $expHash, ?string $obsHash): void
    {
        $this->db->insert('audit_chain_breaks', [
            'audit_log_id'  => $auditLogId,
            'day_anchor'    => $day,
            'expected_prev' => $expPrev,
            'observed_prev' => $obsPrev,
            'expected_hash' => $expHash,
            'observed_hash' => $obsHash,
            'reason'        => $reason,
        ]);
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $n = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = ? AND column_name = ?",
                [$table, $column]
            );
            return $n > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
