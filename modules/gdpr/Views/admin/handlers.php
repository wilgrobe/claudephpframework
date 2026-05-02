<?php $pageTitle = 'GDPR handlers registry'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:980px;margin:0 auto;padding:0 1rem">

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/gdpr" style="color:#4f46e5;text-decoration:none">← DSAR queue</a>
</div>
<h1 style="margin:0 0 .35rem;font-size:1.3rem;font-weight:700">GDPR handlers registry</h1>
<p style="margin:0 0 1rem;color:#6b7280;font-size:13.5px">
    What every active module says about its user-bearing tables. This is the map
    that DataExporter and DataPurger walk during a self-service or DSAR-driven
    erasure / export. Tables marked <em>anonymise</em> with a legal-hold reason
    survive an erasure but with PII scrubbed; tables marked <em>erase</em> are
    deleted outright.
</p>

<?php foreach ($handlers as $h): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-header" style="padding:.75rem 1rem;display:flex;justify-content:space-between;align-items:center">
        <div>
            <strong style="font-size:14px"><?= htmlspecialchars((string) $h->module, ENT_QUOTES) ?></strong>
            <div style="font-size:12px;color:#6b7280;margin-top:.15rem"><?= htmlspecialchars((string) $h->description, ENT_QUOTES) ?></div>
        </div>
        <div style="font-size:11px;color:#9ca3af">
            <?= count($h->tables) ?> table<?= count($h->tables) === 1 ? '' : 's' ?>
            <?php if ($h->customExport):  ?> · custom export<?php endif; ?>
            <?php if ($h->customErase):   ?> · custom erase<?php endif; ?>
        </div>
    </div>
    <?php if (!empty($h->tables)): ?>
    <table class="table" style="width:100%;font-size:12.5px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.4rem .75rem">Table</th>
                <th style="text-align:left;padding:.4rem .75rem">User column</th>
                <th style="text-align:left;padding:.4rem .75rem">Action</th>
                <th style="text-align:left;padding:.4rem .75rem">Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($h->tables as $t):
                $action = (string) ($t['action'] ?? 'keep');
                $colors = ['erase' => '#ef4444', 'anonymize' => '#f59e0b', 'keep' => '#10b981'];
                $color  = $colors[$action] ?? '#6b7280';
                $hold   = (string) ($t['legal_hold_reason'] ?? '');
                $skip   = isset($t['export']) && $t['export'] === false;
            ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.4rem .75rem;font-family:monospace;font-size:12px"><?= htmlspecialchars((string) $t['table'], ENT_QUOTES) ?></td>
                    <td style="padding:.4rem .75rem;font-family:monospace;font-size:12px;color:#6b7280"><?= htmlspecialchars((string) $t['user_column'], ENT_QUOTES) ?></td>
                    <td style="padding:.4rem .75rem">
                        <span style="display:inline-block;padding:.1rem .5rem;border-radius:999px;color:#fff;font-size:11px;background:<?= $color ?>"><?= htmlspecialchars($action, ENT_QUOTES) ?></span>
                    </td>
                    <td style="padding:.4rem .75rem;color:#6b7280">
                        <?= $hold ? htmlspecialchars($hold, ENT_QUOTES) : '' ?>
                        <?= $skip ? '<span style="color:#9ca3af">· not exported</span>' : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<div style="margin:1.5rem 0;padding:.85rem 1rem;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:12.5px;color:#92400e;line-height:1.6">
    <strong>Adding a new module?</strong> Override <code>gdprHandlers()</code> on its
    <code>module.php</code> to declare which tables hold user data, and how
    erasure should treat each. The simple shape is
    <code>['table' =&gt; 'foo', 'user_column' =&gt; 'user_id', 'action' =&gt; 'erase']</code>.
    For complex shapes, supply <code>customExport</code> / <code>customErase</code> closures.
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
