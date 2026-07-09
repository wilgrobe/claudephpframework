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

    <!-- Filters -->
    <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;font-size:13px;">
        <?php
        $tabs = [
            ''                          => 'All',
            '?status=new'               => 'New (' . (int) ($counts['new'] ?? 0) . ')',
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
            $isTest = ($r['kind'] ?? '') === 'testimonial';
            $anon   = (int) ($r['is_anonymous'] ?? 0) === 1;
            $rating = (int) ($r['rating'] ?? 0);
            $status = (string) ($r['status'] ?? 'new');
        ?>
            <div class="card" style="margin-bottom:.85rem;">
                <div class="card-body">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                        <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
                            <span class="badge <?= $isTest ? 'badge-success' : 'badge-gray' ?>"><?= $isTest ? '⭐ Testimonial' : '💬 Feedback' ?></span>
                            <span class="badge <?= $statusBadge[$status] ?? 'badge-gray' ?>"><?= $e(ucfirst($status)) ?></span>
                            <?php if ($rating > 0): ?><span style="color:#f59e0b;font-size:13px;"><?= str_repeat('★', min(5, $rating)) ?></span><?php endif; ?>
                            <?php if (!empty($r['request_response'])): ?><span class="badge badge-warning" title="Submitter asked for a reply">↩ wants reply</span><?php endif; ?>
                        </div>
                        <span style="font-size:12px;color:var(--text-subtle);"><?= $e(substr((string) ($r['created_at'] ?? ''), 0, 16)) ?></span>
                    </div>

                    <?php if (!empty($r['prompt'])): ?>
                        <div style="font-size:12.5px;color:var(--text-subtle);margin-top:.5rem;font-style:italic;">“<?= $e($r['prompt']) ?>”</div>
                    <?php endif; ?>
                    <div style="font-size:14px;color:var(--text-default);margin-top:.35rem;line-height:1.55;white-space:pre-wrap;"><?= $e($r['message'] ?? '') ?></div>

                    <div style="font-size:12.5px;color:var(--text-subtle);margin-top:.6rem;">
                        <?php if ($anon): ?>
                            <em>Anonymous</em>
                        <?php else: ?>
                            <strong><?= $e(($r['name'] ?? '') ?: '—') ?></strong>
                            <?php if (!empty($r['email'])): ?> · <a href="mailto:<?= $e($r['email']) ?>"><?= $e($r['email']) ?></a><?php endif; ?>
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
