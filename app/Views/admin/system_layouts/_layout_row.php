<?php
// app/Views/admin/system_layouts/_layout_row.php
//
// One row in the system-layout placement editor table. A fork of
// modules/pages/Views/admin/_layout_row.php with the page-chrome
// Batch A additions:
//
//   - placement_type selector: "Block" (existing) vs "Page content"
//     (the new content-slot kind that the chrome wrapper interpolates
//     a controller's rendered fragment into).
//   - slot_name input: only meaningful when placement_type is
//     content_slot; defaults to `primary`.
//   - "Page content" badge so admins can tell at a glance which rows
//     are slots vs blocks without expanding the form fields.
//
// Required vars in scope: $i (form index), $p (placement array).
// Optional: $blocksByCategory (BlockRegistry catalogue) — when missing
// the block dropdown still renders the current value as a single
// option so the row is editable in test contexts.
$__row     = (int) ($p['row_index']  ?? 0);
$__col     = (int) ($p['col_index']  ?? 0);
$__order   = (int) ($p['sort_order'] ?? 0);
$__type    = (string) ($p['placement_type'] ?? 'block');
if (!in_array($__type, ['block','content_slot'], true)) $__type = 'block';
$__slot    = (string) ($p['slot_name'] ?? '');
$__key     = (string) ($p['block_key'] ?? '');
// Hide the sentinel from the block dropdown — it's an implementation
// detail that the admin shouldn't need to know about.
if ($__type === 'content_slot' && $__key === \Core\Services\SystemLayoutService::SLOT_SENTINEL) {
    $__key = '';
}
$__vis     = (string) ($p['visible_to'] ?? 'any');
$__settings = $p['settings'] ?? [];
if (!is_string($__settings)) {
    $__settings = empty($__settings) ? '' : json_encode($__settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

$__isSlot = $__type === 'content_slot';
?>
<tr class="placement-row" data-placement-row data-placement-type="<?= e($__type) ?>">
    <td><input type="number" name="placements[<?= $i ?>][row]" value="<?= $__row ?>" min="0" max="5" class="form-control" style="font-size:13px" aria-label="Placement <?= $i + 1 ?> row"></td>
    <td><input type="number" name="placements[<?= $i ?>][col]" value="<?= $__col ?>" min="0" max="3" class="form-control" style="font-size:13px" aria-label="Placement <?= $i + 1 ?> column"></td>
    <td><input type="number" name="placements[<?= $i ?>][sort_order]" value="<?= $__order ?>" min="0" max="999" class="form-control" style="font-size:13px" aria-label="Placement <?= $i + 1 ?> sort order"></td>
    <td>
        <select name="placements[<?= $i ?>][placement_type]"
                class="form-control placement-type-select"
                style="font-size:13px"
                aria-label="Placement <?= $i + 1 ?> kind">
            <option value="block"        <?= $__type === 'block'        ? 'selected' : '' ?>>Block</option>
            <option value="content_slot" <?= $__type === 'content_slot' ? 'selected' : '' ?>>Page content</option>
        </select>
        <?php if ($__isSlot): ?>
        <span class="placement-kind-badge"
              style="display:inline-block;margin-top:.3rem;padding:.05rem .4rem;background:#ecfeff;color:#0e7490;border:1px solid #a5f3fc;border-radius:10px;font-size:10.5px;font-weight:600">
            Page content
        </span>
        <?php endif; ?>
    </td>
    <td>
        <div class="placement-block-cell" style="<?= $__isSlot ? 'display:none' : '' ?>">
            <select name="placements[<?= $i ?>][block_key]" class="form-control placement-block-select" style="font-size:13px" aria-label="Placement <?= $i + 1 ?> block">
                <option value="">— Pick a block —</option>
                <?php foreach (($blocksByCategory ?? []) as $__cat => $__blocks): ?>
                <optgroup label="<?= e($__cat) ?>">
                    <?php foreach ($__blocks as $__b):
                        $__aud = $__b->audience ?? 'any';
                        $__audSuffix = $__aud === 'any' ? '' : ' — ' . $__aud . ' only';
                    ?>
                    <option value="<?= e($__b->key) ?>" <?= $__key === $__b->key ? 'selected' : '' ?>
                            data-audience="<?= e($__aud) ?>"
                            title="<?= e($__b->description) ?>">
                        <?= e($__b->label) ?> (<?= e($__b->key) ?>)<?= $__audSuffix ?>
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
                <?php if (!empty($__key) && empty($blocksByCategory)): ?>
                <option value="<?= e($__key) ?>" selected>(currently <?= e($__key) ?>, registry empty)</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="placement-slot-cell" style="<?= $__isSlot ? '' : 'display:none' ?>">
            <input type="text"
                   name="placements[<?= $i ?>][slot_name]"
                   value="<?= e($__slot ?: 'primary') ?>"
                   class="form-control"
                   style="font-size:13px;font-family:ui-monospace,Menlo,Consolas,monospace"
                   placeholder="primary"
                   maxlength="64"
                   pattern="[a-zA-Z0-9_-]+"
                   aria-label="Placement <?= $i + 1 ?> slot name">
            <small style="display:block;color:#6b7280;font-size:11px;margin-top:.2rem">
                Filled by the route's controller. Default: <code>primary</code>.
            </small>
        </div>
    </td>
    <td>
        <select name="placements[<?= $i ?>][visible_to]"
                class="form-control placement-visibility-select"
                style="font-size:13px;<?= $__isSlot ? 'opacity:.45;pointer-events:none' : '' ?>"
                aria-label="Placement <?= $i + 1 ?> audience"
                <?= $__isSlot ? 'disabled' : '' ?>>
            <option value="any"   <?= $__vis === 'any'   ? 'selected' : '' ?>>Anyone</option>
            <option value="auth"  <?= $__vis === 'auth'  ? 'selected' : '' ?>>Logged in</option>
            <option value="guest" <?= $__vis === 'guest' ? 'selected' : '' ?>>Guests only</option>
        </select>
        <?php if ($__isSlot): ?>
        <small style="display:block;color:#6b7280;font-size:11px;margin-top:.2rem">
            Page content respects the controller's own auth gating.
        </small>
        <?php endif; ?>
    </td>
    <td>
        <textarea name="placements[<?= $i ?>][settings]" rows="1" class="form-control"
                  style="font-size:12px;font-family:ui-monospace,Menlo,Consolas,monospace"
                  placeholder='{"limit": 5}' aria-label="Placement <?= $i + 1 ?> settings JSON"><?= e($__settings) ?></textarea>
    </td>
    <td style="text-align:center">
        <label style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:.2rem;font-size:11px">
            <span class="placement-row-label"
                  style="color:#6b7280;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= e($__isSlot ? ('slot:' . ($__slot ?: 'primary')) : $__key) ?>">
                <?= $__isSlot ? ('slot:' . e($__slot ?: 'primary')) : (e($__key) ?: '—') ?>
            </span>
            <input type="checkbox" name="placements[<?= $i ?>][_delete]" value="1" class="placement-delete-checkbox">
        </label>
    </td>
</tr>
