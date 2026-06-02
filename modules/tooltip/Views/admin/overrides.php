<?php $pageTitle = 'Overrides — ' . (string) ($tip['label'] ?? ''); ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="card" style="max-width:820px">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h2 style="margin:0">Overrides — <?= e((string) $tip['label']) ?></h2>
        <a href="/admin/tooltips/<?= (int) $tip['id'] ?>" class="btn btn-sm btn-secondary">← Edit tooltip</a>
    </div>
    <div class="card-body" style="color:var(--color-gray-600);font-size:13px">
        Overrides replace the tooltip's content for a specific page path, route, or user role. Precedence: <strong>page → route → user_role</strong>.
        <?php if (!empty($conflicts)): ?>
        <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:.5rem .75rem;border-radius:8px;margin-top:.6rem">
            ⚠ Multiple active overrides in scope(s): <strong><?= e(implode(', ', $conflicts)) ?></strong> — the first matching value wins, the rest never apply.
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($overrides)): ?>
    <table class="table">
        <thead><tr><th>Scope</th><th>Value</th><th>Content</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($overrides as $o): ?>
        <tr style="<?= ((int) $o['is_active'] === 0) ? 'opacity:.55' : '' ?>">
            <td style="font-size:12px"><code><?= e((string) $o['scope']) ?></code></td>
            <td style="font-size:12px"><?= e((string) $o['scope_value']) ?></td>
            <td style="font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr((string) ($o['content_html'] ?? ''), 0, 80)) ?></td>
            <td><?= ((int) $o['is_active'] === 1) ? 'Yes' : 'No' ?></td>
            <td style="text-align:right">
                <form method="post" action="/admin/tooltips/overrides/<?= (int) $o['id'] ?>/delete" onsubmit="return confirm('Remove this override?')"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-danger">Remove</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="card-body" style="color:var(--color-gray-500)">No overrides yet.</div>
    <?php endif; ?>

    <form method="post" action="/admin/tooltips/<?= (int) $tip['id'] ?>/overrides" class="card-body" style="display:grid;gap:.6rem;border-top:1px solid var(--color-gray-200)"><?= csrf_field() ?>
        <div style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap">
            <label style="font-size:12px">Scope<br>
                <select name="scope" style="width:130px">
                    <?php foreach ($scopes as $s): ?><option value="<?= $s ?>"><?= e($s) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label style="font-size:12px">Value <span style="color:var(--color-gray-500)">(path / route / role slug)</span><br><input name="scope_value" required placeholder="/pricing" style="width:220px"></label>
            <label style="display:flex;align-items:center;gap:.3rem;font-size:12px;margin-bottom:.4rem"><input type="checkbox" name="is_active" value="1" checked> Active</label>
        </div>
        <label style="font-size:12px">Content<br><textarea name="content_html" rows="3" style="width:100%;font-family:ui-monospace,monospace"></textarea></label>
        <div><button class="btn btn-sm btn-primary">Add override</button></div>
    </form>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
