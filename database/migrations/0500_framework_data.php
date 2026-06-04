<?php
// database/migrations/0500_framework_data.php
//
// Phase 37 consolidated framework data — replaces every seed/DML migration
// in database/migrations/ with one canonical population pass.
//
// Absorbed migrations (DML/seed halves):
//   2026_04_20_000000_create_baseline_tables.php     — permissions/roles/role_permissions/groups/
//                                                       group_roles/users/user_roles/user_groups/
//                                                       settings/menus/menu_items/pages/faq seeds
//   2026_04_22_100020_seed_retry_messages_scheduled_task.php
//   2026_04_22_110000_seed_search_reindex_scheduled_task.php
//   2026_04_25_340000_seed_dashboard_layout.php      — dashboard_stats + dashboard_main + placements
//   2026_04_28_500000_grant_admin_orphaned_permissions.php
//                                                    — admin gets every non-SA-only permission
//                                                    (re-applied by 0999_finalize.php so it catches
//                                                    module-added permissions too)
//   2026_05_02_200000_seed_default_policy_pages.php  — terms / privacy / cookie-policy pages
//   2026_05_02_550000_seed_search_chrome.php         — `search` system layout + slot
//   2026_05_02_600000_add_chromed_url_to_system_layouts.php
//                                                    — chromed_url for `search` (others done by their
//                                                    owning module migrations)
//
// Runs AFTER 0001_framework_schema.php and BEFORE any 0010_<module>_schema.php
// (those run earlier in the global lexicographic order, then this file's
// permissions-grant logic runs before any 0510_<module>_data.php adds new
// module-scoped permission rows).
//
// Idempotency: every seed uses INSERT IGNORE or "skip if exists" checks so
// re-running against a partially-populated DB is safe.

use Core\Database\Migration;
use Core\Support\Markdown;

