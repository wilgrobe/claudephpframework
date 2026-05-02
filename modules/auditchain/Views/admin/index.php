<?php $pageTitle = 'Audit chain'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:980px;margin:0 auto;padding:0 1rem">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:#6b7280">
            <a href="/admin" style="color:#4f46e5;text-decoration:none">← Admin</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">Audit chain</h1>
        <p style="margin:.25rem 0 0;color:#6b7280;font-size:13.5px;line-height:1.55">
            HMAC-SHA256 chain over <code>audit_log</code> rows. Every row is
            sealed with the prior row's hash on insert; verification recomputes
            and reports any drift. Per-day chains let us verify in parallel and
            keep tampering localised.
        </p>
    </div>
    <a href="/admin/audit-chain/breaks" class="btn btn-secondary" style="font-size:12.5px">
        Breaks
        <?php if (($stats['unack_breaks'] ?? 0) > 0): ?>
            <span style="background:#ef4444;color:#fff;padding:.05rem .35rem;border-radius:999px;font-size:10px;margin-left:.25rem"><?= (int) $stats['unack_breaks'] ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- Health overview -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem">
        <?php
        $cards = [
            ['Total runs',    (int) ($stats['total_runs']      ?? 0), '#374151'],
            ['Total breaks',  (int) ($stats['total_breaks']    ?? 0), ($stats['total_breaks'] > 0 ? '#ef4444' : '#10b981')],
            ['Unack breaks',  (int) ($stats['unack_breaks']    ?? 0), ($stats['unack_breaks'] > 0 ? '#ef4444' : '#10b981')],
            ['Last run breaks',(int) ($stats['last_run_breaks']?? 0), ($stats['last_run_breaks'] > 0 ? '#ef4444' : '#10b981')],
        ];
        foreach ($cards as [$label, $value, $color]):
        ?>
            <div style="flex:1 1 140px;text-align:center;padding:.5rem;border-left:3px solid <?= $color ?>;background:#fafafa;border-radius:4px">
                <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em"><?= $label ?></div>
                <div style="font-size:1.4rem;font-weight:700"><?= $value ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="padding:.5rem 1rem;font-size:12.5px;color:#6b7280;border-top:1px solid #f3f4f6">
        Last run: <?= $stats['last_run_at'] ? e(date('M j, Y g:ia T', strtotime((string) $stats['last_run_at']))) : '<em>never</em>' ?>
        · Last break:
        <?= $stats['last_break_at']
            ? '<strong style="color:#ef4444">' . e(date('M j, Y g:ia T', strtotime((string) $stats['last_break_at']))) . '</strong>'
            : '<em>none</em>' ?>
    </div>
</div>

<!-- On-demand verify -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem">
        <h2 style="margin:0 0 .5rem;font-size:1rem">Run on-demand verification</h2>
        <p style="margin:0 0 .75rem;color:#6b7280;font-size:13px;line-height:1.55">
            Walks every row in the date range, recomputes the HMAC chain,
            records any drift in the breaks log. The daily scheduled job
            covers the last 7 days automatically — use this for deeper
            retroactive checks or after a suspected incident.
        </p>
        <form method="POST" action="/admin/audit-chain/verify" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap">
            <?= csrf_field() ?>
            <label>
                <span style="display:block;font-size:12px;color:#6b7280;margin-bottom:.2rem">From</span>
                <input type="date" name="day_from" value="<?= e(date('Y-m-d', time() - 30 * 86400)) ?>">
            </label>
            <label>
                <span style="display:block;font-size:12px;color:#6b7280;margin-bottom:.2rem">To</span>
                <input type="date" name="day_to" value="<?= e(date('Y-m-d')) ?>">
            </label>
            <button type="submit" class="btn btn-primary" style="font-size:13px">Verify range</button>
        </form>
    </div>
</div>

<!-- Recent runs -->
<div class="card">
    <div class="card-header" style="padding:.75rem 1rem"><strong style="font-size:13.5px">Recent verification runs</strong></div>
    <table class="table" style="width:100%;font-size:12.5px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.4rem .75rem">When</th>
                <th style="text-align:left;padding:.4rem .75rem">Range</th>
                <th style="text-align:right;padding:.4rem .75rem">Rows</th>
                <th style="text-align:right;padding:.4rem .75rem">Breaks</th>
                <th style="text-align:right;padding:.4rem .75rem">Duration</th>
                <th style="text-align:left;padding:.4rem .75rem">Triggered by</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recent)): ?>
                <tr><td colspan="6" style="padding:1.5rem;text-align:center;color:#6b7280">No verification runs yet.</td></tr>
            <?php else: foreach ($recent as $r): ?>
                <tr style="border-top:1px solid #f3f4f6;<?= ((int) ($r['breaks_found'] ?? 0)) > 0 ? 'background:#fef2f2' : '' ?>">
                    <td style="padding:.4rem .75rem;color:#6b7280;white-space:nowrap"><?= e(date('M j, g:ia', strtotime((string) $r['started_at']))) ?></td>
                    <td style="padding:.4rem .75rem;font-family:ui-monospace,monospace;font-size:11.5px;color:#6b7280"><?= e($r['day_from'] ?? '—') ?> → <?= e($r['day_to'] ?? '—') ?></td>
                    <td style="padding:.4rem .75rem;text-align:right"><?= $r['rows_verified'] !== null ? number_format((int) $r['rows_verified']) : '—' ?></td>
                    <td style="padding:.4rem .75rem;text-align:right;<?= ((int) ($r['breaks_found'] ?? 0)) > 0 ? 'color:#ef4444;font-weight:600' : 'color:#374151' ?>"><?= $r['breaks_found'] !== null ? number_format((int) $r['breaks_found']) : '—' ?></td>
                    <td style="padding:.4rem .75rem;text-align:right;color:#6b7280"><?= $r['duration_ms'] !== null ? (int) $r['duration_ms'] . ' ms' : '—' ?></td>
                    <td style="padding:.4rem .75rem;color:#6b7280"><?= e($r['triggered_by_username'] ?? 'cron') ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
