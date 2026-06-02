<?php
    $editing = !empty($tip);
    $pageTitle = $editing ? 'Edit tooltip' : 'New tooltip';
    $action = $editing ? '/admin/tooltips/' . (int) $tip['id'] : '/admin/tooltips';
    $v = fn (string $k, $d = '') => $editing ? ($tip[$k] ?? $d) : $d;
    $sel = fn ($a, $b) => ((string) $a === (string) $b) ? 'selected' : '';
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="card" style="max-width:880px">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h2 style="margin:0"><?= $editing ? 'Edit tooltip' : 'New tooltip' ?></h2>
        <a href="/admin/tooltips" class="btn btn-sm btn-secondary">← Back</a>
    </div>
    <form method="post" action="<?= $action ?>" class="card-body" style="display:grid;gap:1rem"><?= csrf_field() ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <label>Label<br><input name="label" required value="<?= e((string) $v('label')) ?>" style="width:100%"></label>
            <label>Slug <span style="color:var(--color-gray-500);font-weight:400">(stable handle)</span><br>
                <input name="slug" value="<?= e((string) $v('slug')) ?>" placeholder="pricing.enterprise" style="width:100%"></label>
        </div>

        <?php if (empty($richOn)): ?>
        <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:.5rem .75rem;border-radius:8px;font-size:13px">
            Rich content is off — bodies are stored + rendered as escaped plain text. Enable the <code>rich-content</code> submodule for markdown / HTML.
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 220px;gap:1rem;align-items:end">
            <label>Content<br><textarea name="content_html" rows="5" style="width:100%;font-family:ui-monospace,monospace"><?= e((string) $v('content_html')) ?></textarea></label>
            <label>Format<br>
                <select name="content_format" style="width:100%" <?= empty($richOn) ? 'disabled' : '' ?>>
                    <?php foreach (['markdown' => 'Markdown', 'plain' => 'Plain text', 'html' => 'HTML'] as $fv => $fl): ?>
                    <option value="<?= $fv ?>" <?= $sel($v('content_format', 'markdown'), $fv) ?>><?= $fl ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($richOn)): ?><input type="hidden" name="content_format" value="plain"><?php endif; ?>
            </label>
        </div>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem">
            <label>Category<br>
                <select name="category_id" style="width:100%">
                    <option value="">— none —</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $sel($v('category_id'), $c['id']) ?>><?= e((string) $c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Placement<br>
                <select name="placement" style="width:100%">
                    <?php foreach (['auto','top','right','bottom','left'] as $p): ?><option value="<?= $p ?>" <?= $sel($v('placement', 'auto'), $p) ?>><?= ucfirst($p) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Theme<br>
                <select name="theme" style="width:100%">
                    <?php foreach (['default','dark','light','accent','warning','info'] as $th): ?><option value="<?= $th ?>" <?= $sel($v('theme', 'default'), $th) ?>><?= ucfirst($th) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Trigger<br>
                <select name="trigger" style="width:100%">
                    <?php foreach (['hover','click','focus','manual'] as $tg): ?><option value="<?= $tg ?>" <?= $sel($v('trigger', 'hover'), $tg) ?>><?= ucfirst($tg) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem">
            <label>Max width (px)<br><input type="number" name="max_width_px" min="80" max="640" value="<?= (int) $v('max_width_px', 280) ?>" style="width:100%"></label>
            <label>Show delay (ms)<br><input type="number" name="show_delay_ms" min="0" max="5000" value="<?= (int) $v('show_delay_ms', 200) ?>" style="width:100%"></label>
            <label>Hide delay (ms)<br><input type="number" name="hide_delay_ms" min="0" max="5000" value="<?= (int) $v('hide_delay_ms', 100) ?>" style="width:100%"></label>
            <label style="display:flex;align-items:center;gap:.4rem;margin-top:1.4rem">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" <?= ((int) $v('is_active', 1) === 1) ? 'checked' : '' ?>> Active
            </label>
        </div>

        <div style="display:flex;gap:.5rem">
            <button class="btn btn-primary"><?= $editing ? 'Save tooltip' : 'Create tooltip' ?></button>
            <a href="/admin/tooltips" class="btn btn-secondary">Cancel</a>
            <?php if ($editing && !empty($overridesOn)): ?><a href="/admin/tooltips/<?= (int) $tip['id'] ?>/overrides" class="btn btn-secondary" style="margin-left:auto">Manage overrides →</a><?php endif; ?>
        </div>
    </form>
</div>

<?php if ($editing): ?>
<div class="card" style="max-width:880px;margin-top:1rem">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">Preview</h3></div>
    <div class="card-body">
        <p style="margin:0;color:var(--color-gray-600)">Here is some example copy with the tooltip inline: <?= $preview ?>. Hover or focus the trigger to see it.</p>
        <p style="font-size:12px;color:var(--color-gray-500);margin:.75rem 0 0">Save to refresh the preview after edits.</p>
    </div>
</div>
<?php endif; ?>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
