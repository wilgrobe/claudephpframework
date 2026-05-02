<?php $pageTitle = 'My policy acceptances'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:760px;margin:0 auto;padding:0 1rem">

<h1 style="margin:0 0 .25rem;font-size:1.4rem;font-weight:700">My policy acceptances</h1>
<p style="margin:0 0 1.5rem;color:#6b7280;font-size:14px">
    A record of every policy version you've accepted. We keep this so you
    have your own audit trail of what you agreed to and when.
</p>

<?php if (!empty($pending)): ?>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:1rem 1.25rem;margin-bottom:1.25rem">
    <strong style="color:#92400e">You have <?= count($pending) ?> updated polic<?= count($pending) === 1 ? 'y' : 'ies' ?> waiting for review.</strong>
    <p style="margin:.35rem 0 .75rem;color:#92400e;font-size:13px;line-height:1.5">
        We've made changes to:
        <?= htmlspecialchars(implode(', ', array_map(fn($p) => $p['kind_label'], $pending)), ENT_QUOTES) ?>.
    </p>
    <a href="/policies/accept" class="btn btn-primary" style="font-size:13px;background:#f59e0b;color:#fff">Review &amp; accept</a>
</div>
<?php endif; ?>

<div class="card">
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">Accepted</th>
                <th style="text-align:left;padding:.5rem .75rem">Policy</th>
                <th style="text-align:left;padding:.5rem .75rem">Version</th>
                <th style="text-align:left;padding:.5rem .75rem">Effective</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($history)): ?>
                <tr><td colspan="5" style="padding:1.5rem;text-align:center;color:#6b7280">
                    You haven't accepted any policies yet — they may not have been published.
                </td></tr>
            <?php else: foreach ($history as $h): ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.5rem .75rem;color:#6b7280;white-space:nowrap"><?= htmlspecialchars(date('M j, Y g:ia', strtotime((string) $h['accepted_at'])), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem"><strong><?= htmlspecialchars((string) $h['kind_label'], ENT_QUOTES) ?></strong></td>
                    <td style="padding:.5rem .75rem">v<?= htmlspecialchars((string) $h['version_label'], ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem;color:#6b7280"><?= htmlspecialchars(date('M j, Y', strtotime((string) $h['effective_date'])), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem;text-align:right">
                        <a href="/policies/<?= htmlspecialchars((string) $h['kind_slug'], ENT_QUOTES) ?>/v/<?= (int) $h['version_id'] ?>"
                           class="btn btn-secondary" style="padding:.2rem .6rem;font-size:12px">View text</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
