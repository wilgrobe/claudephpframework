<?php
// modules/email/migrations/2026_04_30_500010_seed_categories_and_permission.php
use Core\Database\Migration;

/**
 * Seed default email categories + the `email.manage` permission.
 *
 * Defaults cover the four most common categories on a SaaS-y site:
 *   transactional      — receipts, password resets, ticket replies. Cannot
 *                        be suppressed by user (CAN-SPAM § 5(a)(5)(B)).
 *   marketing          — newsletters, promos. Always opt-out-able.
 *   product_updates    — product/changelog announcements.
 *   social             — DM notifications, follow/mention alerts.
 *
 * Admins can add more from /admin/email/categories.
 */
return new class extends Migration {
    public function up(): void
    {
        $cats = [
            ['transactional',   'Transactional', 'Order receipts, password resets, ticket replies. Cannot be opted out by users (CAN-SPAM § 5(a)(5)(B) carve-out for transactional / relationship messages).', 1, 1],
            ['marketing',       'Marketing',     'Newsletters, promotions, sales emails. Always opt-out-able.', 0, 2],
            ['product_updates', 'Product updates','New-feature announcements, release notes.', 0, 3],
            ['social',          'Social',        'DM notifications, follow alerts, comment replies.', 0, 4],
        ];

        foreach ($cats as [$slug, $label, $desc, $isTrans, $sort]) {
            $this->db->query("
                INSERT IGNORE INTO mail_categories (slug, label, description, is_transactional, is_system, sort_order)
                VALUES (?, ?, ?, ?, 1, ?)
            ", [$slug, $label, $desc, $isTrans, $sort]);
        }

        // Permission
        $this->db->query("
            INSERT IGNORE INTO permissions (name, slug, module, description)
            VALUES (?, ?, ?, ?)
        ", [
            'Manage email compliance',
            'email.manage',
            'email',
            'Manage email categories, suppressions, view bounce events.',
        ]);

        $permId = (int) $this->db->fetchColumn(
            "SELECT id FROM permissions WHERE slug = ?", ['email.manage']
        );
        $adminRoleId = (int) $this->db->fetchColumn(
            "SELECT id FROM roles WHERE slug = ?", ['admin']
        );
        if ($permId > 0 && $adminRoleId > 0) {
            $this->db->query("
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                VALUES (?, ?)
            ", [$adminRoleId, $permId]);
        }
    }

    public function down(): void {}
};
