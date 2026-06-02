<?php $pageTitle = 'GDPR / DSAR'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:1080px;margin:0 auto;padding:0 1rem">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:var(--color-gray-500)">
            <a href="/admin" style="color:var(--color-primary);text-decoration:none">← Admin</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">GDPR / DSAR queue</h1>
    </div>
    <a href="/admin/gdpr/handlers" class="btn btn-secondary" style="font-size:12.5px">View handlers registry</a>
</div>

<!-- Stats strip (last 90 days) -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;padding:1rem">
        <?php
        $cards = [
            ['Pending',     (int) ($stats['pending']     ?? 0), 'var(--color-warning)'],
            ['Verified',    (int) ($stats['verified']    ?? 0), 'var(--color-info)'],
            ['In progress', (int) ($stats['in_progress'] ?? 0), '#8b5cf6'],
            ['Completed',   (int) ($stats['completed']   ?? 0), 'var(--color-success)'],
            ['Denied',      (int) ($stats['denied']      ?? 0), 'var(--color-gray-500)'],
            ['OVERDUE',     (int) ($stats['overdue']     ?? 0), 'var(--color-danger)'],
            ['Total',       (int) ($stats['total']       ?? 0), 'var(--color-gray-700)'],
        ];
        foreach ($cards as [$label, $value, $color]):
        ?>
            <div style="flex:1 1 110px;text-align:center;padding:.5rem;border-left:3px solid <?= $color ?>;background:var(--bg-page);border-radius:4px">
                <div style="font-size:11px;color:var(--color-gray-500);text-transform:uppercase;letter-spacing:.04em"><?= $label ?></div>
                <div style="font-size:1.4rem;font-weight:700"><?= $value ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="padding:.5rem 1rem;font-size:12px;color:var(--color-gray-500);border-top:1px solid var(--color-gray-100)">
        Activity in the last 90 days. SLA per GDPR Art. 12(3) is 30 days from request.
    </div>
</div>

