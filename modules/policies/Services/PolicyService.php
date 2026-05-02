<?php
// modules/policies/Services/PolicyService.php
namespace Modules\Policies\Services;

use Core\Database\Database;

/**
 * Policy lifecycle + acceptance lookups.
 *
 *   - listKinds()                  every kind with current version + page wired in
 *   - bumpVersion(kindId, ...)     snapshot the source page body → new version
 *                                   row → flip current_version_id to point at it
 *   - recordAcceptance(...)        write a policy_acceptances row + audit
 *   - unacceptedFor(userId)        the kinds that need this user to re-accept
 *   - acceptanceStats(versionId)   accept count + ratio for the admin UI
 *
 * The "snapshot" mechanic is critical: when an admin clicks Bump
 * version, we COPY the current page.body into policy_versions.body_html.
 * Subsequent edits to the source page DON'T retroactively change what
 * users accepted on date X. The legal evidence is the row, not the
 * live page.
 */
class PolicyService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** @return array<int, array<string,mixed>> */
    public function listKinds(): array
    {
        return $this->db->fetchAll("
            SELECT k.*,
                   p.source_page_id,
                   p.current_version_id,
                   pg.title AS source_page_title,
                   pg.slug  AS source_page_slug,
                   pg.status AS source_page_status,
                   v.version_label AS current_version_label,
                   v.effective_date AS current_effective_date,
                   v.created_at     AS current_created_at
            FROM policy_kinds k
            LEFT JOIN policies p        ON p.kind_id = k.id
            LEFT JOIN pages pg          ON pg.id = p.source_page_id
            LEFT JOIN policy_versions v ON v.id = p.current_version_id
            ORDER BY k.sort_order ASC, k.id ASC
        ");
    }

    public function findKind(int $kindId): ?array
    {
        $row = $this->db->fetchOne("
            SELECT k.*, p.source_page_id, p.current_version_id
            FROM policy_kinds k
            LEFT JOIN policies p ON p.kind_id = k.id
            WHERE k.id = ?
        ", [$kindId]);
        return $row ?: null;
    }

    public function findKindBySlug(string $slug): ?array
    {
        $row = $this->db->fetchOne("
            SELECT k.*, p.source_page_id, p.current_version_id
            FROM policy_kinds k
            LEFT JOIN policies p ON p.kind_id = k.id
            WHERE k.slug = ?
        ", [$slug]);
        return $row ?: null;
    }

    public function listVersions(int $kindId): array
    {
        return $this->db->fetchAll("
            SELECT v.*, u.username AS author_username
            FROM policy_versions v
            LEFT JOIN users u ON u.id = v.created_by
            WHERE v.kind_id = ?
            ORDER BY v.id DESC
        ", [$kindId]);
    }

    public function findVersion(int $versionId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM policy_versions WHERE id = ?",
            [$versionId]
        );
        return $row ?: null;
    }

    public function findCurrentVersion(int $kindId): ?array
    {
        $row = $this->db->fetchOne("
            SELECT v.*
            FROM policies p
            JOIN policy_versions v ON v.id = p.current_version_id
            WHERE p.kind_id = ?
        ", [$kindId]);
        return $row ?: null;
    }

    /**
     * Wire a CMS page as the source for a policy. Lets the admin author
     * the policy via the regular page editor + WYSIWYG, then come here
     * to bump the version when ready.
     */
    public function setSourcePage(int $kindId, ?int $pageId): void
    {
        // Ensure the policies row exists (the seed migration creates
        // it for system kinds; admin-created kinds need this fallback).
        $this->db->query("
            INSERT IGNORE INTO policies (kind_id, source_page_id, current_version_id)
            VALUES (?, NULL, NULL)
        ", [$kindId]);

        $this->db->query("
            UPDATE policies SET source_page_id = ? WHERE kind_id = ?
        ", [$pageId, $kindId]);
    }

    /**
     * Bump a kind's version. Snapshots the current source page body
     * into a new policy_versions row and flips current_version_id.
     *
     * @param int     $kindId
     * @param string  $versionLabel  e.g. "1.0", "2026-04-30"
     * @param ?string $effectiveDate ISO date; defaults to today
     * @param ?string $summary       Optional short blurb shown to users on the
     *                               re-acceptance modal — e.g. "Added cookie clause"
     * @param ?int    $actorUserId   admin clicking bump
     * @return int                   the new policy_versions row id
     */
    public function bumpVersion(int $kindId, string $versionLabel, ?string $effectiveDate = null, ?string $summary = null, ?int $actorUserId = null): int
    {
        $effective = $effectiveDate ?: date('Y-m-d');

        $kind = $this->findKind($kindId);
        if (!$kind) throw new \RuntimeException("Unknown policy kind: #{$kindId}");

        // Snapshot the source page body, if any.
        $sourcePageId = $kind['source_page_id'] ?? null;
        $body         = null;
        if ($sourcePageId) {
            $page = $this->db->fetchOne("SELECT body FROM pages WHERE id = ?", [$sourcePageId]);
            if ($page) $body = (string) $page['body'];
        }

        $versionId = $this->db->insert('policy_versions', [
            'kind_id'        => $kindId,
            'version_label'  => $versionLabel,
            'body_html'      => $body,
            'source_page_id' => $sourcePageId,
            'effective_date' => $effective,
            'summary'        => $summary,
            'created_by'     => $actorUserId,
        ]);

        // Flip the pointer
        $this->db->query("
            UPDATE policies SET current_version_id = ? WHERE kind_id = ?
        ", [$versionId, $kindId]);

        return (int) $versionId;
    }

    /**
     * Record a user's acceptance of a specific version.
     * Idempotent — the (user_id, version_id) unique key suppresses
     * duplicate inserts.
     */
    public function recordAcceptance(int $userId, int $kindId, int $versionId): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ipPacked = ($ip && @inet_pton($ip) !== false) ? @inet_pton($ip) : null;
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(strip_tags($_SERVER['HTTP_USER_AGENT']), 0, 500)
            : null;

        $this->db->query("
            INSERT IGNORE INTO policy_acceptances
              (user_id, kind_id, version_id, accepted_at, ip_address, user_agent)
            VALUES (?, ?, ?, NOW(), ?, ?)
        ", [$userId, $kindId, $versionId, $ipPacked, $ua]);
    }

    /**
     * Return every kind that requires acceptance and where the user
     * either hasn't accepted at all OR has only accepted older versions.
     *
     * Used by RequirePolicyAcceptance middleware to drive the blocking
     * modal. Empty array → user is current on every required policy.
     *
     * @return array<int, array<string,mixed>>  Kind rows with current version
     */
    public function unacceptedFor(int $userId): array
    {
        return $this->db->fetchAll("
            SELECT k.id          AS kind_id,
                   k.slug        AS kind_slug,
                   k.label       AS kind_label,
                   k.description AS kind_description,
                   p.current_version_id AS version_id,
                   v.version_label,
                   v.effective_date,
                   v.summary,
                   v.body_html
            FROM policy_kinds k
            JOIN policies p        ON p.kind_id = k.id
            JOIN policy_versions v ON v.id = p.current_version_id
            WHERE k.requires_acceptance = 1
              AND NOT EXISTS (
                  SELECT 1 FROM policy_acceptances a
                  WHERE a.user_id = ? AND a.version_id = p.current_version_id
              )
            ORDER BY k.sort_order ASC
        ", [$userId]);
    }

    /**
     * Acceptance stats per version — used by /admin/policies/{id} to
     * show "X of Y users have accepted v3".
     *
     * @return array{accepted_users: int, total_users: int, ratio: float}
     */
    public function acceptanceStats(int $versionId): array
    {
        $accepted = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT user_id) FROM policy_acceptances WHERE version_id = ? AND user_id IS NOT NULL",
            [$versionId]
        );
        $totalUsers = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE is_active = 1 AND deleted_at IS NULL"
        );
        $ratio = $totalUsers > 0 ? $accepted / $totalUsers : 0.0;
        return [
            'accepted_users' => $accepted,
            'total_users'    => $totalUsers,
            'ratio'          => $ratio,
        ];
    }

    /**
     * Lookup a user's acceptance history — used by the user's
     * /account/policies page.
     *
     * @return array<int, array<string,mixed>>
     */
    public function userHistory(int $userId): array
    {
        return $this->db->fetchAll("
            SELECT a.accepted_at,
                   k.slug AS kind_slug, k.label AS kind_label,
                   v.version_label, v.effective_date, v.id AS version_id
            FROM policy_acceptances a
            JOIN policy_kinds k    ON k.id = a.kind_id
            JOIN policy_versions v ON v.id = a.version_id
            WHERE a.user_id = ?
            ORDER BY a.accepted_at DESC
        ", [$userId]);
    }

    public function createCustomKind(string $slug, string $label, ?string $description, bool $requiresAcceptance): int
    {
        $clean = preg_replace('/[^a-z0-9_]/', '_', strtolower($slug)) ?? '';
        if ($clean === '' || $clean === 'tos' || $clean === 'privacy' || $clean === 'acceptable_use') {
            throw new \InvalidArgumentException('Slug must be alphanumeric and not collide with the system kinds.');
        }
        $id = $this->db->insert('policy_kinds', [
            'slug'                => $clean,
            'label'               => $label,
            'description'         => $description,
            'requires_acceptance' => $requiresAcceptance ? 1 : 0,
            'is_system'           => 0,
            'sort_order'          => 100,
        ]);
        $this->db->insert('policies', [
            'kind_id'        => $id,
            'source_page_id' => null,
        ]);
        return (int) $id;
    }
}
