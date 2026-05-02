<?php
// modules/audit-log-viewer/module.php
use Core\Module\ModuleProvider;

/**
 * Audit-log viewer module — read-only admin UI over the core
 * `audit_log` table (populated by Auth::auditLog). No write path
 * here; no new tables (the base schema owns `audit_log`).
 *
 * Filter surface: actor_user_id, action (exact or prefix via 'x.*'),
 * model + model_id, date range, free-text search across
 * action/model/notes.
 */
return new class extends ModuleProvider {
    // Namespace must match View::addNamespace's /^[a-zA-Z0-9_]+$/ regex.
    public function name(): string            { return 'audit_log_viewer'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        return [
            // Recent admin events tile — surfaces the most recent rows
            // from audit_log on the SA dashboard. Admin-only so PII in
            // model_id / notes columns doesn't leak. Reuses the
            // existing AuditLogService for consistency with /admin/audit-log.
            new \Core\Module\BlockDescriptor(
                key:         'audit_log_viewer.recent_events',
                label:       'Recent Audit Events (admin)',
                description: 'Last N rows from audit_log with actor + action.',
                category:    'Moderation',
                defaultSize: 'medium',
                defaultSettings: ['limit' => 8],
                audience:    'admin',
                render: function (array $context, array $settings): string {
                    $auth = \Core\Auth\Auth::getInstance();
                    if (!$auth->hasRole(['super-admin','admin'])) return '';

                    $limit = max(1, (int) ($settings['limit'] ?? 8));
                    try {
                        $result = (new \Modules\AuditLogViewer\Services\AuditLogService())
                            ->list([], 1, $limit);
                        $rows = $result['items'] ?? [];
                    } catch (\Throwable) {
                        $rows = [];
                    }

                    $h = '<div class="card"><div class="card-header" style="display:flex;justify-content:space-between;align-items:center">'
                       . '<h3 style="margin:0;font-size:.95rem">Recent Audit Events</h3>'
                       . '<a href="/admin/superadmin/audit-log" style="font-size:12px;color:#4f46e5;text-decoration:none">View all →</a>'
                       . '</div><div class="card-body" style="padding:0">';

                    if (empty($rows)) {
                        $h .= '<p style="padding:1rem 1.25rem;color:#9ca3af;font-size:13px;margin:0">No audit events yet.</p>';
                    } else {
                        foreach ($rows as $r) {
                            $actor  = htmlspecialchars((string) ($r['actor_username'] ?? 'system'), ENT_QUOTES | ENT_HTML5);
                            $action = htmlspecialchars((string) ($r['action'] ?? ''), ENT_QUOTES | ENT_HTML5);
                            $model  = htmlspecialchars((string) ($r['model'] ?? ''), ENT_QUOTES | ENT_HTML5);
                            $when   = !empty($r['created_at']) ? date('M j, g:i A', strtotime($r['created_at'])) : '';
                            $h .= '<div style="padding:.5rem 1.25rem;border-bottom:1px solid #f3f4f6;font-size:13px">'
                                . '<div style="color:#111827"><strong>@' . $actor . '</strong> '
                                . '<code style="font-size:12px;color:#4f46e5">' . $action . '</code>'
                                . ($model !== '' ? ' <span style="color:#6b7280">on ' . $model . '</span>' : '')
                                . '</div>'
                                . ($when !== '' ? '<div style="color:#9ca3af;font-size:11px;margin-top:.15rem">' . $when . '</div>' : '')
                                . '</div>';
                        }
                    }
                    return $h . '</div></div>';
                }
            ),
        ];
    }
};
