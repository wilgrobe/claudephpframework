<?php
/** @var array $items
 *  Each item: [kind, id, created_at, icon, badge_label, badge_color, title,
 *              body, subject, action_type, detail_link, ...] */
/** @var string $kind     all|notification|audit */
/** @var array  $filters */
/** @var int    $page */
/** @var int    $per_page */
/** @var int    $total_loaded */
/** @var int    $fetch_each */
$pageTitle = 'Activity';
include BASE_PATH . '/app/Views/layout/header.php';

$qs = function (array $extra) use ($filters): string {
    $base = $filters;
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') unset($base[$k]); else $base[$k] = $v;
    }
    return http_build_query($base);
};
$totalPages = max(1, (int) ceil($total_loaded / max(1, $per_page)));
$hasMore    = $total_loaded === $fetch_each * 2;  // both sources hit the cap
?>
<style>
.act-shell  { max-width:1100px; margin:0 auto; }
.act-head   { display:flex; justify-content:space-between; align-items:baseline; gap:1rem; margin-bottom:.85rem; flex-wrap:wrap; }
.act-h1     { font-size:1.5rem; font-weight:700; margin:0; }
.act-h1 .ct { color:var(--color-gray-400); font-size:13px; font-weight:500; }
.act-help   { color:var(--color-gray-500); font-size:13px; max-width:680px; line-height:1.5; margin:0 0 1rem; }

.act-tabs    { display:flex; gap:.4rem; margin-bottom:.85rem; }
.act-tab     { padding:.4rem .85rem; border-radius:6px; background: var(--bg-panel, var(--bg-panel)); color:var(--color-gray-700); font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--color-gray-200); }
.act-tab:hover { background:var(--color-gray-50); border-color:var(--color-purple); }
.act-tab.active { background:var(--color-primary); color:var(--bg-panel); border-color:var(--color-primary); }

.act-filters { display:grid; gap:.5rem; grid-template-columns: 1fr 200px 200px 140px; margin-bottom:.85rem; background: var(--bg-panel, var(--bg-panel)); border:1px solid var(--color-gray-200); border-radius:8px; padding:.75rem 1rem; }
.act-filters input, .act-filters button, .act-filters .btn-secondary { padding:.45rem .65rem; border:1px solid var(--color-gray-300); border-radius:4px; font-size:13px; font-family:inherit; }
.act-filters .actions { grid-column: span 4; display:flex; gap:.4rem; justify-content:flex-end; }

.act-feed    { background: var(--bg-panel, var(--bg-panel)); border:1px solid var(--color-gray-200); border-radius:8px; overflow:hidden; }
.act-item    { display:grid; grid-template-columns: 36px 1fr 110px; gap:.85rem; padding:.75rem 1.1rem; border-bottom:1px solid var(--color-gray-100); align-items:start; }
.act-item:last-child { border-bottom:0; }
.act-icon    { font-size:18px; display:flex; align-items:center; justify-content:center; width:36px; height:36px; background:var(--color-gray-50); border-radius:8px; }
.act-row1    { display:flex; align-items:baseline; gap:.5rem; margin-bottom:.15rem; }
.act-row1 .badge { display:inline-block; padding:.05rem .4rem; border-radius:3px; font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; color:var(--bg-panel); }
.act-row1 .badge.audit         { background:var(--color-secondary); }
.act-row1 .badge.notification  { background:var(--color-primary); }
.act-row1 .badge.unread        { background:var(--color-warning-bg); color:var(--color-warning-fg); }
.act-row1 .badge.superadmin    { background:var(--color-warning-bg); color:var(--color-warning-fg); }
.act-row1 .subject { color:var(--color-gray-700); font-size:13.5px; font-weight:600; }
.act-row1 .action  { font-family:monospace; font-size:11.5px; color:var(--color-gray-400); }
.act-title   { color:var(--text-default); font-size:13.5px; margin:0 0 .1rem; word-break:break-word; }
.act-body    { color:var(--color-gray-500); font-size:12px; line-height:1.4; word-break:break-word; }
.act-meta    { text-align:right; font-size:11.5px; color:var(--color-gray-400); }
.act-meta a  { color:var(--color-primary); text-decoration:none; font-weight:600; display:block; margin-top:.2rem; }

.act-empty   { padding:3rem 1rem; text-align:center; color:var(--color-gray-400); font-size:14px; font-style:italic; }
.act-pager   { display:flex; justify-content:space-between; align-items:center; margin-top:.85rem; font-size:12.5px; color:var(--color-gray-500); }
.act-pager .nav a, .act-pager .nav span.disabled { padding:.35rem .7rem; border:1px solid var(--color-gray-300); border-radius:4px; background: var(--bg-panel, var(--bg-panel)); color:var(--color-gray-700); text-decoration:none; margin-left:.25rem; }
.act-pager .nav span.disabled { color:var(--color-gray-300); }

.act-day-header { padding:.5rem 1.1rem; background:var(--bg-page); font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--color-gray-500); font-weight:700; border-bottom:1px solid var(--color-gray-100); }

.act-overflow-warn { background:var(--color-warning-bg); color:var(--color-warning-fg); padding:.55rem .85rem; border-radius:6px; font-size:12px; margin-bottom:.85rem; line-height:1.5; }
</style>

