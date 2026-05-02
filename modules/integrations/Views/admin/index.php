<?php $pageTitle = 'Integrations'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1rem;flex-wrap:wrap">
    <div>
        <h1 style="margin:0;font-size:1.3rem;font-weight:700">Integrations</h1>
        <p style="margin:.35rem 0 0;color:#6b7280;font-size:13.5px;max-width:720px;line-height:1.5">
            Status dashboard for third-party services. All credentials live in <code>.env</code>.
            To change configuration, edit the file directly, save, and reload the app.
            See <code>.env.example</code> for every supported provider and its required vars.
        </p>
    </div>
</div>

<div style="display:flex;flex-direction:column;gap:.75rem">
<?php foreach ($integrations as $row): ?>

<?php if ($row['kind'] === 'multi'): /* OAuth — per-provider sub-rows */ ?>
<div class="card" style="padding:0">
    <div class="card-header" style="padding:.85rem 1.25rem">
        <div>
            <h2 style="margin:0;font-size:1rem"><?= e($row['label']) ?></h2>
            <div style="font-size:12.5px;color:#6b7280;margin-top:.15rem">
                <?= $row['configured']
                    ? '<span style="color:#10b981">● Active</span> — at least one provider is configured.'
                    : '<span style="color:#9ca3af">● Idle</span> — no providers configured.' ?>
            </div>
        </div>
    </div>
    <div>
        <?php foreach ($row['providers'] as $prov): ?>
        <div style="display:flex;gap:1rem;align-items:center;padding:.75rem 1.25rem;border-top:1px solid #f3f4f6">
            <div style="flex:1;min-width:0">
                <div style="font-weight:500;font-size:13.5px;color:#111827"><?= e($prov['label']) ?></div>
                <div style="font-size:12px;color:#6b7280;margin-top:.2rem">
                    <?= $prov['configured']
                        ? '<span style="color:#10b981">✓ Configured</span>'
                        : '<span style="color:#9ca3af">Not configured</span>' ?>
                    · Required: <?php foreach ($prov['required'] as $i => $v): ?><code style="font-size:11.5px"><?= e($v) ?></code><?= $i < count($prov['required']) - 1 ? ', ' : '' ?><?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php else: /* Single-provider integration */ ?>
<div class="card" data-integration-type="<?= e($row['type']) ?>">
    <div class="card-header" style="padding:.85rem 1.25rem;align-items:center">
        <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                <h2 style="margin:0;font-size:1rem"><?= e($row['label']) ?></h2>
                <?php if ($row['configured']): ?>
                <span class="badge badge-success" style="font-size:11px">● Active</span>
                <?php else: ?>
                <span class="badge badge-gray" style="font-size:11px">● Not configured</span>
                <?php endif; ?>
                <span style="font-size:12px;color:#6b7280">provider: <code><?= e($row['provider']) ?></code></span>
            </div>
            <div style="font-size:12.5px;color:#6b7280;margin-top:.3rem;line-height:1.5">
                Driver env var: <code><?= e($row['driver_var']) ?></code>
                <?php if (!empty($row['required'])): ?>
                · Required for current provider:
                <?php foreach ($row['required'] as $i => $v): ?><code style="font-size:11.5px"><?= e($v) ?></code><?= $i < count($row['required']) - 1 ? ', ' : '' ?><?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($row['optional'])): ?>
                <br>Optional: <?php foreach ($row['optional'] as $i => $v): ?><code style="font-size:11.5px"><?= e($v) ?></code><?= $i < count($row['optional']) - 1 ? ', ' : '' ?><?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <button type="button"
                    class="btn btn-sm btn-secondary test-integration"
                    data-type="<?= e($row['type']) ?>">Test connection</button>
        </div>
    </div>
    <div class="integration-result" style="display:none;padding:.5rem 1.25rem 1rem;font-size:13px"></div>
</div>

<?php endif; ?>

<?php endforeach; ?>
</div>

<script>
/* Test-connection buttons on the integrations dashboard.
   POSTs to /admin/integrations/test with the integration type; displays
   the JSON result inline below the card header. */
document.querySelectorAll('.test-integration').forEach(btn => {
    btn.addEventListener('click', async () => {
        const card   = btn.closest('.card');
        const result = card.querySelector('.integration-result');
        btn.disabled = true;
        btn.textContent = 'Testing…';
        result.style.display = 'block';
        result.textContent = 'Running probe…';
        result.style.color = '#6b7280';

        try {
            const res = await csrfPost('/admin/integrations/test', { type: btn.dataset.type });
            if (res && res.success) {
                result.style.color = '#065f46';
                result.textContent = '✓ ' + (res.message || 'Test succeeded.');
            } else {
                result.style.color = '#991b1b';
                result.textContent = '✗ ' + (res?.message || res?.error || 'Test failed.');
            }
        } catch (e) {
            result.style.color = '#991b1b';
            result.textContent = 'Test request failed. Check browser console.';
            console.error(e);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Test connection';
        }
    });
});
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
