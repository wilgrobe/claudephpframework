<?php
// modules/profile/Views/notification_preferences.php
$pageTitle = 'Notification preferences';
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:760px;margin:0 auto">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem">
        <div>
            <h1 style="margin:0;font-size:1.3rem;font-weight:700">Notification preferences</h1>
            <p style="margin:.25rem 0 0;color:var(--text-muted);font-size:13.5px">
                Pick which alerts you want, and how you want them. Defaults are on; toggle off any
                row you don't want. Transactional notifications (account, security, billing) always
                send and aren't shown here.
            </p>
        </div>
        <a href="/profile/edit" class="btn btn-sm btn-secondary">← Back to profile</a>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem"><?= e($_SESSION['success']) ?></div>
    <?php endif; ?>

    <form method="POST" action="/profile/notifications" class="card" style="padding:0">
        <?= csrf_field() ?>

        <?php
            // Group types by their `group` so the table reads naturally:
            // all Social rows together, all Messaging together, etc.
            $grouped = [];
            foreach ($types as $key => $meta) {
                $grouped[$meta['group']][$key] = $meta;
            }
        ?>

        <?php foreach ($grouped as $groupName => $groupTypes): ?>
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border-default)">
            <h2 style="margin:0 0 .65rem;font-size:.95rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
                <?= e($groupName) ?>
            </h2>
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="text-align:left;font-size:12px;color:var(--text-muted)">
                        <th style="padding:.4rem 0;font-weight:500"></th>
                        <th style="padding:.4rem .75rem;font-weight:500;width:90px;text-align:center">In-app</th>
                        <th style="padding:.4rem .75rem;font-weight:500;width:90px;text-align:center">Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groupTypes as $typeKey => $meta): ?>
                    <tr>
                        <td style="padding:.55rem 0;font-size:13.5px"><?= e($meta['label']) ?></td>
                        <?php foreach (['in_app', 'email'] as $ch): ?>
                        <td style="padding:.55rem .75rem;text-align:center">
                            <?php if (in_array($ch, $meta['channels'], true)): ?>
                                <?php $on = !empty($prefs[$typeKey][$ch]); ?>
                                <input type="hidden" name="prefs[<?= e($typeKey) ?>][<?= e($ch) ?>]" value="0">
                                <input type="checkbox"
                                       name="prefs[<?= e($typeKey) ?>][<?= e($ch) ?>]"
                                       value="1"
                                       <?= $on ? 'checked' : '' ?>
                                       aria-label="<?= e($meta['label']) ?> via <?= e($ch) ?>"
                                       style="transform:scale(1.2)">
                            <?php else: ?>
                                <span style="color:var(--text-subtle);font-size:12px">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <div style="padding:1rem 1.25rem;display:flex;gap:.5rem;justify-content:flex-end;background:var(--bg-page,#f9fafb)">
            <button type="submit" class="btn btn-primary">Save preferences</button>
        </div>
    </form>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
