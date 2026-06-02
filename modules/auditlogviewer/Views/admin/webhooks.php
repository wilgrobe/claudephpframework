<?php
/** @var array  $items  shaped rows from fetchEmailEvents/fetchStripeEvents */
/** @var string $source 'all' | 'email' | 'stripe' */
/** @var array  $filters */
/** @var int    $page */
/** @var int    $per_page */
/** @var int    $total_loaded */
/** @var int    $fetch_each */
/** @var array<string, int> $tally  provider key → row count in the full table */
$pageTitle = 'Webhook deliveries';
include BASE_PATH . '/app/Views/layout/header.php';

$qs = function (array $extra) use ($filters): string {
    $base = $filters;
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') unset($base[$k]); else $base[$k] = $v;
    }
    return http_build_query($base);
};
$totalPages = max(1, (int) ceil($total_loaded / max(1, $per_page)));
?>
<style>
.wh-shell  { max-width:1200px; margin:0 auto; }
.wh-head   { display:flex; justify-content:space-between; align-items:baseline; gap:1rem; margin-bottom:.85rem; flex-wrap:wrap; }
.wh-h1     { font-size:1.5rem; font-weight:700; margin:0; }
.wh-h1 .ct { color:var(--color-gray-400); font-size:13px; font-weight:500; }
.wh-help   { color:var(--color-gray-500); font-size:13px; max-width:720px; line-height:1.5; margin:0 0 1rem; }

.wh-tabs   { display:flex; gap:.4rem; margin-bottom:.85rem; }
.wh-tab    { padding:.4rem .85rem; border-radius:6px; background: var(--bg-panel, var(--bg-panel)); color:var(--color-gray-700); font-size:13px; font-weight:600; text-decoration:none; border:1px solid var(--color-gray-200); }
.wh-tab.active { background:var(--color-primary); color:var(--bg-panel); border-color:var(--color-primary); }
.wh-tab:hover { border-color:var(--color-purple); }

.wh-grid   { display:grid; grid-template-columns: 1fr 240px; gap:1rem; align-items:start; }
@media (max-width: 900px) { .wh-grid { grid-template-columns: 1fr; } }

.wh-filters { display:grid; gap:.5rem; grid-template-columns: 1fr 160px 160px 140px; margin-bottom:.85rem; background: var(--bg-panel, var(--bg-panel)); border:1px solid var(--color-gray-200); border-radius:8px; padding:.75rem 1rem; }
.wh-filters input, .wh-filters button, .wh-filters .btn-secondary { padding:.45rem .65rem; border:1px solid var(--color-gray-300); border-radius:4px; font-size:13px; font-family:inherit; }
.wh-filters .actions { grid-column: span 4; display:flex; gap:.4rem; justify-content:flex-end; }

