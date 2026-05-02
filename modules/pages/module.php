<?php
// modules/pages/module.php
use Core\Module\ModuleProvider;

/**
 * Pages module — administrable static pages with SEO metadata, custom
 * layouts, and a "set as guest home" toggle. The public renderer for /{slug}
 * stays in routes/web.php because it's a catch-all that interacts with SEO
 * redirects; the admin CRUD lives here.
 */
return new class extends ModuleProvider {
    public function name(): string { return 'pages'; }

    public function routesFile(): ?string   { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string    { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        return [
            // ── Recent Pages ───────────────────────────────────────────
            // Companion to knowledge_base.recent_articles and
            // store.recent_products — surfaces the most recently
            // published pages. Useful on a blog-style landing or a
            // generic "latest from the team" sidebar.
            new \Core\Module\BlockDescriptor(
                key:         'pages.recent',
                label:       'Recent Pages',
                description: 'Most recently published static pages.',
                category:    'Content',
                defaultSize: 'medium',
                defaultSettings: ['limit' => 6],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'limit', 'label' => 'Maximum pages shown', 'type' => 'number', 'default' => 6],
                ],
                render: function (array $context, array $settings): string {
                    $limit = max(1, (int) ($settings['limit'] ?? 6));
                    try {
                        $rows = \Core\Database\Database::getInstance()->fetchAll(
                            "SELECT id, slug, title, COALESCE(updated_at, created_at) AS dated_at
                               FROM pages
                              WHERE status = 'published' AND is_public = 1
                              ORDER BY dated_at DESC
                              LIMIT ?",
                            [$limit]
                        );
                    } catch (\Throwable) {
                        $rows = [];
                    }

                    $h = '<div class="card"><div class="card-header"><h3 style="margin:0;font-size:.95rem">Recent Pages</h3></div>'
                       . '<div class="card-body" style="padding:0">';
                    if (empty($rows)) {
                        $h .= '<p style="padding:1rem 1.25rem;color:#9ca3af;font-size:13px;margin:0">No published pages yet.</p>';
                    } else {
                        foreach ($rows as $r) {
                            $slug  = rawurlencode((string) ($r['slug'] ?? ''));
                            $title = htmlspecialchars((string) ($r['title'] ?? '(untitled)'), ENT_QUOTES | ENT_HTML5);
                            $when  = !empty($r['dated_at']) ? date('M j, Y', strtotime($r['dated_at'])) : '';
                            $h .= '<a href="/' . $slug . '" style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 1.25rem;border-bottom:1px solid #f3f4f6;text-decoration:none;color:inherit;font-size:13.5px">'
                                . '<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#111827">' . $title . '</span>'
                                . ($when !== '' ? '<span style="color:#9ca3af;font-size:11.5px;flex-shrink:0;margin-left:.5rem">' . $when . '</span>' : '')
                                . '</a>';
                        }
                    }
                    return $h . '</div></div>';
                }
            ),

            // ── Featured Pages ─────────────────────────────────────────
            // Curated subset of pages.recent — admins flip the `featured`
            // flag on the page edit form, this block surfaces the most
            // recently-touched featured rows. Returns '' (silent tile)
            // when the curated set is empty so an unconfigured site
            // doesn't show "No featured pages yet" everywhere.
            new \Core\Module\BlockDescriptor(
                key:         'pages.featured',
                label:       'Featured Pages',
                description: 'Curated featured-flag pages, ordered by recency. Silent when empty.',
                category:    'Content',
                defaultSize: 'medium',
                defaultSettings: ['limit' => 5],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'limit', 'label' => 'Maximum pages shown', 'type' => 'number', 'default' => 5],
                ],
                render: function (array $context, array $settings): string {
                    $limit = max(1, (int) ($settings['limit'] ?? 5));
                    try {
                        $rows = \Core\Database\Database::getInstance()->fetchAll(
                            "SELECT id, slug, title, COALESCE(updated_at, created_at) AS dated_at
                               FROM pages
                              WHERE status = 'published' AND is_public = 1 AND featured = 1
                              ORDER BY dated_at DESC
                              LIMIT ?",
                            [$limit]
                        );
                    } catch (\Throwable) {
                        $rows = [];
                    }
                    if (empty($rows)) return '';

                    $h = '<div class="card"><div class="card-header"><h3 style="margin:0;font-size:.95rem">⭐ Featured Pages</h3></div>'
                       . '<div class="card-body" style="padding:0">';
                    foreach ($rows as $r) {
                        $slug  = rawurlencode((string) ($r['slug'] ?? ''));
                        $title = htmlspecialchars((string) ($r['title'] ?? '(untitled)'), ENT_QUOTES | ENT_HTML5);
                        $h .= '<a href="/' . $slug . '" style="display:block;padding:.6rem 1.25rem;border-bottom:1px solid #f3f4f6;text-decoration:none;color:#111827;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
                            . $title . '</a>';
                    }
                    return $h . '</div></div>';
                }
            ),

            // ── Archive List ───────────────────────────────────────────
            // Group counts by year + month for the published pages set.
            // Doesn't link anywhere (no per-month archive route exists);
            // each row is a static "Month YYYY (n)" tally. If a future
            // archive route lands at /archive/{year}/{month}, swap the
            // <span> for an <a> and link there.
            new \Core\Module\BlockDescriptor(
                key:         'pages.archive_list',
                label:       'Archive List',
                description: 'Year/month tally of published pages. Useful in blog-style sidebars.',
                category:    'Content',
                defaultSize: 'small',
                defaultSettings: ['limit' => 12],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'limit', 'label' => 'Maximum month rows shown', 'type' => 'number', 'default' => 12],
                ],
                render: function (array $context, array $settings): string {
                    $limit = max(1, (int) ($settings['limit'] ?? 12));
                    try {
                        $rows = \Core\Database\Database::getInstance()->fetchAll(
                            "SELECT YEAR(COALESCE(updated_at, created_at)) AS y,
                                    MONTH(COALESCE(updated_at, created_at)) AS m,
                                    COUNT(*) AS n
                               FROM pages
                              WHERE status = 'published' AND is_public = 1
                              GROUP BY y, m
                              ORDER BY y DESC, m DESC
                              LIMIT ?",
                            [$limit]
                        );
                    } catch (\Throwable) {
                        $rows = [];
                    }

                    $h = '<div class="card"><div class="card-header"><h3 style="margin:0;font-size:.95rem">Archive</h3></div>'
                       . '<div class="card-body" style="padding:0">';
                    if (empty($rows)) {
                        $h .= '<p style="padding:1rem 1.25rem;color:#9ca3af;font-size:13px;margin:0">No archive entries.</p>';
                    } else {
                        foreach ($rows as $r) {
                            $y = (int) $r['y'];
                            $m = (int) $r['m'];
                            $n = (int) $r['n'];
                            $label = date('F Y', mktime(0, 0, 0, $m, 1, $y));
                            $h .= '<div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 1.25rem;border-bottom:1px solid #f3f4f6;font-size:13px">'
                                . '<span style="color:#111827">' . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5) . '</span>'
                                . '<span style="color:#9ca3af;font-size:11.5px">(' . $n . ')</span>'
                                . '</div>';
                        }
                    }
                    return $h . '</div></div>';
                }
            ),
        ];
    }
    public function linkSources(): array
    {
        return [\Modules\Pages\LinkSources\PagesLinkSource::class];
    }

    /**
     * GDPR handlers — pages stay (they're public site content); the
     * created_by author link is anonymised. The schema's FK is
     * ON DELETE SET NULL but our DataPurger doesn't hard-delete the
     * users row by default, so we have to explicitly NULL the column.
     */
    public function gdprHandlers(): array
    {
        if (!class_exists(\Modules\Gdpr\Services\GdprHandler::class)) return [];

        return [
            new \Modules\Gdpr\Services\GdprHandler(
                module:      'pages',
                description: 'Pages you authored — author link is anonymised, the page itself stays as published content.',
                tables: [
                    ['table' => 'pages', 'user_column' => 'created_by', 'action' => \Modules\Gdpr\Services\GdprHandler::ACTION_ANONYMIZE],
                ]
            ),
        ];
    }
};
