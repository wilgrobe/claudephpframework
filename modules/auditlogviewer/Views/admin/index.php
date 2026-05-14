<?php
/** @var array $items */
/** @var int   $total */
/** @var int   $page */
/** @var int   $per_page */
/** @var array $filters */
/** @var array $top_actions  array<int, array{action:string, cnt:int}> */
/** @var array $chain_status array{available:bool, breaks:int, last_verified_at:?string} */
$pageTitle = 'Audit log';
include BASE_PATH . '/app/Views/layout/header.php';

$totalPages = max(1, (int) ceil($total / max(1, $per_page)));
$qs = function (array $extra) use ($filters): string {
    $base = $filters;
    foreach ($extra as $k => $v) {
        if ($v === null) unset($base[$k]); else $base[$k] = $v;
    }
    return http_build_query($base);
};
?>
<style>
.al-shell  { max-width:1200px; margin:0 auto; }
.al-head   { display:flex; justify-content:space-between; align-items:baseline; gap:1rem; margin-bottom:.85rem; flex-wrap:wrap; }
.al-h1     { font-size:1.5rem; font-weight:700; margin:0; }
.al-h1 .ct { color:#9ca3af; font-size:13px; font-weight:500; }
.al-cta    { display:flex; gap:.5rem; align-items:center; }
.al-pill   { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .6rem; border-radius:999px; font-size:11.5px; font-weight:600; }
.al-pill-ok      { background:#ecfdf5; color:#047857; }
.al-pill-warn    { background:#fee2e2; color:#991b1b; }
.al-pill-unknown { background:#f3f4f6; color:#6b7280; }
.al-pill a       { color:inherit; text-decoration:none; }

.al-grid { display:grid; grid-template-columns: 1fr 240px; gap:1rem; align-items:start; }
@media (max-width: 900px) { .al-grid { grid-template-columns: 1fr; } }

.al-quickdates { display:flex; gap:.3rem; flex-wrap:wrap; margin-bottom:.5rem; }
.al-qd { padding:.25rem .6rem; border-radius:4px; background:#f3f4f6; color:#374151; font-size:11.5px; font-weight:600; text-decoration:none; }
.al-qd:hover { background:#e5e7eb; color:#111; }

.al-filters { display:grid; gap:.5rem; grid-template-columns: repeat(4, 1fr); margin-bottom:.85rem; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:.75rem 1rem; }
.al-filters input, .al-filters button, .al-filters .btn-secondary { padding:.5rem .65rem; border:1px solid #d1d5db; border-radius:4px; font-size:13px; font-family:inherit; }
.al-filters .span2 { grid-column: span 2; }
.al-filters .actions { grid-column: span 4; display:flex; gap:.4rem; justify-content:flex-end; }

.al-sidebar h3   { font-size:11.5px; text-transform:uppercase; letter-spacing:.3px; color:#6b7280; margin:0 0 .5rem; font-weight:700; }
.al-sidebar .card { padding:.75rem 1rem; }
.al-chip-list { display:flex; flex-direction:column; gap:.2rem; }
.al-chip      { display:flex; justify-content:space-between; align-items:center; padding:.3rem .55rem; border-radius:4px; background:#fff; border:1px solid #e5e7eb; font-size:12px; text-decoration:none; color:#374151; }
.al-chip:hover { background:#f9fafb; border-color:#c4b5fd; }
.al-chip .ct  { color:#9ca3af; font-size:10.5px; font-weight:600; }
.al-chip.active { background:#ede9fe; border-color:#c4b5fd; color:#5b21b6; }

.al-table { width:100%; background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; border-collapse:collapse; }
.al-table th, .al-table td { padding:.55rem .8rem; text-align:left; font-size:12.5px; border-bottom:1px solid #f3f4f6; }
.al-table th { background:#fafafa; font-size:10.5px; text-transform:uppercase; letter-spacing:.3px; color:#6b7280; font-weight:700; }
.al-table tr:last-child td { border-bottom:0; }
.al-table code { font-size:11.5px; }
.al-empty { padding:3rem 1rem; color:#9ca3af; text-align:center; }

.al-pager { display:flex; justify-content:space-between; align-items:center; margin-top:.85rem; font-size:12.5px; color:#6b7280; }
.al-pager .nav a, .al-pager .nav span.disabled { padding:.35rem .7rem; border:1px solid #d1d5db; border-radius:4px; background:#fff; color:#374151; text-decoration:none; margin-left:.25rem; }
.al-pager .nav span.disabled { color:#d1d5db; }
.al-pager .nav .current { background:#4f46e5; color:#fff; border-color:#4f46e5; }

.badge-superadmin { display:inline-block; padding:.05rem .35rem; border-radius:3px; background:#fef3c7; color:#92400e; font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; margin-left:.25rem; }
</style>

<div class="al-shell">
    <div class="al-head">
        <h1 class="al-h1">Audit log <span class="ct">(<?= number_format($total) ?>)</span></h1>
        <div class="al-cta">
            <?php
            // Chain integrity pill. Three states: clean, breaks-found, unknown.
            $cs = $chain_status;
            if (!$cs['available']):
            ?>
                <span class="al-pill al-pill-unknown" title="auditchain module not installed">⚙ chain n/a</span>
            <?php elseif ($cs['breaks'] > 0): ?>
                <a class="al-pill al-pill-warn" href="/admin/audit-chain/breaks" title="<?= $cs['breaks'] ?> tampered row(s) detected — click to triage">⚠ <?= $cs['breaks'] ?> chain break<?= $cs['breaks'] === 1 ? '' : 's' ?></a>
            <?php else: ?>
                <a class="al-pill al-pill-ok" href="/admin/audit-chain" title="No tampered rows<?= $cs['last_verified_at'] ? ' — last verified ' . htmlspecialchars($cs['last_verified_at'], ENT_QUOTES) : '' ?>">✓ chain ok</a>
            <?php endif; ?>
            <?php $csvQs = $qs([]); ?>
            <a class="btn btn-secondary" href="/admin/audit-log.csv<?= $csvQs ? '?' . $csvQs : '' ?>" style="font-size:12.5px;padding:.45rem .85rem;background:#fff;border:1px solid #d1d5db;border-radius:4px;text-decoration:none;color:#374151;">⬇ Export CSV</a>
        </div>
    </div>

    <!-- Quick-date shortcuts. Click bumps the filter form with a preset date_from. -->
    <div class="al-quickdates">
        <?php
        $today = date('Y-m-d');
        $last24 = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $last7  = date('Y-m-d', strtotime('-7 days'));
        $last30 = date('Y-m-d', strtotime('-30 days'));
        $thisMonth = date('Y-m-01');
        ?>
        <span style="color:#9ca3af;font-size:11.5px;align-self:center;margin-right:.4rem">Quick filter:</span>
        <a class="al-qd" href="?<?= $qs(['date_from' => $last24, 'date_to' => null]) ?>">Last 24h</a>
        <a class="al-qd" href="?<?= $qs(['date_from' => $last7, 'date_to' => null]) ?>">Last 7d</a>
        <a class="al-qd" href="?<?= $qs(['date_from' => $last30, 'date_to' => null]) ?>">Last 30d</a>
        <a class="al-qd" href="?<?= $qs(['date_from' => $thisMonth, 'date_to' => null]) ?>">This month</a>
        <?php if (!empty($filters)): ?>
            <a class="al-qd" href="/admin/audit-log" style="margin-left:.4rem;background:#fee2e2;color:#991b1b">✕ Clear all filters</a>
        <?php endif; ?>
    </div>

    <form method="get" class="al-filters">
        <input name="action"        value="<?= e((string) ($filters['action'] ?? '')) ?>" placeholder="action (e.g. auth.login or auth.*)" aria-label="action">
        <input name="actor_user_id" value="<?= e((string) ($filters['actor_user_id'] ?? '')) ?>" placeholder="actor user id" type="number" aria-label="actor user id">
        <input name="model"         value="<?= e((string) ($filters['model'] ?? '')) ?>" placeholder="model (e.g. users)" aria-label="model">
        <input name="model_id"      value="<?= e((string) ($filters['model_id'] ?? '')) ?>" placeholder="model id" type="number" aria-label="model id">
        <input name="date_from"     value="<?= e((string) ($filters['date_from'] ?? '')) ?>" placeholder="from YYYY-MM-DD" aria-label="from">
        <input name="date_to"       value="<?= e((string) ($filters['date_to']   ?? '')) ?>" placeholder="to YYYY-MM-DD" aria-label="to">
        <input name="q"             value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="search action / model / notes" class="span2" aria-label="search">
        <div class="actions">
            <button class="btn btn-primary" style="background:#4f46e5;color:#fff;border-color:#4f46e5">Filter</button>
            <a href="/admin/audit-log" class="btn-secondary" style="text-decoration:none;color:#374151;">Clear</a>
        </div>
    </form>

    <div class="al-grid">
        <div>
            <?php if (empty($items)): ?>
                <div class="card"><div class="al-empty">No matching rows.</div></div>
            <?php else: ?>
            <table class="al-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Model</th>
                        <th>IP</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $r): ?>
                    <tr>
                        <td style="white-space:nowrap;color:#374151"><?= e(date('M j, H:i:s', strtotime((string) $r['created_at']))) ?></td>
                        <td>
                            <?php if (!empty($r['actor_username'])): ?>
                                @<?= e((string) $r['actor_username']) ?>
                                <?php if (!empty($r['emulated_username'])): ?>
                                    <div style="font-size:11px;color:#b45309">emulated by @<?= e((string) $r['emulated_username']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#9ca3af">system</span>
                            <?php endif; ?>
                            <?php if ((int) $r['superadmin_mode'] === 1): ?>
                                <span class="badge-superadmin">superadmin</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?<?= $qs(['action' => $r['action']]) ?>" style="color:#4f46e5;text-decoration:none">
                                <code style="background:#f3f4f6;padding:.1rem .35rem;border-radius:3px"><?= e((string) $r['action']) ?></code>
                            </a>
                        </td>
                        <td style="font-family:monospace;font-size:11.5px;color:#374151">
                            <?php if ($r['model']): ?>
                                <a href="?<?= $qs(['model' => $r['model']]) ?>" style="color:inherit;text-decoration:none"><?= e((string) $r['model']) ?></a>
                                <?php if ($r['model_id']): ?>
                                    <a href="?<?= $qs(['model' => $r['model'], 'model_id' => $r['model_id']]) ?>" style="color:#9ca3af;text-decoration:none"> #<?= (int) $r['model_id'] ?></a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#9ca3af">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#9ca3af;font-family:monospace;font-size:11.5px"><?= e((string) ($r['ip_address'] ?? '')) ?></td>
                        <td><a href="/admin/audit-log/<?= (int) $r['id'] ?>" style="font-size:11.5px;color:#4f46e5;text-decoration:none;font-weight:600">Detail →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="al-pager">
                <div>
                    Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                    · showing <strong><?= number_format(count($items)) ?></strong> of <strong><?= number_format($total) ?></strong> row<?= $total === 1 ? '' : 's' ?>
                </div>
                <div class="nav">
                    <?php if ($page > 1): ?>
                        <a href="?<?= $qs(['page' => 1]) ?>" title="First">«</a>
                        <a href="?<?= $qs(['page' => $page - 1]) ?>" title="Previous">‹ Prev</a>
                    <?php else: ?>
                        <span class="disabled">«</span>
                        <span class="disabled">‹ Prev</span>
                    <?php endif; ?>

                    <?php
                    // Compact numeric strip: current ± 2, with first / last
                    // pinned. Skips gaps with ellipsis.
                    $strip = [];
                    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) $strip[] = $i;
                    if ($strip[0] > 1) {
                        if ($strip[0] > 2) array_unshift($strip, '…');
                        array_unshift($strip, 1);
                    }
                    if (end($strip) < $totalPages) {
                        if (end($strip) < $totalPages - 1) $strip[] = '…';
                        $strip[] = $totalPages;
                    }
                    foreach ($strip as $p):
                        if ($p === '…') { echo '<span class="disabled">…</span>'; continue; }
                    ?>
                        <a href="?<?= $qs(['page' => $p]) ?>" class="<?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
                    <?php endforeach; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= $qs(['page' => $page + 1]) ?>" title="Next">Next ›</a>
                        <a href="?<?= $qs(['page' => $totalPages]) ?>" title="Last">»</a>
                    <?php else: ?>
                        <span class="disabled">Next ›</span>
                        <span class="disabled">»</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <aside class="al-sidebar">
            <div class="card" style="margin-bottom:.85rem">
                <h3>Top actions</h3>
                <p style="color:#9ca3af;font-size:11px;margin:0 0 .5rem;line-height:1.4">Click to filter</p>
                <div class="al-chip-list">
                    <?php foreach ($top_actions as $row):
                        $isActive = ($filters['action'] ?? '') === $row['action'];
                    ?>
                        <a class="al-chip <?= $isActive ? 'active' : '' ?>"
                           href="?<?= $qs(['action' => $row['action'], 'page' => null]) ?>"
                           title="<?= htmlspecialchars($row['action'], ENT_QUOTES) ?>">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:140px;font-family:monospace"><?= e($row['action']) ?></span>
                            <span class="ct"><?= number_format($row['cnt']) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($top_actions)): ?>
                        <div style="color:#9ca3af;font-size:11.5px;font-style:italic;text-align:center;padding:.5rem">no actions yet</div>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
