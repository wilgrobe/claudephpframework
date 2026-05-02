<?php $pageTitle = 'Policies'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:980px;margin:0 auto;padding:0 1rem">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:#6b7280">
            <a href="/admin" style="color:#4f46e5;text-decoration:none">← Admin</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">Policies</h1>
        <p style="margin:.25rem 0 0;color:#6b7280;font-size:13.5px">
            Author each policy as a regular page; bump the version when ready
            to deploy. Required-acceptance kinds re-prompt every user on their
            next page view.
        </p>
    </div>
</div>

<div class="card" style="margin-bottom:1.25rem">
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">Policy</th>
                <th style="text-align:left;padding:.5rem .75rem">Source page</th>
                <th style="text-align:left;padding:.5rem .75rem">Current version</th>
                <th style="text-align:left;padding:.5rem .75rem">Acceptance</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($kinds as $k):
                $stats = $k['stats'] ?? ['accepted_users' => 0, 'total_users' => 0, 'ratio' => 0.0];
            ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.5rem .75rem">
                        <strong><?= htmlspecialchars((string) $k['label'], ENT_QUOTES) ?></strong>
                        <?php if ($k['requires_acceptance']): ?>
                            <span style="font-size:10px;background:#dbeafe;color:#1e40af;padding:.1rem .35rem;border-radius:999px;margin-left:.35rem">REQUIRED</span>
                        <?php endif; ?>
                        <?php if ($k['is_system']): ?>
                            <span style="font-size:10px;background:#f3f4f6;color:#6b7280;padding:.1rem .35rem;border-radius:999px;margin-left:.25rem">system</span>
                        <?php endif; ?>
                        <div style="font-size:11.5px;color:#9ca3af;margin-top:.15rem">slug: <code><?= htmlspecialchars((string) $k['slug'], ENT_QUOTES) ?></code></div>
                    </td>
                    <td style="padding:.5rem .75rem;color:#6b7280;font-size:12px">
                        <?= $k['source_page_id'] ? htmlspecialchars((string) ($k['source_page_title'] ?? ''), ENT_QUOTES) : '<em>(not set)</em>' ?>
                    </td>
                    <td style="padding:.5rem .75rem">
                        <?php if ($k['current_version_id']): ?>
                            <strong>v<?= htmlspecialchars((string) ($k['current_version_label'] ?? ''), ENT_QUOTES) ?></strong>
                            <div style="font-size:11.5px;color:#6b7280">eff. <?= htmlspecialchars(date('M j, Y', strtotime((string) ($k['current_effective_date'] ?? 'now'))), ENT_QUOTES) ?></div>
                        <?php else: ?>
                            <em style="color:#9ca3af">never published</em>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.5rem .75rem;font-size:12px">
                        <?php if ($k['current_version_id'] && $stats['total_users'] > 0): ?>
                            <strong><?= number_format($stats['ratio'] * 100, 1) ?>%</strong>
                            <span style="color:#6b7280">
                                — <?= (int) $stats['accepted_users'] ?> / <?= (int) $stats['total_users'] ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#9ca3af">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.5rem .75rem;text-align:right">
                        <a href="/admin/policies/<?= (int) $k['id'] ?>" class="btn btn-secondary" style="padding:.2rem .6rem;font-size:12px">Manage</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add custom kind -->
<div class="card">
    <div class="card-header" style="padding:.75rem 1rem"><strong style="font-size:13.5px">Add a custom policy</strong></div>
    <div class="card-body" style="padding:1rem">
        <form method="POST" action="/admin/policies/kinds" style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;align-items:end">
            <?= csrf_field() ?>
            <label style="display:block">
                <span style="display:block;font-size:12px;color:#6b7280;margin-bottom:.2rem">Slug (alphanumeric)</span>
                <input type="text" name="slug" required style="width:100%" placeholder="cookie_policy">
            </label>
            <label style="display:block">
                <span style="display:block;font-size:12px;color:#6b7280;margin-bottom:.2rem">Label</span>
                <input type="text" name="label" required style="width:100%" placeholder="Cookie Policy">
            </label>
            <label style="display:block;grid-column:1 / -1">
                <span style="display:block;font-size:12px;color:#6b7280;margin-bottom:.2rem">Description</span>
                <input type="text" name="description" style="width:100%" placeholder="Optional short description shown to admins">
            </label>
            <label style="display:flex;align-items:center;gap:.4rem;font-size:13px">
                <input type="checkbox" name="requires_acceptance" value="1">
                Requires acceptance (blocking modal)
            </label>
            <button type="submit" class="btn btn-primary" style="font-size:13px;justify-self:start">Add policy kind</button>
        </form>
    </div>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
