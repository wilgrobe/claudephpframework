<?php
// modules/gdpr/Services/DsarService.php
namespace Modules\Gdpr\Services;

use Core\Database\Database;

/**
 * Lifecycle for the dsar_requests table.
 *
 * States:
 *   pending      — just created. Awaiting email verification or admin pickup.
 *   verified     — verification token consumed (self-service path).
 *   in_progress  — admin has picked it up; export building / purge running.
 *   completed    — fulfilled. Result links recorded in `notes`.
 *   denied       — refused (e.g. identity not established for an external request).
 *   expired      — SLA window passed without action; admin lost track.
 *
 * SLA: GDPR Article 12(3) gives the data controller "without undue delay
 * and in any event within one month". We use 30 days as the deadline +
 * surface an "overdue" badge as soon as sla_due_at is in the past.
 */
class DsarService
{
    public const SLA_DAYS = 30;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Create a new DSAR row.
     *
     * @param string  $kind         access | export | erasure | restriction | rectification | objection
     * @param string  $email        requester email — verification email goes here
     * @param ?int    $userId       linked user (when known)
     * @param string  $source       self_service | admin | external
     * @param ?string $name         requester name (when external & not signed in)
     * @return int                  the new row id
     */
    public function create(string $kind, string $email, ?int $userId = null, string $source = 'self_service', ?string $name = null): int
    {
        $token = $source === 'self_service' && $userId === null
            ? bin2hex(random_bytes(32))
            : null;

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ipPacked = ($ip && @inet_pton($ip) !== false) ? @inet_pton($ip) : null;

        return $this->db->insert('dsar_requests', [
            'user_id'            => $userId,
            'requester_email'    => $email,
            'requester_name'     => $name,
            'kind'               => $kind,
            'status'             => $userId !== null ? 'verified' : 'pending',
            'source'             => $source,
            'sla_due_at'         => date('Y-m-d H:i:s', time() + self::SLA_DAYS * 86400),
            'verification_token' => $token,
            'verified_at'        => $userId !== null ? date('Y-m-d H:i:s') : null,
            'ip_address'         => $ipPacked,
        ]);
    }

    /**
     * Consume a verification token from a one-time email link. Returns
     * the dsar_requests row, or null if the token is invalid/expired.
     */
    public function verify(string $token): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM dsar_requests
             WHERE verification_token = ? AND status = 'pending'",
            [$token]
        );
        if (!$row) return null;

        $this->db->update('dsar_requests', [
            'status'             => 'verified',
            'verified_at'        => date('Y-m-d H:i:s'),
            'verification_token' => null,
        ], 'id = ?', [$row['id']]);

        return $this->db->fetchOne("SELECT * FROM dsar_requests WHERE id = ?", [$row['id']]);
    }

    public function setStatus(int $id, string $status, ?int $handlerId = null, ?string $notes = null): void
    {
        $update = ['status' => $status];
        if ($handlerId !== null) $update['handled_by'] = $handlerId;
        if ($notes !== null)     $update['notes']      = $notes;
        if (in_array($status, ['completed', 'denied', 'expired'], true)) {
            $update['completed_at'] = date('Y-m-d H:i:s');
        }
        $this->db->update('dsar_requests', $update, 'id = ?', [$id]);
    }

    /**
     * Sweep called by the scheduler — flag rows whose SLA has elapsed
     * without resolution. Doesn't auto-resolve; just makes them visible
     * in the admin queue's "overdue" filter.
     */
    public function flagOverdue(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM dsar_requests
             WHERE status IN ('pending','verified','in_progress')
               AND sla_due_at < NOW()"
        );
        // We don't move state here — admins still want to see + work the
        // backlog. The view marks them visually.
        return count($rows);
    }

    /** Recent rows for the admin queue. */
    public function recent(int $limit = 100, ?string $statusFilter = null): array
    {
        $where = '';
        $args  = [];
        if ($statusFilter !== null && $statusFilter !== 'all') {
            $where = ' WHERE status = ?';
            $args[] = $statusFilter;
        }
        return $this->db->fetchAll("
            SELECT d.*, u.username AS user_username, h.username AS handler_username,
                   (d.sla_due_at < NOW() AND d.status IN ('pending','verified','in_progress')) AS overdue
            FROM dsar_requests d
            LEFT JOIN users u ON u.id = d.user_id
            LEFT JOIN users h ON h.id = d.handled_by
            {$where}
            ORDER BY d.id DESC
            LIMIT ?
        ", array_merge($args, [$limit]));
    }

    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM dsar_requests WHERE id = ?", [$id]);
        return $row ?: null;
    }
}
