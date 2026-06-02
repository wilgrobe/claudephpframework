<?php
/**
 * @var array $rows     contact_messages rows for the current filter+page
 * @var int   $total    total rows matching filter
 * @var array $counts   {new, read, replied, archived, total}
 * @var array $filters  {status, q}
 * @var int   $page
 * @var int   $perPage
 */
$pageTitle  = 'Contact messages';
$totalPages = max(1, (int) ceil($total / $perPage));

$filterUrl = static function (array $overrides = []) use ($filters): string {
    $params = array_merge([
        'status' => $filters['status'],
        'q'      => $filters['q'],
    ], $overrides);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return '/admin/contact-messages' . ($params ? '?' . http_build_query($params) : '');
};
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<style>
.cm-shell { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }
.cm-h1    { font-size: 1.4rem; font-weight: 700; margin: 0 0 .25rem; }
.cm-help  { color: var(--color-gray-500); font-size: 13.5px; margin: 0 0 1.25rem; line-height: 1.55; }

.cm-tabs { display: flex; gap: .35rem; flex-wrap: wrap; margin-bottom: 1rem; border-bottom: 1px solid var(--color-gray-200); }
.cm-tab { padding: .55rem .85rem; text-decoration: none; color: var(--color-gray-700); font-size: 13.5px; border-bottom: 2px solid transparent; }
.cm-tab.here { color: var(--color-primary); border-bottom-color: var(--color-primary); font-weight: 600; }
.cm-tab .count { color: var(--color-gray-500); font-size: 12px; margin-left: .25rem; font-variant-numeric: tabular-nums; }
.cm-tab.here .count { color: var(--color-primary); }

.cm-filter { background: var(--color-gray-50); border: 1px solid var(--color-gray-200); border-radius: 8px; padding: .65rem .9rem; margin-bottom: 1rem; display: flex; gap: .85rem; align-items: center; flex-wrap: wrap; }
.cm-filter input[type="search"] { padding: .35rem .55rem; border: 1px solid var(--color-gray-300); border-radius: 4px; font-size: 13px; min-width: 280px; }
.cm-filter button { padding: .4rem .9rem; background: var(--color-primary); color: white; border: 0; border-radius: 4px; cursor: pointer; font-size: 13px; }
.cm-filter a.clear { font-size: 12px; color: var(--color-gray-500); text-decoration: none; margin-left: auto; }

.cm-table { width: 100%; border-collapse: collapse; background: white; border: 1px solid var(--color-gray-200); border-radius: 8px; overflow: hidden; }
.cm-table th { background: var(--color-gray-50); padding: .55rem .75rem; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--color-gray-500); text-align: left; border-bottom: 1px solid var(--color-gray-200); }
.cm-table td { padding: .65rem .75rem; font-size: 13.5px; vertical-align: top; border-bottom: 1px solid var(--color-gray-100); }
.cm-table tr:last-child td { border-bottom: 0; }
.cm-table tr.is-new { background: var(--color-warning-bg); }
.cm-table tr.is-new td:first-child::before { content: '●'; color: var(--color-warning); margin-right: .35rem; }
.cm-table a.subject-link { color: var(--text-default); text-decoration: none; font-weight: 500; }
.cm-table a.subject-link:hover { text-decoration: underline; color: var(--color-primary); }
.cm-table .meta { color: var(--color-gray-500); font-size: 12px; }
.cm-table .pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .35px; }
.cm-table .pill.new      { background: var(--color-warning-bg); color: var(--color-warning-fg); }
.cm-table .pill.read     { background: var(--color-gray-100); color: var(--color-gray-600); }
.cm-table .pill.replied  { background: var(--color-success-bg); color: var(--color-success-fg); }
.cm-table .pill.archived { background: var(--accent-subtle); color: var(--color-primary-dark); }

.cm-empty { background: white; border: 1px dashed var(--color-gray-300); border-radius: 8px; padding: 2rem 1rem; text-align: center; color: var(--color-gray-500); font-size: 13.5px; }

