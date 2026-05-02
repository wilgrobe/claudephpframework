<?php
// app/Models/Group.php
namespace App\Models;

use Core\Database\Database;

class Group
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function all(int $limit = 50, int $offset = 0): array
    {
        // Use LEFT JOIN + GROUP BY instead of a correlated subquery per row.
        return $this->db->fetchAll(
            "SELECT g.*, u.username AS created_by_username,
                    COUNT(ug.user_id) AS member_count
             FROM `groups` g
             LEFT JOIN users u ON u.id = g.created_by
             LEFT JOIN user_groups ug ON ug.group_id = g.id
             GROUP BY g.id, u.username
             ORDER BY g.name
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM `groups` WHERE id = ?", [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne("SELECT * FROM `groups` WHERE slug = ?", [$slug]);
    }

    public function create(array $data): int
    {
        return $this->db->insert('groups', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('groups', $data, 'id = ?', [$id]);
    }

    /**
     * Hard-delete a group and everything hanging off it.
     *
     * Why this isn't just `DELETE FROM groups`:
     *   MySQL FK cascades at delete time fire on every dependent table in
     *   parallel. For this schema that means both user_groups (CASCADE) and
     *   group_roles (CASCADE) get touched simultaneously. But user_groups
     *   also has an FK to group_roles with ON DELETE RESTRICT, and that
     *   RESTRICT is checked against the dependent cascades — which aborts
     *   the whole delete, even though the user_groups rows would have been
     *   removed a moment later.
     *
     *   The fix is to explicitly delete the "downstream" rows first in a
     *   transaction, so by the time we drop `groups`, user_groups is empty
     *   and the group_roles cascade has nothing to restrict against.
     *
     * Wrapped in a transaction so a mid-flight failure leaves the group
     * intact rather than half-deleted.
     */
    public function delete(int $id): int
    {
        return $this->db->transaction(function () use ($id) {
            // Permissions attached to this group's roles. No FK back from
            // group_roles → group_role_permissions, so deleting roles would
            // orphan these. Clear them up-front.
            $this->db->query(
                "DELETE grp FROM group_role_permissions grp
                   JOIN group_roles gr ON gr.id = grp.group_role_id
                  WHERE gr.group_id = ?",
                [$id]
            );

            // user_groups first — this clears the RESTRICT references
            // toward group_roles so the subsequent group_roles cascade is
            // unblocked.
            $this->db->delete('user_groups', 'group_id = ?', [$id]);

            // Now it's safe to drop the group. Remaining rows (group_roles,
            // group_invitations, group_owner_removal_requests, content_items
            // owner pointers) are handled by the existing FK cascades /
            // SET NULLs in the schema.
            return $this->db->delete('groups', 'id = ?', [$id]);
        });
    }

    // ── Members ───────────────────────────────────────────────────────────────

    public function getMembers(int $groupId): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.avatar,
                    gr.name AS role_name, gr.slug AS role_slug, gr.base_role,
                    gr.id AS group_role_id, ug.joined_at, ug.invited_by
             FROM users u
             JOIN user_groups ug ON ug.user_id = u.id
             JOIN group_roles gr ON gr.id = ug.group_role_id
             WHERE ug.group_id = ?
             ORDER BY FIELD(gr.base_role,'group_owner','group_admin','manager','editor','member'), u.last_name",
            [$groupId]
        );
    }

    public function getOwners(int $groupId): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.username, u.email, u.first_name, u.last_name
             FROM users u
             JOIN user_groups ug ON ug.user_id = u.id
             JOIN group_roles gr ON gr.id = ug.group_role_id
             WHERE ug.group_id = ? AND gr.base_role = 'group_owner'",
            [$groupId]
        );
    }

    public function getMembership(int $groupId, int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT ug.*, gr.base_role, gr.slug AS role_slug, gr.name AS role_name
             FROM user_groups ug
             JOIN group_roles gr ON gr.id = ug.group_role_id
             WHERE ug.group_id = ? AND ug.user_id = ?",
            [$groupId, $userId]
        );
    }

    public function addMember(int $groupId, int $userId, int $groupRoleId, ?int $invitedBy = null): int
    {
        return $this->db->insert('user_groups', [
            'group_id'      => $groupId,
            'user_id'       => $userId,
            'group_role_id' => $groupRoleId,
            'invited_by'    => $invitedBy,
        ]);
    }

    public function updateMemberRole(int $groupId, int $userId, int $groupRoleId): int
    {
        return $this->db->update(
            'user_groups',
            ['group_role_id' => $groupRoleId],
            'group_id = ? AND user_id = ?',
            [$groupId, $userId]
        );
    }

    public function removeMember(int $groupId, int $userId): int
    {
        return $this->db->delete('user_groups', 'group_id = ? AND user_id = ?', [$groupId, $userId]);
    }

    // ── Group Roles ───────────────────────────────────────────────────────────

    public function getRoles(int $groupId): array
    {
        return $this->db->fetchAll(
            "SELECT gr.*, u.username AS created_by_username
             FROM group_roles gr
             LEFT JOIN users u ON u.id = gr.created_by
             WHERE gr.group_id = ?
             ORDER BY FIELD(gr.base_role,'group_owner','group_admin','manager','editor','member'), gr.name",
            [$groupId]
        );
    }

    public function findRole(int $groupRoleId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM group_roles WHERE id = ?", [$groupRoleId]);
    }

    public function findRoleBySlug(int $groupId, string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM group_roles WHERE group_id = ? AND slug = ?",
            [$groupId, $slug]
        );
    }

    /**
     * Create the 5 built-in group roles for a newly created group.
     */
    public function createDefaultRoles(int $groupId, ?int $createdBy = null): void
    {
        $defaults = [
            ['group_owner', 'Group Owner', 'Full ownership of the group'],
            ['group_admin', 'Group Admin', 'Administrative access within group'],
            ['manager',     'Manager',     'Manage members and content'],
            ['editor',      'Editor',      'Create and edit group content'],
            ['member',      'Member',      'Standard group member'],
        ];
        foreach ($defaults as [$slug, $name, $desc]) {
            $this->db->insert('group_roles', [
                'group_id'   => $groupId,
                'name'       => $name,
                'slug'       => $slug,
                'description'=> $desc,
                'base_role'  => $slug,
                'is_system'  => 1,
                'created_by' => $createdBy,
            ]);
        }
    }

    /**
     * Create a custom group role based on a lesser built-in role.
     * Only group_owner or group_admin may call this.
     */
    public function createCustomRole(int $groupId, array $data): int
    {
        return $this->db->insert('group_roles', [
            'group_id'    => $groupId,
            'name'        => $data['name'],
            'slug'        => $data['slug'],
            'description' => $data['description'] ?? null,
            'base_role'   => $data['base_role'],
            'is_system'   => 0,
            'created_by'  => $data['created_by'] ?? null,
        ]);
    }

    public function updateRole(int $groupRoleId, array $data): int
    {
        return $this->db->update('group_roles', $data, 'id = ? AND is_system = 0', [$groupRoleId]);
    }

    public function deleteRole(int $groupRoleId): int
    {
        return $this->db->delete('group_roles', 'id = ? AND is_system = 0', [$groupRoleId]);
    }

    public function syncRolePermissions(int $groupRoleId, array $permissionIds): void
    {
        $this->db->delete('group_role_permissions', 'group_role_id = ?', [$groupRoleId]);
        foreach ($permissionIds as $pid) {
            $this->db->insert('group_role_permissions', [
                'group_role_id' => $groupRoleId,
                'permission_id' => (int) $pid,
                'granted'       => 1,
            ]);
        }
    }

    public function getRolePermissions(int $groupRoleId): array
    {
        return $this->db->fetchAll(
            "SELECT p.* FROM permissions p
             JOIN group_role_permissions grp ON grp.permission_id = p.id
             WHERE grp.group_role_id = ? AND grp.granted = 1",
            [$groupRoleId]
        );
    }

    // ── Invitations ───────────────────────────────────────────────────────────

    public function createInvitation(array $data): int
    {
        $data['token']      = bin2hex(random_bytes(32));
        $data['expires_at'] = date('Y-m-d H:i:s', strtotime('+7 days'));
        return $this->db->insert('group_invitations', $data);
    }

    public function findInvitationByToken(string $token): ?array
    {
        return $this->db->fetchOne(
            "SELECT gi.*, g.name AS group_name, g.slug AS group_slug,
                    gr.name AS role_name, gr.base_role
             FROM group_invitations gi
             JOIN `groups` g ON g.id = gi.group_id
             LEFT JOIN group_roles gr ON gr.id = gi.group_role_id
             WHERE gi.token = ? AND gi.status = 'pending' AND gi.expires_at > NOW()",
            [$token]
        );
    }

    /**
     * Look up an invitation by token regardless of status, but only return
     * it if the given user was the one who consumed it. Used to give a
     * friendlier redirect when a user revisits a stale acceptance link.
     */
    public function findConsumedInvitationByToken(string $token, int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT gi.*, g.name AS group_name, g.slug AS group_slug
               FROM group_invitations gi
               JOIN `groups` g ON g.id = gi.group_id
              WHERE gi.token = ?
                AND gi.status = 'accepted'
                AND gi.user_id = ?
              LIMIT 1",
            [$token, $userId]
        );
    }

    public function acceptInvitation(int $invitationId, int $userId): void
    {
        $inv = $this->db->fetchOne("SELECT * FROM group_invitations WHERE id = ?", [$invitationId]);
        if (!$inv) return;

        $this->db->transaction(function () use ($inv, $userId) {
            // Add to group
            $this->addMember($inv['group_id'], $userId, $inv['group_role_id'] ?? $this->getDefaultMemberRoleId($inv['group_id']), $inv['invited_by']);
            // Mark accepted
            $this->db->update('group_invitations', [
                'status'      => 'accepted',
                'user_id'     => $userId,
                'accepted_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$inv['id']]);
        });
    }

    public function getDefaultMemberRoleId(int $groupId): int
    {
        $role = $this->db->fetchOne(
            "SELECT id FROM group_roles WHERE group_id = ? AND slug = 'member' AND is_system = 1",
            [$groupId]
        );
        return $role ? (int) $role['id'] : 0;
    }

    public function getOwnerRoleId(int $groupId): int
    {
        $role = $this->db->fetchOne(
            "SELECT id FROM group_roles WHERE group_id = ? AND slug = 'group_owner' AND is_system = 1",
            [$groupId]
        );
        return $role ? (int) $role['id'] : 0;
    }

    // ── Owner Removal Workflow ────────────────────────────────────────────────

    /**
     * Create an owner-removal request.
     *
     * @param int|null $newRoleId  group_roles.id to demote to on approval,
     *                             or NULL to remove from group entirely.
     */
    public function requestOwnerRemoval(int $groupId, int $requestedBy, int $targetUserId, ?int $newRoleId = null): int
    {
        return $this->db->insert('group_owner_removal_requests', [
            'group_id'       => $groupId,
            'requested_by'   => $requestedBy,
            'target_user_id' => $targetUserId,
            'new_role_id'    => $newRoleId,
            'status'         => 'pending',
        ]);
    }

    public function findOwnerRemovalRequest(int $requestId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM group_owner_removal_requests WHERE id = ?",
            [$requestId]
        );
    }

    public function resolveOwnerRemoval(int $requestId, string $status): void
    {
        $req = $this->findOwnerRemovalRequest($requestId);
        if (!$req || $req['status'] !== 'pending') return;

        $this->db->update('group_owner_removal_requests', [
            'status'      => $status,
            'resolved_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$requestId]);

        if ($status !== 'approved') return;

        // Apply whatever the requester chose at request time:
        //   new_role_id NULL      → remove from group entirely
        //   new_role_id IS VALID  → switch target to that role
        // A NULL with a missing fallback still means "remove" — safer than
        // accidentally demoting to a stale "member" role that no longer
        // exists.
        $newRoleId = isset($req['new_role_id']) ? (int) $req['new_role_id'] : 0;
        if ($newRoleId > 0) {
            // Double-check the role still belongs to this group.
            $role = $this->findRole($newRoleId);
            if ($role && (int) $role['group_id'] === (int) $req['group_id']) {
                $this->updateMemberRole((int)$req['group_id'], (int)$req['target_user_id'], $newRoleId);
                return;
            }
        }

        // Fallback: full removal from group.
        $this->removeMember((int)$req['group_id'], (int)$req['target_user_id']);
    }

    // ── User's Groups ─────────────────────────────────────────────────────────

    public function getUserGroups(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT g.*, gr.name AS role_name, gr.base_role, ug.joined_at
             FROM `groups` g
             JOIN user_groups ug ON ug.group_id = g.id
             JOIN group_roles gr ON gr.id = ug.group_role_id
             WHERE ug.user_id = ?
             ORDER BY g.name",
            [$userId]
        );
    }
}
