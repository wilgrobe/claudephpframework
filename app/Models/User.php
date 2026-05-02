<?php
// app/Models/User.php
namespace App\Models;

use Core\Database\Database;

class User
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, username, email, first_name, last_name, avatar, bio,
                    is_active, is_superadmin, email_verified_at, phone, last_login_at, created_at
             FROM users WHERE id = ?",
            [$id]
        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);
    }

    public function all(int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT id, username, email, first_name, last_name, is_active, is_superadmin, last_login_at, created_at
             FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function search(string $q, int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT id, username, email, first_name, last_name
             FROM users
             WHERE username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?
             LIMIT ?",
            ["%$q%", "%$q%", "%$q%", "%$q%", $limit]
        );
    }

    public function count(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM users");
    }

    public function create(array $data): int
    {
        if (isset($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }
        return $this->db->insert('users', $data);
    }

    public function update(int $id, array $data): int
    {
        if (isset($data['password']) && $data['password']) {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        } elseif (isset($data['password'])) {
            unset($data['password']); // Don't update with empty password
        }
        return $this->db->update('users', $data, 'id = ?', [$id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('users', 'id = ?', [$id]);
    }

    // ── Global Roles ──────────────────────────────────────────────────────────

    public function getRoles(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT r.* FROM roles r
             JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ?",
            [$userId]
        );
    }

    public function syncRoles(int $userId, array $roleIds): void
    {
        $this->db->delete('user_roles', 'user_id = ?', [$userId]);
        foreach ($roleIds as $roleId) {
            $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => (int) $roleId]);
        }
    }

    // ── Group Memberships ─────────────────────────────────────────────────────

    public function getGroups(int $userId): array
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

    // ── OAuth Providers ───────────────────────────────────────────────────────

    public function getOAuthProviders(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT provider, created_at FROM user_oauth WHERE user_id = ?",
            [$userId]
        );
    }

    // ── Permissions (via global roles) ────────────────────────────────────────

    public function getPermissions(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT p.* FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ?",
            [$userId]
        );
    }

    public function withDetails(array $user): array
    {
        $user['roles']           = $this->getRoles($user['id']);
        $user['groups']          = $this->getGroups($user['id']);
        $user['oauth_providers'] = $this->getOAuthProviders($user['id']);
        return $user;
    }
}