return new class extends Migration {

    /** Sentinel prefix marking a policy page row as seed-managed. */
    private const POLICY_SEED_MARKER = 'cphpfw:policy-seed:';

    public function up(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->seedPermissions();
            $this->seedRoles();
            $this->seedRolePermissions();
            $this->seedGroups();
            $this->seedGroupRoles();
            $this->seedUsers();
            $this->seedUserRoles();
            $this->seedUserGroups();
            $this->seedSettings();
            $this->seedMenus();
            $this->seedMenuItems();
            $this->seedDefaultPages();
            $this->seedFaq();
            $this->seedScheduledTasks();
            $this->seedSystemLayouts();
            $this->seedPolicyPages();
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function down(): void
    {
        // Data-only down — the schema is owned by 0001_framework_schema.php's down().
        // Deliberately conservative: scoped deletes that don't clobber admin work.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->db->query("DELETE FROM system_block_placements WHERE system_name IN ('dashboard_stats','dashboard_main','search')");
            $this->db->query("DELETE FROM system_layouts WHERE name IN ('dashboard_stats','dashboard_main','search')");
            $this->db->query("DELETE FROM scheduled_tasks WHERE name IN ('retry-messages','search-reindex-nightly')");
            $this->unseedPolicyPages();
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    // ── PERMISSIONS ───────────────────────────────────────────────

    private function seedPermissions(): void
    {
        $rows = [
            // Users
            ['View Users',           'users.view',         'users',         'View user list and profiles'],
            ['Create Users',         'users.create',       'users',         'Create new user accounts'],
            ['Edit Users',           'users.edit',         'users',         'Edit user accounts'],
            ['Delete Users',         'users.delete',       'users',         'Delete user accounts'],
            ['Manage Users',         'users.manage',       'users',         'Full user management'],
            // Roles
            ['View Roles',           'roles.view',         'roles',         'View roles'],
            ['Create Roles',         'roles.create',       'roles',         'Create new roles'],
            ['Edit Roles',           'roles.edit',         'roles',         'Edit roles'],
            ['Delete Roles',         'roles.delete',       'roles',         'Delete roles'],
            // Groups
            ['View Groups',          'groups.view',        'groups',        'View groups'],
            ['Create Groups',        'groups.create',      'groups',        'Create new groups'],
            ['Edit Groups',          'groups.edit',        'groups',        'Edit groups'],
            ['Delete Groups',        'groups.delete',      'groups',        'Delete groups'],
            ['Manage Group Members', 'groups.members',     'groups',        'Add/remove group members'],
            // Content
            ['View Content',         'content.view',       'content',       'View content'],
            ['Create Content',       'content.create',     'content',       'Create content'],
            ['Edit Content',         'content.edit',       'content',       'Edit any content'],
            ['Delete Content',       'content.delete',     'content',       'Delete content'],
            ['Publish Content',      'content.publish',    'content',       'Publish/unpublish content'],
            // Pages
            ['Manage Pages',         'pages.manage',       'pages',         'Create/edit/delete static pages'],
            // Menus
            ['Manage Menus',         'menus.manage',       'menus',         'Create and manage navigation menus'],
            // FAQ
            ['Manage FAQ',           'faq.manage',         'faq',           'Manage FAQ entries'],
            // Reports/Audit
            ['View Reports',         'reports.view',       'reports',       'Access reports'],
            ['View Audit Log',       'audit.view',         'audit',         'View system audit log'],
            // Notifications
            ['Send Notifications',   'notifications.send', 'notifications', 'Broadcast notifications'],
        ];
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO permissions (`name`, slug, module, `description`) VALUES (?, ?, ?, ?)"
        );
        foreach ($rows as $r) $stmt->execute($r);
    }

    // ── ROLES ─────────────────────────────────────────────────────

    private function seedRoles(): void
    {
        $rows = [
            ['Super Admin',   'super-admin', 'Full system access with emulation capability', 1],
            ['Administrator', 'admin',       'Full administrative access',                   1],
            ['Editor',        'editor',      'Create and edit content',                      0],
            ['Viewer',        'viewer',      'Read-only access',                             0],
        ];
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO roles (`name`, slug, `description`, is_system) VALUES (?, ?, ?, ?)"
        );
        foreach ($rows as $r) $stmt->execute($r);
    }

    private function seedRolePermissions(): void
    {
        // Super Admin → all permissions
        $this->db->query(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = 'super-admin'"
        );
        // Admin → everything except SA-only
        $this->db->query(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p
             WHERE r.slug = 'admin' AND p.slug NOT IN ('users.delete','roles.delete','audit.view')"
        );
        // Editor → content + faq + view-like
        $this->db->query(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p
             WHERE r.slug = 'editor'
               AND (p.module IN ('content','faq') OR p.slug IN ('users.view','groups.view','pages.manage'))"
        );
        // Viewer → all *.view perms
        $this->db->query(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p
             WHERE r.slug = 'viewer' AND p.slug LIKE '%.view'"
        );
    }

    // ── GROUPS + GROUP ROLES ──────────────────────────────────────

    private function seedGroups(): void
    {
        $rows = [
            ['Engineering', 'engineering', 'Engineering team', 0],
            ['Marketing',   'marketing',   'Marketing team',   0],
            ['Management',  'management',  'Management team',  0],
        ];
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO `groups` (`name`, slug, `description`, is_public) VALUES (?, ?, ?, ?)"
        );
        foreach ($rows as $r) $stmt->execute($r);
    }

    private function seedGroupRoles(): void
    {
        $template = [
            ['Group Owner', 'group_owner', 'Full ownership of the group',        'group_owner'],
            ['Group Admin', 'group_admin', 'Administrative access within group', 'group_admin'],
            ['Manager',     'manager',     'Manage members and content',         'manager'],
            ['Editor',      'editor',      'Create and edit group content',      'editor'],
            ['Member',      'member',      'Standard group member',              'member'],
        ];
        $groups = $this->db->fetchAll(
            "SELECT id FROM `groups` WHERE slug IN ('engineering','marketing','management') ORDER BY id"
        );
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO group_roles (group_id, `name`, slug, `description`, base_role, is_system)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        foreach ($groups as $g) {
            foreach ($template as $t) {
                $stmt->execute([(int) $g['id'], $t[0], $t[1], $t[2], $t[3]]);
            }
        }
    }

    // ── USERS + ROLE / GROUP MEMBERSHIP ───────────────────────────

    private function seedUsers(): void
    {
        // Password hash for "Admin@123" — same for all three seed users.
        $pw = '$2y$12$HFVsCqoJuxXGuRkIZfNsDeUYwsaj0RDwpDVbvld4ETM40dCr.30ru';
        $rows = [
            ['admin',  'admin@example.com',  $pw, 'System', 'Admin',  1, 1],
            ['editor', 'editor@example.com', $pw, 'Jane',   'Editor', 1, 0],
            ['viewer', 'viewer@example.com', $pw, 'John',   'Viewer', 1, 0],
        ];
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO users
                (username, email, `password`, first_name, last_name, is_active, is_superadmin, email_verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        foreach ($rows as $r) $stmt->execute($r);
    }

    private function seedUserRoles(): void
    {
        $this->db->query(
            "INSERT IGNORE INTO user_roles (user_id, role_id)
             SELECT u.id, r.id FROM users u, roles r
             WHERE u.email = 'admin@example.com'  AND r.slug = 'super-admin'"
        );
        $this->db->query(
            "INSERT IGNORE INTO user_roles (user_id, role_id)
             SELECT u.id, r.id FROM users u, roles r
             WHERE u.email = 'editor@example.com' AND r.slug = 'editor'"
        );
        $this->db->query(
            "INSERT IGNORE INTO user_roles (user_id, role_id)
             SELECT u.id, r.id FROM users u, roles r
             WHERE u.email = 'viewer@example.com' AND r.slug = 'viewer'"
        );
    }

    private function seedUserGroups(): void
    {
        // admin → Engineering as group_owner
        $this->db->query(
            "INSERT IGNORE INTO user_groups (user_id, group_id, group_role_id)
             SELECT u.id, g.id, gr.id
               FROM users u, `groups` g
               JOIN group_roles gr ON gr.group_id = g.id AND gr.slug = 'group_owner'
              WHERE u.email = 'admin@example.com' AND g.slug = 'engineering'"
        );
        // editor → Engineering as editor
        $this->db->query(
            "INSERT IGNORE INTO user_groups (user_id, group_id, group_role_id)
             SELECT u.id, g.id, gr.id
               FROM users u, `groups` g
               JOIN group_roles gr ON gr.group_id = g.id AND gr.slug = 'editor'
              WHERE u.email = 'editor@example.com' AND g.slug = 'engineering'"
        );
        // viewer → Marketing as member
        $this->db->query(
            "INSERT IGNORE INTO user_groups (user_id, group_id, group_role_id)
             SELECT u.id, g.id, gr.id
               FROM users u, `groups` g
               JOIN group_roles gr ON gr.group_id = g.id AND gr.slug = 'member'
              WHERE u.email = 'viewer@example.com' AND g.slug = 'marketing'"
        );
    }

    // ── SITE SETTINGS ─────────────────────────────────────────────

    private function seedSettings(): void
    {
        $rows = [
            // Core site identity
            ['site', 'site_name',           'My Application',               'string',  1],
            ['site', 'site_tagline',        'Built with ClaudePHPFramework',  'string',  1],
            ['site', 'contact_email',       'hello@example.com',            'string',  0],
            ['site', 'allow_registration',  'true',                         'boolean', 1],
            ['site', 'require_email_verify','true',                         'boolean', 0],
            ['site', 'default_group_role',  'member',                       'string',  0],
            ['site', 'maintenance_mode',    'false',                        'boolean', 0],
            // Group policy
            ['site', 'single_group_only',   'false', 'boolean', 0],
            ['site', 'allow_group_creation','true',  'boolean', 0],
            // Appearance
            ['site', 'layout_orientation',  'sidebar',   'string', 1],
            ['site', 'theme.palette.primary.bg',       '#4f46e5',   'string', 1],
            ['site', 'theme.palette.primary.grad',  '#3730a3',   'string', 1],
            ['site', 'theme.palette.secondary.bg',     '#0ea5e9',   'string', 1],
            ['site', 'theme.palette.default.success',       '#10b981',   'string', 1],
            ['site', 'theme.palette.default.danger',        '#ef4444',   'string', 1],
            ['site', 'theme.palette.default.warning',       '#f59e0b',   'string', 1],
            ['site', 'theme.palette.default.info',          '#3b82f6',   'string', 1],
            // Footer
            ['site', 'footer_enabled',       'true',                            'boolean', 1],
            ['site', 'footer_logo_text',     '🚀 My Application',              'string',  1],
            ['site', 'footer_tagline',       '',                                'string',  1],
            ['site', 'footer_copyright',     '© {{year}} My Application',       'string',  1],
            ['site', 'footer_powered_by',    'Powered by ClaudePHPFramework',     'string',  1],
            ['site', 'footer_show_menu',     'true',                            'boolean', 1],
            ['site', 'footer_menu_location', 'footer',                          'string',  1],
        ];
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO settings (`scope`, `key`, `value`, `type`, is_public) VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($rows as $r) $stmt->execute($r);
    }

    // ── MENUS + ITEMS ─────────────────────────────────────────────

    private function seedMenus(): void
    {
        $rows = [
            ['Main Navigation', 'header', 'Primary site navigation'],
            ['Footer Links',    'footer', 'Footer navigation links'],
        ];
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO menus (`name`, location, `description`) VALUES (?, ?, ?)"
        );
        foreach ($rows as $r) $stmt->execute($r);
    }

    private function seedMenuItems(): void
    {
        $items = [
            ['Main Navigation', 'Home',      '/',                 1, 'always',     null],
            ['Main Navigation', 'Dashboard', '/dashboard',        2, 'logged_in',  null],
            ['Main Navigation', 'Groups',    '/groups',           3, 'logged_in',  null],
            ['Main Navigation', 'Admin',     '/admin/superadmin', 4, 'role',       'admin'],
            ['Footer Links',    'FAQ',       '/faq',              1, 'always',     null],
            ['Footer Links',    'Privacy',   '/privacy-policy',   2, 'always',     null],
            ['Footer Links',    'Terms',     '/terms-of-service', 3, 'always',     null],
        ];
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO menu_items (menu_id, parent_id, label, url, sort_order, visibility, condition_value)
             SELECT m.id, NULL, ?, ?, ?, ?, ? FROM menus m WHERE m.`name` = ?"
        );
        foreach ($items as [$menu, $label, $url, $sort, $vis, $cond]) {
            $stmt->execute([$label, $url, $sort, $vis, $cond, $menu]);
        }
    }

    // ── DEFAULT PAGES (legacy slugs from baseline) ────────────────

    private function seedDefaultPages(): void
    {
        $rows = [
            ['Privacy Policy',   'privacy-policy',   '<h1>Privacy Policy</h1><p>Your privacy matters to us.</p>',                       'published', 1],
            ['Terms of Service', 'terms-of-service', '<h1>Terms of Service</h1><p>By using this service you agree to these terms.</p>', 'published', 1],
            ['About Us',         'about',            '<h1>About Us</h1><p>Welcome to our platform.</p>',                                'published', 1],
        ];
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO pages (title, slug, `body`, `status`, is_public) VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($rows as $r) $stmt->execute($r);
    }

    // ── FAQ ───────────────────────────────────────────────────────

    private function seedFaq(): void
    {
        $cats = [['General', 'general', 1], ['Account', 'account', 2]];
        $stmtCat = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO faq_categories (`name`, slug, sort_order) VALUES (?, ?, ?)"
        );
        foreach ($cats as $r) $stmtCat->execute($r);

        $faqs = [
            ['general', 'What is this platform?',           'This is a multi-group collaboration platform.',                                          1],
            ['account', 'How do I reset my password?',      'Click "Forgot Password" on the login page to receive a reset link.',                     1],
            ['account', 'Can I belong to multiple groups?', 'Yes! You can be a member of multiple groups and have different roles in each.',         2],
        ];
        $stmtFaq = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO faqs (category_id, question, answer, sort_order, is_public)
             SELECT c.id, ?, ?, ?, 1 FROM faq_categories c WHERE c.slug = ?"
        );
        foreach ($faqs as [$cat, $q, $a, $sort]) {
            $stmtFaq->execute([$q, $a, $sort, $cat]);
        }
    }

    // ── SCHEDULED TASKS ───────────────────────────────────────────

    private function seedScheduledTasks(): void
    {
        $tasks = [
            [
                'retry-messages',
                \Core\Queue\Jobs\CallCommandJob::class,
                json_encode(['command' => 'retry-messages', 'args' => ['20']]),
                '* * * * *',
            ],
            [
                'search-reindex-nightly',
                \Core\Queue\Jobs\CallCommandJob::class,
                json_encode(['command' => 'search:reindex', 'args' => ['all']]),
                '0 3 * * *',
            ],
        ];
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO scheduled_tasks
                (name, class, payload, schedule_expression, queue, enabled, next_run_at)
             VALUES (?, ?, ?, ?, 'default', 1, NOW())"
        );
        foreach ($tasks as $t) $stmt->execute($t);
    }

    // ── SYSTEM LAYOUTS (dashboard + search) ───────────────────────

    private function seedSystemLayouts(): void
    {
        // dashboard_stats — 1×4 stat cards
        $this->db->query(
            "INSERT IGNORE INTO system_layouts
                (name, `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['dashboard_stats', 1, 4, json_encode([24, 24, 24, 24]), json_encode([100]), 1, 1280]
        );
        $this->db->query("DELETE FROM system_block_placements WHERE system_name = 'dashboard_stats'");
        $statsPlacements = [
            ['groups.my_groups_count',     0, 0],
            ['notifications.unread_count', 0, 1],
            ['groups.total_users_count',   0, 2],
            ['groups.total_count',         0, 3],
        ];
        foreach ($statsPlacements as [$key, $row, $col]) {
            $this->db->insert('system_block_placements', [
                'system_name' => 'dashboard_stats',
                'row_index'   => $row,
                'col_index'   => $col,
                'sort_order'  => 0,
                'block_key'   => $key,
                'settings'    => null,
                'visible_to'  => 'auth',
            ]);
        }

        // dashboard_main — 1×2 (content + sidebar stack)
        $this->db->query(
            "INSERT IGNORE INTO system_layouts
                (name, `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['dashboard_main', 1, 2, json_encode([70, 27]), json_encode([100]), 3, 1280]
        );
        $this->db->query("DELETE FROM system_block_placements WHERE system_name = 'dashboard_main'");
        $mainPlacements = [
            ['content.dashboard_feed',     0, 0, 0],
            ['groups.my_groups_list',      0, 1, 0],
            ['notifications.recent_list',  0, 1, 1],
        ];
        foreach ($mainPlacements as [$key, $row, $col, $order]) {
            $this->db->insert('system_block_placements', [
                'system_name' => 'dashboard_main',
                'row_index'   => $row,
                'col_index'   => $col,
                'sort_order'  => $order,
                'block_key'   => $key,
                'settings'    => null,
                'visible_to'  => 'auth',
            ]);
        }

        // search — 1×1 single content slot
        $this->db->query(
            "INSERT IGNORE INTO system_layouts
                (name, friendly_name, module, category, description, chromed_url,
                 `rows`, cols, col_widths, row_heights, gap_pct, max_width_px)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, ?, 0, 1024)",
            [
                'search',
                'Site search results',
                'core',
                'Public',
                'Wraps /search. Drop a "Can\'t find it? Contact us" CTA below the results, or a featured/popular-searches sidebar.',
                '/search',
                json_encode([100]),
                json_encode([100]),
            ]
        );
        $this->db->query("DELETE FROM system_block_placements WHERE system_name = 'search'");
        $this->db->insert('system_block_placements', [
            'system_name'    => 'search',
            'row_index'      => 0,
            'col_index'      => 0,
            'sort_order'     => 0,
            'block_key'      => '__slot__',
            'placement_type' => 'content_slot',
            'slot_name'      => 'primary',
            'visible_to'     => 'any',
        ]);
    }

    // ── DEFAULT POLICY PAGES (terms / privacy / cookie-policy) ────

    private function seedPolicyPages(): void
    {
        $seedDir = BASE_PATH . '/database/seeds/policies';
        $pages = [
            ['slug' => 'terms',         'title' => 'Terms of Service', 'source' => 'terms-of-service.md', 'seo_description' => 'The terms governing your use of this site and its services.'],
            ['slug' => 'privacy',       'title' => 'Privacy Policy',   'source' => 'privacy-policy.md',   'seo_description' => 'How we collect, use, and protect personal information, and what rights you have over it.'],
            ['slug' => 'cookie-policy', 'title' => 'Cookie Policy',    'source' => 'cookie-policy.md',    'seo_description' => 'The cookies and similar technologies this site uses, and how you can control them.'],
        ];
        foreach ($pages as $p) {
            $existing = $this->db->fetchOne("SELECT id FROM pages WHERE slug = ?", [$p['slug']]);
            if ($existing) continue;

            $sourcePath = $seedDir . '/' . $p['source'];
            if (!is_file($sourcePath)) {
                throw new \RuntimeException("[0500_framework_data] missing policy seed: $sourcePath");
            }
            $markdown = (string) file_get_contents($sourcePath);
            $html     = Markdown::render($markdown);
            $hash     = hash('sha256', $html);

            $this->db->query(
                "INSERT INTO pages
                    (title, slug, body, layout, status, is_public,
                     seo_title, seo_description, seo_keywords,
                     sort_order, published_at)
                 VALUES (?, ?, ?, 'default', 'published', 1, ?, ?, ?, 0, NOW())",
                [
                    $p['title'],
                    $p['slug'],
                    $html,
                    $p['title'],
                    $p['seo_description'],
                    self::POLICY_SEED_MARKER . $hash,
                ]
            );
        }
    }

    /**
     * Roll back ONLY policy rows that still match the seed's hash, so
     * admin-edited pages aren't clobbered.
     */
    private function unseedPolicyPages(): void
    {
        $slugs = ['terms', 'privacy', 'cookie-policy'];
        foreach ($slugs as $slug) {
            $row = $this->db->fetchOne(
                "SELECT id, body, seo_keywords FROM pages WHERE slug = ?",
                [$slug]
            );
            if (!$row) continue;
            $stored = (string) ($row['seo_keywords'] ?? '');
            if (!str_starts_with($stored, self::POLICY_SEED_MARKER)) continue;
            $expectedHash = substr($stored, strlen(self::POLICY_SEED_MARKER));
            $actualHash   = hash('sha256', (string) ($row['body'] ?? ''));
            if (!hash_equals($expectedHash, $actualHash)) continue;
            $this->db->query("DELETE FROM pages WHERE id = ?", [$row['id']]);
        }
    }
};
