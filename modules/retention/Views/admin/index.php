<?php $pageTitle = 'Data retention'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:1080px;margin:0 auto;padding:0 1rem">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:#6b7280">
            <a href="/admin" style="color:#4f46e5;text-decoration:none">← Admin</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">Data retention</h1>
        <p style="margin:.25rem 0 0;color:#6b7280;font-size:13.5px;line-height:1.5">
            Time-based purge / anonymise rules. The daily sweep runs every
            enabled rule; admins can also preview a count or run a rule on
            demand. Edit any rule's retention days or action; module-declared
            defaults are preserved on edit (your overrides win).
        </p>
    </div>
    <div style="display:flex;gap:.5rem">
        <form method="POST" action="/admin/retention/sync" style="display:inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary" style="font-size:12.5px">Re-sync from modules</button>
        </form>
        <form method="POST" action="/admin/retention/run-all" data-confirm="Run every enabled retention rule now? This may delete or anonymise rows immediately." style="display:inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" style="font-size:12.5px">Run all enabled now</button>
        </form>
    </div>
</div>

<!-- Stats strip -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem">
        <?php
        $cards = [
            ['Total rules',    (int) ($totals['total']     ?? 0), '#374151'],
            ['Enabled',        (int) ($totals['enabled']   ?? 0), '#10b981'],
            ['Purge',          (int) ($totals['purge']     ?? 0), '#ef4444'],
            ['Anonymize',      (int) ($totals['anonymize'] ?? 0), '#f59e0b'],
        ];
        foreach ($cards as [$label, $value, $color]):
        ?>
            <div style="flex:1 1 140px;text-align:center;padding:.5rem;border-left:3px solid <?= $color ?>;background:#fafafa;border-radius:4px">
                <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em"><?= $label ?></div>
                <div style="font-size:1.4rem;font-weight:700"><?= $value ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Rules list grouped by module -->
