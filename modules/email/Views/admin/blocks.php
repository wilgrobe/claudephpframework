<?php $pageTitle = 'Blocked sends'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:980px;margin:0 auto;padding:0 1rem">

<div style="font-size:12px;color:var(--color-gray-500);margin-bottom:.25rem">
    <a href="/admin/email-suppressions" style="color:var(--color-primary);text-decoration:none">← Suppressions</a>
</div>
<h1 style="margin:0 0 .25rem;font-size:1.3rem;font-weight:700">Blocked sends</h1>
<p style="margin:0 0 1rem;color:var(--color-gray-500);font-size:13.5px">
    Sends that were skipped because the recipient is on the suppression list.
    Useful for diagnosing "I never received the email" tickets.
</p>

<div class="card">
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <thead style="background:var(--color-gray-50)">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">When</th>
                <th style="text-align:left;padding:.5rem .75rem">Email</th>
                <th style="text-align:left;padding:.5rem .75rem">Category</th>
                <th style="text-align:left;padding:.5rem .75rem">Subject</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="4" style="padding:1.5rem;text-align:center;color:var(--color-gray-500)">No blocked sends yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr style="border-top:1px solid var(--color-gray-100)">
                    <td style="padding:.5rem .75rem;color:var(--color-gray-500);font-size:12px;white-space:nowrap"><?= e(date('M j, g:ia', strtotime((string) $r['blocked_at']))) ?></td>
                    <td style="padding:.5rem .75rem;font-family:ui-monospace,monospace;font-size:12px"><?= e($r['email']) ?></td>
                    <td style="padding:.5rem .75rem"><?= e($r['category_slug']) ?></td>
                    <td style="padding:.5rem .75rem;color:var(--color-gray-700);max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['subject'] ?? '—') ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
