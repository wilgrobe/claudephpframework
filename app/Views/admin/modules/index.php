<?php $pageTitle = 'Modules'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:960px;margin:0 auto">

<div style="margin-bottom:1rem">
    <h1 style="margin:0 0 .25rem 0;font-size:1.4rem;font-weight:700">Modules</h1>
    <p style="color:#6b7280;font-size:13.5px;margin:0">
        Every discovered module across every configured module root, with its current
        state and declared dependencies. Disabled modules show what's missing; install
        or re-enable the missing dependency to restore them on the next request.
        Email notification on auto-disable is configured at
        <a href="/admin/settings/security" style="color:#4f46e5">Security &amp; Privacy</a>.
    </p>
    <?php if (!empty($roots)): ?>
    <div style="margin-top:.4rem;font-size:12px;color:#6b7280">
        Roots:
        <?php foreach ($roots as $i => $root): ?>
            <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;<?= is_dir($root) ? '' : 'color:#991b1b' ?>" title="<?= is_dir($root) ? 'mounted' : 'not on disk' ?>"><?= e($root) ?></code><?= $i < count($roots) - 1 ? ' ' : '' ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php
    $disabledCount = 0;
    foreach ($modules as $m) if ($m['state'] !== 'active') $disabledCount++;
?>
<?php if ($disabledCount > 0): ?>
<div style="padding:.75rem 1rem;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:6px;font-size:13.5px;margin-bottom:1rem">
    <strong><?= (int) $disabledCount ?></strong> module<?= $disabledCount === 1 ? '' : 's' ?>
    currently disabled. Resolve the missing dependencies to bring them back online.
</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table" style="margin:0">
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Tier</th>
                    <th>State</th>
                    <th>Requires</th>
                    <th>Has Blocks</th>
                    <th>Last Change</th>
                    <th style="width:120px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($modules as $m): ?>
                <tr>
                    <td>
                        <code style="font-size:12px;font-weight:600"><?= e($m['name']) ?></code>
                        <?php if (!empty($m['notice'])): ?>
                        <div style="font-size:11.5px;color:#6b7280;margin-top:.15rem"><?= e($m['notice']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $tier = $m['tier'] ?? 'core'; ?>
                        <?php if ($tier === 'premium'): ?>
                            <span class="badge badge-info" title="Premium module — gated by EntitlementCheck">premium</span>
                        <?php else: ?>
                            <span class="badge badge-gray" title="Core module — ships in the open-source repo">core</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($m['state'] === 'active'): ?>
                            <span class="badge badge-success">active</span>
                        <?php elseif ($m['state'] === 'disabled_dependency'): ?>
                            <span class="badge badge-danger">disabled — missing deps</span>
                            <?php if (!empty($m['missing'])): ?>
                            <div style="font-size:11.5px;color:#991b1b;margin-top:.25rem">
                                missing: <code><?= e(implode(', ', $m['missing'])) ?></code>
                            </div>
                            <?php endif; ?>
                        <?php elseif ($m['state'] === 'disabled_admin'): ?>
                            <span class="badge badge-warning">disabled by admin</span>
                        <?php elseif ($m['state'] === 'disabled_unlicensed'): ?>
                            <span class="badge badge-warning">disabled — unlicensed</span>
                            <div style="font-size:11.5px;color:#92400e;margin-top:.25rem">
                                Premium module not granted by the active EntitlementCheck.
                            </div>
                        <?php else: ?>
                            <span class="badge badge-gray"><?= e($m['state']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12.5px">
                        <?php if (empty($m['requires'])): ?>
                            <span style="color:#9ca3af">—</span>
                        <?php else: ?>
                            <code style="font-size:11.5px"><?= e(implode(', ', $m['requires'])) ?></code>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($m['has_blocks']): ?>
                            <span class="badge badge-info">yes</span>
                        <?php else: ?>
                            <span style="color:#9ca3af;font-size:12px">no</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#6b7280;font-size:12.5px">
                        <?= $m['updated_at'] ? date('M j, Y g:i A', strtotime($m['updated_at'])) : '<span style="color:#9ca3af">—</span>' ?>
                    </td>
                    <td>
                        <?php if ($m['state'] === 'active'): ?>
                            <form method="POST" action="/admin/modules/<?= e(rawurlencode($m['name'])) ?>/disable" style="margin:0"
                                  onsubmit="return confirm('Disable \'<?= e($m['name']) ?>\'? Routes, views, and blocks from this module stop loading on the next request. Modules that require it will cascade to disabled_dependency.')">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-xs btn-secondary" title="Mark this module as disabled by admin">Disable</button>
                            </form>
                        <?php elseif ($m['state'] === 'disabled_admin'): ?>
                            <form method="POST" action="/admin/modules/<?= e(rawurlencode($m['name'])) ?>/enable" style="margin:0"
                                  onsubmit="return confirm('Re-enable \'<?= e($m['name']) ?>\'? It will return to active on the next request (or to disabled_dependency if its requires() can\'t be satisfied).')">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-xs btn-primary">Enable</button>
                            </form>
                        <?php else: ?>
                            <span style="color:#9ca3af;font-size:11.5px" title="Disabled by missing dependencies — fix the dep to restore">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($modules)): ?>
                <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem">No modules discovered.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