.wh-table { width:100%; background: var(--bg-panel, var(--bg-panel)); border:1px solid var(--color-gray-200); border-radius:8px; overflow:hidden; border-collapse:collapse; }
.wh-table th, .wh-table td { padding:.55rem .8rem; text-align:left; font-size:12.5px; border-bottom:1px solid var(--color-gray-100); }
.wh-table th { background:var(--bg-page); font-size:10.5px; text-transform:uppercase; letter-spacing:.3px; color:var(--color-gray-500); font-weight:700; }
.wh-table tr:last-child td { border-bottom:0; }
.wh-icon  { font-size:14px; }
.wh-prov-pill { display:inline-block; padding:.1rem .45rem; border-radius:3px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.prov-ses        { background:var(--color-warning-bg); color:var(--color-warning-fg); }
.prov-sendgrid   { background:var(--color-info-bg); color:var(--color-info-fg); }
.prov-postmark   { background:var(--color-warning-bg); color:var(--color-warning-fg); }
.prov-mailgun    { background:var(--color-danger-bg); color:var(--color-danger-fg); }
.prov-smtp2go    { background:var(--color-success-bg); color:var(--color-success-fg); }
.prov-stripe     { background:var(--color-info-bg); color:var(--color-secondary); }

.wh-sidebar h3 { font-size:11.5px; text-transform:uppercase; letter-spacing:.3px; color:var(--color-gray-500); margin:0 0 .5rem; font-weight:700; }
.wh-sidebar .card { padding:.75rem 1rem; }
.wh-chip-list { display:flex; flex-direction:column; gap:.2rem; }
.wh-chip { display:flex; justify-content:space-between; align-items:center; padding:.3rem .55rem; border-radius:4px; background: var(--bg-panel, var(--bg-panel)); border:1px solid var(--color-gray-200); font-size:12px; text-decoration:none; color:var(--color-gray-700); }
.wh-chip:hover { background:var(--color-gray-50); border-color:var(--color-purple); }
.wh-chip .ct { color:var(--color-gray-400); font-size:10.5px; font-weight:600; }
.wh-chip.active { background:var(--color-purple-bg); border-color:var(--color-purple); color:var(--color-purple-fg); }

.wh-empty { padding:3rem 1rem; color:var(--color-gray-400); text-align:center; font-style:italic; }
.wh-pager { display:flex; justify-content:space-between; align-items:center; margin-top:.85rem; font-size:12.5px; color:var(--color-gray-500); }
.wh-pager .nav a, .wh-pager .nav span.disabled { padding:.35rem .7rem; border:1px solid var(--color-gray-300); border-radius:4px; background: var(--bg-panel, var(--bg-panel)); color:var(--color-gray-700); text-decoration:none; margin-left:.25rem; }
.wh-pager .nav span.disabled { color:var(--color-gray-300); }
</style>

<div class="wh-shell">
    <div class="wh-head">
        <h1 class="wh-h1">Webhook deliveries <span class="ct">(<?= number_format($total_loaded) ?> in working set)</span></h1>
        <div style="display:flex;gap:.5rem;align-items:center">
            <a href="/admin/activity" style="font-size:12.5px;color:var(--color-primary);text-decoration:none">Activity →</a>
            <span style="color:var(--color-gray-300)">·</span>
            <a href="/admin/audit-log" style="font-size:12.5px;color:var(--color-primary);text-decoration:none">Audit log →</a>
        </div>
    </div>
    <p class="wh-help">Unified view of inbound webhook deliveries from email providers (SES / SendGrid / Postmark / Mailgun / SMTP2GO bounce + complaint events) and Stripe (subscription events). Click any row to inspect the full payload and signature debug info. Stripe events can be replayed — bounce events are append-only and already applied.</p>

    <div class="wh-tabs">
        <a class="wh-tab <?= $source === 'all' ? 'active' : '' ?>"    href="?<?= $qs(['source' => 'all',    'page' => null]) ?>">All</a>
        <a class="wh-tab <?= $source === 'email' ? 'active' : '' ?>"  href="?<?= $qs(['source' => 'email',  'page' => null]) ?>">Email providers</a>
        <a class="wh-tab <?= $source === 'stripe' ? 'active' : '' ?>" href="?<?= $qs(['source' => 'stripe', 'page' => null]) ?>">Stripe</a>
    </div>

    <form method="get" class="wh-filters">
        <input name="q"          value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="search email / event_type / event_id" aria-label="search">
        <input name="event_type" value="<?= e((string) ($filters['event_type'] ?? '')) ?>" placeholder="event_type" aria-label="event_type">
        <input name="date_from"  value="<?= e((string) ($filters['date_from'] ?? '')) ?>" placeholder="from YYYY-MM-DD" aria-label="from">
        <input name="date_to"    value="<?= e((string) ($filters['date_to'] ?? '')) ?>" placeholder="to YYYY-MM-DD" aria-label="to">
        <input type="hidden" name="source"   value="<?= e($source) ?>">
        <input type="hidden" name="provider" value="<?= e((string) ($filters['provider'] ?? '')) ?>">
        <div class="actions">
            <button class="btn btn-primary" style="background:var(--color-primary);color:var(--bg-panel);border:1px solid var(--color-primary);padding:.45rem .85rem;border-radius:4px;font-size:13px;cursor:pointer">Filter</button>
            <a href="/admin/webhooks" class="btn-secondary" style="padding:.45rem .85rem;text-decoration:none;color:var(--color-gray-700);border:1px solid var(--color-gray-300);border-radius:4px;font-size:13px">Clear</a>
        </div>
    </form>

    <div class="wh-grid">
        <div>
            <?php if (empty($items)): ?>
                <div class="card"><div class="wh-empty">No webhook deliveries match the current filters.</div></div>
            <?php else: ?>
                <table class="wh-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Provider</th>
                            <th>Event</th>
                            <th>Subject</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item):
                        $providerSlug = str_replace(['email:', 'stripe'], ['', 'stripe'], $item['provider']);
                        if ($providerSlug === '') $providerSlug = 'stripe';
                    ?>
                        <tr>
                            <td style="white-space:nowrap;color:var(--color-gray-700)"><?= e(date('M j, H:i:s', strtotime((string) $item['ts']))) ?></td>
                            <td>
                                <span class="wh-icon" style="color:<?= $item['color'] ?>"><?= $item['icon'] ?></span>
                                <span class="wh-prov-pill prov-<?= htmlspecialchars($providerSlug, ENT_QUOTES) ?>"><?= e($item['provider_label']) ?></span>
                            </td>
                            <td><code style="background:var(--color-gray-100);padding:.1rem .4rem;border-radius:3px;font-size:11.5px"><?= e($item['event_type']) ?></code></td>
                            <td style="font-family:monospace;font-size:11.5px;color:var(--color-gray-700);max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string) $item['subject_label']) ?></td>
                            <td><a href="/admin/webhooks/<?= e($item['source']) ?>/<?= (int) $item['id'] ?>" style="font-size:11.5px;color:var(--color-primary);text-decoration:none;font-weight:600">Inspect →</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="wh-pager">
                    <div>Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> · showing <strong><?= number_format(count($items)) ?></strong> of <strong><?= number_format($total_loaded) ?></strong> loaded</div>
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

        <aside class="wh-sidebar">
            <div class="card" style="margin-bottom:.85rem">
                <h3>Providers</h3>
                <p style="color:var(--color-gray-400);font-size:11px;margin:0 0 .5rem;line-height:1.4">Click to filter by provider</p>
                <div class="wh-chip-list">
                    <?php
                    $chipMeta = [
                        'email:ses'      => ['SES',       'var(--color-warning-bg)', 'var(--color-warning-fg)'],
                        'email:sendgrid' => ['SendGrid',  'var(--color-info-bg)', 'var(--color-info-fg)'],
                        'email:postmark' => ['Postmark',  '#fef9c3', '#854d0e'],
                        'email:mailgun'  => ['Mailgun',   '#fce7f3', '#9d174d'],
                        'email:smtp2go'  => ['SMTP2GO',   '#dcfce7', '#166534'],
                        'stripe'         => ['Stripe',    '#cffafe', '#155e75'],
                    ];
                    foreach ($chipMeta as $key => [$label, $bg, $fg]):
                        $count    = (int) ($tally[$key] ?? 0);
                        $isActive = ($filters['provider'] ?? '') === $key;
                    ?>
                        <a class="wh-chip <?= $isActive ? 'active' : '' ?>"
                           href="?<?= $qs(['provider' => $key, 'page' => null, 'source' => str_starts_with($key, 'email:') ? 'email' : 'stripe']) ?>">
                            <span><span style="background:<?= $bg ?>;color:<?= $fg ?>;padding:.05rem .35rem;border-radius:2px;font-size:10px;font-weight:700;margin-right:.3rem"><?= e($label) ?></span></span>
                            <span class="ct"><?= number_format($count) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h3>Why no replay for email?</h3>
                <p style="color:var(--color-gray-500);font-size:11.5px;line-height:1.45;margin:0">
                    Bounce + complaint webhooks trigger suppression at receipt. The destination address is already in <code>mail_suppressions</code>; replay would be a no-op. Use Stripe-side webhooks for replay — they're idempotent via <code>UNIQUE(event_id)</code>.
                </p>
            </div>
        </aside>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
