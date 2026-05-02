<?php $pageTitle = 'DSAR #' . (int) $row['id']; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:880px;margin:0 auto;padding:0 1rem">

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/gdpr" style="color:#4f46e5;text-decoration:none">← DSAR queue</a>
</div>
<h1 style="margin:0 0 1rem;font-size:1.3rem;font-weight:700">
    DSAR #<?= (int) $row['id'] ?>: <?= htmlspecialchars((string) $row['kind'], ENT_QUOTES) ?>
</h1>

<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem;font-size:13px">
        <div style="display:grid;grid-template-columns:140px 1fr;gap:.5rem 1rem">
            <div style="color:#6b7280">Requested by</div>
            <div>
                <?= htmlspecialchars((string) ($row['requester_name'] ?: '(no name)'), ENT_QUOTES) ?>
                &lt;<?= htmlspecialchars((string) $row['requester_email'], ENT_QUOTES) ?>&gt;
                <?php if ($row['user_id']): ?>
                    — <a href="/admin/users/<?= (int) $row['user_id'] ?>" style="color:#4f46e5;text-decoration:none">user #<?= (int) $row['user_id'] ?></a>
                <?php endif; ?>
            </div>

            <div style="color:#6b7280">Source</div>
            <div><?= htmlspecialchars((string) $row['source'], ENT_QUOTES) ?></div>

            <div style="color:#6b7280">Requested at</div>
            <div><?= htmlspecialchars(date('M j, Y g:ia T', strtotime((string) $row['requested_at'])), ENT_QUOTES) ?></div>

            <div style="color:#6b7280">SLA due</div>
            <div>
                <?= htmlspecialchars(date('M j, Y g:ia T', strtotime((string) $row['sla_due_at'])), ENT_QUOTES) ?>
                <?php if (strtotime((string) $row['sla_due_at']) < time() && !in_array($row['status'], ['completed','denied','expired'], true)): ?>
                    <span style="color:#ef4444;font-weight:600">— OVERDUE</span>
                <?php endif; ?>
            </div>

            <div style="color:#6b7280">Status</div>
            <div><strong><?= htmlspecialchars((string) $row['status'], ENT_QUOTES) ?></strong></div>

            <?php if ($row['notes']): ?>
                <div style="color:#6b7280">Notes</div>
                <div style="white-space:pre-wrap"><?= htmlspecialchars((string) $row['notes'], ENT_QUOTES) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Action: change status -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem">
        <h2 style="margin:0 0 .75rem;font-size:1rem">Update status</h2>
        <form method="POST" action="/admin/gdpr/dsar/<?= (int) $row['id'] ?>/status">
            <?= csrf_field() ?>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-start">
                <select name="status" style="font-size:13px" aria-label="Status">
                    <?php foreach (['pending','verified','in_progress','completed','denied','expired'] as $s): ?>
                        <option value="<?= $s ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
                <textarea name="notes" placeholder="Notes (optional, visible to admins only)"
                          style="flex:1 1 280px;font-size:13px;min-height:60px" aria-label="Notes (optional, visible to admins only)"><?= htmlspecialchars((string) ($row['notes'] ?? ''), ENT_QUOTES) ?></textarea>
                <button type="submit" class="btn btn-primary" style="font-size:13px">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Actions: build export / erase -->
<?php if ($row['user_id'] && in_array($row['kind'], ['access','export','erasure'], true)): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem">
        <h2 style="margin:0 0 .75rem;font-size:1rem">Fulfil this request</h2>

        <?php if (in_array($row['kind'], ['access','export'], true)): ?>
            <form method="POST" action="/admin/gdpr/dsar/<?= (int) $row['id'] ?>/build-export" style="margin-bottom:.75rem">
                <?= csrf_field() ?>
                <input type="hidden" name="dsar_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="userId"  value="<?= (int) $row['user_id'] ?>">
                <button type="submit" class="btn btn-secondary" style="font-size:13px">Build data export for user #<?= (int) $row['user_id'] ?></button>
                <span style="font-size:12px;color:#6b7280;margin-left:.5rem">
                    The download link appears below once the export is ready.
                </span>
            </form>
        <?php endif; ?>

        <?php if ($row['kind'] === 'erasure'): ?>
            <form method="POST" action="/admin/gdpr/users/<?= (int) $row['user_id'] ?>/erase"
                  data-confirm="Permanently erase user #<?= (int) $row['user_id'] ?>? This cannot be undone."
                  style="display:flex;gap:.5rem;align-items:center">
                <?= csrf_field() ?>
                <input type="text" name="confirm" placeholder='type "erase" to confirm' required style="font-size:13px;flex:1 1 200px" autocomplete="off" aria-label="Confirm">
                <button type="submit" class="btn btn-danger" style="font-size:13px;background:#ef4444;color:#fff">
                    Erase user #<?= (int) $row['user_id'] ?>
                </button>
            </form>
            <div style="margin-top:.5rem;font-size:12px;color:#6b7280;line-height:1.5">
                Runs the registry: every active module's gdprHandlers() pipes through DataPurger.
                Tables marked legal-hold are anonymised, not deleted. Audit-trail row written.
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent exports linked to this DSAR -->
<?php if (!empty($exports)): ?>
<div class="card">
    <div class="card-header" style="padding:.75rem 1rem"><strong style="font-size:13.5px">Exports for this DSAR</strong></div>
    <table class="table" style="width:100%;font-size:13px">
        <tbody>
            <?php foreach ($exports as $exp): ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.5rem .75rem;color:#6b7280;font-size:12px"><?= htmlspecialchars(date('M j, g:ia', strtotime((string) $exp['requested_at'])), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem"><?= htmlspecialchars((string) $exp['status'], ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem"><?= $exp['file_size'] ? number_format((int) $exp['file_size'] / 1024, 1) . ' KB' : '—' ?></td>
                    <td style="padding:.5rem .75rem;text-align:right">
                        <?php if ($exp['status'] === 'ready' && $exp['download_token']): ?>
                            <a href="/account/data/download/<?= htmlspecialchars((string) $exp['download_token'], ENT_QUOTES) ?>"
                               class="btn btn-secondary" style="font-size:12px;padding:.2rem .6rem">Download</a>
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
