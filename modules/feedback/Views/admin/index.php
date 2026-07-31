<?php
/**
 * Admin feedback queue. Rendered by Admin\FeedbackAdminController::index.
 *
 * @var array  $rows
 * @var array  $counts      {new, reviewed, published, archived, testimonial}
 * @var ?string $filterKind
 * @var ?string $filterStat
 */
$pageTitle = 'Feedback';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$csrf = function_exists('csrf_token') ? csrf_token() : '';
$statusBadge = [
    'new'       => 'badge-primary',
    'reviewed'  => 'badge-gray',
    'published' => 'badge-success',
    'archived'  => 'badge-warning',
];
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div class="shell shell--medium" style="max-width:960px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
        <h1 style="margin:0;font-size:1.5rem;">💬 Feedback</h1>
        <a href="/testimonials" class="btn btn-secondary btn-sm" target="_blank">View public testimonials →</a>
    </div>

    <!-- Issue-reporting settings — the switch lives next to the reports it
         produces, so an operator turning it on can see the effect here. -->
    <?php $w = $widget ?? ['enabled' => false, 'audience' => 'members', 'launcher' => 'both', 'notify' => null]; ?>
    <details class="card" style="margin-bottom:1rem;"<?= empty($w['enabled']) ? ' open' : '' ?>>
        <summary style="cursor:pointer;padding:.75rem 1rem;font-weight:600;font-size:13.5px;">
            🛠 In-app issue reporting —
            <span style="font-weight:400;color:<?= !empty($w['enabled']) ? 'var(--color-success,#16a34a)' : 'var(--text-subtle)' ?>;">
                <?= !empty($w['enabled']) ? 'on' : 'off' ?>
            </span>
        </summary>
        <div class="card-body" style="border-top:1px solid var(--color-gray-200);">
            <p style="margin:0 0 .9rem;font-size:13px;color:var(--text-subtle);line-height:1.6;">
                Shows a “Report an issue” button on every page. Reports capture the page, the signed-in
                user, and the errors their browser recorded — so you get something you can act on instead
                of “it’s broken”. New reports are emailed to you the moment they’re filed.
            </p>
            <form method="post" action="/admin/site-feedback/widget">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

                <label style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem;font-size:13.5px;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="enabled" value="1"<?= !empty($w['enabled']) ? ' checked' : '' ?>>
                    Enable the issue-report widget
                </label>

                <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-bottom:.85rem;font-size:13px;">
                    <label style="display:block;">
                        <span style="display:block;font-weight:600;margin-bottom:.25rem;">Who can see it</span>
                        <select name="audience" class="form-control" style="font-size:13px;">
                            <option value="members"<?= ($w['audience'] ?? '') === 'members' ? ' selected' : '' ?>>Signed-in users only</option>
                            <option value="everyone"<?= ($w['audience'] ?? '') === 'everyone' ? ' selected' : '' ?>>Everyone, including visitors</option>
                        </select>
                    </label>
                    <label style="display:block;">
                        <span style="display:block;font-weight:600;margin-bottom:.25rem;">Where it appears</span>
                        <select name="launcher" class="form-control" style="font-size:13px;">
                            <option value="both"<?= ($w['launcher'] ?? '') === 'both' ? ' selected' : '' ?>>Corner button + footer link</option>
                            <option value="bubble"<?= ($w['launcher'] ?? '') === 'bubble' ? ' selected' : '' ?>>Corner button only</option>
                            <option value="footer"<?= ($w['launcher'] ?? '') === 'footer' ? ' selected' : '' ?>>Footer link only</option>
                        </select>
                    </label>
                    <label style="display:block;flex:1;min-width:220px;">
                        <span style="display:block;font-weight:600;margin-bottom:.25rem;">Email new reports to</span>
                        <input type="email" name="notify_email" class="form-control" style="font-size:13px;width:100%;"
                               value="<?= $e($w['notify'] ?? '') ?>" placeholder="you@example.com">
                    </label>
                </div>

                <button class="btn btn-primary btn-sm" type="submit">Save settings</button>
            </form>
        </div>
    </details>

    <?php if (!empty($filterId)): ?>
    <!-- Arrived from a notification/email deep link. Say so, and offer the way
         back to the full queue — otherwise a single-row page looks like the
         list has been emptied. -->
    <div class="card" style="margin-bottom:1rem;border-left:3px solid var(--color-primary);">
        <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;padding:.7rem 1rem;">
            <span style="font-size:13px;">Showing report <strong>#<?= (int) $filterId ?></strong> only.</span>
            <a href="/admin/site-feedback?kind=issue" class="btn btn-secondary btn-sm">View all issues →</a>
        </div>
    </div>
    <?php if (empty($rows)): ?>
        <div class="card"><div class="card-body" style="text-align:center;padding:2rem;color:var(--text-subtle)">
            Report #<?= (int) $filterId ?> no longer exists — it may have been deleted.
        </div></div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Filters -->
    <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;font-size:13px;">
        <?php
        $tabs = [
            ''                          => 'All',
            '?status=new'               => 'New (' . (int) ($counts['new'] ?? 0) . ')',
            '?kind=issue'               => '🐞 Issues (' . (int) ($counts['issue'] ?? 0) . ')',
            '?kind=feedback'            => 'Feedback',
            '?kind=testimonial'         => 'Testimonials (' . (int) ($counts['testimonial'] ?? 0) . ')',
            '?status=published'         => 'Published (' . (int) ($counts['published'] ?? 0) . ')',
            '?status=archived'          => 'Archived (' . (int) ($counts['archived'] ?? 0) . ')',
        ];
        $curQs = ($filterKind ? '?kind=' . $filterKind : ($filterStat ? '?status=' . $filterStat : ''));
        foreach ($tabs as $qs => $label):
            $on = $curQs === $qs;
        ?>
            <a href="/admin/site-feedback<?= $e($qs) ?>" class="btn btn-sm <?= $on ? 'btn-primary' : 'btn-secondary' ?>"><?= $e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($rows)): ?>
        <div class="card"><div class="card-body" style="text-align:center;padding:2.5rem;color:var(--text-subtle)">
            No feedback yet. Submissions from your <a href="/feedback">Feedback page</a> will appear here.
        </div></div>
    <?php else: ?>
        <?php foreach ($rows as $r):
            $isTest  = ($r['kind'] ?? '') === 'testimonial';
            $isIssue = ($r['kind'] ?? '') === 'issue';
            $anon    = (int) ($r['is_anonymous'] ?? 0) === 1;
            $rating  = (int) ($r['rating'] ?? 0);
            $status  = (string) ($r['status'] ?? 'new');
            $ctx     = [];
            if ($isIssue && !empty($r['context'])) {
                $decoded = json_decode((string) $r['context'], true);
                if (is_array($decoded)) $ctx = $decoded;
            }
            $blocking = $isIssue && (string) ($r['severity'] ?? '') === 'blocking';
        ?>
            <!-- id lets an email/notification link jump straight to this card
                 (#report-N) even when the whole queue is rendered. -->
            <div class="card" id="report-<?= (int) $r['id'] ?>" style="margin-bottom:.85rem;<?= $blocking ? 'border-left:3px solid #dc2626;' : '' ?>">
                <div class="card-body">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                        <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
                            <?php if ($isIssue): ?>
                                <span class="badge badge-danger">🐞 Issue #<?= (int) $r['id'] ?></span>
                            <?php else: ?>
                                <span class="badge <?= $isTest ? 'badge-success' : 'badge-gray' ?>"><?= $isTest ? '⭐ Testimonial' : '💬 Feedback' ?></span>
                            <?php endif; ?>
                            <span class="badge <?= $statusBadge[$status] ?? 'badge-gray' ?>"><?= $e(ucfirst($status)) ?></span>
                            <?php if ($blocking): ?><span class="badge badge-danger" title="Reporter couldn’t carry on">⛔ Blocking</span><?php endif; ?>
                            <?php if ($rating > 0): ?><span style="color:#f59e0b;font-size:13px;"><?= str_repeat('★', min(5, $rating)) ?></span><?php endif; ?>
                            <?php if (!empty($r['request_response'])): ?><span class="badge badge-warning" title="Submitter asked for a reply">↩ wants reply</span><?php endif; ?>
                        </div>
                        <span style="font-size:12px;color:var(--text-subtle);"><?= $e(substr((string) ($r['created_at'] ?? ''), 0, 16)) ?></span>
                    </div>

                    <?php if ($isIssue): ?>
                        <!-- An issue report answers two questions; showing them
                             as one blob loses which half is the symptom. -->
                        <div style="margin-top:.7rem;font-size:12px;font-weight:600;color:var(--text-subtle);text-transform:uppercase;letter-spacing:.03em;">Trying to</div>
                        <div style="font-size:14px;line-height:1.55;white-space:pre-wrap;"><?= $e($r['intent'] ?? '') ?></div>
                        <div style="margin-top:.6rem;font-size:12px;font-weight:600;color:var(--text-subtle);text-transform:uppercase;letter-spacing:.03em;">What happened</div>
                        <div style="font-size:14px;line-height:1.55;white-space:pre-wrap;"><?= $e($r['message'] ?? '') ?></div>

                        <?php if (!empty($r['page_url'])): ?>
                            <div style="margin-top:.6rem;font-size:12.5px;">
                                <span style="color:var(--text-subtle);">On:</span>
                                <a href="<?= $e($r['page_url']) ?>" target="_blank" rel="noopener" style="word-break:break-all;"><?= $e($r['page_url']) ?></a>
                            </div>
                        <?php endif; ?>

                        <?php
                        $diag   = $ctx['diagnostics'] ?? [];
                        $errs   = (array) ($diag['js_errors'] ?? []);
                        $failed = (array) ($diag['failed_requests'] ?? []);
                        $crumbs = (array) ($diag['breadcrumbs'] ?? []);
                        $cerr   = (array) ($diag['console_errors'] ?? []);
                        $envRow = $ctx['client'] ?? [];
                        $hasDiag = $errs || $failed || $crumbs || $cerr || $envRow;
                        ?>
                        <?php if ($errs || $failed): ?>
                            <div style="margin-top:.6rem;font-size:12.5px;color:#b91c1c;font-weight:600;">
                                ⚠ <?= count($failed) ?> failed request<?= count($failed) === 1 ? '' : 's' ?>,
                                <?= count($errs) ?> script error<?= count($errs) === 1 ? '' : 's' ?> recorded
                            </div>
                        <?php endif; ?>

                        <?php if ($hasDiag): ?>
                        <details style="margin-top:.6rem;">
                            <summary style="cursor:pointer;font-size:12.5px;font-weight:600;color:var(--color-primary);">Technical details</summary>
                            <div style="margin-top:.55rem;font-size:12px;line-height:1.6;">
                                <?php if ($envRow): ?>
                                    <div style="color:var(--text-subtle);margin-bottom:.5rem;">
                                        <?php foreach ($envRow as $k => $v): ?>
                                            <span style="display:inline-block;margin-right:.9rem;"><?= $e(str_replace('_', ' ', (string) $k)) ?>: <strong><?= $e($v) ?></strong></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($ctx['request']['user_agent'])): ?>
                                    <div style="color:var(--text-subtle);margin-bottom:.5rem;word-break:break-all;">UA: <?= $e($ctx['request']['user_agent']) ?></div>
                                <?php endif; ?>
                                <?php if ($failed || $errs || $cerr): ?>
                                    <pre style="background:#0f172a;color:#e2e8f0;border-radius:6px;padding:.7rem .8rem;overflow-x:auto;font-size:11.5px;line-height:1.6;margin:0 0 .5rem;"><?php
                                        foreach ($failed as $f) {
                                            echo $e(sprintf("%s  %s %s → %s\n", $f['at'] ?? '', $f['method'] ?? 'GET', $f['url'] ?? '', $f['status'] ?? '?'));
                                        }
                                        foreach (array_merge($errs, $cerr) as $x) {
                                            $line = is_array($x) ? trim((($x['at'] ?? '') . '  ' . ($x['message'] ?? '') . ' ' . ($x['source'] ?? ''))) : (string) $x;
                                            echo $e($line . "\n");
                                        }
                                    ?></pre>
                                <?php endif; ?>
                                <?php if ($crumbs): ?>
                                    <div style="color:var(--text-subtle);">
                                        <strong>Clicked just before:</strong>
                                        <?= $e(implode(' → ', array_map(
                                            static fn($c) => is_array($c) ? (string) ($c['label'] ?? '?') : (string) $c,
                                            array_slice($crumbs, -8)
                                        ))) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!empty($r['prompt'])): ?>
                            <div style="font-size:12.5px;color:var(--text-subtle);margin-top:.5rem;font-style:italic;">“<?= $e($r['prompt']) ?>”</div>
                        <?php endif; ?>
                        <div style="font-size:14px;color:var(--text-default);margin-top:.35rem;line-height:1.55;white-space:pre-wrap;"><?= $e($r['message'] ?? '') ?></div>
                    <?php endif; ?>

                    <div style="font-size:12.5px;color:var(--text-subtle);margin-top:.6rem;">
                        <?php if ($anon): ?>
                            <em><?= $isIssue ? 'Signed-out visitor, no contact details' : 'Anonymous' ?></em>
                        <?php else: ?>
                            <?php
                            // Whether someone was signed in is user_id, NOT whether
                            // a name got stored — plenty of accounts have no name
                            // set, and calling those "signed-out" misreports who
                            // filed the report.
                            $who = trim((string) ($r['name'] ?? ''));
                            if ($who === '') {
                                $who = !empty($r['user_id'])
                                    ? 'Signed-in user #' . (int) $r['user_id']
                                    : ($isIssue ? 'Signed-out visitor' : '—');
                            }
                            ?>
                            <strong><?= $e($who) ?></strong>
                            <?php if (!empty($r['email'])): ?> · <a href="mailto:<?= $e($r['email']) ?>?subject=<?= $e(rawurlencode('Re: your report (#' . (int) $r['id'] . ')')) ?>"><?= $e($r['email']) ?></a><?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex;gap:.4rem;margin-top:.8rem;flex-wrap:wrap;">
                        <?php if ($status !== 'reviewed'): ?>
                            <form method="post" action="/admin/site-feedback/<?= (int) $r['id'] ?>/status" style="display:inline;">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="status" value="reviewed">
                                <button class="btn btn-secondary btn-xs" type="submit">Mark reviewed</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($isTest && (int) ($r['consent_display'] ?? 0) === 1 && $status !== 'published'): ?>
                            <form method="post" action="/admin/site-feedback/<?= (int) $r['id'] ?>/status" style="display:inline;">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="status" value="published">
                                <button class="btn btn-success btn-xs" type="submit">Publish testimonial</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($status !== 'archived'): ?>
                            <form method="post" action="/admin/site-feedback/<?= (int) $r['id'] ?>/status" style="display:inline;">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="status" value="archived">
                                <button class="btn btn-secondary btn-xs" type="submit">Archive</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/admin/site-feedback/<?= (int) $r['id'] ?>/delete" style="display:inline;" onsubmit="return confirm('Delete this feedback permanently?');">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <button class="btn btn-danger btn-xs" type="submit">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
