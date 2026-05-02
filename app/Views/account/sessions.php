<?php $pageTitle = 'Active sessions'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:760px;margin:0 auto">
<h1 style="margin:0 0 .25rem 0">Active sessions</h1>
<p style="color:#6b7280;font-size:14px;margin:0 0 1rem 0">
    Every device currently signed in as you. Sign out any device individually;
    signing out the current device logs you out here.
</p>

<?php if (empty($sessions)): ?>
<div class="card"><div class="card-body" style="color:#9ca3af;text-align:center;padding:2rem 1rem">
    No active sessions.
</div></div>
<?php else: ?>
<div class="card">
<?php foreach ($sessions as $s):
    $ua = (string) ($s['user_agent'] ?? '');
    $uaShort = mb_strlen($ua) > 80 ? mb_substr($ua, 0, 77) . '…' : ($ua ?: 'unknown device');
    $activity = strtotime((string) $s['last_activity']);
    $ago = max(0, time() - $activity);
    $agoLabel = $ago < 60 ? 'just now'
            : ($ago < 3600 ? floor($ago / 60) . ' minutes ago'
            : ($ago < 86400 ? floor($ago / 3600) . ' hours ago'
            : floor($ago / 86400) . ' days ago'));
?>
<div style="padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem">
    <div style="flex:1;min-width:0">
        <div>
            <strong style="font-size:14px" title="<?= e($ua) ?>"><?= e($uaShort) ?></strong>
            <?php if (!empty($s['is_current'])): ?>
            <span style="background:#ecfdf5;color:#059669;border:1px solid #a7f3d0;padding:.1rem .5rem;border-radius:10px;font-size:11px;margin-left:.5rem">This device</span>
            <?php endif; ?>
        </div>
        <div style="color:#9ca3af;font-size:12px;margin-top:.25rem">
            IP <?= e((string) ($s['ip_address'] ?? 'unknown')) ?> · last active <?= e($agoLabel) ?>
        </div>
    </div>
    <form method="post" action="/account/sessions/<?= e((string) $s['id']) ?>/terminate"
          onsubmit="return confirm('<?= !empty($s['is_current']) ? 'Sign out of this device? You will need to log in again.' : 'Sign out this device?' ?>')">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-danger">
            <?= !empty($s['is_current']) ? 'Sign out' : 'Sign out' ?>
        </button>
    </form>
</div>
<?php endforeach; ?>
</div>

<p style="color:#9ca3af;font-size:12px;margin-top:1rem">
    If you see a device you don't recognize, sign it out and change your password
    immediately.
</p>
<?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
