<?php $pageTitle = 'API keys'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<h1 style="margin:0 0 1rem 0">API keys</h1>

<?php if (!empty($justMinted)): ?>
<div class="card" style="margin-bottom:1rem;border-left:4px solid #10b981;background:#ecfdf5">
    <div class="card-body">
        <strong>Key "<?= e((string) $justMinted['name']) ?>" created.</strong>
        <p style="font-size:13px;color:#374151;margin:.5rem 0">
            This is the ONLY time you'll see the full token. Copy it now — you won't be able to retrieve it again.
        </p>
        <input type="text" value="<?= e((string) $justMinted['token']) ?>"
               readonly onclick="this.select()" aria-label="API token (read-only — copy now, will not be shown again)"
               style="width:100%;font-family:monospace;padding:.5rem;background:#fff;border:1px solid #d1d5db;border-radius:4px">
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <strong>Your keys</strong>
    </div>
    <?php if (empty($keys)): ?>
    <div class="card-body" style="color:#9ca3af;text-align:center;padding:2rem 1rem">
        No API keys yet. Create one below to integrate with the API.
    </div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Name</th><th>Suffix</th><th>Scopes</th><th>Last used</th><th>Expires</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($keys as $k): $revoked = !empty($k['revoked_at']); ?>
        <tr style="<?= $revoked ? 'opacity:.55' : '' ?>">
            <td><?= e((string) $k['name']) ?></td>
            <td><code>…<?= e((string) $k['last_four']) ?></code></td>
            <td style="font-size:12px;font-family:monospace">
                <?php $scopes = json_decode((string) $k['scopes_json'], true) ?: []; ?>
                <?= $scopes ? e(implode(' ', $scopes)) : '<span style="color:#9ca3af">(all)</span>' ?>
            </td>
            <td style="font-size:12px"><?= !empty($k['last_used_at']) ? e(date('M j, Y', strtotime((string) $k['last_used_at']))) : '—' ?></td>
            <td style="font-size:12px"><?= !empty($k['expires_at'])   ? e(date('M j, Y', strtotime((string) $k['expires_at'])))   : 'never' ?></td>
            <td><?= $revoked ? '<span class="badge badge-gray">revoked</span>' : '<span class="badge badge-success">active</span>' ?></td>
            <td>
                <?php if (!$revoked): ?>
                <form method="post" action="/account/api-keys/<?= (int) $k['id'] ?>/revoke" style="display:inline" onsubmit="return confirm('Revoke key?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><strong>Create a new key</strong></div>
    <form method="post" action="/account/api-keys">
        <?= csrf_field() ?>
        <div class="card-body">
            <label>Name (for your reference)
                <input name="name" required maxlength="120" placeholder="e.g. My laptop" style="width:100%">
            </label>
            <label style="display:block;margin-top:.5rem">Scopes (comma-separated)
                <input name="scopes" placeholder="read:store,write:content" style="width:100%">
            </label>
            <p style="color:#9ca3af;font-size:11px;margin:.25rem 0 0 0">Leave blank for a key with no scopes (restrictive). App admins publish available scope strings.</p>
            <label style="display:block;margin-top:.5rem">Expires in (days)
                <input type="number" name="expires_in_days" min="0" value="0" style="width:120px">
                <span style="color:#9ca3af;font-size:11px">0 = never</span>
            </label>
        </div>
        <div class="card-footer" style="padding:.5rem 1rem;text-align:right;background:#f9fafb">
            <button type="submit" class="btn btn-primary">Create key</button>
        </div>
    </form>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
