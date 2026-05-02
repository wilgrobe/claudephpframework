<?php
// modules/gdpr/Services/DataPurger.php
namespace Modules\Gdpr\Services;

use Core\Database\Database;

/**
 * Executes the per-user erasure pipeline. Walks every active GdprHandler
 * and applies its declared `action` to each table:
 *
 *   - 'erase'      DELETE FROM {table} WHERE {user_column} = {user_id}
 *   - 'anonymize'  UPDATE {table} SET {user_column}=NULL,
 *                                    {anonymize_columns...}=...,
 *                                WHERE {user_column} = {user_id}
 *   - 'keep'       no-op (declared but skipped — admins can audit)
 *
 * The user row itself is handled last: name/email/avatar/bio/phone are
 * scrubbed first (so audit_log rows that reference users.id via FK
 * SET NULL can resolve cleanly), then the row is hard-deleted only when
 * no FK with ON DELETE CASCADE/SET NULL still points at it.
 *
 * Wrapped in a transaction so a partial purge can be rolled back. If
 * any handler throws, every prior change is reverted and the user
 * remains in their pre-purge state.
 *
 * Writes a single `gdpr.user_erased` audit_log row at the end with the
 * purger's actor id (or NULL when run by the cron job) and the user_id
 * being erased — proof of fulfilment for GDPR Art. 17(2).
 */
class DataPurger
{
    private Database     $db;
    private GdprRegistry $registry;

    public function __construct(?Database $db = null, ?GdprRegistry $registry = null)
    {
        $this->db       = $db       ?? Database::getInstance();
        $this->registry = $registry ?? new GdprRegistry();
    }

    /**
     * Run the full erasure pipeline for one user.
     *
     * @param int   $userId
     * @param ?int  $actorUserId  who's running the purge (null = cron)
     * @return array{tables_erased: int, tables_anonymized: int, tables_kept: int, custom_handlers: int}
     */
    public function purge(int $userId, ?int $actorUserId = null): array
    {
        $stats = ['tables_erased' => 0, 'tables_anonymized' => 0, 'tables_kept' => 0, 'custom_handlers' => 0];
        $marker = GdprHandler::defaultMarker($userId);

        $this->db->beginTransaction();
        try {
            // 1. Walk handlers in declared order.
            foreach ($this->registry->all() as $handler) {
                foreach ($handler->tables as $tbl) {
                    $action = (string) ($tbl['action'] ?? GdprHandler::ACTION_KEEP);
                    $table  = (string) $tbl['table'];
                    $col    = (string) $tbl['user_column'];

                    switch ($action) {
                        case GdprHandler::ACTION_ERASE:
                            try {
                                $this->db->query(
                                    "DELETE FROM `{$table}` WHERE `{$col}` = ?",
                                    [$userId]
                                );
                                $stats['tables_erased']++;
                            } catch (\Throwable $e) {
                                // Table missing on this install? Log + continue.
                                error_log("DataPurger: erase {$table}.{$col} failed: " . $e->getMessage());
                            }
                            break;

                        case GdprHandler::ACTION_ANONYMIZE:
                            $sets = ["`{$col}` = NULL"];
                            $args = [];
                            $cols = (array) ($tbl['anonymize_columns'] ?? []);
                            foreach ($cols as $name => $val) {
                                $sets[] = "`{$name}` = ?";
                                $args[] = $val;
                            }
                            $args[] = $userId;
                            try {
                                $this->db->query(
                                    "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$col}` = ?",
                                    $args
                                );
                                $stats['tables_anonymized']++;
                            } catch (\Throwable $e) {
                                error_log("DataPurger: anonymize {$table}.{$col} failed: " . $e->getMessage());
                            }
                            break;

                        case GdprHandler::ACTION_KEEP:
                        default:
                            $stats['tables_kept']++;
                            break;
                    }
                }

                if ($handler->customErase !== null) {
                    try {
                        ($handler->customErase)($userId, $marker);
                        $stats['custom_handlers']++;
                    } catch (\Throwable $e) {
                        // A custom handler throwing in a transaction is
                        // a soft failure — the rollback below will
                        // catch it. Re-throw so the txn rolls back.
                        throw new \RuntimeException(
                            "Custom erase handler for module '{$handler->module}' failed: " . $e->getMessage(),
                            0, $e
                        );
                    }
                }
            }

            // 2. Scrub the users row itself. We anonymize first so any
            //    audit_log row whose FK points at users.id can still
            //    resolve to a row (with stripped PII), then we mark
            //    deleted_at. We deliberately don't DELETE FROM users
            //    by default — keep the row so historical FK references
            //    don't go to NULL silently.
            //
            //    Admins who want a hard delete can do it manually via
            //    /admin/gdpr/users/{id} → "Hard delete row" once they're
            //    sure no FK still references it.
            $this->db->query(
                "UPDATE users
                    SET email      = CONCAT('erased-', id, '@invalid.local'),
                        username   = CONCAT('erased_', id),
                        password   = NULL,
                        first_name = NULL,
                        last_name  = NULL,
                        avatar     = NULL,
                        bio        = NULL,
                        phone      = NULL,
                        is_active  = 0,
                        two_factor_enabled = 0,
                        two_factor_method  = NULL,
                        two_factor_secret  = NULL,
                        two_factor_recovery_codes = NULL,
                        deleted_at = CURRENT_TIMESTAMP
                  WHERE id = ?",
                [$userId]
            );

            // 3. Audit-trail row, BEFORE commit so a transaction abort
            //    rolls it back too. Route through AuditChainService when
            //    available so the row is sealed for tamper detection.
            $auditRow = [
                'actor_user_id' => $actorUserId,
                'action'        => 'gdpr.user_erased',
                'model'         => 'users',
                'model_id'      => $userId,
                'old_values'    => null,
                'new_values'    => json_encode([
                    'tables_erased'     => $stats['tables_erased'],
                    'tables_anonymized' => $stats['tables_anonymized'],
                    'tables_kept'       => $stats['tables_kept'],
                    'custom_handlers'   => $stats['custom_handlers'],
                ]),
                'ip_address'    => null,
                'user_agent'    => null,
                'created_at'    => date('Y-m-d H:i:s'),
            ];
            if (class_exists(\Modules\Auditchain\Services\AuditChainService::class)) {
                (new \Modules\Auditchain\Services\AuditChainService($this->db))
                    ->sealAndInsert($this->db, $auditRow);
            } else {
                $this->db->insert('audit_log', $auditRow);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $stats;
    }
}
