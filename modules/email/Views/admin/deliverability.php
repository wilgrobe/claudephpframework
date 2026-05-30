<?php
$pageTitle = 'Deliverability';
$check = function (?array $r): string {
    if (!$r) return '';
    $color = $r['pass'] ? '#16a34a' : '#dc2626';
    $bg    = $r['pass'] ? '#dcfce7' : 'var(--color-danger-bg)';
    $label = $r['pass'] ? 'PASS' : 'FAIL';
    return "<span style=\"display:inline-block;padding:.15rem .5rem;border-radius:10px;font-size:11px;font-weight:600;background:$bg;color:$color\">$label</span>";
};
$pct = function (int $num, int $den): string {
    if ($den <= 0) return '—';
    $r = ($num / $den) * 100;
    return number_format($r, $r < 1 ? 2 : 1) . '%';
};
$rateChip = function (float $value, float $goodMax, float $warnMax): string {
    if ($value <= $goodMax) { $bg='#dcfce7'; $col='#166534'; }
    elseif ($value <= $warnMax) { $bg='var(--color-warning-bg)'; $col='var(--color-warning-fg)'; }
    else { $bg='var(--color-danger-bg)'; $col='var(--color-danger-fg)'; }
    return "<span style=\"padding:.1rem .4rem;border-radius:8px;background:$bg;color:$col;font-size:11px;font-weight:600\">"
         . number_format($value, $value < 1 ? 2 : 1) . '%</span>';
};
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="page-header">
    <a href="/admin/email-suppressions" class="btn btn-sm btn-secondary">&larr; Suppressions</a>
    <h1>Deliverability</h1>
</div>

<p style="color:var(--color-gray-500);font-size:13px;margin:0 0 1.5rem;max-width:780px">
    Verify SPF / DKIM / DMARC for your sending domain and monitor bounce + complaint rates.
    See <a href="/docs/email-deliverability" target="_blank">the deliverability guide</a>
    for IP warming + record-setup walk-throughs.
</p>