.cm-pagination { display: flex; gap: .35rem; justify-content: center; margin-top: 1rem; flex-wrap: wrap; }
.cm-pagination a, .cm-pagination span { padding: .3rem .65rem; border: 1px solid var(--color-gray-200); border-radius: 4px; font-size: 12.5px; text-decoration: none; color: var(--color-gray-700); background: white; }
.cm-pagination .here { background: var(--color-primary); color: white; border-color: var(--color-primary); }

.cm-settings-cta { float: right; font-size: 13px; color: var(--color-gray-500); text-decoration: none; }
.cm-settings-cta:hover { color: var(--color-primary); }
</style>

<div class="cm-shell">
    <a href="/admin/settings/contact" class="cm-settings-cta">⚙ Contact settings →</a>
    <h1 class="cm-h1">Contact messages</h1>
    <p class="cm-help">Messages submitted via the site's contact form. New submissions also trigger an email to the configured recipient list.</p>

    <nav class="cm-tabs">
        <?php
        $tabs = [
            ''         => ['All',      $counts['total']],
            'new'      => ['New',      $counts['new']],
            'read'     => ['Read',     $counts['read']],
            'replied'  => ['Replied',  $counts['replied']],
            'archived' => ['Archived', $counts['archived']],
        ];
        foreach ($tabs as $slug => [$label, $n]):
            $active = $filters['status'] === $slug;
        ?>
            <a href="<?= htmlspecialchars($filterUrl(['status' => $slug, 'page' => null]), ENT_QUOTES) ?>"
               class="cm-tab <?= $active ? 'here' : '' ?>">
                <?= htmlspecialchars($label, ENT_QUOTES) ?> <span class="count"><?= number_format($n) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <form class="cm-filter" method="get" action="/admin/contact-messages">
        <?php if ($filters['status']): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filters['status'], ENT_QUOTES) ?>">
        <?php endif; ?>
        <input type="search" name="q" placeholder="Search name / email / subject / body"
               value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>">
        <button type="submit">Search</button>
        <?php if ($filters['q'] !== '' || $filters['status'] !== ''): ?>
            <a class="clear" href="/admin/contact-messages">Clear ×</a>
        <?php endif; ?>
    </form>

    <?php if (empty($rows)): ?>
        <div class="cm-empty">
            <?php if ($total === 0 && $filters['q'] === '' && $filters['status'] === ''): ?>
                No contact messages yet. The contact form lives at <a href="/contact">/contact</a>.
            <?php else: ?>
                No rows match the current filter. <a href="/admin/contact-messages">Clear filters ×</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table class="cm-table">
            <thead>
                <tr>
                    <th style="width:90px;">Status</th>
                    <th>From</th>
                    <th>Subject / preview</th>
                    <th style="width:160px;">Received</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr class="<?= $row['status'] === 'new' ? 'is-new' : '' ?>">
                        <td><span class="pill <?= htmlspecialchars($row['status'], ENT_QUOTES) ?>"><?= htmlspecialchars($row['status'], ENT_QUOTES) ?></span></td>
                        <td>
                            <div><strong><?= htmlspecialchars($row['name'], ENT_QUOTES) ?></strong></div>
                            <div class="meta"><?= htmlspecialchars($row['email'], ENT_QUOTES) ?></div>
                        </td>
                        <td>
                            <a class="subject-link" href="/admin/contact-messages/<?= (int) $row['id'] ?>">
                                <?= htmlspecialchars($row['subject'] ?: '(no subject)', ENT_QUOTES) ?>
                            </a>
                            <div class="meta">
                                <?= htmlspecialchars(mb_substr((string) $row['body'], 0, 120), ENT_QUOTES) ?><?= mb_strlen((string) $row['body']) > 120 ? '…' : '' ?>
                            </div>
                        </td>
                        <td class="meta"><?= htmlspecialchars($row['created_at'], ENT_QUOTES) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <div class="cm-pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($filterUrl(['page' => $page - 1]), ENT_QUOTES) ?>">← Prev</a>
                <?php endif; ?>
                <span class="here">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars($filterUrl(['page' => $page + 1]), ENT_QUOTES) ?>">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
