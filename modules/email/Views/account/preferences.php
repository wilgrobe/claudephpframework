<?php $pageTitle = 'Email preferences'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:680px;margin:0 auto;padding:0 1rem">

<h1 style="margin:0 0 .25rem;font-size:1.4rem;font-weight:700">Email preferences</h1>
<p style="margin:0 0 1.5rem;color:#6b7280;font-size:14px">
    Choose which emails you want to receive at <code><?= e($email) ?></code>.
    Transactional emails (order receipts, password resets, ticket replies)
    can't be turned off — those are the messages we need to send to operate
    your account.
</p>

<form method="POST" action="/account/email-preferences">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-body" style="padding:1.25rem">
            <?php foreach ($categories as $cat):
                $isTrans   = (int) $cat['is_transactional'] === 1;
                $allowed   = !in_array($cat['slug'], $suppressed, true);
            ?>
                <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:.75rem 0;border-bottom:1px solid #f3f4f6">
                    <div style="flex:1 1 auto;padding-right:1rem">
                        <div style="font-weight:600;font-size:14px">
                            <?= e($cat['label']) ?>
                            <?php if ($isTrans): ?>
                                <span style="font-size:10px;background:#f3f4f6;color:#6b7280;padding:.1rem .35rem;border-radius:999px;margin-left:.25rem">always on</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($cat['description'])): ?>
                            <div style="font-size:12.5px;color:#6b7280;margin-top:.2rem;line-height:1.5"><?= e($cat['description']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($isTrans): ?>
                            <input type="checkbox" checked disabled aria-label="Always-on transactional category">
                        <?php else: ?>
                            <label class="toggle-switch">
                                <input type="checkbox" name="allow[<?= e($cat['slug']) ?>]" value="1" <?= $allowed ? 'checked' : '' ?> aria-label="Receive <?= e($cat['label'] ?? $cat['slug']) ?> emails">
                                <span class="toggle-slider"></span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card-footer" style="padding:.85rem 1.25rem;background:#f9fafb;border-top:1px solid #e5e7eb;text-align:right">
            <button type="submit" class="btn btn-primary" style="font-size:13.5px">Save preferences</button>
        </div>
    </div>
</form>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
