<?php $pageTitle = 'Import #' . (int) $import['id']; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<a href="/admin/import" style="color:#6b7280;font-size:13px;text-decoration:none">← Imports</a>
<h1 style="margin:.5rem 0 1rem 0">Import #<?= (int) $import['id'] ?> · <?= e((string) $import['entity_type']) ?>
    <span class="badge" style="margin-left:.5rem"><?= e((string) $import['status']) ?></span>
</h1>

<div class="card">
    <div class="card-header"><strong>Column mapping</strong></div>
    <form method="post" action="/admin/import/<?= (int) $import['id'] ?>/map">
        <?= csrf_field() ?>
        <div class="card-body">
            <p style="color:#6b7280;font-size:13px">
                File format: <code><?= e((string) $import['file_format']) ?></code> ·
                <?= (int) $import['row_count'] ?> rows detected ·
                Source columns: <code><?= e(implode(', ', $headers)) ?></code>
            </p>
            <table class="table">
                <thead><tr><th>Target field</th><th>Source column</th></tr></thead>
                <tbody>
                <?php foreach ((array) ($handler['fields'] ?? []) as $f): ?>
                <tr>
                    <td><code><?= e($f) ?></code></td>
                    <td>
                        <select name="map_<?= e($f) ?>" aria-label="Map CSV column to field <?= e($f) ?>">
                            <option value="">— skip —</option>
                            <?php foreach ($headers as $h): ?>
                            <option value="<?= e($h) ?>" <?= ($mapping[$f] ?? '') === $h ? 'selected' : '' ?>><?= e($h) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer" style="padding:.5rem 1rem;text-align:right;background:#f9fafb">
            <button type="submit" class="btn btn-primary">Save mapping</button>
        </div>
    </form>
</div>

<?php if (!empty($mapping)): ?>
<div class="card" style="margin-top:1rem">
    <div class="card-header"><strong>Run</strong></div>
    <div class="card-body" style="display:flex;gap:.5rem">
        <form method="post" action="/admin/import/<?= (int) $import['id'] ?>/run">
            <?= csrf_field() ?>
            <input type="hidden" name="dry_run" value="1">
            <button type="submit" class="btn btn-secondary">Dry run</button>
        </form>
        <form method="post" action="/admin/import/<?= (int) $import['id'] ?>/run" onsubmit="return confirm('Import will modify the database. Continue?')">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Run import</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($import['stats_json'])): ?>
<div class="card" style="margin-top:1rem">
    <div class="card-header"><strong>Result</strong></div>
    <div class="card-body">
        <pre style="background:#f9fafb;padding:.5rem;border-radius:4px;font-size:12px;margin:0"><?= e(json_encode(json_decode((string) $import['stats_json'], true), JSON_PRETTY_PRINT)) ?></pre>
        <?php $errors = json_decode((string) ($import['errors_json'] ?? '[]'), true) ?: []; ?>
        <?php if (!empty($errors)): ?>
        <h4 style="margin-top:.5rem">Errors (first <?= count($errors) ?>)</h4>
        <table class="table">
            <thead><tr><th>Row</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach ($errors as $err): ?>
            <tr>
                <td><?= (int) ($err['row'] ?? 0) ?></td>
                <td style="font-size:13px;color:#b91c1c"><?= e((string) ($err['error'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
