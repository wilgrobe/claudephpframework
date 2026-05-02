<?php $pageTitle = 'Settings'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<!-- Dedicated settings pages — purpose-built forms for grouped options that
     are easier to configure here than as loose key/value rows below. -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <a href="/admin/settings/appearance" class="btn btn-sm btn-secondary" style="background:#eef2ff;border-color:#c7d2fe;color:#4338ca">
        🎨 Appearance &rarr;
    </a>
    <a href="/admin/settings/footer" class="btn btn-sm btn-secondary" style="background:#eef2ff;border-color:#c7d2fe;color:#4338ca">
        📐 Footer &rarr;
    </a>
    <a href="/admin/settings/groups" class="btn btn-sm btn-secondary" style="background:#eef2ff;border-color:#c7d2fe;color:#4338ca">
        👥 Group Policy &rarr;
    </a>
    <a href="/admin/settings/security" class="btn btn-sm btn-secondary" style="background:#eef2ff;border-color:#c7d2fe;color:#4338ca">
        🔒 Security &amp; Privacy &rarr;
    </a>
    <a href="/admin/settings/access" class="btn btn-sm btn-secondary" style="background:#eef2ff;border-color:#c7d2fe;color:#4338ca">
        🚪 Registration &amp; Access &rarr;
    </a>
</div>

<!-- Scope tabs -->
<div style="display:flex;gap:.35rem;margin-bottom:1rem">
    <?php foreach (['site','page','function','group'] as $s): ?>
    <a href="/admin/settings?scope=<?= $s ?>"
       class="btn btn-sm <?= $scope === $s ? 'btn-primary' : 'btn-secondary' ?>"><?= ucfirst($s) ?></a>
    <?php endforeach; ?>
</div>

<?php
    $managedKeys = $managedKeys ?? []; // set by controller on scope='site'
?>
<?php if ($scope === 'site' && !empty($managedKeys)): ?>
<div style="padding:.75rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1rem;font-size:13px;color:#4338ca;line-height:1.5">
    <strong>Note:</strong> Keys managed on a dedicated page (Appearance, Footer, Group Policy, Security &amp; Privacy, Registration &amp; Access) are hidden from this grid so there's one source of truth. Edit them on their own page. The grid below is for ad-hoc or custom site settings.
</div>
<?php endif; ?>

<div class="grid grid-2" style="align-items:flex-start">

    <!-- Current settings -->
    <div class="card">
        <div class="card-header"><h2><?= ucfirst($scope) ?> Settings</h2></div>
        <?php if (empty($settings)): ?>
        <div class="card-body">
            <p style="color:#6b7280;margin:0">
                <?php if ($scope === 'site' && !empty($managedKeys)): ?>
                    No ad-hoc settings yet. Every site-level setting is currently managed on a dedicated page above.
                <?php else: ?>
                    No settings yet for this scope.
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <form method="POST" action="/admin/settings">
            <?= csrf_field() ?>
            <input type="hidden" name="scope" value="<?= e($scope) ?>">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Key</th><th>Value</th><th>Type</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($settings as $key => $meta):
                        // Back-compat: older callers of this view may have passed a flat
                        // [key => value] map. If we only see a scalar, synthesize meta.
                        $type = is_array($meta) ? ($meta['type']  ?? 'string') : 'string';
                        $raw  = is_array($meta) ? ($meta['raw']   ?? '')       : (is_array($meta) ? json_encode($meta) : (string)$meta);
                        $val  = is_array($meta) ? ($meta['value'] ?? null)     : $meta;
                        $badgeClass = [
                            'boolean' => 'badge-success',
                            'integer' => 'badge-info',
                            'json'    => 'badge-warning',
                            'text'    => 'badge-gray',
                            'string'  => 'badge-gray',
                        ][$type] ?? 'badge-gray';
                    ?>
                    <tr>
                        <td><code style="font-size:12px"><?= e($key) ?></code>
                            <input type="hidden" name="types[<?= e($key) ?>]" value="<?= e($type) ?>">
                        </td>
                        <td>
                            <?php if ($type === 'boolean'): ?>
                                <?php
                                    // Post as '1' when checked. SettingsService::cast
                                    // accepts '1', 'true', 'on', 'yes' as truthy.
                                    // The accompanying hidden input ensures a '0'
                                    // is posted when the box is unchecked — otherwise
                                    // browsers omit unchecked boxes entirely and the
                                    // save() loop never sees the key at all.
                                ?>
                                <input type="hidden" name="settings[<?= e($key) ?>]" value="0">
                                <?= toggle_switch("settings[$key]", (bool) $val, '1') ?>
                            <?php elseif ($type === 'integer'): ?>
                                <input type="number" step="1" name="settings[<?= e($key) ?>]"
                                       value="<?= e($raw) ?>"
                                       class="form-control" style="font-size:13px;max-width:160px" aria-label="<?= e($key) ?>">
                            <?php elseif ($type === 'text'): ?>
                                <textarea name="settings[<?= e($key) ?>]" rows="3"
                                          class="form-control" style="font-size:13px;font-family:inherit" aria-label="<?= e($key) ?>"><?= e($raw) ?></textarea>
                            <?php elseif ($type === 'json'): ?>
                                <textarea name="settings[<?= e($key) ?>]" rows="3"
                                          class="form-control" style="font-size:12px;font-family:ui-monospace,Menlo,Consolas,monospace" aria-label="<?= e($key) ?>"><?= e($raw) ?></textarea>
                            <?php else: /* string + anything unknown */ ?>
                                <input type="text" name="settings[<?= e($key) ?>]"
                                       value="<?= e($raw) ?>"
                                       class="form-control" style="font-size:13px" aria-label="<?= e($key) ?>">
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $badgeClass ?>"><?= e($type) ?></span></td>
                        <td>
                            <form method="POST" action="/admin/settings/delete" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="key" value="<?= e($key) ?>">
                                <input type="hidden" name="scope" value="<?= e($scope) ?>">
                                <button class="btn btn-xs btn-danger" onclick="return confirm('Delete setting?')">×</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="padding:1rem 1.25rem">
                <button type="submit" class="btn btn-primary btn-sm">Save All</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- Add setting -->
    <div class="card">
        <div class="card-header"><h2>Add Setting</h2></div>
        <div class="card-body">
            <form method="POST" action="/admin/settings">
                <?= csrf_field() ?>
                <input type="hidden" name="scope" value="<?= e($scope) ?>">
                <?php if ($scope !== 'site'): ?>
                <div class="form-group">
                    <label for="scope_key">Scope Key <span style="font-weight:400;color:#6b7280">(page slug, function name, group id…)</span></label>
                    <input type="text" name="scope_key" class="form-control" value="<?= e($scopeKey ?? '')?>" id="scope_key">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="new_key">Key *</label>
                    <input type="text" name="new_key" class="form-control" required placeholder="my_setting_key" id="new_key">
                </div>
                <div class="form-group">
                    <label for="new_value">Value</label>
                    <input type="text" name="new_value" class="form-control" id="new_value">
                </div>
                <div class="form-group">
                    <label for="new_type">Type</label>
                    <select name="new_type" class="form-control" id="new_type">
                        <option value="string">String</option>
                        <option value="integer">Integer</option>
                        <option value="boolean">Boolean</option>
                        <option value="json">JSON</option>
                        <option value="text">Text (long)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Add Setting</button>
            </form>
        </div>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
