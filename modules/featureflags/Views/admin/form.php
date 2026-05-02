<?php
$isNew = empty($flag);
$pageTitle = $isNew ? 'New feature flag' : 'Edit flag ' . $flag['key'];
$selectedGroups = !empty($flag['groups_json']) ? array_map('intval', (array) json_decode((string) $flag['groups_json'], true)) : [];
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<a href="/admin/feature-flags" style="color:#6b7280;font-size:13px;text-decoration:none">← Flags</a>

<div class="card" style="margin-top:.5rem">
    <div class="card-header"><h2 style="margin:0"><?= $isNew ? 'New flag' : 'Edit ' . e((string) $flag['key']) ?></h2></div>
    <form method="post" action="/admin/feature-flags">
        <?= csrf_field() ?>
        <div class="card-body">
            <label>Key
                <input name="key" required value="<?= e((string) ($flag['key'] ?? '')) ?>" <?= $isNew ? '' : 'readonly style="background:#f3f4f6"' ?> placeholder="new_checkout" style="width:100%">
            </label>
            <label style="display:block;margin-top:.5rem">Label
                <input name="label" required value="<?= e((string) ($flag['label'] ?? '')) ?>" style="width:100%">
            </label>
            <label style="display:block;margin-top:.5rem">Description
                <textarea name="description" rows="2" style="width:100%"><?= e((string) ($flag['description'] ?? '')) ?></textarea>
            </label>
            <div style="display:grid;gap:.75rem;grid-template-columns:1fr 1fr;margin-top:.75rem">
                <label>
                    <input type="checkbox" name="enabled" value="1" <?= !empty($flag['enabled']) ? 'checked' : '' ?>>
                    Globally enabled
                </label>
                <label>Rollout percent (0–100)
                    <input type="number" name="rollout_percent" min="0" max="100" value="<?= e((string) ($flag['rollout_percent'] ?? 100)) ?>">
                </label>
            </div>
            <label style="display:block;margin-top:.75rem">Groups that get the flag (hold Ctrl/Cmd for multi-select)
                <select name="group_ids[]" multiple size="6" style="width:100%">
                    <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= in_array((int) $g['id'], $selectedGroups, true) ? 'selected' : '' ?>>
                        <?= e((string) $g['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p style="color:#9ca3af;font-size:12px">Users in any selected group see the flag regardless of rollout percent.</p>
        </div>
        <div class="card-footer" style="padding:.75rem 1.25rem;background:#f9fafb;text-align:right">
            <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create flag' : 'Save' ?></button>
        </div>
    </form>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
