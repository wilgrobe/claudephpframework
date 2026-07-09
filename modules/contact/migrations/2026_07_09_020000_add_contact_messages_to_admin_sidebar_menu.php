<?php
// modules/contact/migrations/2026_07_09_020000_add_contact_messages_to_admin_sidebar_menu.php
use Core\Database\Migration;

/**
 * Add the Contact messages inbox to the admin_sidebar menu.
 *
 * The contact module ships an admin queue at /admin/contact-messages but never
 * surfaced a nav link, so admins had to know the URL. Add a "Contact messages"
 * child under an "Engagement" holder (find-or-create — grouped with the
 * Feedback inbox), gated on the `module` visibility mode so it renders only
 * where the contact module is active. The route is [Auth, RequireAdmin], so
 * the link is admin-only regardless.
 *
 * Idempotent: skips if the link already exists; find-or-create on the holder so
 * re-runs never duplicate. Runs on every tenant/built-site DB via
 * `tenant:migrate` and on central/apex via `migrate`.
 */
return new class extends Migration {
    public function up(): void
    {
        $menu = $this->db->fetchOne("SELECT id FROM menus WHERE location = 'admin_sidebar' LIMIT 1");
        if (!$menu) return; // no admin_sidebar menu on this DB — nothing to do
        $mid = (int) $menu['id'];

        $exists = $this->db->fetchColumn(
            "SELECT id FROM menu_items WHERE menu_id = ? AND url = '/admin/contact-messages' LIMIT 1",
            [$mid]
        );
        if ($exists) return;

        // Find-or-create the "Engagement" admin holder (shared with Feedback).
        $holderId = $this->db->fetchColumn(
            "SELECT id FROM menu_items WHERE menu_id = ? AND kind = 'holder' AND label = 'Engagement' LIMIT 1",
            [$mid]
        );
        if (!$holderId) {
            $maxHolderSort = (int) $this->db->fetchColumn(
                "SELECT COALESCE(MAX(sort_order), 0) FROM menu_items WHERE menu_id = ? AND parent_id IS NULL",
                [$mid]
            );
            $holderId = $this->db->insert('menu_items', [
                'menu_id'    => $mid,
                'parent_id'  => null,
                'label'      => 'Engagement',
                'url'        => null,
                'kind'       => 'holder',
                'icon'       => '💬',
                'target'     => '_self',
                'sort_order' => $maxHolderSort + 10,
                'visibility' => 'admin',
                'is_active'  => 1,
            ]);
        }

        $childSort = (int) $this->db->fetchColumn(
            "SELECT COALESCE(MAX(sort_order), 0) FROM menu_items WHERE menu_id = ? AND parent_id = ?",
            [$mid, (int) $holderId]
        );
        $this->db->insert('menu_items', [
            'menu_id'         => $mid,
            'parent_id'       => (int) $holderId,
            'label'           => 'Contact messages',
            'url'             => '/admin/contact-messages',
            'kind'            => 'link',
            'icon'            => null,
            'target'          => '_self',
            'sort_order'      => $childSort + 10,
            'visibility'      => 'module',
            'condition_value' => 'contact',
            'is_active'       => 1,
        ]);
    }

    public function down(): void
    {
        $menu = $this->db->fetchOne("SELECT id FROM menus WHERE location = 'admin_sidebar' LIMIT 1");
        if (!$menu) return;
        $mid = (int) $menu['id'];
        $this->db->query(
            "DELETE FROM menu_items WHERE menu_id = ? AND url = '/admin/contact-messages'",
            [$mid]
        );
        $holderId = $this->db->fetchColumn(
            "SELECT id FROM menu_items WHERE menu_id = ? AND kind = 'holder' AND label = 'Engagement' LIMIT 1",
            [$mid]
        );
        if ($holderId) {
            $cnt = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM menu_items WHERE menu_id = ? AND parent_id = ?",
                [$mid, (int) $holderId]
            );
            if ($cnt === 0) {
                $this->db->query("DELETE FROM menu_items WHERE id = ?", [(int) $holderId]);
            }
        }
    }
};
