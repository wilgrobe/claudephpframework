<?php $pageTitle = 'Recovery Codes'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:560px;margin:0 auto">

<?php if ($isNew && !empty($codes)): ?>
<!-- First-time display: highlight urgency -->
<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;gap:.75rem;align-items:flex-start">
    <span style="font-size:1.3rem;flex-shrink:0">🔑</span>
    <div>
        <strong style="display:block;font-size:14px;color:#92400e;margin-bottom:.25rem">Save these recovery codes now!</strong>
        <div style="font-size:13px;color:#78350f;line-height:1.5">
            These codes are shown <strong>only once</strong>. Store them somewhere safe — a password manager, printed paper, or encrypted notes. Each code can only be used once to regain access if you lose your authenticator device.
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header">
        <h2>Your Recovery Codes</h2>
        <button class="btn btn-secondary btn-sm" onclick="copyAll()">📋 Copy All</button>
    </div>
    <div class="card-body">
        <div id="code-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
            <?php foreach ($codes as $code): ?>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:.65rem 1rem;font-family:'Courier New',monospace;font-size:1rem;font-weight:700;text-align:center;letter-spacing:.08em;user-select:all">
                <?= e($code) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<a href="/profile/2fa" class="btn btn-primary" style="display:flex;justify-content:center;margin-bottom:1.25rem">
    ✓ I've saved my recovery codes
</a>

<?php else: ?>
<!-- Viewing existing codes page (no plain-text codes available) -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header">
        <h2>Recovery Codes</h2>
        <a href="/profile/2fa" class="btn btn-secondary btn-sm">← Back</a>
    </div>
    <div class="card-body">
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;font-size:13.5px;color:#374151;line-height:1.6;margin-bottom:1.25rem">
            Recovery codes are stored securely and cannot be displayed again after initial setup. If you've lost your codes or used too many, regenerate a new set below.
        </div>

        <div style="border-top:1px solid #e5e7eb;padding-top:1.25rem">
            <div style="font-weight:600;font-size:14px;margin-bottom:.5rem">Regenerate Recovery Codes</div>
            <p style="font-size:13px;color:#6b7280;margin:0 0 .75rem">This will invalidate all existing recovery codes.</p>

            <?php $error = \Core\Session::flash('error'); ?>
            <?php if ($error): ?><div style="background:#fee2e2;color:#991b1b;padding:.6rem .85rem;border-radius:6px;font-size:13px;border:1px solid #fca5a5;margin-bottom:.75rem"><?= e($error) ?></div><?php endif; ?>

            <form method="POST" action="/profile/2fa/recovery-codes" style="display:flex;gap:.6rem;align-items:flex-start">
                <?= csrf_field() ?>
                <input type="password" name="password" class="form-control" placeholder="Confirm password" required style="max-width:220px" aria-label="Confirm password">
                <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Regenerate all recovery codes? Existing codes will stop working.')">Regenerate</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

</div>

<script>
function copyAll() {
    const codes = [...document.querySelectorAll('#code-grid div')].map(d => d.textContent.trim()).join('\n');
    navigator.clipboard.writeText(codes).then(() => {
        const btn = event.target;
        btn.textContent = '✓ Copied!';
        setTimeout(() => btn.textContent = '📋 Copy All', 2000);
    });
}
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
