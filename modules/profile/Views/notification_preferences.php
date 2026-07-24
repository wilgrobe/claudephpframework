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

        <?php $delivery = $delivery ?? null; if ($delivery !== null):
            $dMode = in_array($delivery['mode'] ?? '', ['each','digest','off'], true) ? $delivery['mode'] : 'each';
            $dChan = in_array($delivery['channel'] ?? '', ['both','email','sms','none'], true) ? $delivery['channel'] : 'both';
            $modeOpts = ['each' => 'A notification for each task', 'digest' => 'One daily digest', 'off' => 'Off — don\'t remind me'];
            $chanOpts = ['both' => 'Email + SMS', 'email' => 'Email only', 'sms' => 'SMS only', 'none' => 'On-site only (no email/SMS)'];
        ?>
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border-default)">
            <h2 style="margin:0 0 .35rem;font-size:.95rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Marketing task reminders</h2>
            <p style="margin:0 0 .8rem;color:var(--text-muted);font-size:13px;max-width:70ch">How you'd like your due / overdue task reminders delivered. The per-notification grid below can still fine-tune individual channels.</p>
            <div style="display:flex;flex-wrap:wrap;gap:1.5rem 2.5rem">
                <fieldset style="border:0;margin:0;padding:0;min-width:200px">
                    <legend style="font-size:12.5px;font-weight:600;padding:0;margin-bottom:.4rem">How often</legend>
                    <?php foreach ($modeOpts as $v => $label): ?>
                    <label style="display:flex;align-items:center;gap:.5rem;font-size:14px;margin:.25rem 0;cursor:pointer">
                        <input type="radio" name="delivery_mode" value="<?= e($v) ?>" <?= $dMode === $v ? 'checked' : '' ?>> <?= e($label) ?>
                    </label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset style="border:0;margin:0;padding:0;min-width:200px">
                    <legend style="font-size:12.5px;font-weight:600;padding:0;margin-bottom:.4rem">Where to send them</legend>
                    <?php foreach ($chanOpts as $v => $label): ?>
                    <label style="display:flex;align-items:center;gap:.5rem;font-size:14px;margin:.25rem 0;cursor:pointer">
                        <input type="radio" name="delivery_channel" value="<?= e($v) ?>" <?= $dChan === $v ? 'checked' : '' ?>> <?= e($label) ?>
                    </label>
                    <?php endforeach; ?>
                </fieldset>
            </div>
            <p style="margin:.8rem 0 0;font-size:11.5px;color:var(--text-muted)">The bell (on-site) always shows reminders unless you pick “Off”. SMS also needs a verified phone + an SMS provider.</p>
        </div>
        <?php endif; ?>

        <?php
            // Group types by their `group` so the table reads naturally:
            // all Social rows together, all Messaging together, etc.
            $grouped = [];
            foreach ($types as $key => $meta) {
                $grouped[$meta['group']][$key] = $meta;
            }
            // Channel columns: only render a column if at least one type uses it,
            // so SMS appears once any type (e.g. task reminders) declares it.
            $allChannels = ['in_app' => 'In-app', 'email' => 'Email', 'sms' => 'SMS'];
            $channelCols = [];
            foreach ($allChannels as $ch => $label) {
                foreach ($types as $meta) {
                    if (in_array($ch, $meta['channels'], true)) { $channelCols[$ch] = $label; break; }
                }
            }
            $showSmsNote = isset($channelCols['sms']);
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
                        <?php foreach ($channelCols as $chLabel): ?>
                        <th style="padding:.4rem .75rem;font-weight:500;width:90px;text-align:center"><?= e($chLabel) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groupTypes as $typeKey => $meta): ?>
                    <tr>
                        <td style="padding:.55rem 0;font-size:13.5px"><?= e($meta['label']) ?></td>
                        <?php foreach (array_keys($channelCols) as $ch): ?>
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

        <?php if ($showSmsNote): ?>
        <p style="margin:0;padding:.6rem 1.25rem;font-size:12px;color:var(--text-muted);border-bottom:1px solid var(--border-default)">
            SMS reminders also need a verified mobile number on your profile and an SMS provider configured for the site — until both are set, SMS is skipped and you still get the email + in-app reminder.
        </p>
        <?php endif; ?>

        <div style="padding:1rem 1.25rem;display:flex;gap:.5rem;justify-content:flex-end;background:var(--bg-page,var(--color-gray-50))">
            <button type="submit" class="btn btn-primary">Save preferences</button>
        </div>
    </form>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
