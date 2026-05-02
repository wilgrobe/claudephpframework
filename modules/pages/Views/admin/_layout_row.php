<?php
// modules/pages/Views/admin/_layout_row.php
//
// One row in the placement editor table. Included from layout.php for both
// the existing placements (server-rendered) AND the JS clone template
// (rendered with $i='__INDEX__' so the JS can swap a real index in).
//
// Required vars in scope: $i (form index), $p (placement array)
$__row     = (int) ($p['row_index']  ?? 0);
$__col     = (int) ($p['col_index']  ?? 0);
$__order   = (int) ($p['sort_order'] ?? 0);
$__key     = (string) ($p['block_key'] ?? '');
$__vis     = (string) ($p['visible_to'] ?? 'any');
$__settings = $p['settings'] ?? [];
if (!is_string($__settings)) {
    $__settings = empty($__settings) ? '' : json_encode($__settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>
<tr class="placement-row" data-placement-row>
    <td><input type="number" name="placements[<?= $i ?>][row]" value="<?= $__row ?>" min="0" max="5" class="form-control" style="font-size:13px" aria-label="Placement <?= $i + 1 ?> row"></td>
    <td><input type="number" name="placements[<?= $i ?>][col]" value="<?= $__col ?>" min="0" max="3" class="form-control" style="font-size:13px" aria-label="Placement <?= $i + 1 ?> column"></td>
    <td><input type="number" name="placements[<?= $i ?>][sort_order]" value="<?= $__order ?>" min="0" max="999" class="form-control" style="font-size:13px" aria-label="Placement <?= $i + 1 ?> sort order"></td>
    <td>
        <select name="placements[<?= $i ?>][block_key]" class="form-control placement-block-select" style="font-size:13px" aria-label="Placement <?= $i + 1 ?> block">
            <option value="">— Pick a block —</option>
            <?php foreach ($blocksByCategory as $__cat => $__blocks): ?>
            <optgroup label="<?= e($__cat) ?>">
                <?php foreach ($__blocks as $__b):
                    /* Audience hint suffix on the option label so admins
                       know which `visible_to` to pair with. Plain text
                       only — browsers render <option> as text and
                       strip child markup, so a <span> here would be
                       lost on most engines. */
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
            <?php if (!empty($__key) && !$blocksByCategory): ?>
            <option value="<?= e($__key) ?>" selected>(currently <?= e($__key) ?>, registry empty)</option>
            <?php endif; ?>
        </select>
    </td>
    <td>
        <select name="placements[<?= $i ?>][visible_to]" class="form-control" style="font-size:13px" aria-label="Placement <?= $i + 1 ?> audience">
            <option value="any"   <?= $__vis === 'any'   ? 'selected' : '' ?>>Anyone</option>
            <option value="auth"  <?= $__vis === 'auth'  ? 'selected' : '' ?>>Logged in</option>
            <option value="guest" <?= $__vis === 'guest' ? 'selected' : '' ?>>Guests only</option>
        </select>
    </td>
    <td>
        <textarea name="placements[<?= $i ?>][settings]" rows="1" class="form-control"
                  style="font-size:12px;font-family:ui-monospace,Menlo,Consolas,monospace"
                  placeholder='{"limit": 5}' aria-label="Placement <?= $i + 1 ?> settings JSON"><?= e($__settings) ?></textarea>
    </td>
    <td style="text-align:center">
        <?php /* Block-key label always visible above the checkbox so admins
                 confirm WHICH placement they're removing instead of relying on
                 row position. JS keeps it in sync when the dropdown changes
                 (see the page-layout / system-layout editor scripts). */ ?>
        <label style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:.2rem;font-size:11px">
            <span class="placement-row-label" style="color:#6b7280;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= e($__key) ?>"><?= e($__key) ?: '—' ?></span>
            <input type="checkbox" name="placements[<?= $i ?>][_delete]" value="1" class="placement-delete-checkbox">
        </label>
    </td>
</tr>
