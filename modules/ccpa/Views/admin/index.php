<?php $pageTitle = 'CCPA opt-outs'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:980px;margin:0 auto;padding:0 1rem">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:#6b7280">
            <a href="/admin" style="color:#4f46e5;text-decoration:none">← Admin</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">CCPA / CPRA opt-outs</h1>
        <p style="margin:.25rem 0 0;color:#6b7280;font-size:13.5px">
            "Do Not Sell or Share My Personal Information" records.
            Master toggle + disclosure URL configurable on
            <a href="/admin/settings/access" style="color:#4f46e5;text-decoration:none">/admin/settings/access</a>.
        </p>
    </div>
</div>

<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem">
        <?php
        $cards = [
            ['Total (90d)',     (int) ($stats['total']        ?? 0), '#374151'],
            ['Self-service',    (int) ($stats['self_service'] ?? 0), '#3b82f6'],
            ['GPC signal',      (int) ($stats['gpc_signal']   ?? 0), '#8b5cf6'],
            ['Admin-initiated', (int) ($stats['admin']        ?? 0), '#f59e0b'],
            ['Withdrawn',       (int) ($stats['withdrawn']    ?? 0), '#6b7280'],
        ];
        foreach ($cards as [$label, $value, $color]):
        ?>
            <div style="flex:1 1 130px;text-align:center;padding:.5rem;border-left:3px solid <?= $color ?>;background:#fafafa;border-radius:4px">
                <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em"><?= $label ?></div>
                <div style="font-size:1.4rem;font-weight:700"><?= $value ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-header" style="padding:.75rem 1rem">
        <strong style="font-size:13.5px">Recent opt-outs</strong>
    </div>
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">When</th>
                <th style="text-align:left;padding:.5rem .75rem">Identity</th>
                <th style="text-align:left;padding:.5rem .75rem">Source</th>
                <th style="text-align:left;padding:.5rem .75rem">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recent)): ?>
                <tr><td colspan="4" style="padding:1.5rem;text-align:center;color:#6b7280">No opt-outs recorded yet.</td></tr>
            <?php else: foreach ($recent as $r):
                $sourceColors = [
                    'self_service' => '#3b82f6',
                    'gpc_signal'   => '#8b5cf6',
                    'admin'        => '#f59e0b',
                    'api'          => '#6b7280',
                ];
                $color = $sourceColors[$r['source']] ?? '#6b7280';
                $isWithdrawn = !empty($r['withdrawn_at']);
            ?>
                <tr style="border-top:1px solid #f3f4f6;<?= $isWithdrawn ? 'opacity:.55' : '' ?>">
                    <td style="padding:.5rem .75rem;color:#6b7280;font-size:12px;white-space:nowrap"><?= e(date('M j, g:ia', strtotime((string) $r['created_at']))) ?></td>
                    <td style="padding:.5rem .75rem">
                        <?php if ($r['user_username']): ?>
                            <a href="/admin/users/<?= (int) $r['user_id'] ?>" style="color:#4f46e5;text-decoration:none"><?= e($r['user_username']) ?></a>
                            <span style="color:#9ca3af;font-size:11px">— <?= e($r['user_email']) ?></span>
                        <?php elseif ($r['email']): ?>
                            <span style="font-family:ui-monospace,monospace;font-size:12px;color:#374151"><?= e($r['email']) ?></span>
                            <span style="color:#9ca3af;font-size:11px;margin-left:.25rem">(guest)</span>
                        <?php else: ?>
                            <em style="color:#9ca3af;font-size:12px">cookie-only opt-out</em>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.5rem .75rem">
                        <span style="display:inline-block;padding:.1rem .5rem;border-radius:999px;color:#fff;font-size:11px;background:<?= $color ?>"><?= e($r['source']) ?></span>
                    </td>
                    <td style="padding:.5rem .75rem;color:#6b7280;font-size:12px">
                        <?php if ($isWithdrawn): ?>
                            withdrawn <?= e(date('M j', strtotime((string) $r['withdrawn_at']))) ?>
                        <?php else: ?>
                            <span style="color:#10b981;font-weight:500">active</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
