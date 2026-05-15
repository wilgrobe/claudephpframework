<?php
/** @var array  $by_group  array<string, array<int, array{type, label, channels, prefs}>> */
/** @var array  $user */
/** @var string $csrf */
$pageTitle = 'Notification preferences';
include BASE_PATH . '/app/Views/layout/header.php';
?>
<style>
.np-shell  { max-width:780px; margin:0 auto; padding:1.5rem 0; }
.np-h1     { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; }
.np-help   { color:var(--color-gray-500); font-size:13px; line-height:1.5; margin:0 0 1.5rem; }

.np-group  { background:#fff; border:1px solid var(--color-gray-200); border-radius:8px; margin-bottom:1rem; overflow:hidden; }
.np-group h2 { margin:0; padding:.7rem 1rem; background:#fafafa; border-bottom:1px solid var(--color-gray-200); font-size:13px; font-weight:700; color:var(--color-gray-700); text-transform:uppercase; letter-spacing:.05em; }

.np-row    { display:grid; grid-template-columns: 1fr 90px 90px; gap:.85rem; padding:.7rem 1rem; align-items:center; border-bottom:1px solid var(--color-gray-100); font-size:13.5px; }
.np-row:last-child { border-bottom:0; }
.np-row .label  { color:#111; }
.np-row .toggle { text-align:center; }
.np-row.head    { background:#fafafa; padding:.5rem 1rem; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--color-gray-400); font-weight:700; }
.np-row.head .label { color:var(--color-gray-400); }

.np-toggle-cell { display:inline-flex; align-items:center; justify-content:center; gap:.25rem; }
.np-toggle-cell input[type=checkbox] { transform: scale(1.2); cursor:pointer; }
.np-toggle-disabled { color:var(--color-gray-300); font-style:italic; font-size:11.5px; }

.np-actions { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; }
.btn-primary { background:var(--color-primary); color:#fff; border:1px solid var(--color-primary); padding:.5rem 1rem; border-radius:6px; font-weight:600; cursor:pointer; }
.btn-primary:hover { background:var(--color-primary-dark); }
.btn-secondary { background:#fff; color:var(--color-gray-700); border:1px solid var(--color-gray-300); padding:.5rem 1rem; border-radius:6px; font-weight:600; text-decoration:none; }
</style>

<div class="np-shell">
    <h1 class="np-h1">🔔 Notification preferences</h1>
    <p class="np-help">Pick which notifications you want to receive and through which channel. <strong>In-app</strong> notifications show up in the bell-icon dropdown + on your <code>/notifications</code> page. <strong>Email</strong> notifications also land in your inbox via the framework's MailService.</p>

    <form method="post" action="/notifications/preferences">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

        <?php foreach ($by_group as $groupName => $items): ?>
            <div class="np-group">
                <h2><?= htmlspecialchars((string) $groupName, ENT_QUOTES) ?></h2>
                <div class="np-row head">
                    <div class="label">Notify me about…</div>
                    <div class="toggle">In-app</div>
                    <div class="toggle">Email</div>
                </div>
                <?php foreach ($items as $row):
                    $type     = $row['type'];
                    $channels = $row['channels'];
                    $prefs    = $row['prefs'];
                ?>
                    <div class="np-row">
                        <div class="label">
                            <?= htmlspecialchars((string) $row['label'], ENT_QUOTES) ?>
                            <div style="font-size:11px;color:var(--color-gray-400);margin-top:.1rem"><code><?= htmlspecialchars($type, ENT_QUOTES) ?></code></div>
                        </div>
                        <?php foreach (['in_app', 'email'] as $ch): ?>
                            <div class="toggle">
                                <?php if (in_array($ch, $channels, true)): ?>
                                    <label class="np-toggle-cell">
                                        <input type="checkbox"
                                               name="prefs[<?= htmlspecialchars($type, ENT_QUOTES) ?>][<?= $ch ?>]"
                                               value="1"
                                               <?= !empty($prefs[$ch]) ? 'checked' : '' ?>>
                                    </label>
                                <?php else: ?>
                                    <span class="np-toggle-disabled">n/a</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="np-actions">
            <a href="/notifications" class="btn-secondary">Back to inbox</a>
            <button type="submit" class="btn-primary">Save preferences</button>
        </div>
    </form>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
