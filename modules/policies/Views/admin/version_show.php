<?php $pageTitle = $kind['label'] . ' v' . $version['version_label']; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:880px;margin:0 auto;padding:0 1rem">

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/policies/<?= (int) $kind['id'] ?>" style="color:#4f46e5;text-decoration:none">
        ← <?= htmlspecialchars((string) $kind['label'], ENT_QUOTES) ?>
    </a>
</div>
<h1 style="margin:0 0 .25rem;font-size:1.3rem;font-weight:700">
    v<?= htmlspecialchars((string) $version['version_label'], ENT_QUOTES) ?>
</h1>
<div style="color:#6b7280;font-size:13px;margin-bottom:1.5rem">
    Effective <?= htmlspecialchars(date('F j, Y', strtotime((string) $version['effective_date'])), ENT_QUOTES) ?>
    · created <?= htmlspecialchars(date('M j, Y g:ia', strtotime((string) $version['created_at'])), ENT_QUOTES) ?>
</div>

<?php if (!empty($version['summary'])): ?>
<div style="background:#fafafa;border-left:3px solid #4f46e5;padding:.75rem 1rem;margin-bottom:1.5rem;font-size:13.5px">
    <strong>Change summary:</strong>
    <div style="margin-top:.25rem;color:#374151"><?= htmlspecialchars((string) $version['summary'], ENT_QUOTES) ?></div>
</div>
<?php endif; ?>

<!-- Acceptance stats -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem;display:flex;gap:1rem;align-items:center">
        <div style="font-size:1.6rem;font-weight:700;color:#4f46e5">
            <?= number_format(($stats['ratio'] ?? 0) * 100, 1) ?>%
        </div>
        <div style="font-size:13px;color:#374151;line-height:1.5">
            <?= (int) ($stats['accepted_users'] ?? 0) ?> of <?= (int) ($stats['total_users'] ?? 0) ?> active users
            have accepted this version.
        </div>
    </div>
</div>

<!-- Snapshotted body -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-header" style="padding:.75rem 1rem">
        <strong style="font-size:13.5px">Snapshotted text (this is what users see + accept)</strong>
    </div>
    <div class="card-body" style="padding:1.25rem;line-height:1.7;font-size:14px">
        <?= \Core\Validation\Validator::sanitizeHtml((string) ($version['body_html'] ?? '<em>(no body — source page was empty at bump time)</em>')) ?>
    </div>
</div>

<!-- Recent acceptance sample -->
<div class="card">
    <div class="card-header" style="padding:.75rem 1rem">
        <strong style="font-size:13.5px">Most recent 100 acceptances</strong>
    </div>
    <table class="table" style="width:100%;font-size:12.5px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.4rem .75rem">When</th>
                <th style="text-align:left;padding:.4rem .75rem">User</th>
                <th style="text-align:left;padding:.4rem .75rem">IP</th>
                <th style="text-align:left;padding:.4rem .75rem">User-Agent</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sample)): ?>
                <tr><td colspan="4" style="padding:1rem;text-align:center;color:#6b7280">Nobody has accepted this version yet.</td></tr>
            <?php else: foreach ($sample as $a):
                $ipText = $a['ip_address'] ? @inet_ntop($a['ip_address']) : '';
            ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.4rem .75rem;color:#6b7280;white-space:nowrap"><?= htmlspecialchars(date('M j, Y g:ia', strtotime((string) $a['accepted_at'])), ENT_QUOTES) ?></td>
                    <td style="padding:.4rem .75rem">
                        <?php if ($a['username']): ?>
                            <a href="/admin/users/<?= (int) $a['user_id'] ?>" style="color:#4f46e5;text-decoration:none"><?= htmlspecialchars((string) $a['username'], ENT_QUOTES) ?></a>
                        <?php else: ?>
                            <em style="color:#9ca3af">(erased user)</em>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.4rem .75rem;font-family:monospace;font-size:11px;color:#6b7280"><?= htmlspecialchars((string) ($ipText ?? ''), ENT_QUOTES) ?></td>
                    <td style="padding:.4rem .75rem;color:#9ca3af;font-size:11.5px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= htmlspecialchars((string) ($a['user_agent'] ?? ''), ENT_QUOTES) ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