<!-- ── DNS auth verifier ────────────────────────────────────────── -->
<div class="card" style="max-width:900px;margin-bottom:1.5rem">
    <div class="card-header"><h2>DNS auth verifier</h2></div>
    <div class="card-body">
        <form method="GET" action="/admin/email-deliverability" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap">
            <div style="flex:1 1 240px">
                <label for="domain" style="font-size:12px;font-weight:600;color:var(--color-gray-500);display:block;margin-bottom:.2rem">Sending domain</label>
                <input type="text" name="domain" id="domain" value="<?= e($lastDomain) ?>" placeholder="example.com" required>
            </div>
            <div style="flex:0 0 200px">
                <label for="selector" style="font-size:12px;font-weight:600;color:var(--color-gray-500);display:block;margin-bottom:.2rem">DKIM selector</label>
                <input type="text" name="selector" id="selector" value="<?= e($lastSelector) ?>" placeholder="default" required>
            </div>
            <button class="btn btn-primary">Run check</button>
        </form>
        <small style="color:var(--color-gray-400);display:block;margin-top:.4rem">
            Common DKIM selectors: <code>default</code> (most setups) · <code>google</code> (Google Workspace) ·
            <code>s1</code> / <code>selector1</code> (Mailgun, Microsoft 365) · <code>k1</code> (Mailchimp).
        </small>

        <?php if ($checks): ?>
        <table style="width:100%;border-collapse:collapse;margin-top:1.25rem">
            <thead><tr style="background:var(--color-gray-50)">
                <th style="padding:.5rem 1rem;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;color:var(--color-gray-500);width:70px">Status</th>
                <th style="padding:.5rem 1rem;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;color:var(--color-gray-500);width:90px">Check</th>
                <th style="padding:.5rem 1rem;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;color:var(--color-gray-500)">Result</th>
            </tr></thead>
            <tbody>
            <?php foreach (['spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $key => $label): $r = $checks[$key]; ?>
            <tr style="border-top:1px solid var(--color-gray-100)">
                <td style="padding:.6rem 1rem;vertical-align:top"><?= $check($r) ?></td>
                <td style="padding:.6rem 1rem;vertical-align:top;font-weight:600"><?= e($label) ?></td>
                <td style="padding:.6rem 1rem">
                    <div style="font-size:13px;color:var(--color-gray-900)"><?= e($r['detail']) ?></div>
                    <?php if (!empty($r['records'])): ?>
                    <details style="margin-top:.35rem">
                        <summary style="cursor:pointer;font-size:12px;color:var(--color-gray-500)">View raw record</summary>
                        <pre style="margin:.3rem 0 0;padding:.5rem .65rem;background:var(--color-gray-50);border:1px solid var(--color-gray-200);border-radius:4px;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:200px;overflow-y:auto"><?= e(implode("\n", $r['records'])) ?></pre>
                    </details>
                    <?php endif; ?>
                    <?php if (!empty($r['hints'])): ?>
                    <ul style="margin:.4rem 0 0 1rem;padding:0;color:var(--color-gray-500);font-size:12.5px">
                        <?php foreach ($r['hints'] as $h): ?>
                        <li><?= e($h) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── Bounce / complaint rates ───────────────────────────────────── -->
<?php
$panel = function(array $b, string $label) use ($pct, $rateChip) {
    $t = $b['totals'];
    $delivered = $t['delivered'];
    // For rate calc, the denominator is delivered + bounced (the volume
    // that actually reached an MTA + got a verdict). Webhook events
    // don't always include "delivered" — providers vary — so fall back
    // to bounced + complaint + unsubscribe + other when delivered is 0.
    $den = $delivered > 0 ? $delivered : array_sum($t);
    $bounceRate    = $den > 0 ? ($t['bounced']    / $den) * 100 : 0;
    $complaintRate = $den > 0 ? ($t['complaint']  / $den) * 100 : 0;
    ?>
    <div class="card" style="flex:1 1 380px;min-width:380px">
        <div class="card-header"><h2><?= e($label) ?></h2></div>
        <div class="card-body">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <tr><td style="padding:.3rem 0;color:var(--color-gray-500)">Total events</td>
                    <td style="text-align:right;font-weight:600"><?= number_format(array_sum($t)) ?></td></tr>
                <tr><td style="padding:.3rem 0;color:var(--color-gray-500)">Delivered</td>
                    <td style="text-align:right;font-weight:600"><?= number_format($t['delivered']) ?></td></tr>
                <tr><td style="padding:.3rem 0;color:var(--color-gray-500)">Bounced</td>
                    <td style="text-align:right">
                        <span style="font-weight:600"><?= number_format($t['bounced']) ?></span>
                        <?= $rateChip($bounceRate, 2.0, 5.0) ?>
                    </td></tr>
                <tr><td style="padding:.3rem 0;color:var(--color-gray-500)">Complaint / spam</td>
                    <td style="text-align:right">
                        <span style="font-weight:600"><?= number_format($t['complaint']) ?></span>
                        <?= $rateChip($complaintRate, 0.1, 0.3) ?>
                    </td></tr>
                <tr><td style="padding:.3rem 0;color:var(--color-gray-500)">Unsubscribed</td>
                    <td style="text-align:right;font-weight:600"><?= number_format($t['unsubscribe']) ?></td></tr>
            </table>

            <?php if (!empty($b['by_provider'])): ?>
            <details style="margin-top:.75rem">
                <summary style="cursor:pointer;font-size:12px;color:var(--color-gray-500)">By provider</summary>
                <table style="width:100%;border-collapse:collapse;margin-top:.4rem;font-size:12px">
                    <thead><tr style="border-bottom:1px solid var(--color-gray-200)">
                        <th style="padding:.25rem .25rem;text-align:left">Provider</th>
                        <th style="padding:.25rem .25rem;text-align:right">Bounced</th>
                        <th style="padding:.25rem .25rem;text-align:right">Complaint</th>
                        <th style="padding:.25rem .25rem;text-align:right">Total</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($b['by_provider'] as $prov => $pt): ?>
                    <tr>
                        <td style="padding:.2rem .25rem"><?= e($prov) ?></td>
                        <td style="padding:.2rem .25rem;text-align:right"><?= number_format($pt['bounced']) ?></td>
                        <td style="padding:.2rem .25rem;text-align:right"><?= number_format($pt['complaint']) ?></td>
                        <td style="padding:.2rem .25rem;text-align:right;color:var(--color-gray-500)"><?= number_format(array_sum($pt)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>

            <p style="margin:.75rem 0 0;font-size:11.5px;color:var(--color-gray-400);line-height:1.4">
                Industry baseline: bounce rate ≤ 2% good, ≤ 5% concerning, &gt; 5% problematic.
                Complaint rate ≤ 0.1% good, ≤ 0.3% concerning, &gt; 0.3% account-risk.
            </p>
        </div>
    </div>
    <?php
};
?>
<div style="display:flex;gap:1rem;flex-wrap:wrap;max-width:900px">
    <?php $panel($bounce7,  'Last 7 days');  ?>
    <?php $panel($bounce30, 'Last 30 days'); ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
