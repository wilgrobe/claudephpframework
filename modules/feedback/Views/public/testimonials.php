<?php
/**
 * Public testimonials showcase. Rendered by FeedbackController::testimonials.
 *
 * @var string $siteName
 * @var array  $testimonials  published testimonial rows
 */
$pageTitle = 'Testimonials';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:1100px;margin:0 auto;padding:1.75rem 1.25rem 3rem;">
    <div style="text-align:center;margin-bottom:1.75rem;">
        <h1 style="font-size:1.9rem;font-weight:700;margin:0 0 .35rem;color:var(--text-default)">What people are saying</h1>
        <p style="color:var(--text-subtle);font-size:14.5px;margin:0">Real feedback from people who’ve used <?= $e($siteName) ?>.</p>
    </div>

    <?php if (empty($testimonials)): ?>
        <div class="card"><div class="card-body" style="text-align:center;padding:2.5rem;color:var(--text-subtle)">
            No testimonials published yet. <a href="/feedback" style="color:var(--color-primary)">Be the first to share one →</a>
        </div></div>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.1rem;">
            <?php foreach ($testimonials as $t):
                $rating = (int) ($t['rating'] ?? 0); ?>
                <figure class="card" style="margin:0;"><div class="card-body">
                    <?php if ($rating > 0): ?>
                        <div style="color:#f59e0b;font-size:15px;margin-bottom:.55rem;letter-spacing:1px;">
                            <?= str_repeat('★', min(5, $rating)) . str_repeat('☆', max(0, 5 - $rating)) ?>
                        </div>
                    <?php endif; ?>
                    <blockquote style="margin:0 0 .85rem;font-size:14.5px;line-height:1.65;color:var(--text-default)">“<?= $e($t['message'] ?? '') ?>”</blockquote>
                    <figcaption style="font-size:13px;font-weight:600;color:var(--color-primary)">— <?= $e(($t['name'] ?? '') ?: 'A happy customer') ?></figcaption>
                </div></figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:2rem;">
        <a href="/feedback" class="btn btn-primary">Share your experience</a>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
