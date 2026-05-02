<?php $pageTitle = 'COPPA registration rejections'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:980px;margin:0 auto;padding:0 1rem">

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin" style="color:#4f46e5;text-decoration:none">← Admin</a>
</div>
<h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">COPPA registration rejections</h1>
<p style="margin:.25rem 0 1.5rem;color:#6b7280;font-size:13.5px">
    Sign-up attempts blocked by the age gate. Configure the toggle + minimum
    age on
    <a href="/admin/settings/access" style="color:#4f46e5;text-decoration:none">/admin/settings/access</a>.
</p>

<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem">
        <div style="flex:1 1 160px;text-align:center;padding:.5rem;border-left:3px solid #f59e0b;background:#fafafa;border-radius:4px">
            <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">Rejections (last 30d)</div>
            <div style="font-size:1.4rem;font-weight:700"><?= (int) ($stats['total_30d'] ?? 0) ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="padding:.75rem 1rem">
        <strong style="font-size:13.5px">Recent rejections</strong>
    </div>
    <table class="table" style="width:100%;font-size:12.5px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">When</th>
                <th style="text-align:left;padding:.5rem .75rem">IP</th>
                <th style="text-align:left;padding:.5rem .75rem">Email hash</th>
                <th style="text-align:right;padding:.5rem .75rem">Min age at time</th>
                <th style="text-align:left;padding:.5rem .75rem">User-Agent</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="5" style="padding:1.5rem;text-align:center;color:#6b7280">No COPPA rejections recorded.</td></tr>
            <?php else: foreach ($rows as $r):
                $payload = json_decode((string) ($r['new_values'] ?? '{}'), true) ?: [];
            ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.4rem .75rem;color:#6b7280;font-size:11.5px;white-space:nowrap"><?= e(date('M j, g:ia', strtotime((string) $r['created_at']))) ?></td>
                    <td style="padding:.4rem .75rem;font-family:ui-monospace,monospace;font-size:11.5px;color:#374151"><?= e((string) ($r['ip_address'] ?? '—')) ?></td>
                    <td style="padding:.4rem .75rem;font-family:ui-monospace,monospace;font-size:11px;color:#9ca3af"><?= e((string) ($payload['email_hash'] ?? '—')) ?></td>
                    <td style="padding:.4rem .75rem;text-align:right"><?= (int) ($payload['minimum_age'] ?? 0) ?></td>
                    <td style="padding:.4rem .75rem;color:#9ca3af;font-size:11px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string) ($r['user_agent'] ?? '')) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div style="margin:1.5rem 0;padding:1rem;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:12.5px;color:#92400e;line-height:1.6">
    <strong>Privacy note:</strong> when a sign-up is rejected for being under the
    minimum age, we DO NOT store the date of birth. The audit row records only the
    IP, the user-agent, the minimum age that was in effect, and a 16-char SHA-256
    prefix of the (lowercased) email — enough to spot patterns without retaining
    information about a child's identity.
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
