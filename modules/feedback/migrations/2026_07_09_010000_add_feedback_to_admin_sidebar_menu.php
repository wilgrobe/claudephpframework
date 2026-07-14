<?php
// modules/feedback/migrations/2026_07_09_010000_add_feedback_to_admin_sidebar_menu.php
use Core\Database\Migration;

/**
 * Add the site Feedback inbox to the admin_sidebar menu.
 *
 * Surfaces an "Engagement" holder (find-or-create) with a single "Feedback"
 * child pointing at /admin/site-feedback. The feedback module is a free/always-
 * present module, so a plain module-gate would always show the link; instead
 * the child is gated on the `setting` visibility mode → it renders only when
 * the site has toggled the feature on (`builder.feedback.enabled` truthy),
 * matching the wizard checkbox that drives the public page + footer link.
 *
 * The route itself is [Auth, RequireAdmin], so the link is admin-only in any
 * case. Runs on every tenant/built-site DB via `tenant:migrate` and on central/
 * apex via `migrate`. Idempotent: skips if the link already exists; find-or-
 * create on the holder so re-runs never duplicate.
 */
return new class extends Migration {
    public function up(): void
    {
        $menu = $this->db->fetchOne("SELECT id FROM menus WHERE location = 'admin_sidebar' LIMIT 1");
        if (!$menu) return; // no admin_sidebar menu on this DB — nothing to do
        $mid = (int) $menu['id'];

        // menu_items.visibility is a strict ENUM; widen it to accept 'setting'
        // (the feature-toggle gate) before inserting. Idempotent — only ALTERs
        // when the value is missing. Fresh installs already carry it via the
        // baseline schema; this catches DBs migrated before it was added.
        $col  = $this->db->fetchOne("SHOW COLUMNS FROM menu_items LIKE 'visibility'");
        $type = strtolower((string) ($col['Type'] ?? ''));
        // Widen when ANY value this migration inserts is missing — not just
        // 'setting'. A DB that carried 'setting' but not 'admin'/'superadmin'
        // (its holder insert uses 'admin') otherwise skipped the ALTER and the
        // insert truncated.
        $needed = ["'setting'", "'admin'", "'superadmin'"];
        $missing = false;
        foreach ($needed as $v) { if (strpos($type, $v) === false) { $missing = true; break; } }
        if ($col && $missing) {
            $this->db->query(
                "ALTER TABLE menu_items MODIFY `visibility` "
                . "enum('always','logged_in','logged_out','role','permission','group','module','setting','admin','superadmin') "
                . "COLLATE utf8mb4_unicode_ci DEFAULT 'always'"
            );
        }

        // Already present? Idempotent no-op.
        $exists = $this->db->fetchColumn(
            "SELECT id FROM menu_items WHERE menu_id = ? AND url = '/admin/site-feedback' LIMIT 1",
            [$mid]
        );
        if ($exists) return;

        // Find-or-create an "Engagement" admin holder (feedback is an
        // engagement surface; a bare site may not have one yet).
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
            'label'           => 'Feedback',
            'url'             => '/admin/site-feedback',
            'kind'            => 'link',
            'icon'            => null,
            'target'          => '_self',
            'sort_order'      => $childSort + 10,
            'visibility'      => 'setting',
            'condition_value' => 'builder.feedback.enabled',
            'is_active'       => 1,
        ]);
    }

    public function down(): void
    {
        $menu = $this->db->fetchOne("SELECT id FROM menus WHERE location = 'admin_sidebar' LIMIT 1");
        if (!$menu) return;
        $mid = (int) $menu['id'];
        $this->db->query(
            "DELETE FROM menu_items WHERE menu_id = ? AND url = '/admin/site-feedback'",
            [$mid]
        );
        // Remove the Engagement holder only if it's now empty.
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