<div class="act-shell">
    <div class="act-head">
        <h1 class="act-h1">Activity <span class="ct">(<?= number_format($total_loaded) ?>)</span></h1>
        <div style="display:flex;gap:.5rem;align-items:center">
            <a href="/notifications" style="font-size:12.5px;color:var(--color-primary);text-decoration:none">Notifications →</a>
            <span style="color:var(--color-gray-300)">·</span>
            <a href="/admin/audit-log" style="font-size:12.5px;color:var(--color-primary);text-decoration:none">Audit log →</a>
        </div>
    </div>
    <p class="act-help">Merged chronological feed of in-app notifications + audit-log entries. Click an item to jump to its origin surface (notification body or audit-row detail with old/new diff).</p>

    <div class="act-tabs">
        <a class="act-tab <?= $kind === 'all' ? 'active' : '' ?>"          href="?<?= $qs(['kind' => 'all',          'page' => null]) ?>">All</a>
        <a class="act-tab <?= $kind === 'notification' ? 'active' : '' ?>" href="?<?= $qs(['kind' => 'notification', 'page' => null]) ?>">Notifications only</a>
        <a class="act-tab <?= $kind === 'audit' ? 'active' : '' ?>"        href="?<?= $qs(['kind' => 'audit',        'page' => null]) ?>">Audit only</a>
    </div>

    <form method="get" class="act-filters">
        <input name="q"             value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="search action / title / body / notes" aria-label="search">
        <input name="actor_user_id" value="<?= e((string) ($filters['actor_user_id'] ?? '')) ?>" placeholder="actor user id" type="number" aria-label="actor user id">
        <input name="date_from"     value="<?= e((string) ($filters['date_from'] ?? '')) ?>" placeholder="from YYYY-MM-DD" aria-label="from">
        <input name="date_to"       value="<?= e((string) ($filters['date_to'] ?? '')) ?>" placeholder="to YYYY-MM-DD" aria-label="to">
        <input type="hidden" name="kind" value="<?= e($kind) ?>">
        <div class="actions">
            <button class="btn btn-primary" style="background:var(--color-primary);color:var(--bg-panel);border:1px solid var(--color-primary);padding:.45rem .85rem;border-radius:4px;font-size:13px;cursor:pointer">Filter</button>
            <a href="/admin/activity" class="btn-secondary" style="padding:.45rem .85rem;text-decoration:none;color:var(--color-gray-700);border:1px solid var(--color-gray-300);border-radius:4px;font-size:13px">Clear</a>
        </div>
    </form>

    <?php if ($hasMore): ?>
    <div class="act-overflow-warn">
        ⚠ Working set capped at <?= number_format($fetch_each * 2) ?> rows (<?= number_format($fetch_each) ?> per source). Narrow the date window or actor filter to drill deeper.
    </div>
    <?php endif; ?>

    <?php if (empty($items)): ?>
        <div class="act-feed"><div class="act-empty">No activity matches the current filters.</div></div>
    <?php else: ?>
        <div class="act-feed">
            <?php
            // Day-banner grouping for scannability. Items are already
            // sorted desc by created_at + id so the banner emits on
            // each new date boundary.
            $lastDay = null;
            foreach ($items as $item):
                $day = date('Y-m-d', strtotime((string) $item['created_at']));
                if ($day !== $lastDay):
                    $lastDay = $day;
            ?>
                <div class="act-day-header">
                    <?= e(date('l · F j, Y', strtotime((string) $item['created_at']))) ?>
                    <?php if ($day === date('Y-m-d')): ?> · today<?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="act-item">
                <div class="act-icon" title="<?= e($item['action_type']) ?>"><?= $item['icon'] ?></div>
                <div>
                    <div class="act-row1">
                        <span class="badge <?= $item['kind'] ?>"><?= e($item['badge_label']) ?></span>
                        <span class="subject"><?= e($item['subject']) ?></span>
                        <span class="action"><?= e($item['action_type']) ?></span>
                        <?php if ($item['kind'] === 'notification' && empty($item['read'])): ?>
                            <span class="badge unread">unread</span>
                        <?php endif; ?>
                        <?php if (!empty($item['superadmin'])): ?>
                            <span class="badge superadmin">superadmin</span>
                        <?php endif; ?>
                    </div>
                    <p class="act-title"><?= e($item['title']) ?></p>
                    <?php if (!empty($item['body'])): ?>
                        <p class="act-body"><?= e($item['body']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="act-meta">
                    <?= e(date('H:i:s', strtotime((string) $item['created_at']))) ?>
                    <a href="<?= e($item['detail_link']) ?>"><?= $item['kind'] === 'audit' ? 'detail →' : 'open →' ?></a>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <div class="act-pager">
            <div>Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> · showing <strong><?= number_format(count($items)) ?></strong> of <strong><?= number_format($total_loaded) ?></strong> loaded row<?= $total_loaded === 1 ? '' : 's' ?></div>
            <div class="nav">
                <?php if ($page > 1): ?>
                    <a href="?<?= $qs(['page' => $page - 1]) ?>">‹ Prev</a>
                <?php else: ?>
                    <span class="disabled">‹ Prev</span>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= $qs(['page' => $page + 1]) ?>">Next ›</a>
                <?php else: ?>
                    <span class="disabled">Next ›</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
