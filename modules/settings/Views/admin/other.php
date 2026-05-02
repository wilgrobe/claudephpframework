<?php $pageTitle = 'Other Settings'; $activePanel = 'other'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<style>
/* Native dialog modal — no JS framework needed. The opener button
   calls dialog.showModal() which gives ESC-to-close + backdrop +
   focus trap for free. ::backdrop styles the overlay. */
.add-setting-dialog {
    border: 0;
    border-radius: 8px;
    padding: 0;
    width: min(480px, 92vw);
    box-shadow: 0 10px 40px rgba(0,0,0,.25);
}
.add-setting-dialog::backdrop {
    background: rgba(17, 24, 39, .55);
}
.add-setting-dialog .dialog-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.add-setting-dialog .dialog-header h2 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 600;
}
.add-setting-dialog .dialog-close {
    background: none;
    border: 0;
    font-size: 1.4rem;
    line-height: 1;
    color: #6b7280;
    cursor: pointer;
    padding: 0 .25rem;
}
.add-setting-dialog .dialog-body { padding: 1rem 1.25rem; }
.add-setting-dialog .dialog-footer {
    padding: .75rem 1.25rem;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: .5rem;
}

/* Wider key column so dotted namespaced keys stay readable. Value
   cell uses 100% so the input grows with available width. */
.other-grid .col-key   { width: 30%; min-width: 220px; font-family: monospace; font-size: 12.5px; }
.other-grid .col-value { width: 100%; }
.other-grid .col-type  { width: 90px; color: #6b7280; font-size: 12.5px; }
.other-grid .col-act   { width: 50px; text-align: right; }
.other-grid .col-value input { width: 100%; }
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1rem">
    <div>
        <h1 style="margin:0;font-size:1.4rem">Other / Unmanaged</h1>
        <p style="color:#6b7280;font-size:13.5px;margin:.35rem 0 0;max-width:640px">
            Free-form key/value rows that aren't claimed by any panel.
            In a healthy install this is mostly empty — anything common
            ends up on its own panel. Use this grid for ad-hoc /
            experimental keys or for plug-in modules that haven't
            declared their keys yet.
        </p>
    </div>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('add-setting-dialog').showModal()">
        + Add setting
    </button>
</div>

<div class="card">
    <div class="card-header"><h2 style="margin:0;font-size:1rem">Site Settings</h2></div>
    <?php if (empty($settings)): ?>
    <div class="card-body">
        <p style="color:#6b7280;margin:0">
            No ad-hoc settings yet. Every site-level setting is currently claimed by a panel.
            Click <strong>+ Add setting</strong> above to create a custom one.
        </p>
    </div>
    <?php else: ?>
    <form method="post" action="/admin/settings">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="site">
        <table class="table other-grid" style="margin:0;width:100%">
            <thead>
                <tr>
                    <th class="col-key">Key</th>
                    <th class="col-value">Value</th>
                    <th class="col-type">Type</th>
                    <th class="col-act"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($settings as $key => $meta): ?>
                <tr>
                    <td class="col-key"><?= e($key) ?></td>
                    <td class="col-value">
                        <input name="settings[<?= e($key) ?>]" class="form-control" value="<?= e((string) ($meta['raw'] ?? '')) ?>" aria-label="<?= e($key) ?>">
                    </td>
                    <td class="col-type"><?= e((string) ($meta['type'] ?? 'string')) ?></td>
                    <td class="col-act">
                        <!-- Inner delete form lives outside the outer save form via the
                             form attribute on the button — keeps the save POST clean. -->
                        <button type="submit" form="del-<?= e($key) ?>" class="btn btn-sm btn-secondary"
                                style="color:#dc2626;border-color:#fecaca" title="Delete this setting"
                                onclick="return confirm('Delete <?= e($key) ?>?')">×</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="card-body" style="background:#f9fafb;border-top:1px solid #e5e7eb;text-align:right">
            <button type="submit" class="btn btn-primary">Save All</button>
        </div>
    </form>
    <!-- Per-row delete forms rendered as siblings (the table buttons
         above reference them via form="del-{key}"). Keeps the markup
         valid (no nested forms) and the value-edit save endpoint
         doesn't get confused by a delete POST. -->
    <?php foreach ($settings as $key => $meta): ?>
    <form method="post" action="/admin/settings/delete" id="del-<?= e($key) ?>" style="display:none">
        <?= csrf_field() ?>
        <input type="hidden" name="key" value="<?= e($key) ?>">
        <input type="hidden" name="scope" value="site">
    </form>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add-new modal — native <dialog>, no JS framework. Opener button
     above calls showModal(). Submit posts to the existing /admin/settings
     endpoint so the legacy save handler picks it up unchanged. -->
<dialog id="add-setting-dialog" class="add-setting-dialog">
    <form method="post" action="/admin/settings">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="site">
        <div class="dialog-header">
            <h2>Add new setting</h2>
            <button type="button" class="dialog-close" aria-label="Close"
                    onclick="this.closest('dialog').close()">×</button>
        </div>
        <div class="dialog-body">
            <div class="form-group">
                <label for="new_key">Key</label>
                <input name="new_key" class="form-control" placeholder="namespace.key_name" required autofocus id="new_key">
                <small style="color:#6b7280">Convention: lowercase + dots / underscores. e.g. <code>integrations.foo_token</code>.</small>
            </div>
            <div class="form-group">
                <label for="new_value">Value</label>
                <input name="new_value" class="form-control" id="new_value">
            </div>
            <div class="form-group">
                <label for="new_type">Type</label>
                <select name="new_type" class="form-control" id="new_type">
                    <option value="string">string</option>
                    <option value="boolean">boolean</option>
                    <option value="integer">integer</option>
                    <option value="json">json</option>
                    <option value="text">text</option>
                </select>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Cancel</button>
            <button type="submit" class="btn btn-primary">Add Setting</button>
        </div>
    </form>
</dialog>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
