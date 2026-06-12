<?php
// 2026_06_12_020000_seed_admin_sidebar_menu.php
//
// Make the admin navigation admin-editable by driving it from the menu system
// instead of the hardcoded $adminNav array in header.php.
//
// 1. Extends menu_items.visibility ENUM with three admin-chrome kinds:
//      module     — shown when module_active(condition_value)
//      admin      — shown for the 'admin' OR 'super-admin' role
//      superadmin — shown only for superadmins (Auth::isSuperAdmin())
//    (MODIFY COLUMN is idempotent; fresh installs already get the wide ENUM
//     from 0001, existing DBs get widened here.)
//
// 2. Seeds an `admin_sidebar` menu reproducing today's nav item-for-item:
//      - 6 admin group holders (visibility=admin), children carry per-module
//        gating via visibility=module where the array had module_active(...).
//      - the flat Superadmin block, REGROUPED into holders (visibility=superadmin)
//        so it's no longer one long list.
//    Children of a superadmin holder use visibility=always (or module for the
//    module-gated entries); the holder's superadmin gate + the tree's
//    orphan-drop ensure those children never leak to a non-superadmin.
//
// header.php renders this menu when seeded and falls back to the hardcoded
// array when it isn't, so the nav can never be left broken.
//
// Idempotent: skips the seed when an `admin_sidebar` menu already exists.

