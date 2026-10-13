<?php $pageTitle = 'Active sessions'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<?php
// A readable name for a session's device. The raw user-agent string is ~120
// characters of "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 ..." and on a
// phone it wrapped one word per line beside the Sign out button, which made the
// page unreadable (Christy, 2026-10-12). Order matters: Edge and Opera also say
// "Chrome", and Chrome also says "Safari".
$__deviceName = static function (string $ua): string {
    if ($ua === '') return 'Unknown device';
    $browser = match (true) {
        str_contains($ua, 'Edg/')                                => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')  => 'Opera',
        str_contains($ua, 'SamsungBrowser')                      => 'Samsung Internet',
        str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
        str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') => 'Chrome',
        str_contains($ua, 'Safari/')                             => 'Safari',
        default                                                  => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'Android')                             => 'Android',
        str_contains($ua, 'iPhone')                              => 'iPhone',
        str_contains($ua, 'iPad')                                => 'iPad',
        str_contains($ua, 'CrOS')                                => 'Chromebook',
        str_contains($ua, 'Windows')                             => 'Windows',
        str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
        str_contains($ua, 'Linux')                               => 'Linux',
        default                                                  => '',
    };
    return $os !== '' ? "$browser on $os" : $browser;
};
?>
<style>
    .sess-wrap  { max-width: 760px; margin: 0 auto; }
    .sess-row   { padding: 1rem 1.25rem; border-bottom: 1px solid var(--color-gray-100); display: flex; flex-wrap: wrap;
                  justify-content: space-between; align-items: flex-start; gap: .75rem 1rem; }
    .sess-info  { flex: 1 1 16rem; min-width: 0; }
    .sess-name  { font-size: 15px; font-weight: 600; }
    .sess-badge { display: inline-block; background: var(--color-success-bg); color: #059669; border: 1px solid #a7f3d0;
                  padding: .1rem .5rem; border-radius: 10px; font-size: 11px; margin-left: .4rem; vertical-align: middle; }
    .sess-meta  { color: var(--color-gray-400); font-size: 12px; margin-top: .3rem; overflow-wrap: anywhere; }
    .sess-ua    { color: var(--color-gray-400); font-size: 11px; margin-top: .25rem; overflow-wrap: anywhere; line-height: 1.4; }
    .sess-act   { flex: 0 0 auto; }
    @media (max-width: 560px) { .sess-act { flex-basis: 100%; } }
</style>

<div class="sess-wrap">
<h1 style="margin:0 0 .25rem 0">Active sessions</h1>
<p style="color:var(--color-gray-500);font-size:14px;margin:0 0 1rem 0">
    Every device currently signed in as you. Sign out any device individually;
    signing out the current device logs you out here.
</p>

<?php if (empty($sessions)): ?>
<div class="card"><div class="card-body" style="color:var(--color-gray-400);text-align:center;padding:2rem 1rem">
    No active sessions.
</div></div>
<?php else: ?>
<div class="card">
<?php foreach ($sessions as $s):
    $ua = (string) ($s['user_agent'] ?? '');
    $activity = strtotime((string) $s['last_activity']);
    $ago = max(0, time() - $activity);
    $agoLabel = $ago < 60 ? 'just now'
            : ($ago < 3600 ? floor($ago / 60) . ' minutes ago'
            : ($ago < 86400 ? floor($ago / 3600) . ' hours ago'
            : floor($ago / 86400) . ' days ago'));
?>
<div class="sess-row">
    <div class="sess-info">
        <div>
            <span class="sess-name"><?= e($__deviceName($ua)) ?></span>
            <?php if (!empty($s['is_current'])): ?>
            <span class="sess-badge">This device</span>
            <?php endif; ?>
        </div>
        <div class="sess-meta">
            Last active <?= e($agoLabel) ?> · IP <?= e((string) ($s['ip_address'] ?? 'unknown')) ?>
        </div>
        <?php if ($ua !== ''): ?>
        <div class="sess-ua"><?= e($ua) ?></div>
        <?php endif; ?>
    </div>
    <form class="sess-act" method="post" action="/account/sessions/<?= e((string) $s['id']) ?>/terminate"
          data-mo-confirm="<?= !empty($s['is_current']) ? 'Sign out of this device? You will need to log in again.' : 'Sign out this device?' ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-danger">Sign out</button>
    </form>
</div>
<?php endforeach; ?>
</div>

<p style="color:var(--color-gray-400);font-size:12px;margin-top:1rem">
    If you see a device you don't recognize, sign it out and change your password
    immediately.
</p>
<?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
