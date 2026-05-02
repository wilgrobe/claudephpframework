<?php $pageTitle = ($kind['label'] ?? 'Policy') . ' — admin'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:880px;margin:0 auto;padding:0 1rem">

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/policies" style="color:#4f46e5;text-decoration:none">← Policies</a>
</div>
<h1 style="margin:0 0 1rem;font-size:1.3rem;font-weight:700">
    <?= htmlspecialchars((string) $kind['label'], ENT_QUOTES) ?>
    <?php if ($kind['is_system']): ?>
        <span style="font-size:11px;background:#f3f4f6;color:#6b7280;padding:.15rem .5rem;border-radius:999px;margin-left:.25rem">system</span>
    <?php endif; ?>
</h1>

<!-- Source page -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem">
        <h2 style="margin:0 0 .5rem;font-size:1rem">Source page</h2>
        <p style="margin:0 0 .75rem;color:#6b7280;font-size:13px;line-height:1.55">
            Pick a CMS page to use as the live source for this policy. When
            you bump the version, the page's body is snapshotted into the
            version row — later edits to the page don't change what users
            previously accepted.
        </p>
        <form method="POST" action="/admin/policies/<?= (int) $kind['id'] ?>/source" style="display:flex;gap:.5rem;align-items:center">
            <?= csrf_field() ?>
            <select name="source_page_id" style="flex:1 1 auto" aria-label="Source page id">
                <option value="">— none —</option>
                <?php foreach ($pages as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= (int) ($kind['source_page_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $p['title'], ENT_QUOTES) ?>
                        — /<?= htmlspecialchars((string) $p['slug'], ENT_QUOTES) ?>
                        <?= $p['status'] === 'draft' ? '(draft)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary" style="font-size:13px">Save</button>
        </form>
    </div>
</div>

<!-- Bump version -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem">
        <h2 style="margin:0 0 .5rem;font-size:1rem">Publish a new version</h2>
        <p style="margin:0 0 .75rem;color:#6b7280;font-size:13px;line-height:1.55">
            Snapshots the source page's current body into a new version. If
            this kind requires acceptance, every user will see the
            re-acceptance modal on their next page view.
        </p>
        <form method="POST" action="/admin/policies/<?= (int) $kind['id'] ?>/bump"
              data-confirm="Bump this version? Required-acceptance kinds will re-prompt every user."
              style="display:grid;grid-template-columns:auto auto 1fr auto;gap:.5rem;align-items:end">
            <?= csrf_field() ?>
            <label>
                <span style="display:block;font-size:11.5px;color:#6b7280;margin-bottom:.15rem">Version label</span>
                <input type="text" name="version_label" required placeholder="1.0" style="width:100px">
            </label>
            <label>
                <span style="display:block;font-size:11.5px;color:#6b7280;margin-bottom:.15rem">Effective date</span>
                <input type="date" name="effective_date" value="<?= date('Y-m-d') ?>">
            </label>
            <label>
                <span style="display:block;font-size:11.5px;color:#6b7280;margin-bottom:.15rem">Summary of changes (shown to users)</span>
                <input type="text" name="summary" placeholder="Added cookie clause, clarified data retention" style="width:100%">
            </label>
            <button type="submit" class="btn btn-primary" style="font-size:13px">Bump</button>
        </form>
    </div>
</div>

<!-- Acceptance stats -->
<?php if ($stats): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem;display:flex;gap:1rem;align-items:center">
        <div style="font-size:1.6rem;font-weight:700;color:#4f46e5">
            <?= number_format($stats['ratio'] * 100, 1) ?>%
        </div>
        <div style="font-size:13px;color:#374151;line-height:1.5">
            <?= (int) $stats['accepted_users'] ?> of <?= (int) $stats['total_users'] ?> active users have
            accepted the current version.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Version history -->
<div class="card">
    <div class="card-header" style="padding:.75rem 1rem">
        <strong style="font-size:13.5px">Version history</strong>
    </div>
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">Version</th>
                <th style="text-align:left;padding:.5rem .75rem">Effective</th>
                <th style="text-align:left;padding:.5rem .75rem">Created</th>
                <th style="text-align:left;padding:.5rem .75rem">By</th>
                <th style="text-align:left;padding:.5rem .75rem">Summary</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($versions)): ?>
                <tr><td colspan="6" style="padding:1.5rem;text-align:center;color:#6b7280">No versions yet. Bump one above.</td></tr>
            <?php else: foreach ($versions as $v):
                $current = (int) ($kind['current_version_id'] ?? 0) === (int) $v['id'];
            ?>
                <tr style="border-top:1px solid #f3f4f6;<?= $current ? 'background:#f5f3ff' : '' ?>">
                    <td style="padding:.5rem .75rem">
                        <strong>v<?= htmlspecialchars((string) $v['version_label'], ENT_QUOTES) ?></strong>
                        <?php if ($current): ?><span style="font-size:10px;background:#4f46e5;color:#fff;padding:.1rem .35rem;border-radius:999px;margin-left:.25rem">CURRENT</span><?php endif; ?>
                    </td>
                    <td style="padding:.5rem .75rem;color:#6b7280"><?= htmlspecialchars(date('M j, Y', strtotime((string) $v['effective_date'])), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem;color:#6b7280;font-size:12px"><?= htmlspecialchars(date('M j, Y g:ia', strtotime((string) $v['created_at'])), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem;color:#6b7280"><?= htmlspecialchars((string) ($v['author_username'] ?? '—'), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem;color:#374151;font-size:12px"><?= htmlspecialchars((string) ($v['summary'] ?? ''), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem;text-align:right">
                        <a href="/admin/policies/<?= (int) $kind['id'] ?>/v/<?= (int) $v['id'] ?>"
                           class="btn btn-secondary" style="padding:.2rem .6rem;font-size:12px">View</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if (!$kind['is_system']): ?>
<form method="POST" action="/admin/policies/kinds/<?= (int) $kind['id'] ?>/delete"
      data-confirm="Delete this policy kind? Acceptance history is preserved."
      style="margin-top:1.5rem">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger" style="font-size:12px;background:#ef4444;color:#fff">Delete this policy kind</button>
</form>
<?php endif; ?>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