use Core\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        // 1. Widen the ENUM (safe to re-run).
        $this->db->query(
            "ALTER TABLE `menu_items` MODIFY COLUMN `visibility` "
            . "enum('always','logged_in','logged_out','role','permission','group','module','admin','superadmin') "
            . "COLLATE utf8mb4_unicode_ci DEFAULT 'always'"
        );

        // 2. Seed — skip if already present.
        $existing = $this->db->fetchOne(
            "SELECT id FROM menus WHERE location = 'admin_sidebar' LIMIT 1"
        );
        if ($existing) return;

        $menuId = $this->db->insert('menus', [
            'name'        => 'Admin Sidebar',
            'handle'      => 'admin-sidebar',
            'location'    => 'admin_sidebar',
            'description' => 'Admin + superadmin navigation. Reorder / hide / group from here.',
            'is_active'   => 1,
        ]);

        // [icon, label, holder-visibility, [ [childLabel, url, childVisibility, conditionValue], ... ]]
        $sections = [
            // ── Admin groups (visibility=admin) ──────────────────────────────
            ['👥', 'Users & Access', 'admin', [
                ['Users',           '/admin/users',    'always', null],
                ['Roles',           '/admin/roles',    'always', null],
                ['Groups',          '/admin/groups',   'module', 'groups'],
                ['Active Sessions', '/admin/sessions', 'always', null],
            ]],
            ['📝', 'Content', 'admin', [
                ['Pages',          '/admin/pages', 'module', 'pages'],
                ['Knowledge Base', '/admin/kb',    'module', 'knowledge_base'],
                ['FAQ',            '/admin/faqs',  'module', 'faq'],
            ]],
            ['🛒', 'Commerce', 'admin', [
                ['Store products',     '/admin/store/products',     'module', 'store'],
                ['Store orders',       '/admin/store/orders',       'module', 'store'],
                ['Shipping zones',     '/admin/store/shipping',     'module', 'store'],
                ['Tax rules',          '/admin/store/tax',          'module', 'store'],
                ['Store settings',     '/admin/store/settings',     'module', 'store'],
                ['Subscription plans', '/admin/subscription-plans', 'module', 'subscriptions'],
                ['Subscriptions',      '/admin/subscriptions',      'module', 'subscriptions'],
                ['Coupons',            '/admin/coupons',            'module', 'coupons'],
                ['Invoices',           '/admin/invoices',           'module', 'invoicing'],
            ]],
            ['💬', 'Engagement', 'admin', [
                ['Polls',         '/admin/polls',    'module', 'polls'],
                ['Events',        '/admin/events',   'module', 'events'],
                ['Reviews',       '/admin/reviews',  'module', 'reviews'],
                ['Comments',      '/admin/comments', 'module', 'comments'],
                ['Activity feed', '/admin/activity', 'module', 'activity_feed'],
            ]],
            ['🛠️', 'Operations', 'admin', [
                ['Helpdesk',             '/admin/helpdesk',                   'module', 'helpdesk'],
                ['Moderation reports',   '/admin/moderation/reports',         'module', 'moderation'],
                ['Report notifications', '/admin/moderation/notify-settings', 'module', 'moderation'],
                ['Scheduling',           '/admin/scheduling/resources',       'module', 'scheduling'],
                ['Audit log',            '/admin/audit-log',                  'module', 'audit_log_viewer'],
                ['Import / export',      '/admin/import',                     'module', 'import_export'],
            ]],
            ['⚙️', 'Configuration', 'admin', [
                ['Menus',         '/admin/menus',         'module', 'menus'],
                ['Forms',         '/admin/forms',         'module', 'forms'],
                ['Advertising',   '/admin/advertising',   'module', 'advertising'],
                ['Newsletters',   '/admin/newsletters',   'module', 'newsletter'],
                ['Taxonomy',      '/admin/taxonomy/sets', 'module', 'taxonomy'],
                ['Hierarchies',   '/admin/hierarchies',   'module', 'hierarchies'],
                ['Feature flags', '/admin/feature-flags', 'module', 'feature_flags'],
                ['Translations',  '/admin/i18n',          'module', 'i18n'],
            ]],

            // ── Superadmin (visibility=superadmin), regrouped from the flat list ──
            ['📊', 'Overview', 'superadmin', [
                ['SA Dashboard', '/admin/superadmin',       'always', null],
                ['All Users',    '/admin/superadmin/users', 'always', null],
            ]],
            ['📋', 'Logs', 'superadmin', [
                ['Audit Log',   '/admin/superadmin/audit-log',   'always', null],
                ['Message Log', '/admin/superadmin/message-log', 'always', null],
            ]],
            ['⚙️', 'System & Settings', 'superadmin', [
                ['Site Settings',  '/admin/settings',       'always', null],
                ['Integrations',   '/admin/integrations',   'module', 'integrations'],
                ['Modules',        '/admin/modules',        'always', null],
                ['System Layouts', '/admin/system-layouts', 'always', null],
            ]],
        ];

        $sort = 0;
        foreach ($sections as [$icon, $label, $holderVis, $children]) {
            $sort += 10;
            $holderId = $this->db->insert('menu_items', [
                'menu_id'    => $menuId,
                'parent_id'  => null,
                'label'      => $label,
                'url'        => null,
                'kind'       => 'holder',
                'icon'       => $icon,
                'target'     => '_self',
                'sort_order' => $sort,
                'visibility' => $holderVis,
                'is_active'  => 1,
            ]);

            foreach ($children as [$cLabel, $cUrl, $cVis, $cCond]) {
                $sort += 10;
                $this->db->insert('menu_items', [
                    'menu_id'         => $menuId,
                    'parent_id'       => $holderId,
                    'label'           => $cLabel,
                    'url'             => $cUrl,
                    'kind'            => 'link',
                    'target'          => '_self',
                    'sort_order'      => $sort,
                    'visibility'      => $cVis,
                    'condition_value' => $cCond,
                    'is_active'       => 1,
                ]);
            }
        }
    }

    public function down(): void
    {
        $menu = $this->db->fetchOne("SELECT id FROM menus WHERE location = 'admin_sidebar' LIMIT 1");
        if ($menu) {
            // menu_items cascade on menu delete via the FK.
            $this->db->query("DELETE FROM menus WHERE id = ?", [(int) $menu['id']]);
        }
        // ENUM left widened — harmless, and reverting could orphan saved values.
    }
};
