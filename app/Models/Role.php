<?php
// app/Models/Role.php
namespace App\Models;

use Core\Database\Database;

class Role
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function all(): array
    {
        return $this->db->fetchAll("SELECT * FROM roles ORDER BY name");
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM roles WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->db->insert('roles', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('roles', $data, 'id = ?', [$id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('roles', 'id = ? AND is_system = 0', [$id]);
    }

    public function getPermissions(int $roleId): array
    {
        return $this->db->fetchAll(
            "SELECT p.* FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = ?",
            [$roleId]
        );
    }

    public function getAllPermissions(): array
    {
        return $this->db->fetchAll("SELECT * FROM permissions ORDER BY module, name");
    }

    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->delete('role_permissions', 'role_id = ?', [$roleId]);
        foreach ($permissionIds as $pid) {
            $this->db->insert('role_permissions', [
                'role_id'       => $roleId,
                'permission_id' => (int) $pid,
            ]);
        }
    }
}