<?php
$grouped = [];
foreach ($rules as $r) {
    $grouped[$r['module']][] = $r;
}
ksort($grouped);
foreach ($grouped as $modName => $modRules):
?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-header" style="padding:.75rem 1rem;background:#fafafa">
        <strong style="font-size:13px;color:#374151"><?= htmlspecialchars((string) $modName, ENT_QUOTES) ?></strong>
        <span style="color:#9ca3af;font-size:11.5px"> · <?= count($modRules) ?> rule<?= count($modRules) === 1 ? '' : 's' ?></span>
    </div>
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">Rule</th>
                <th style="text-align:left;padding:.5rem .75rem">Table</th>
                <th style="text-align:left;padding:.5rem .75rem">Action</th>
                <th style="text-align:right;padding:.5rem .75rem">Days kept</th>
                <th style="text-align:left;padding:.5rem .75rem">Last run</th>
                <th style="text-align:right;padding:.5rem .75rem">Last rows</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($modRules as $r):
                $actionColors = ['purge' => '#ef4444', 'anonymize' => '#f59e0b'];
                $color = $actionColors[$r['action']] ?? '#6b7280';
                $statusColors = ['ok' => '#10b981', 'failed' => '#ef4444', 'dry_run' => '#3b82f6'];
            ?>
                <tr style="border-top:1px solid #f3f4f6;<?= !$r['is_enabled'] ? 'opacity:.55' : '' ?>">
                    <td style="padding:.5rem .75rem">
                        <strong><?= htmlspecialchars((string) $r['label'], ENT_QUOTES) ?></strong>
                        <?php if (!$r['is_enabled']): ?>
                            <span style="font-size:10px;background:#f3f4f6;color:#6b7280;padding:.1rem .35rem;border-radius:999px;margin-left:.25rem">DISABLED</span>
                        <?php endif; ?>
                        <?php if ($r['source'] === 'admin_custom'): ?>
                            <span style="font-size:10px;background:#dbeafe;color:#1e40af;padding:.1rem .35rem;border-radius:999px;margin-left:.25rem">edited</span>
                        <?php endif; ?>
                        <div style="font-size:11.5px;color:#9ca3af;margin-top:.15rem"><code><?= htmlspecialchars((string) $r['key'], ENT_QUOTES) ?></code></div>
                    </td>
                    <td style="padding:.5rem .75rem;font-family:monospace;font-size:11.5px;color:#6b7280"><?= htmlspecialchars((string) $r['table_name'], ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem">
                        <span style="display:inline-block;padding:.1rem .5rem;border-radius:999px;color:#fff;font-size:11px;background:<?= $color ?>"><?= htmlspecialchars((string) $r['action'], ENT_QUOTES) ?></span>
                    </td>
                    <td style="padding:.5rem .75rem;text-align:right;font-weight:600"><?= (int) $r['days_keep'] ?></td>
                    <td style="padding:.5rem .75rem;font-size:12px;color:#6b7280;white-space:nowrap">
                        <?php if ($r['last_run_at']): ?>
                            <?= htmlspecialchars(date('M j, g:ia', strtotime((string) $r['last_run_at'])), ENT_QUOTES) ?>
                            <?php if ($r['last_run_status']): ?>
                                <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:<?= $statusColors[$r['last_run_status']] ?? '#6b7280' ?>;margin-left:.25rem"></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <em style="color:#9ca3af">never</em>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.5rem .75rem;text-align:right;color:#6b7280"><?= $r['last_run_rows'] !== null ? number_format((int) $r['last_run_rows']) : '—' ?></td>
                    <td style="padding:.5rem .75rem;text-align:right">
                        <a href="/admin/retention/<?= (int) $r['id'] ?>" class="btn btn-secondary" style="padding:.2rem .6rem;font-size:12px">Manage</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

<?php if (!empty($recent)): ?>
<div class="card">
    <div class="card-header" style="padding:.75rem 1rem"><strong style="font-size:13.5px">Recent runs (last 25)</strong></div>
    <table class="table" style="width:100%;font-size:12.5px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.4rem .75rem">When</th>
                <th style="text-align:left;padding:.4rem .75rem">Rule</th>
                <th style="text-align:left;padding:.4rem .75rem">Module</th>
                <th style="text-align:right;padding:.4rem .75rem">Rows</th>
                <th style="text-align:right;padding:.4rem .75rem">Duration</th>
                <th style="text-align:left;padding:.4rem .75rem">Type</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent as $r): ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.4rem .75rem;color:#6b7280;white-space:nowrap"><?= htmlspecialchars(date('M j, g:ia', strtotime((string) $r['started_at'])), ENT_QUOTES) ?></td>
                    <td style="padding:.4rem .75rem">
                        <a href="/admin/retention/<?= (int) $r['rule_id'] ?>" style="color:#4f46e5;text-decoration:none"><?= htmlspecialchars((string) $r['rule_label'], ENT_QUOTES) ?></a>
                    </td>
                    <td style="padding:.4rem .75rem;color:#6b7280"><?= htmlspecialchars((string) $r['rule_module'], ENT_QUOTES) ?></td>
                    <td style="padding:.4rem .75rem;text-align:right"><?= $r['rows_affected'] !== null ? number_format((int) $r['rows_affected']) : '—' ?></td>
                    <td style="padding:.4rem .75rem;text-align:right;color:#6b7280"><?= $r['duration_ms'] !== null ? (int) $r['duration_ms'] . ' ms' : '—' ?></td>
                    <td style="padding:.4rem .75rem">
                        <?php if ((int) $r['dry_run'] === 1): ?>
                            <span style="font-size:10px;background:#dbeafe;color:#1e40af;padding:.1rem .35rem;border-radius:999px">dry-run</span>
                        <?php elseif (!empty($r['error_message'])): ?>
                            <span style="font-size:10px;background:#fee2e2;color:#991b1b;padding:.1rem .35rem;border-radius:999px">failed</span>
                        <?php else: ?>
                            <span style="font-size:10px;background:#d1fae5;color:#065f46;padding:.1rem .35rem;border-radius:999px">ok</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
