<?php $pageTitle = 'Active sessions'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="display:grid;gap:1rem;grid-template-columns:3fr 1fr">
<div>
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <h2 style="margin:0">Active sessions <span style="color:#9ca3af;font-size:13px">(<?= count($sessions) ?>)</span></h2>
            <?php if ($userFilter): ?>
            <a href="/admin/sessions" class="btn btn-sm btn-secondary">Clear filter</a>
            <?php endif; ?>
        </div>
        <?php if (empty($sessions)): ?>
        <div class="card-body" style="text-align:center;color:#6b7280;padding:3rem 1rem">
            No active sessions match.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>IP</th>
                        <th>Browser / agent</th>
                        <th>Last activity</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $s):
                        $ua = (string) ($s['user_agent'] ?? '');
                        $uaShort = mb_strlen($ua) > 60 ? mb_substr($ua, 0, 57) . '…' : $ua;
                        $activity = strtotime((string) $s['last_activity']);
                        $ago = max(0, time() - $activity);
                        $agoLabel = $ago < 60 ? "{$ago}s ago"
                                : ($ago < 3600 ? floor($ago / 60) . 'm ago'
                                : ($ago < 86400 ? floor($ago / 3600) . 'h ago'
                                : floor($ago / 86400) . 'd ago'));
                    ?>
                    <tr>
                        <td>
                            <?php if (!empty($s['user_id'])): ?>
                            <strong><?= e((string) ($s['username'] ?: ($s['first_name'] . ' ' . $s['last_name']))) ?></strong>
                            <div style="color:#9ca3af;font-size:11px"><?= e((string) $s['email']) ?> · id <?= (int) $s['user_id'] ?></div>
                            <?php else: ?>
                            <span style="color:#9ca3af">guest</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-family:monospace;font-size:12px"><?= e((string) ($s['ip_address'] ?? '')) ?></td>
                        <td style="font-size:12px;color:#4b5563" title="<?= e($ua) ?>"><?= e($uaShort) ?></td>
                        <td style="font-size:12px;white-space:nowrap" title="<?= e(date('Y-m-d H:i:s', $activity)) ?>">
                            <?= e($agoLabel) ?>
                        </td>
                        <td>
                            <form method="post" action="/admin/sessions/<?= e((string) $s['id']) ?>/terminate" style="display:inline" onsubmit="return confirm('Terminate this session?')">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Terminate</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<aside>
    <div class="card">
        <div class="card-header"><strong>Users with most sessions</strong></div>
        <div class="card-body" style="padding:.5rem 0">
            <?php if (empty($topUsers)): ?>
            <div style="padding:.75rem 1rem;color:#9ca3af;font-size:13px">No active authenticated sessions.</div>
            <?php else: ?>
            <?php foreach ($topUsers as $u): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 1rem;border-bottom:1px solid #f3f4f6">
                <a href="/admin/sessions?user_id=<?= (int) $u['id'] ?>" style="color:inherit;text-decoration:none">
                    <strong><?= e((string) ($u['username'] ?: $u['email'])) ?></strong>
                    <div style="font-size:11px;color:#9ca3af"><?= (int) $u['session_count'] ?> session<?= (int) $u['session_count'] === 1 ? '' : 's' ?></div>
                </a>
                <form method="post" action="/admin/sessions/user/<?= (int) $u['id'] ?>/terminate-all" style="display:inline" onsubmit="return confirm('Kick ALL of <?= e((string) ($u['username'] ?: $u['email'])) ?>\'s sessions?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger" title="Kick all">×</button>
                </form>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-top:1rem">
        <div class="card-header"><strong>Notes</strong></div>
        <div class="card-body" style="font-size:12.5px;color:#4b5563;line-height:1.5">
            Sessions live in the <code>sessions</code> DB table. Terminating a session
            deletes the row — the target's next request lands on <code>/login</code>.
            Expired sessions are swept by PHP's probabilistic GC. For emergency sign-out
            of a compromised account, click "Kick all" next to their name above.
        </div>
    </div>
</aside>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