<!-- Pending erasures (grace-window queue) -->
<?php if (!empty($pendingErasures)): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-header" style="padding:.75rem 1rem;background:var(--color-warning-bg)">
        <strong style="font-size:13.5px;color:var(--color-warning-fg)">⏳ Pending erasures (grace window)</strong>
    </div>
    <table class="table" style="width:100%;font-size:13px;margin:0">
        <thead style="background:var(--bg-page)">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">User</th>
                <th style="text-align:left;padding:.5rem .75rem">Requested</th>
                <th style="text-align:left;padding:.5rem .75rem">Erases at</th>
                <th style="text-align:right;padding:.5rem .75rem">Hrs left</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pendingErasures as $u): ?>
                <tr style="border-top:1px solid var(--color-gray-100)">
                    <td style="padding:.5rem .75rem">
                        <a href="/admin/users/<?= (int) $u['id'] ?>" style="color:var(--color-primary);text-decoration:none">
                            <?= htmlspecialchars((string) ($u['username'] ?? '(no username)'), ENT_QUOTES) ?>
                        </a>
                        <span style="color:var(--color-gray-500);font-size:12px">— <?= htmlspecialchars((string) $u['email'], ENT_QUOTES) ?></span>
                    </td>
                    <td style="padding:.5rem .75rem;color:var(--color-gray-500);font-size:12px"><?= htmlspecialchars(date('M j, g:ia', strtotime((string) $u['deletion_requested_at'])), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem;color:var(--color-gray-500);font-size:12px"><?= htmlspecialchars(date('M j, g:ia', strtotime((string) $u['deletion_grace_until'])), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem;text-align:right;font-weight:600;color:<?= ((int) $u['hours_remaining'] < 24) ? 'var(--color-danger)' : 'var(--color-gray-700)' ?>">
                        <?= (int) $u['hours_remaining'] ?>h
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Status filter -->
<div style="margin-bottom:.5rem;font-size:12.5px">
    Filter:
    <?php foreach (['all','pending','verified','in_progress','completed','denied'] as $f):
        $active = $filter === $f;
    ?>
        <a href="?status=<?= $f ?>" style="display:inline-block;padding:.2rem .6rem;margin-left:.25rem;border-radius:999px;text-decoration:none;
            background:<?= $active ? 'var(--color-primary)' : 'var(--color-gray-100)' ?>;color:<?= $active ? 'var(--bg-panel)' : 'var(--color-gray-700)' ?>"><?= $f ?></a>
    <?php endforeach; ?>
</div>

<!-- DSAR queue -->
<div class="card">
    <table class="table" style="width:100%;font-size:13px">
        <thead style="background:var(--color-gray-50)">
            <tr>
                <th style="text-align:left;padding:.5rem .75rem">#</th>
                <th style="text-align:left;padding:.5rem .75rem">Requested</th>
                <th style="text-align:left;padding:.5rem .75rem">Kind</th>
                <th style="text-align:left;padding:.5rem .75rem">Requester</th>
                <th style="text-align:left;padding:.5rem .75rem">Status</th>
                <th style="text-align:left;padding:.5rem .75rem">SLA</th>
                <th style="text-align:left;padding:.5rem .75rem">Handler</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" style="padding:1.5rem;text-align:center;color:var(--color-gray-500)">No DSAR rows match this filter.</td></tr>
            <?php else: foreach ($rows as $r):
                $statusColors = [
                    'pending'     => 'var(--color-warning)',
                    'verified'    => 'var(--color-info)',
                    'in_progress' => '#8b5cf6',
                    'completed'   => 'var(--color-success)',
                    'denied'      => 'var(--color-gray-500)',
                    'expired'     => 'var(--color-gray-400)',
                ];
                $color = $statusColors[$r['status']] ?? 'var(--color-gray-500)';
            ?>
                <tr style="border-top:1px solid var(--color-gray-100);<?= $r['overdue'] ? 'background:var(--color-danger-bg)' : '' ?>">
                    <td style="padding:.5rem .75rem;color:var(--color-gray-500);font-size:12px">#<?= (int) $r['id'] ?></td>
                    <td style="padding:.5rem .75rem;color:var(--color-gray-500);font-size:12px;white-space:nowrap"><?= htmlspecialchars(date('M j, g:ia', strtotime((string) $r['requested_at'])), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem"><strong><?= htmlspecialchars((string) $r['kind'], ENT_QUOTES) ?></strong></td>
                    <td style="padding:.5rem .75rem">
                        <?php if ($r['user_username']): ?>
                            <a href="/users/<?= htmlspecialchars((string) $r['user_username'], ENT_QUOTES) ?>" style="color:var(--color-primary);text-decoration:none"><?= htmlspecialchars((string) $r['user_username'], ENT_QUOTES) ?></a>
                        <?php else: ?>
                            <span style="color:var(--color-gray-500);font-size:12px"><?= htmlspecialchars((string) $r['requester_email'], ENT_QUOTES) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.5rem .75rem">
                        <span style="display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:11px;font-weight:600;color:var(--bg-panel);background:<?= $color ?>"><?= htmlspecialchars((string) $r['status'], ENT_QUOTES) ?></span>
                    </td>
                    <td style="padding:.5rem .75rem;font-size:12px;<?= $r['overdue'] ? 'color:var(--color-danger);font-weight:600' : 'color:var(--color-gray-500)' ?>">
                        <?php if ($r['overdue']): ?>OVERDUE<?php else: ?>
                            due <?= htmlspecialchars(date('M j', strtotime((string) $r['sla_due_at'])), ENT_QUOTES) ?>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.5rem .75rem;color:var(--color-gray-500);font-size:12px"><?= htmlspecialchars((string) ($r['handler_username'] ?? '—'), ENT_QUOTES) ?></td>
                    <td style="padding:.5rem .75rem;text-align:right">
                        <a href="/admin/gdpr/dsar/<?= (int) $r['id'] ?>" class="btn btn-secondary" style="padding:.2rem .6rem;font-size:12px">View</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
