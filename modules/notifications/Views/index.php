<?php $pageTitle = 'Notifications'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:720px;margin:0 auto">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <h1 style="margin:0;font-size:1.25rem;font-weight:600">Notifications</h1>
    <?php if (!empty($notifications)): ?>
    <form method="POST" action="/notifications/mark-all-read">
        <?= csrf_field() ?>
        <button class="btn btn-secondary btn-sm">Mark all as read</button>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem">
        <div style="font-size:2.5rem;margin-bottom:.75rem">🔔</div>
        <p style="color:#6b7280;margin:0">No notifications yet.</p>
    </div>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:.5rem">
<?php foreach ($notifications as $n): ?>
<div class="notif-row" data-id="<?= e($n['id']) ?>" style="position:relative;background:#fff;border:1px solid <?= $n['read_at'] ? '#e5e7eb' : '#c7d2fe' ?>;border-radius:8px;padding:1rem 1.25rem;display:flex;gap:1rem;align-items:flex-start;<?= !$n['read_at'] ? 'background:#f5f3ff;' : '' ?>">
    <?php if (!empty($n['can_delete'])): ?>
    <!-- Dismiss (×). Only rendered once the notification is read AND any
         action it carried has been resolved. Controller enforces the same
         rule, so a tampered DOM still gets a 409. -->
    <button type="button" onclick="dismissNotification(this)"
            title="Dismiss"
            aria-label="Dismiss notification"
            style="position:absolute;top:.4rem;right:.55rem;background:none;border:none;cursor:pointer;color:#9ca3af;font-size:16px;line-height:1;padding:.15rem .35rem;border-radius:4px">×</button>
    <?php endif; ?>
    <div style="font-size:1.4rem;flex-shrink:0">
        <?php
        $icons = [
            'group.invitation'          => '👥',
            'group.owner_removal_request'=> '⚠️',
            '2fa'                       => '🔐',
            'default'                   => '🔔',
        ];
        $icon = '🔔';
        foreach ($icons as $type => $ico) {
            if (str_contains($n['type'], $type)) { $icon = $ico; break; }
        }
        echo $icon;
        ?>
    </div>
    <div style="flex:1;min-width:0">
        <div class="notif-title" style="font-weight:<?= $n['read_at'] ? '400' : '600' ?>;font-size:14px;color:#111827">
            <?= e($n['title'] ?? $n['type']) ?>
        </div>
        <?php if ($n['body']): ?>
        <div style="color:#6b7280;font-size:13.5px;margin-top:.2rem"><?= e($n['body']) ?></div>
        <?php endif; ?>
        <?php
        // Extract action data from the notification payload. Different
        // notification types store different shapes — handled below.
        $data = $n['data'] ? json_decode($n['data'], true) : [];
        $joinUrl = $data['join_url'] ?? null;

        // Group invitation: show Accept + View details.
        $inviteToken = null;
        if ($n['type'] === 'group.invitation' && $joinUrl
            && preg_match('#/join/([A-Za-z0-9]+)#', $joinUrl, $matches)) {
            $inviteToken = $matches[1];
        }

        // Owner-removal request: show Approve + Reject links to the GET
        // confirmation pages. The actual state change still requires a
        // POST from those pages (CSRF-protected), so these links are safe
        // to click from anywhere — same flow as the email's buttons.
        $removalApproveUrl = null;
        $removalRejectUrl  = null;
        if ($n['type'] === 'group.owner_removal_request'
            && !empty($data['group_id']) && !empty($data['request_id'])) {
            $gid = (int)$data['group_id'];
            $rid = (int)$data['request_id'];
            $removalApproveUrl = "/groups/$gid/owner-removal/$rid/approve";
            $removalRejectUrl  = "/groups/$gid/owner-removal/$rid/reject";
        }
        ?>

        <?php if ($inviteToken): ?>
        <div style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem;flex-wrap:wrap">
            <form method="POST" action="/join/<?= e($inviteToken) ?>" style="margin:0">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-xs btn-primary">Accept</button>
            </form>
            <a href="<?= e($joinUrl) ?>" style="font-size:13px;color:#6b7280;text-decoration:none">
                View details
            </a>
        </div>

        <?php elseif ($removalApproveUrl): ?>
        <div style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem;flex-wrap:wrap">
            <a href="<?= e($removalApproveUrl) ?>" class="btn btn-xs btn-danger">Approve removal</a>
            <a href="<?= e($removalRejectUrl) ?>"  class="btn btn-xs btn-secondary">Reject</a>
        </div>

        <?php elseif ($joinUrl): ?>
        <a href="<?= e($joinUrl) ?>" style="font-size:13px;color:#4f46e5;text-decoration:none;display:inline-block;margin-top:.35rem">
            View invitation →
        </a>
        <?php endif; ?>
    </div>
    <div style="flex-shrink:0;text-align:right">
        <div style="font-size:12px;color:#9ca3af;white-space:nowrap">
            <?= date('M j, g:i A', strtotime($n['created_at'])) ?>
        </div>
        <?php if (!$n['read_at']): ?>
        <button onclick="markRead('<?= e($n['id']) ?>', this)"
                style="font-size:11px;color:#4f46e5;background:none;border:none;cursor:pointer;padding:0;margin-top:.3rem">
            Mark read
        </button>
        <?php else: ?>
        <div style="font-size:11px;color:#9ca3af;margin-top:.3rem">Read</div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div>

<script>
/* Mark a notification as read.
   The old version used btn.closest('div[style]') which walked into the
   small right-hand column (date + button) instead of the full row, and
   then tried to querySelector a font-weight element that lived elsewhere
   in the DOM tree — so the POST succeeded but the UI threw before
   updating. Now we use the .notif-row class added for dismissNotification
   to target the outer row cleanly, and the title we relabel is found by
   a dedicated .notif-title class instead of a style-attribute guess. */
async function markRead(id, btn) {
    const row = btn.closest('.notif-row');
    if (!row) return;

    let res;
    try {
        res = await csrfPost('/notifications/' + id + '/read');
    } catch (e) {
        console.error('markRead network/parse failure', e);
        alert('Could not mark this notification as read. Please reload the page and try again.');
        return;
    }
    if (res && res.error) { alert(res.error); return; }

    row.style.background  = '#fff';
    row.style.borderColor = '#e5e7eb';
    const title = row.querySelector('.notif-title');
    if (title) title.style.fontWeight = '400';

    // Swap the button for a plain "Read" marker so the spot doesn't
    // visually collapse.
    const readMark = document.createElement('div');
    readMark.style.cssText = 'font-size:11px;color:#9ca3af;margin-top:.3rem';
    readMark.textContent = 'Read';
    btn.replaceWith(readMark);
}

/* dismissNotification(btn) is defined globally in app.js so the dashboard
   card and the notifications page share one implementation. */
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
