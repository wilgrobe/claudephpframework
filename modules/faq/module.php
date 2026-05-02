<?php
// modules/faq/module.php
use Core\Module\ModuleProvider;

/**
 * FAQ module — categorized Q&A with admin CRUD and a public /faq page.
 * The public JSON_ARRAYAGG query in FaqController::publicIndex includes a
 * fallback for MySQL versions without that aggregate — preserved verbatim
 * from the original App\Controllers\Admin\FaqController.
 */
return new class extends ModuleProvider {
    public function name(): string { return 'faq'; }

    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        return [
            // Compact recent-FAQs tile. Optional category filter.
            new \Core\Module\BlockDescriptor(
                key:         'faq.recent',
                label:       'Recent FAQs',
                description: 'List of recent FAQ entries, optionally filtered by category id.',
                category:    'Knowledge Base',
                defaultSize: 'medium',
                defaultSettings: ['limit' => 5, 'category_id' => null],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $limit  = max(1, (int) ($settings['limit'] ?? 5));
                    $catId  = isset($settings['category_id']) && $settings['category_id'] !== null && $settings['category_id'] !== ''
                        ? (int) $settings['category_id']
                        : null;

                    try {
                        if ($catId !== null) {
                            $rows = \Core\Database\Database::getInstance()->fetchAll(
                                "SELECT id, question, category_id FROM faqs
                                  WHERE is_public = 1 AND is_active = 1 AND category_id = ?
                                  ORDER BY sort_order, id DESC LIMIT ?",
                                [$catId, $limit]
                            );
                        } else {
                            $rows = \Core\Database\Database::getInstance()->fetchAll(
                                "SELECT id, question, category_id FROM faqs
                                  WHERE is_public = 1 AND is_active = 1
                                  ORDER BY sort_order, id DESC LIMIT ?",
                                [$limit]
                            );
                        }
                    } catch (\Throwable) {
                        $rows = [];
                    }

                    $h = '<div class="card"><div class="card-header" style="display:flex;justify-content:space-between;align-items:center">'
                       . '<h3 style="margin:0;font-size:.95rem">Recent FAQs</h3>'
                       . '<a href="/faq" style="font-size:12px;color:#4f46e5;text-decoration:none">Browse all →</a>'
                       . '</div><div class="card-body" style="padding:0">';

                    if (empty($rows)) {
                        $h .= '<p style="padding:1rem 1.25rem;color:#9ca3af;font-size:13px;margin:0">No FAQs yet.</p>';
                    } else {
                        foreach ($rows as $r) {
                            $q = htmlspecialchars((string) ($r['question'] ?? ''), ENT_QUOTES | ENT_HTML5);
                            $h .= '<a href="/faq#faq-' . (int) $r['id'] . '" style="display:block;padding:.55rem 1.25rem;border-bottom:1px solid #f3f4f6;text-decoration:none;color:#111827;font-size:13.5px">'
                                . $q . '</a>';
                        }
                    }
                    return $h . '</div></div>';
                }
            ),

            // Inline accordion — admin picks specific FAQ ids to render
            // as expandable Q/A pairs on the page itself. Shipped as a
            // marketing-page primitive rather than a directory tile.
            // Settings: { ids: [1,2,3], heading: "Frequently asked" }.
            // Native <details>/<summary> for zero-JS expand behaviour.
            new \Core\Module\BlockDescriptor(
                key:         'faq.accordion',
                label:       'FAQ Accordion (inline)',
                description: 'Expandable Q/A accordion of admin-picked FAQ ids. Settings: ids[], heading.',
                category:    'Knowledge Base',
                defaultSize: 'large',
                defaultSettings: ['ids' => [], 'heading' => 'Frequently asked questions'],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $ids = $settings['ids'] ?? [];
                    if (!is_array($ids)) $ids = [];
                    $ids = array_values(array_filter(array_map('intval', $ids), fn($n) => $n > 0));
                    $heading = (string) ($settings['heading'] ?? 'Frequently asked questions');

                    if (empty($ids)) {
                        return '<div class="card"><div class="card-body" style="padding:1rem 1.25rem;color:#9ca3af;font-size:13px">No FAQ ids configured for this accordion.</div></div>';
                    }

                    try {
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $rows = \Core\Database\Database::getInstance()->fetchAll(
                            "SELECT id, question, answer FROM faqs
                              WHERE is_public = 1 AND is_active = 1 AND id IN ($placeholders)",
                            $ids
                        );
                        // Preserve admin-specified order
                        $byId = [];
                        foreach ($rows as $r) $byId[(int) $r['id']] = $r;
                        $ordered = [];
                        foreach ($ids as $id) {
                            if (isset($byId[$id])) $ordered[] = $byId[$id];
                        }
                        $rows = $ordered;
                    } catch (\Throwable) {
                        $rows = [];
                    }

                    $h = '<section style="max-width:760px;margin:0 auto;padding:1rem">';
                    $h .= '<h2 style="margin:0 0 1rem 0;font-size:1.3rem;font-weight:700">' . htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5) . '</h2>';
                    foreach ($rows as $r) {
                        $q = htmlspecialchars((string) $r['question'], ENT_QUOTES | ENT_HTML5);
                        $a = \Core\Validation\Validator::sanitizeHtml((string) ($r['answer'] ?? ''));
                        $h .= '<details style="border:1px solid #e5e7eb;border-radius:6px;padding:.6rem 1rem;margin-bottom:.5rem">'
                            . '<summary style="font-weight:600;font-size:14px;cursor:pointer;color:#111827">' . $q . '</summary>'
                            . '<div style="font-size:13.5px;color:#374151;margin-top:.5rem;line-height:1.6">' . $a . '</div>'
                            . '</details>';
                    }
                    return $h . '</section>';
                }
            ),
        ];
    }
};
