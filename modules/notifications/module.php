<?php
// modules/notifications/module.php
use Core\Module\ModuleProvider;

/**
 * Notifications module — per-user notification inbox + bell counter endpoint.
 * The NotificationService that writes notifications stays in core/Services/
 * (other modules dispatch to it); this module just owns the read/manage UI.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'notifications'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        return [
            // Top-stats card — count of unread notifications. Returns ''
            // for guests so the cell is empty instead of always reading 0.
            new \Core\Module\BlockDescriptor(
                key:         'notifications.unread_count',
                label:       'Unread Notifications (count)',
                description: 'Stat card showing the viewer\'s unread notification count.',
                category:    'Stats',
                defaultSize: 'small',
                audience:    'auth',
                render: function (array $context, array $settings): string {
                    $auth = \Core\Auth\Auth::getInstance();
                    if ($auth->guest()) return '';
                    try {
                        $svc = new \Core\Services\NotificationService();
                        $n = count($svc->getUnread((int) $auth->id(), 99));
                    } catch (\Throwable) {
                        $n = 0;
                    }
                    return '<div class="stat-card"><div class="stat-label">Unread Notifications</div>'
                         . '<div class="stat-value">' . (int) $n . '</div></div>';
                }
            ),

            // Sidebar card — last N notifications with the read/unread
            // styling, dismiss affordance, and the "View all" link the
            // pre-composer dashboard had baked into the static view.
            new \Core\Module\BlockDescriptor(
                key:         'notifications.recent_list',
                label:       'Recent Notifications',
                description: 'Sidebar card listing the most recent notifications with dismiss affordance.',
                category:    'Notifications',
                defaultSize: 'medium',
                defaultSettings: ['limit' => 6],
                audience:    'auth',
                render: function (array $context, array $settings): string {
                    $auth = \Core\Auth\Auth::getInstance();
                    if ($auth->guest()) return '';

                    $limit = max(1, (int) ($settings['limit'] ?? 6));

                    try {
                        $svc = new \Core\Services\NotificationService();
                        $items = $svc->annotate($svc->getAll((int) $auth->id(), $limit));
                    } catch (\Throwable) {
                        $items = [];
                    }

                    $h = '<div class="card"><div class="card-header">'
                       . '<h2 style="margin:0;font-size:1rem">Recent Notifications</h2>'
                       . '<a href="/notifications" style="font-size:12px;color:#4f46e5;text-decoration:none">View all →</a>'
                       . '</div><div class="card-body" style="padding:0">';

                    if (empty($items)) {
                        $h .= '<p style="padding:1rem 1.25rem;color:#6b7280;font-size:13px;margin:0">No notifications yet.</p>';
                    } else {
                        foreach (array_slice($items, 0, $limit) as $n) {
                            $id    = htmlspecialchars((string) ($n['id'] ?? ''), ENT_QUOTES | ENT_HTML5);
                            $title = htmlspecialchars((string) ($n['title'] ?? ''), ENT_QUOTES | ENT_HTML5);
                            $body  = htmlspecialchars((string) ($n['body']  ?? ''), ENT_QUOTES | ENT_HTML5);
                            $unread= empty($n['read_at']);
                            $created = $n['created_at'] ?? null;
                            $whenStr = $created ? date('M j, g:i A', strtotime($created)) : '';

                            $h .= '<div class="notif-row" data-id="' . $id . '" style="position:relative;padding:.75rem 1.25rem;border-bottom:1px solid #f3f4f6;'
                                . ($unread ? 'background:#f5f3ff' : '') . '">';
                            if (!empty($n['can_delete'])) {
                                $h .= '<button type="button" onclick="dismissNotification(this)" '
                                    . 'title="Dismiss" aria-label="Dismiss notification" '
                                    . 'style="position:absolute;top:.3rem;right:.5rem;background:none;border:none;cursor:pointer;color:#9ca3af;font-size:14px;line-height:1;padding:.15rem .3rem;border-radius:4px">×</button>';
                            }
                            $h .= '<div style="font-weight:' . ($unread ? '600' : '500') . ';font-size:13px;color:#111827;padding-right:1rem">' . $title . '</div>';
                            if ($body !== '') {
                                $h .= '<div style="font-size:12px;color:#6b7280;margin-top:.15rem;line-height:1.4">' . $body . '</div>';
                            }
                            if ($whenStr !== '') {
                                $h .= '<div style="font-size:11px;color:#9ca3af;margin-top:.25rem">' . $whenStr . '</div>';
                            }
                            $h .= '</div>';
                        }
                    }
                    return $h . '</div></div>';
                }
            ),
        ];
    }
};
