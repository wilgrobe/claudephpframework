<?php
// database/migrations/2026_06_06_000000_seed_core_features_page.php
use Core\Database\Migration;

/**
 * Seed the /core-features marketing page + a Footer Links menu entry
 * for it. The page is a 21-section / 53-block sections+blocks layout
 * built via the composer save path; this migration mirrors that shape
 * so fresh installs land with the same content as the dev DB it was
 * authored on.
 *
 * Idempotent on the page side via `slug` check; idempotent on the
 * menu side via `url` check. Safe to re-run.
 *
 * If `Modules\Builder\Services\SectionLayoutRenderer` is loadable at
 * migrate time (the normal case on builder installs), `rendered_html`
 * is baked at the end of up() so the live page serves immediately.
 * On framework-only installs without the builder app, the render is
 * skipped silently; the next composer save by an admin will populate
 * rendered_html.
 *
 * down() removes the menu entry + the page row (cascades sections +
 * blocks via the FK on page_sections.page_id).
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Idempotency check: page already seeded? ─────────────────
        $existing = $this->db->fetchOne(
            "SELECT id FROM pages WHERE slug = ? LIMIT 1",
            ['core-features']
        );
        if ($existing) {
            // Re-running on a DB that already has the page — leave it
            // alone (it might have admin-side edits we don't want to
            // clobber). Still try to add the menu entry if it's
            // missing, since that's a separate concern.
            $this->ensureMenuEntry();
            return;
        }

        // ── Build the layout ───────────────────────────────────────
        $layout = $this->buildLayout();

        // ── Insert the pages row ───────────────────────────────────
        $pageId = (int) $this->db->insert('pages', [
            'slug'            => 'core-features',
            'title'           => 'Framework core features',
            'body'            => '',
            'status'          => 'published',
            'is_public'       => 1,
            'seo_title'       => 'Framework core features — every common stack piece, built in',
            'seo_description' => 'Compare Claude PHP Framework against Laravel, Symfony, CodeIgniter, and Yii. Auth, RBAC, 2FA, GDPR, audit chains, page composer — all in the box.',
        ]);

        // ── Insert sections + blocks ───────────────────────────────
        $sortS = 0;
        foreach ($layout as $section) {
            $secId = (int) $this->db->insert('page_sections', [
                'page_id'    => $pageId,
                'sort_order' => $sortS++,
                'settings'   => json_encode($section['settings'] ?? []),
            ]);
            $sortB = 0;
            foreach (($section['blocks'] ?? []) as $block) {
                $this->db->insert('section_blocks', [
                    'section_id' => $secId,
                    'sort_order' => $sortB++,
                    'block_key'  => $block['key'],
                    'settings'   => json_encode($block['settings'] ?? []),
                ]);
            }
        }

        // ── Bake rendered_html (best-effort) ───────────────────────
        try {
            if (class_exists(\Modules\Builder\Services\SectionLayoutRenderer::class)) {
                $renderer = new \Modules\Builder\Services\SectionLayoutRenderer();
                $html = $renderer->render($pageId);
                $this->db->query("UPDATE pages SET rendered_html = ? WHERE id = ?", [$html, $pageId]);
            }
        } catch (\Throwable $e) {
            // Render-time issue (block missing, BlockRegistry not
            // ready, etc.) — leave rendered_html NULL. Next admin save
            // through the composer will regenerate.
            error_log('[seed_core_features_page] render failed: ' . $e->getMessage());
        }

        $this->ensureMenuEntry();
    }

    public function down(): void
    {
        // Remove the menu entry first (if present).
        $this->db->query(
            "DELETE mi FROM menu_items mi
              JOIN menus m ON m.id = mi.menu_id
             WHERE m.location = 'footer' AND mi.url = ?",
            ['/core-features']
        );

        // Delete the page; FK on page_sections.page_id cascades sections,
        // FK on section_blocks.section_id cascades blocks.
        $this->db->query("DELETE FROM pages WHERE slug = ?", ['core-features']);
    }

    /**
     * Insert the /core-features link into the Footer Links menu if
     * it's not already there. Lands at the highest `sort_order` of
     * existing footer items + 1, so it appears at the end of the
     * marketing cluster. Operators can reorder via the menu builder.
     */
    private function ensureMenuEntry(): void
    {
        $footer = $this->db->fetchOne(
            "SELECT id FROM menus WHERE location = 'footer' ORDER BY id ASC LIMIT 1"
        );
        if (!$footer) return; // No footer menu present — nothing to do.
        $menuId = (int) $footer['id'];

        $exists = $this->db->fetchOne(
            "SELECT id FROM menu_items WHERE menu_id = ? AND url = ? LIMIT 1",
            [$menuId, '/core-features']
        );
        if ($exists) return;

        // Pick a sort_order that lands the entry after existing marketing
        // links but before the legal cluster (privacy / terms / cookie).
        // Heuristic: highest sort_order of items with sort_order < 20 + 1,
        // capped at 19 so it doesn't bleed into the legal cluster.
        $row = $this->db->fetchOne(
            "SELECT COALESCE(MAX(sort_order), 0) AS m FROM menu_items
              WHERE menu_id = ? AND sort_order < 20",
            [$menuId]
        );
        $sortOrder = min(19, (int) ($row['m'] ?? 0) + 1);

        $this->db->insert('menu_items', [
            'menu_id'    => $menuId,
            'parent_id'  => null,
            'label'      => 'Framework core features',
            'url'        => '/core-features',
            'sort_order' => $sortOrder,
            'target'     => '_self',
            'visibility' => 'always',
        ]);
    }

    /**
     * The 21-section / 53-block layout. Mirrors what the dev DB
     * already has — built via the page composer + saved through
     * PageComposerController::save(). Kept as a literal array so the
     * migration is self-contained.
     */
    private function buildLayout(): array
    {
        // Cell symbols for the comparison table.
        $Y = '✓ Built in';
        $P = '+ Add-on';
        $N = '– Not included';

        $mkHeading = fn(string $level, string $text) => [
            'key' => 'siteblocks.heading',
            'settings' => ['level' => $level, 'text_html' => $text],
        ];
        $mkParagraph = fn(string $body) => [
            'key' => 'siteblocks.paragraph',
            'settings' => ['body_html' => $body],
        ];
        $mkCard = fn(string $title, string $body) => [
            'key' => 'siteblocks.detail_card',
            'settings' => ['title_html' => $title, 'body_html' => $body, 'image_url' => ''],
        ];
        $mkTable = function (array $headers, array $rows): array {
            $cells = array_merge([$headers], $rows);
            $html  = '<table><thead><tr>';
            foreach ($headers as $h) $html .= '<th>' . htmlspecialchars((string) $h, ENT_QUOTES) . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>';
                foreach ($r as $c) $html .= '<td>' . htmlspecialchars((string) $c, ENT_QUOTES) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            return [
                'key' => 'siteblocks.table',
                'settings' => [
                    'cols'       => count($headers),
                    'rows'       => count($cells),
                    'cells'      => $cells,
                    'body_html'  => $html,
                    'has_header' => true,
                    'striped'    => true,
                    'bordered'   => true,
                    'compact'    => false,
                ],
            ];
        };

        return [
            // ── 1. Hero ─────────────────────────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 16, 'padding_y_px' => 48, 'padding_top_px' => 0, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    ['key' => 'siteblocks.hero', 'settings' => [
                        'title_html'    => 'Everything most frameworks make you bolt on. Already in the box.',
                        'subtitle_html' => 'Auth, RBAC, two-factor, GDPR, audit chains, cookie consent, page composer, multi-provider mail, notifications, retention. All shipped together. All maintained together. One license.',
                        'cta_label'     => 'See the comparison',
                        'cta_url'       => '#comparison',
                        'min_height_px' => 320,
                        'text_align'    => 'center',
                    ]],
                ],
            ],

            // ── 2. Intro ────────────────────────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 32, 'padding_top_px' => 0, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    $mkParagraph('Most PHP frameworks ship the HTTP layer, an ORM, and a templating engine — the rest is "an exercise for the reader." That phrase covers a lot of ground: auth UI, role-based access, the admin shell, audit logging, GDPR compliance, cookie consent, two-factor authentication, multi-provider email, a page builder, observability, and the long tail of operator tooling that real production apps need.'),
                    $mkParagraph('You assemble all of it from third-party packages. Each package has its own maintainer, its own conventions, its own security history, and its own opinions about how your code should look. Six months in, half your codebase is glue between someone else\'s ideas.'),
                    $mkParagraph('Claude PHP Framework takes the other approach. One cohesive opinionated stack, every piece designed to work with every other piece, by the same maintainers, under one MIT license, with one canonical way to do each thing. The page below walks through what that means in practice — and how the comparison sits against the most-used PHP frameworks.'),
                ],
            ],

            // ── 3. The comparison table ────────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 32, 'padding_top_px' => 0, 'padding_bottom_px' => 0, 'background' => 'color', 'background_value' => '#f9fafb'],
                'blocks' => [
                    $mkHeading('h2', '<span id="comparison">The headline comparison</span>'),
                    $mkParagraph('What ships out of the box across the five most popular PHP frameworks. <strong>✓ Built in</strong> = first-party, supported by core. <strong>+ Add-on</strong> = a popular third-party package fills the gap (you choose, install, configure, and maintain it). <strong>– Not included</strong> = no canonical option; you build it.'),
                    $mkTable(
                        ['Capability', 'Claude PHP', 'Laravel', 'Symfony', 'CodeIgniter', 'Yii'],
                        [
                            ['User auth (password + reset)',          $Y, $Y, $Y, $P, $Y],
                            ['OAuth login (Google / GitHub / etc.)', $Y, $P, $P, $P, $P],
                            ['Two-factor auth (TOTP + recovery)',     $Y, $P, $P, $N, $P],
                            ['Email + SMS challenge codes',           $Y, $N, $N, $N, $N],
                            ['Role / permission matrix (RBAC)',       $Y, $P, $P, $P, $Y],
                            ['Group-based ownership + group roles',   $Y, $N, $N, $N, $N],
                            ['API key management',                    $Y, $P, $P, $N, $N],
                            ['Login anomaly detection',               $Y, $N, $N, $N, $N],
                            ['Audit log with tamper-proof chain',     $Y, $P, $P, $N, $N],
                            ['GDPR data export + erasure',            $Y, $N, $N, $N, $N],
                            ['CCPA "Do Not Sell" flow',               $Y, $N, $N, $N, $N],
                            ['COPPA age gate',                        $Y, $N, $N, $N, $N],
                            ['Cookie consent banner',                 $Y, $N, $N, $N, $N],
                            ['Policy pages + acceptance tracking',    $Y, $N, $N, $N, $N],
                            ['Data retention sweeps',                 $Y, $N, $N, $N, $N],
                            ['Multi-provider mail (5+ providers)',    $Y, $P, $P, $P, $P],
                            ['Mail bounce + complaint handling',      $Y, $N, $N, $N, $N],
                            ['Multi-provider SMS',                    $Y, $N, $N, $N, $N],
                            ['In-app notifications + preferences',    $Y, $P, $P, $N, $N],
                            ['Admin shell + chrome',                  $Y, $P, $P, $N, $P],
                            ['Settings UI (scope-aware)',             $Y, $N, $N, $N, $N],
                            ['Page composer (drag-drop blocks)',      $Y, $N, $N, $N, $N],
                            ['Site block library (50+ blocks)',       $Y, $N, $N, $N, $N],
                            ['Theme system (presets + brand color)',  $Y, $N, $N, $N, $N],
                            ['Menu builder',                          $Y, $N, $N, $N, $N],
                            ['Feature flags',                         $Y, $P, $P, $N, $N],
                            ['Import / export tooling',               $Y, $P, $P, $N, $N],
                            ['Taxonomy (generic tags + categories)',  $Y, $P, $N, $N, $N],
                            ['Health monitoring + cron freshness',    $Y, $N, $N, $N, $N],
                            ['Queue worker + scheduler',              $Y, $Y, $Y, $P, $Y],
                            ['ORM / query builder',                   $Y, $Y, $Y, $Y, $Y],
                            ['Migrations',                            $Y, $Y, $Y, $Y, $Y],
                        ],
                    ),
                    $mkParagraph('No PHP framework is bad at the things in the bottom four rows — every modern stack handles HTTP, database, queues, and migrations. The differentiation is the dozens of rows above: the parts that decide what you spend your year building.'),
                ],
            ],

            // ── 4. Identity & access ───────────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 48, 'padding_top_px' => 16, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    $mkHeading('h2', 'Identity &amp; access'),
                    $mkParagraph('User accounts, authentication, and authorization — the layer almost every app needs and the layer Laravel\'s Jetstream, Spatie\'s laravel-permission, and Symfony\'s Security bundle each solve differently. Claude PHP rolls it into one coherent set of primitives.'),
                ],
            ],
            [
                'settings' => ['col_count' => 3, 'gap_px' => 24, 'padding_y_px' => 16, 'padding_top_px' => 0, 'padding_bottom_px' => 16, 'background' => 'none'],
                'blocks' => [
                    $mkCard('👤 Users + auth',
                        'bcrypt at cost 12 (auto-rehashes on login when cost changes). Password reset with email link + rate-limited submissions. OAuth 2.0 login with Google, Microsoft, GitHub, Facebook, LinkedIn, Apple — and an `email_verified` gate so unverified-provider emails can\'t take over verified accounts.'),
                    $mkCard('🔐 Two-factor',
                        'TOTP (Authenticator app), email-delivered codes, and SMS-delivered codes — all in core, all first-party. Recovery codes with atomic consumption via optimistic-lock CAS (no double-use even under concurrent submission). Per-user rate limit on send + verify.'),
                    $mkCard('🛡 RBAC + groups',
                        'roles + permissions + role_permissions tables. Per-user role assignment + per-permission grants. Groups own resources in parallel to users with their own role hierarchy (group_owner / group_admin / group_member). API keys with per-key scopes.'),
                ],
            ],

            // ── 5. Privacy & compliance ────────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 32, 'padding_top_px' => 0, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    $mkHeading('h2', 'Privacy &amp; compliance'),
                    $mkParagraph('GDPR, CCPA, COPPA, cookie consent — the regulatory work most frameworks leave entirely to you. Claude PHP ships modules for each, with a clear extension point per other module to declare what data it owns and how that data should be exported, anonymised, or purged.'),
                ],
            ],
            [
                'settings' => ['col_count' => 3, 'gap_px' => 24, 'padding_y_px' => 16, 'padding_top_px' => 0, 'padding_bottom_px' => 16, 'background' => 'none'],
                'blocks' => [
                    $mkCard('🇪🇺 GDPR',
                        'Data Subject Access Request flow — user clicks Export, gets an emailed zip of every row they own across every module. Erasure with a 30-day cancel-link sent at request time so account takeovers can\'t silently nuke the rightful owner. Per-module anonymisation via a single `gdprHandlers()` hook each module declares.'),
                    $mkCard('🇺🇸 CCPA + COPPA',
                        '"Do Not Sell or Share My Personal Information" opt-out page wired into the policy framework. COPPA age gate that refuses signups under 13 (configurable). Policy pages (privacy, terms, cookie policy) with per-user acceptance tracking — when terms change, users see the diff and re-accept.'),
                    $mkCard('🍪 Cookie consent',
                        'Banner with HMAC-signed preferences (so consent state survives stripped cookies and can be cryptographically verified at audit time). Per-category opt-in (analytics, marketing, etc.). The banner is dropped into the footer; module authors can gate features on the category flags they care about.'),
                ],
            ],

            // ── 6. Security & observability ────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 32, 'padding_top_px' => 16, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    $mkHeading('h2', 'Security &amp; observability'),
                    $mkParagraph('The things you only realise you need after the first incident. Each is shipped, documented, and wired through the admin shell so operators can actually use them.'),
                ],
            ],
            [
                'settings' => ['col_count' => 3, 'gap_px' => 24, 'padding_y_px' => 16, 'padding_top_px' => 0, 'padding_bottom_px' => 16, 'background' => 'none'],
                'blocks' => [
                    $mkCard('🚨 Login anomaly detection',
                        'New-device + new-location notifications fire when the device family (browser + major version + OS) differs from prior sessions. Per-user-acknowledged via a one-click "Yes that was me" link. Configurable thresholds; falls back to safe defaults.'),
                    $mkCard('🔗 Audit chain',
                        'Every audit_log row hashes the prior row\'s id + hash + payload, producing a tamper-evident chain. The admin viewer surfaces "chain ok / N breaks detected" at a glance + provides a per-row "verify chain integrity" action. Breaks are surfaced + alertable, not silently ignored.'),
                    $mkCard('📈 Health + cron freshness',
                        'Built-in SystemHealthChecker with 6 sections (tenants, jobs, scheduled_tasks, tls_certs, audit_log security, spend budget). Cron freshness watchdog detects when the scheduler stops firing — so silent cron stalls don\'t hide behind "everything still looks fine." Notifications fire on degradation transitions.'),
                ],
            ],

            // ── 7. Content & site building ─────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 32, 'padding_top_px' => 16, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    $mkHeading('h2', 'Content &amp; site building'),
                    $mkParagraph('The user-facing surface most frameworks leave as a downstream concern — "go pick a CMS or build it yourself." Claude PHP ships a page composer, a 50+ block library, a menu builder, and a token-driven theme system, all first-party.'),
                ],
            ],
            [
                'settings' => ['col_count' => 3, 'gap_px' => 24, 'padding_y_px' => 16, 'padding_top_px' => 0, 'padding_bottom_px' => 16, 'background' => 'none'],
                'blocks' => [
                    $mkCard('🧩 Page composer',
                        'Drag-drop sections + blocks. 15 Quick Blocks (Paragraph, Heading, Detail Card, Bulleted List, Blockquote, Button, Table, Container, HTML, Markdown, Image, Hero, CTA Banner, Spacer, Code Block) plus 35+ context-specific blocks. Inline content-editable for text blocks. Editable Table cells with row/col dimension controls. Container block nesting. Floating WYSIWYG toolbar (B/I/U/link). Image upload inline.'),
                    $mkCard('🧭 Menu builder',
                        'Header / footer / sidebar / custom locations, each with a `handle` for the `siteblocks.menu` block to render. Format settings per menu: orientation (horizontal / vertical), bounded (free / boxed), spacing (adaptive / even), child display (dropdown / flat / accordion), and link-state colors (active / completed / disabled).'),
                    $mkCard('🎨 Theme system',
                        '22 design presets. Brand color drops 9 chrome tokens via HSL derivation (primary_dark, accent.contrast, accent.subtle, header_bg/text, sidebar_bg/text, footer_bg/text — all hue-coordinated). 23-font library with primary / secondary / tertiary slots + weight + italic per slot. Per-page color / style / font / per-element overrides + custom CSS escape hatch.'),
                ],
            ],

            // ── 8. Operator tools ─────────────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 32, 'padding_top_px' => 16, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    $mkHeading('h2', 'Operator tools'),
                    $mkParagraph('The "/admin" surface most frameworks treat as a build-it-yourself afterthought. Filament for Laravel and EasyAdmin for Symfony are excellent but each is a separate framework you have to learn on top of the framework you already know. Claude PHP\'s admin shell is the framework — same routing, same auth, same composer, same theming.'),
                ],
            ],
            [
                'settings' => ['col_count' => 3, 'gap_px' => 24, 'padding_y_px' => 16, 'padding_top_px' => 0, 'padding_bottom_px' => 16, 'background' => 'none'],
                'blocks' => [
                    $mkCard('📜 Audit log viewer',
                        'Filterable timeline at /admin/audit-log. Per-field unified diff on the show view. Prev/Next navigation between adjacent rows. CSV export of filtered results. Quick-filter chips for the most-common action prefixes (auth, project, broadcast, deploy, etc.). Chain integrity pill at the top.'),
                    $mkCard('🔔 Notifications',
                        '10+ notification types pre-classified by domain (Social / Messaging / Groups / Projects / Marketing / Operations). Per-(type, channel) preference grid at /notifications/preferences — each row is in_app + email toggles. Snooze with auto-resume. Activity timeline merging notifications + audit events chronologically.'),
                    $mkCard('⚙️ Settings + import/export',
                        'Scope-aware key/value store (site / user / project / etc.) with optional scope_key for further nesting. JSON values supported alongside scalars; type coercion handled at read time. Admin import/export tools (CSV + JSON) with per-module schemas — round-trip user data without writing one-off scripts.'),
                ],
            ],

            // ── 9. Communication ──────────────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 32, 'padding_top_px' => 16, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    $mkHeading('h2', 'Communication primitives'),
                    $mkParagraph('Mail is solved by every framework. What\'s not solved by every framework is the long tail around mail: bounce + complaint handling across five providers, multi-provider SMS routing, outbound webhook delivery with retry, and the in-app notification surface that ties it all together.'),
                ],
            ],
            [
                'settings' => ['col_count' => 3, 'gap_px' => 24, 'padding_y_px' => 16, 'padding_top_px' => 0, 'padding_bottom_px' => 16, 'background' => 'none'],
                'blocks' => [
                    $mkCard('✉️ Mail',
                        'SMTP, Amazon SES, SendGrid, Postmark, Mailgun, SMTP2GO + a log driver for dev. One configuration shape across all of them. Provider webhooks (bounce / complaint / unsubscribe / delivery / open / click) handled for all 5 providers — and `mail_suppressions` are honoured at send time so soft-bounced addresses don\'t keep eating budget.'),
                    $mkCard('📱 SMS',
                        'Twilio + Vonage + a generic HTTP driver. Per-user opt-in checkbox + audit row at signup. Send via `SmsService::send($to, $body)` regardless of provider; per-tenant override of the active driver via settings.'),
                    $mkCard('🔗 Webhooks',
                        'Outbound webhook gateway with retry queue, idempotent delivery (deduplicated by event id), HMAC signing of payloads. Subscribers register via /api/webhooks/subscribe with per-target shared secrets. Inbound webhook receivers for Stripe, mail providers, deploy hosts.'),
                ],
            ],

            // ── 10. Data tools ────────────────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 32, 'padding_top_px' => 16, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    $mkHeading('h2', 'Data lifecycle tools'),
                    $mkParagraph('Retention sweeps, taxonomy, import/export. The reasonable expectations production apps eventually accumulate.'),
                ],
            ],
            [
                'settings' => ['col_count' => 3, 'gap_px' => 24, 'padding_y_px' => 16, 'padding_top_px' => 0, 'padding_bottom_px' => 16, 'background' => 'none'],
                'blocks' => [
                    $mkCard('🗄 Data retention',
                        'Per-table retention policies declared in config; a `php artisan system:prune` CLI walks every configured table and deletes rows older than the retention window. Soft-deleted rows skipped via per-table extra_where clauses. Dry-run by default, `--apply` to execute. Operator audit row written on every real run.'),
                    $mkCard('🏷 Taxonomy',
                        'Generic categories + tags via a `taxonomy_entity_terms` join table. Works for any module without per-module schema duplication. `attach_term($entityType, $entityId, $taxonomy, $termSlug)` is the entire API. Knowledge base articles, blog posts, FAQs, custom modules — all categorisable through the same path.'),
                    $mkCard('📥 Import / export',
                        'Admin-side CSV + JSON import / export at /admin/imports + /admin/exports. Per-module field maps. Bulk user import with column mapping (email / first / last / status / tags / custom_fields). One-shot exports for compliance requests + post-incident audits.'),
                ],
            ],

            // ── 11. Developer ergonomics ──────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 32, 'padding_top_px' => 16, 'padding_bottom_px' => 0, 'background' => 'none'],
                'blocks' => [
                    $mkHeading('h2', 'Developer ergonomics'),
                    $mkParagraph('The framework you build on shapes how you think. We optimised for "find the one canonical way to do this and ship" over "evaluate the seventeen competing packages and write a Decision Doc."'),
                ],
            ],
            [
                'settings' => ['col_count' => 3, 'gap_px' => 24, 'padding_y_px' => 16, 'padding_top_px' => 0, 'padding_bottom_px' => 16, 'background' => 'none'],
                'blocks' => [
                    $mkCard('🧰 Module system',
                        'Drop a folder under /modules/, declare a `module.php` that returns a `ModuleProvider` subclass, and everything else auto-discovers: routes, views (namespaced by module name), migrations, blocks for the page composer, public pages, GDPR handlers, admin commands, submodule descriptors, and integration hooks. Modules can ship dependencies on each other via `requires()`.'),
                    $mkCard('🎫 Migrations',
                        'Single-direction migrations with batch tracking. Idempotent helpers (`tableExists`, `columnExists`, `indexExists`) built into the base Migration class. Per-tenant migration support via `tenant:migrate` for multi-tenant deploys. Date-prefixed names sort deterministically.'),
                    $mkCard('💻 CLI (artisan)',
                        'Command discovery via the same `commands()` ModuleProvider hook. Built-in flag parsing with single-dash detection (`-apply` triggers a clear error pointing at `--apply`). Colored output. Exit codes 0 / 2 / 3 differentiate success / partial / total failure so cron alerting can fire on real problems.'),
                ],
            ],

            // ── 12. The philosophical pitch ───────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 20, 'padding_y_px' => 48, 'padding_top_px' => 32, 'padding_bottom_px' => 16, 'background' => 'color', 'background_value' => '#f9fafb'],
                'blocks' => [
                    $mkHeading('h2', 'Why this matters'),
                    $mkParagraph('Most PHP frameworks make you the integrator. They give you the HTTP-layer building blocks and a vague sense that "something will work it out." That something is usually you, at 11pm, on Stack Overflow, evaluating whether <code>spatie/laravel-permission</code> or <code>tygh/role-based-access-control</code> is the active project this quarter. Or whether the GDPR compliance package you adopted in 2021 still ships security updates in 2026.'),
                    $mkParagraph('That work is real, valuable, and we\'ve done it. Then we shipped one stack where the answers are first-party, designed together, and licensed together. Less time deciding, more time shipping. Fewer dependencies to audit. One canonical way to do each thing, so the next person on the team — human or AI — can find their footing in minutes instead of weeks.'),
                    $mkParagraph('No framework is perfect for every project. If you\'re building Laravel-flavour code already and your team is happy with it, great — keep going. But if you\'re starting fresh, or your last project landed in dependency hell, or you want to spend your year shipping product features instead of integrating other people\'s opinions about how product features should be shipped — this is what that looks like.'),
                ],
            ],

            // ── 13. CTA ────────────────────────────────────────────
            [
                'settings' => ['col_count' => 1, 'gap_px' => 16, 'padding_y_px' => 48, 'padding_top_px' => 0, 'padding_bottom_px' => 48, 'background' => 'none'],
                'blocks' => [
                    ['key' => 'siteblocks.cta_banner', 'settings' => [
                        'title_html'       => 'See it in action',
                        'description_html' => 'Start a project — the wizard takes you from idea to working app in one sitting. No subscription, no surprise renewals. Manual design tools are free; AI helpers and premium-module builds debit tokens only when you use them.',
                        'cta_label'         => 'Create your free account',
                        'cta_url'           => '/signup',
                        'tone'              => 'primary',
                    ]],
                ],
            ],
        ];
    }
};
