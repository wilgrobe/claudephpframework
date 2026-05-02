<?php $pageTitle = 'Import / export'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<h1 style="margin:0 0 1rem 0">Import / export</h1>

<div style="display:grid;gap:1.5rem;grid-template-columns:1fr 1fr">
<div class="card">
    <div class="card-header"><strong>Upload to import</strong></div>
    <form method="post" action="/admin/import/upload" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="card-body">
            <label>Entity type
                <select name="entity_type" required>
                    <option value="">— pick one —</option>
                    <?php foreach ($handlers as $t => $h): ?>
                    <option value="<?= e($t) ?>"><?= e((string) $h['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:block;margin-top:.5rem">File (CSV, TSV, or JSON)
                <input type="file" name="file" required>
            </label>
            <p style="color:#9ca3af;font-size:12px">
                After upload you'll map columns to fields before anything is written.
            </p>
        </div>
        <div class="card-footer" style="padding:.5rem 1rem;text-align:right;background:#f9fafb">
            <button type="submit" class="btn btn-primary">Upload</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header"><strong>Export</strong></div>
    <div class="card-body">
        <?php if (empty($handlers)): ?>
        <div style="color:#9ca3af">No exportable types registered.</div>
        <?php else: ?>
        <ul style="list-style:none;padding:0;margin:0">
            <?php foreach ($handlers as $t => $h): ?>
            <li style="padding:.25rem 0">
                <a href="/admin/export/<?= e($t) ?>.csv"><?= e((string) $h['label']) ?></a>
                <span style="color:#9ca3af;font-size:12px;margin-left:.25rem">CSV</span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><strong>Recent imports</strong></div>
    <?php if (empty($imports)): ?>
    <div class="card-body" style="color:#9ca3af;text-align:center;padding:2rem 1rem">No imports yet.</div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>When</th><th>Entity</th><th>Status</th><th>Rows</th><th>Processed</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($imports as $i): ?>
        <tr>
            <td style="font-size:12px"><?= e(date('M j H:i', strtotime((string) $i['created_at']))) ?></td>
            <td><?= e((string) $i['entity_type']) ?></td>
            <td><span class="badge"><?= e((string) $i['status']) ?></span></td>
            <td><?= (int) $i['row_count'] ?></td>
            <td><?= (int) $i['processed_count'] ?></td>
            <td><a href="/admin/import/<?= (int) $i['id'] ?>" class="btn btn-sm btn-secondary">Open</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
