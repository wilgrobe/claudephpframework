<?php $pageTitle = 'Bounce events'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:980px;margin:0 auto;padding:0 1rem">

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/email-suppressions" style="color:#4f46e5;text-decoration:none">← Suppressions</a>
</div>
<h1 style="margin:0 0 .25rem;font-size:1.3rem;font-weight:700">Bounce events</h1>
<p style="margin:0 0 1rem;color:#6b7280;font-size:13.5px">
    Recent webhook events from email providers. Hard bounces and
    complaints automatically suppress the address (wildcard); soft
    bounces are logged only.
</p>

<div class="card">
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">When</th>
                <th style="text-align:left;padding:.5rem .75rem">Provider</th>
                <th style="text-align:left;padding:.5rem .75rem">Event</th>
                <th style="text-align:left;padding:.5rem .75rem">Email</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="4" style="padding:1.5rem;text-align:center;color:#6b7280">No bounce events yet. Configure webhook URLs at your provider to start seeing data.</td></tr>
            <?php else: foreach ($rows as $r):
                $colors = [
                    'hard_bounce' => '#ef4444',
                    'soft_bounce' => '#f59e0b',
                    'complaint'   => '#dc2626',
                    'spamreport'  => '#dc2626',
                    'unsubscribe' => '#3b82f6',
                    'bounce'      => '#f59e0b',
                    'failed'      => '#ef4444',
                    'complained'  => '#dc2626',
                ];
                $color = $colors[$r['event_type']] ?? '#6b7280';
            ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.5rem .75rem;color:#6b7280;font-size:12px;white-space:nowrap"><?= e(date('M j, g:ia', strtotime((string) $r['received_at']))) ?></td>
                    <td style="padding:.5rem .75rem;color:#6b7280"><?= e($r['provider']) ?></td>
                    <td style="padding:.5rem .75rem">
                        <span style="display:inline-block;padding:.1rem .5rem;border-radius:999px;color:#fff;font-size:11px;background:<?= $color ?>"><?= e($r['event_type']) ?></span>
                    </td>
                    <td style="padding:.5rem .75rem;font-family:ui-monospace,monospace;font-size:12px"><?= e($r['email']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
