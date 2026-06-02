<?php $pageTitle = 'Login anomalies'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:1080px;margin:0 auto;padding:0 1rem">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:var(--color-gray-500)">
            <a href="/admin" style="color:var(--color-primary);text-decoration:none">← Admin</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">Login anomalies</h1>
        <p style="margin:.25rem 0 0;color:var(--color-gray-500);font-size:13.5px">
            Geo + impossible-travel detection on every authenticated sign-in.
            Toggle + thresholds on
            <a href="/admin/settings/security" style="color:var(--color-primary);text-decoration:none">/admin/settings/security</a>.
        </p>
    </div>
</div>

<!-- Stats -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem">
        <?php
        $cards = [
            ['Total (30d)',    (int) ($stats['total']         ?? 0), 'var(--color-gray-700)'],
            ['Info',           (int) ($stats['info']          ?? 0), 'var(--color-info)'],
            ['Warn',           (int) ($stats['warn']          ?? 0), 'var(--color-warning)'],
            ['Alert',          (int) ($stats['alerts']        ?? 0), 'var(--color-danger)'],
            ['Unacknowledged', (int) ($stats['unacknowledged']?? 0), ($stats['unacknowledged'] > 0 ? 'var(--color-danger)' : 'var(--color-success)')],
        ];
        foreach ($cards as [$label, $value, $color]):
        ?>
            <div style="flex:1 1 130px;text-align:center;padding:.5rem;border-left:3px solid <?= $color ?>;background:var(--bg-page);border-radius:4px">
                <div style="font-size:11px;color:var(--color-gray-500);text-transform:uppercase;letter-spacing:.04em"><?= $label ?></div>
                <div style="font-size:1.4rem;font-weight:700"><?= $value ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-header" style="padding:.75rem 1rem">
        <strong style="font-size:13.5px">Recent anomalies</strong>
    </div>
    <table class="table" style="width:100%;font-size:12.5px;margin:0">
        <thead style="background:var(--color-gray-50)">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">When</th>
                <th style="text-align:left;padding:.5rem .75rem">User</th>
                <th style="text-align:left;padding:.5rem .75rem">From → Prior</th>
                <th style="text-align:right;padding:.5rem .75rem">Distance</th>
                <th style="text-align:right;padding:.5rem .75rem">Speed</th>
                <th style="text-align:left;padding:.5rem .75rem">Severity</th>
                <th style="text-align:left;padding:.5rem .75rem">Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recent)): ?>
                <tr><td colspan="8" style="padding:1.5rem;text-align:center;color:var(--color-success)">No anomalies detected.</td></tr>
            <?php else: foreach ($recent as $r):
                $sevColors = ['info' => 'var(--color-info)', 'warn' => 'var(--color-warning)', 'alert' => 'var(--color-danger)'];
                $color = $sevColors[$r['severity']] ?? 'var(--color-gray-500)';
                $isAck = !empty($r['acknowledged_at']);
            ?>
                <tr style="border-top:1px solid var(--color-gray-100);<?= !$isAck && $r['severity'] === 'alert' ? 'background:var(--color-danger-bg)' : '' ?>">
                    <td style="padding:.4rem .75rem;color:var(--color-gray-500);font-size:11.5px;white-space:nowrap"><?= e(date('M j, g:ia', strtotime((string) $r['created_at']))) ?></td>
                    <td style="padding:.4rem .75rem">
                        <a href="/admin/users/<?= (int) $r['user_id'] ?>" style="color:var(--color-primary);text-decoration:none"><?= e($r['username'] ?? '?') ?></a>
                    </td>
                    <td style="padding:.4rem .75rem;font-size:11.5px;color:var(--color-gray-700)">
                        <strong><?= e($r['city'] ?? '?') ?>, <?= e($r['country_code'] ?? '?') ?></strong>
                        <span style="color:var(--color-gray-400)"> ← </span>
                        <span style="color:var(--color-gray-500)"><?= e($r['prior_city'] ?? '?') ?>, <?= e($r['prior_country_code'] ?? '?') ?></span>
                    </td>
                    <td style="padding:.4rem .75rem;text-align:right"><?= $r['distance_km'] !== null ? number_format((int) $r['distance_km']) . ' km' : '—' ?></td>
                    <td style="padding:.4rem .75rem;text-align:right;<?= ((int) ($r['implied_kmh'] ?? 0)) >= 2000 ? 'color:var(--color-danger);font-weight:600' : '' ?>"><?= $r['implied_kmh'] !== null ? number_format((int) $r['implied_kmh']) . ' km/h' : '—' ?></td>
                    <td style="padding:.4rem .75rem">
                        <span style="display:inline-block;padding:.1rem .5rem;border-radius:999px;color:var(--bg-panel);font-size:10px;background:<?= $color ?>"><?= e($r['severity']) ?></span>
                        <div style="font-size:10.5px;color:var(--color-gray-400);margin-top:.15rem"><?= e($r['rule']) ?></div>
                    </td>
                    <td style="padding:.4rem .75rem;color:var(--color-gray-500);font-size:11.5px">
                        <?php if ($isAck): ?>
                            ack by <?= e($r['ack_username'] ?? '?') ?>
                        <?php else: ?>
                            <span style="color:var(--color-danger);font-weight:500">unack</span>
                            <?php if ($r['action_taken']): ?>
                                <div style="font-size:10.5px;color:var(--color-gray-400)"><?= e($r['action_taken']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.4rem .75rem;text-align:right">
                        <?php if (!$isAck): ?>
                            <form method="POST" action="/admin/security/anomalies/<?= (int) $r['id'] ?>/ack" style="display:inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:.15rem .5rem">Ack</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div style="margin:1.5rem 0;padding:1rem;background:var(--color-warning-bg);border:1px solid var(--color-warning-bg);border-radius:6px;font-size:12.5px;color:var(--color-warning-fg);line-height:1.6">
    <strong>Detection notes:</strong> Geo lookups use the free
    <a href="https://ip-api.com" target="_blank" rel="noopener" style="color:var(--color-warning-fg)">ip-api.com</a>
    service (rate-limited to 45 req/min per origin IP). Cached 30 days per IP.
    Severity escalates above 900 km/h (warn — faster than commercial flight)
    and 2000 km/h (alert — definitely VPN / proxy hop). False positives are
    common for travelers + VPN users; legitimate users may need to verify
    via the email link.
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
