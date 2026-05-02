<?php
// modules/coreblocks/module.php
use Core\Module\ModuleProvider;

/**
 * Coreblocks module — admin observability tiles that don't have a
 * natural existing-module owner.
 *
 * The blocks here surface state from cross-cutting subsystems: the
 * jobs queue, message_log, sessions, users, module_status. None of
 * those has a dedicated module (they're framework-level), so a
 * dedicated tiny module that ships only blocks is the cleanest home.
 *
 * No routes, no admin UI, no migrations of its own — just the
 * SystemStatusService used by the system_status block, plus the
 * descriptors below.
 *
 * Every block is admin-only (returns '' for non-admins). Designed for
 * the SA dashboard and any custom admin pages an SA composes.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'coreblocks'; }
    public function migrationsPath(): ?string { return null; }
    public function viewsPath(): ?string      { return null; }
    public function routesFile(): ?string     { return null; }

    public function blocks(): array
    {
        // Shared admin-gate closure — every block in this module hides
        // for non-admins. Pulling the check out keeps each render
        // closure compact.
        $adminOnly = static function (callable $fn): callable {
            return function (array $context, array $settings) use ($fn): string {
                $auth = \Core\Auth\Auth::getInstance();
                if (!$auth->hasRole(['super-admin','admin'])) return '';
                return $fn($context, $settings);
            };
        };

        return [
            // ── Failed jobs count ──────────────────────────────────────
            new \Core\Module\BlockDescriptor(
                key:         'coreblocks.failed_jobs_count',
                label:       'Failed Jobs (count)',
                description: 'Stat card showing jobs whose attempts are exhausted (terminal failures).',
                category:    'Stats',
                defaultSize: 'small',
                audience:    'admin',
                render: $adminOnly(function (array $context, array $settings): string {
                    try {
                        $n = (int) \Core\Database\Database::getInstance()->fetchColumn(
                            "SELECT COUNT(*) FROM jobs WHERE status = 'failed' AND attempts >= max_attempts"
                        );
                    } catch (\Throwable) { $n = 0; }
                    // Red tint when non-zero so the SA dashboard shows
                    // visible alarm on stuck queues.
                    $bg = $n > 0 ? 'background:#fee2e2;color:#991b1b' : '';
                    return '<div class="stat-card" style="' . $bg . '"><div class="stat-label">Failed Jobs</div>'
                         . '<div class="stat-value">' . $n . '</div></div>';
                })
            ),

            // ── Failed mail count ──────────────────────────────────────
            new \Core\Module\BlockDescriptor(
                key:         'coreblocks.failed_mail_count',
                label:       'Failed Mail (count)',
                description: 'Stat card showing emails that gave up (status=failed, attempts exhausted) in the last 24h.',
                category:    'Stats',
                defaultSize: 'small',
                audience:    'admin',
                render: $adminOnly(function (array $context, array $settings): string {
                    try {
                        $n = (int) \Core\Database\Database::getInstance()->fetchColumn(
                            "SELECT COUNT(*) FROM message_log
                              WHERE channel = 'email'
                                AND status = 'failed'
                                AND attempts >= max_attempts
                                AND last_attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                        );
                    } catch (\Throwable) { $n = 0; }
                    $bg = $n > 0 ? 'background:#fee2e2;color:#991b1b' : '';
                    return '<div class="stat-card" style="' . $bg . '"><div class="stat-label">Failed Mail (24h)</div>'
                         . '<div class="stat-value">' . $n . '</div></div>';
                })
            ),

            // ── Active sessions count ──────────────────────────────────
            // "Active" = a session row whose last_activity is within the
            // configured app.session.lifetime window (defaults to 24
            // minutes via PHP's session.gc_maxlifetime). Distinct user_id
            // so concurrent same-user sessions count once.
            new \Core\Module\BlockDescriptor(
                key:         'coreblocks.active_sessions_count',
                label:       'Active Sessions (count)',
                description: 'Stat card showing distinct authenticated users active in the last 30 minutes.',
                category:    'Stats',
                defaultSize: 'small',
                audience:    'admin',
                render: $adminOnly(function (array $context, array $settings): string {
                    try {
                        $n = (int) \Core\Database\Database::getInstance()->fetchColumn(
                            "SELECT COUNT(DISTINCT user_id) FROM sessions
                              WHERE user_id IS NOT NULL
                                AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
                        );
                    } catch (\Throwable) { $n = 0; }
                    return '<div class="stat-card"><div class="stat-label">Active Users (30m)</div>'
                         . '<div class="stat-value">' . $n . '</div></div>';
                })
            ),

            // ── Recent signups list ────────────────────────────────────
            new \Core\Module\BlockDescriptor(
                key:         'coreblocks.recent_signups',
                label:       'Recent Signups (list)',
                description: 'Sidebar card listing the most recently-created user accounts.',
                category:    'Moderation',
                defaultSize: 'medium',
                defaultSettings: ['limit' => 5],
                audience:    'admin',
                render: $adminOnly(function (array $context, array $settings): string {
                    $limit = max(1, (int) ($settings['limit'] ?? 5));
                    try {
                        $rows = \Core\Database\Database::getInstance()->fetchAll(
                            "SELECT id, username, email, first_name, last_name, created_at
                               FROM users ORDER BY created_at DESC LIMIT ?",
                            [$limit]
                        );
                    } catch (\Throwable) { $rows = []; }

                    $h = '<div class="card"><div class="card-header" style="display:flex;justify-content:space-between;align-items:center">'
                       . '<h3 style="margin:0;font-size:.95rem">Recent Signups</h3>'
                       . '<a href="/admin/users" style="font-size:12px;color:#4f46e5;text-decoration:none">All users →</a>'
                       . '</div><div class="card-body" style="padding:0">';

                    if (empty($rows)) {
                        $h .= '<p style="padding:1rem 1.25rem;color:#9ca3af;font-size:13px;margin:0">No users yet.</p>';
                    } else {
                        foreach ($rows as $u) {
                            $name = trim((string) ($u['first_name'] ?? '')) . ' ' . trim((string) ($u['last_name'] ?? ''));
                            $name = htmlspecialchars(trim($name), ENT_QUOTES | ENT_HTML5);
                            $username = htmlspecialchars((string) ($u['username'] ?? ''), ENT_QUOTES | ENT_HTML5);
                            $when = !empty($u['created_at']) ? date('M j, g:i A', strtotime($u['created_at'])) : '';
                            $id = (int) ($u['id'] ?? 0);
                            $h .= '<a href="/admin/users/' . $id . '" style="display:block;padding:.55rem 1.25rem;border-bottom:1px solid #f3f4f6;text-decoration:none;color:inherit;font-size:13px">'
                                . '<div style="font-weight:500;color:#111827">' . ($name !== '' ? $name : '@' . $username) . '</div>'
                                . '<div style="color:#9ca3af;font-size:11.5px;margin-top:.15rem">'
                                .   ($username !== '' ? '@' . $username . ' · ' : '') . $when
                                . '</div></a>';
                        }
                    }
                    return $h . '</div></div>';
                })
            ),

            // ── Disabled modules summary ──────────────────────────────
            new \Core\Module\BlockDescriptor(
                key:         'coreblocks.disabled_modules_summary',
                label:       'Disabled Modules (summary)',
                description: 'Stat card showing how many modules are currently disabled (dependency or admin) + link to /admin/modules.',
                category:    'Stats',
                defaultSize: 'small',
                audience:    'admin',
                render: $adminOnly(function (array $context, array $settings): string {
                    try {
                        $row = \Core\Database\Database::getInstance()->fetchOne(
                            "SELECT
                                SUM(CASE WHEN state = 'disabled_dependency' THEN 1 ELSE 0 END) AS dep_off,
                                SUM(CASE WHEN state = 'disabled_admin'      THEN 1 ELSE 0 END) AS adm_off
                               FROM module_status"
                        );
                    } catch (\Throwable) {
                        $row = null;
                    }
                    $depOff = (int) ($row['dep_off'] ?? 0);
                    $admOff = (int) ($row['adm_off'] ?? 0);
                    $total  = $depOff + $admOff;
                    $bg     = $total > 0 ? 'background:#fef3c7;color:#92400e' : '';
                    $detail = $total === 0
                        ? 'all active'
                        : ($depOff . ' dep · ' . $admOff . ' admin');

                    return '<div class="stat-card" style="' . $bg . '">'
                         . '<div class="stat-label">Disabled Modules</div>'
                         . '<div class="stat-value">' . $total . '</div>'
                         . '<a href="/admin/modules" style="display:block;font-size:11px;color:inherit;text-decoration:none;margin-top:.35rem;opacity:.85">' . $detail . ' →</a>'
                         . '</div>';
                })
            ),

            // ── System status (composite) ──────────────────────────────
            new \Core\Module\BlockDescriptor(
                key:         'coreblocks.system_status',
                label:       'System Status',
                description: 'Composite health indicator for DB, queue, mail, sessions. Each row shows ✓/✗ and a short note.',
                category:    'Stats',
                defaultSize: 'medium',
                audience:    'admin',
                render: $adminOnly(function (array $context, array $settings): string {
                    try {
                        $snap = (new \Modules\Coreblocks\Services\SystemStatusService())->snapshot();
                    } catch (\Throwable $e) {
                        return '<div class="card"><div class="card-body" style="padding:1rem 1.25rem;color:#991b1b;font-size:13px">System status probe failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5) . '</div></div>';
                    }

                    $headerBg = $snap['all_ok'] ? '#ecfdf5' : '#fef3c7';
                    $headerFg = $snap['all_ok'] ? '#065f46' : '#92400e';

                    $h = '<div class="card">'
                       . '<div class="card-header" style="display:flex;justify-content:space-between;align-items:center;background:' . $headerBg . ';color:' . $headerFg . '">'
                       . '<h3 style="margin:0;font-size:.95rem">System Status</h3>'
                       . '<span style="font-size:11.5px;font-weight:600">' . ($snap['all_ok'] ? 'all systems go' : 'attention needed') . '</span>'
                       . '</div><div class="card-body" style="padding:0">';

                    $labels = [
                        'database' => 'Database',
                        'queue'    => 'Queue + scheduler',
                        'mail'     => 'Email delivery',
                        'sessions' => 'Sessions',
                    ];
                    foreach ($snap['probes'] as $key => $probe) {
                        $label = $labels[$key] ?? ucfirst($key);
                        $icon  = $probe['ok'] ? '✓' : '✗';
                        $iconColor = $probe['ok'] ? '#10b981' : '#dc2626';
                        $note  = htmlspecialchars((string) $probe['note'], ENT_QUOTES | ENT_HTML5);
                        $h .= '<div style="display:flex;justify-content:space-between;align-items:center;padding:.55rem 1.25rem;border-bottom:1px solid #f3f4f6;font-size:13px">'
                            . '<div><span style="color:' . $iconColor . ';font-weight:700;font-size:14px;margin-right:.4rem">' . $icon . '</span>'
                            . '<span style="color:#111827">' . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5) . '</span></div>'
                            . '<div style="color:#6b7280;font-size:11.5px">' . $note . '</div>'
                            . '</div>';
                    }
                    return $h . '</div></div>';
                })
            ),
        ];
    }
};
