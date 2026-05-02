<?php $pageTitle = 'Email suppressions'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:1080px;margin:0 auto;padding:0 1rem">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:#6b7280">
            <a href="/admin" style="color:#4f46e5;text-decoration:none">← Admin</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">Email suppressions</h1>
        <p style="margin:.25rem 0 0;color:#6b7280;font-size:13.5px">
            Addresses + categories that won't receive email. Each row blocks
            that (email, category) pair from sending. The wildcard category
            <code>all</code> blocks every category — used for hard bounces
            and complaints.
        </p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/admin/email-suppressions/blocks" class="btn btn-secondary" style="font-size:12.5px">View blocked sends</a>
        <a href="/admin/email-suppressions/bounces" class="btn btn-secondary" style="font-size:12.5px">View bounce events</a>
    </div>
</div>

<!-- Stats -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem">
        <?php
        $cards = [
            ['Total',         (int) ($stats['total']      ?? 0), '#374151'],
            ['User opt-outs', (int) ($stats['user_unsub'] ?? 0), '#3b82f6'],
            ['Hard bounces',  (int) ($stats['bounces']    ?? 0), '#ef4444'],
            ['Complaints',    (int) ($stats['complaints'] ?? 0), '#f59e0b'],
            ['Admin manual',  (int) ($stats['manual']     ?? 0), '#8b5cf6'],
            ['Wildcard (all)',(int) ($stats['wildcard']   ?? 0), '#dc2626'],
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

<!-- Add form -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-header" style="padding:.75rem 1rem"><strong style="font-size:13.5px">Add suppression</strong></div>
    <div class="card-body" style="padding:1rem">
        <form method="POST" action="/admin/email-suppressions" style="display:grid;grid-template-columns:1fr 200px 1fr auto;gap:.5rem;align-items:end">
            <?= csrf_field() ?>
            <label>
                <span style="display:block;font-size:11.5px;color:#6b7280;margin-bottom:.15rem">Email</span>
                <input type="email" name="email" required style="width:100%" placeholder="user@example.com">
            </label>
            <label>
                <span style="display:block;font-size:11.5px;color:#6b7280;margin-bottom:.15rem">Category</span>
                <select name="category_slug" required style="width:100%">
                    <option value="all">all (wildcard)</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c['slug']) ?>"><?= e($c['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="q">
                <span style="display:block;font-size:11.5px;color:#6b7280;margin-bottom:.15rem">Notes (optional)</span>
                <input type="text" name="notes" style="width:100%" placeholder="Why this was added">
            </label>
            <button type="submit" class="btn btn-primary" style="font-size:13px">Add</button>
        </form>
    </div>
</div>

<!-- Search + list -->
<form method="GET" action="/admin/email-suppressions" style="margin-bottom:.75rem">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by email..." style="width:300px;padding:.4rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:13px" aria-label="Search by email..." id="q">
</form>

<div class="card">
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">Email</th>
                <th style="text-align:left;padding:.5rem .75rem">Category</th>
                <th style="text-align:left;padding:.5rem .75rem">Reason</th>
                <th style="text-align:left;padding:.5rem .75rem">Added</th>
                <th style="text-align:left;padding:.5rem .75rem">User</th>
                <th style="text-align:left;padding:.5rem .75rem">Notes</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="padding:1.5rem;text-align:center;color:#6b7280">No suppressions match.</td></tr>
            <?php else: foreach ($rows as $r):
                $reasonColors = [
                    'user_unsubscribe' => '#3b82f6',
                    'hard_bounce'      => '#ef4444',
                    'complaint'        => '#f59e0b',
                    'manual_admin'     => '#8b5cf6',
                    'api'              => '#6b7280',
                    'spam_report'      => '#dc2626',
                ];
                $color = $reasonColors[$r['reason']] ?? '#6b7280';
            ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.5rem .75rem;font-family:ui-monospace,monospace;font-size:12px"><?= e($r['email']) ?></td>
                    <td style="padding:.5rem .75rem"><?= e($r['category_slug']) ?></td>
                    <td style="padding:.5rem .75rem">
                        <span style="display:inline-block;padding:.1rem .5rem;border-radius:999px;color:#fff;font-size:11px;background:<?= $color ?>"><?= e($r['reason']) ?></span>
                    </td>
                    <td style="padding:.5rem .75rem;color:#6b7280;font-size:12px;white-space:nowrap"><?= e(date('M j, Y', strtotime((string) $r['created_at']))) ?></td>
                    <td style="padding:.5rem .75rem;color:#6b7280"><?= e($r['user_username'] ?? '—') ?></td>
                    <td style="padding:.5rem .75rem;color:#9ca3af;font-size:11.5px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['notes'] ?? '') ?></td>
                    <td style="padding:.5rem .75rem;text-align:right">
                        <form method="POST" action="/admin/email-suppressions/<?= (int) $r['id'] ?>/delete"
                              data-confirm="Remove this suppression? The address will be eligible for sending again."
                              style="display:inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:.15rem .5rem">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
