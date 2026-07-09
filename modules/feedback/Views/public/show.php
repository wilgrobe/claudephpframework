<?php
/**
 * Public feedback form. Rendered by FeedbackController::show.
 *
 * @var string $siteName
 * @var array  $prompts   [key => question]
 * @var array  $old       flashed POST values from a failed submit
 * @var array  $errors    per-field validation errors
 */
$pageTitle = 'Share your feedback';
$old    = $old ?? [];
$errors = $errors ?? [];
$e   = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$val = static fn($k, $d = '') => $e($old[$k] ?? $d);
$isTestimonial = ($old['kind'] ?? 'feedback') === 'testimonial';
$isAnon        = !empty($old['is_anonymous']);
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<style>
.fb-shell { max-width: 620px; margin: 0 auto; padding: 1.5rem 1.25rem 3rem; }
.fb-shell h1 { font-size: 1.7rem; margin: 0 0 .35rem; font-weight: 700; color: var(--text-default); }
.fb-shell .fb-intro { color: var(--color-gray-600, var(--text-subtle)); font-size: 14.5px; margin: 0 0 1.5rem; line-height: 1.55; }
.fb-field { margin-bottom: 1.1rem; }
.fb-field label.fb-lab { display: block; font-weight: 600; font-size: 13.5px; margin-bottom: .35rem; color: var(--text-default); }
.fb-field .fb-err { color: var(--color-danger-fg, #b91c1c); font-size: 12.5px; margin-top: .3rem; }
.fb-types { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; margin-bottom: 1.25rem; }
.fb-type { border: 2px solid var(--border-default, var(--color-gray-200)); border-radius: 10px; padding: .85rem 1rem; cursor: pointer; text-align: center; transition: all .1s; background: var(--bg-panel); }
.fb-type:hover { border-color: var(--color-primary); }
.fb-type.on { border-color: var(--color-primary); background: var(--accent-subtle, rgba(0,102,204,.06)); }
.fb-type .fb-type-ico { font-size: 1.4rem; }
.fb-type .fb-type-t { font-weight: 700; font-size: 13.5px; margin-top: .15rem; color: var(--text-default); }
.fb-type .fb-type-d { font-size: 11.5px; color: var(--text-subtle); margin-top: .1rem; }
.fb-type input { position: absolute; opacity: 0; pointer-events: none; }
.fb-stars { display: inline-flex; flex-direction: row-reverse; gap: .15rem; font-size: 1.7rem; line-height: 1; }
.fb-stars input { display: none; }
.fb-stars label { color: var(--color-gray-300, #cbd5e1); cursor: pointer; transition: color .1s; }
.fb-stars label:hover, .fb-stars label:hover ~ label, .fb-stars input:checked ~ label { color: #f59e0b; }
.fb-check { display: flex; gap: .5rem; align-items: flex-start; font-size: 13.5px; cursor: pointer; color: var(--text-default); }
.fb-check input { margin-top: .15rem; }
.fb-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.fb-submit { background: var(--color-primary); color: #fff; border: 0; border-radius: 8px; padding: .7rem 1.6rem; font-size: 14.5px; font-weight: 600; cursor: pointer; }
.fb-submit:hover { filter: brightness(1.05); }
.fb-hint { font-size: 12px; color: var(--text-subtle); margin-top: .3rem; }
</style>

<div class="fb-shell">
    <h1>Share your feedback</h1>
    <p class="fb-intro">We’d genuinely love to hear from you — tell us what you think of <?= $e($siteName) ?>, what we could do better, or share your experience. You can stay anonymous, or leave your details if you’d like a reply.</p>

    <form method="post" action="/feedback" novalidate>
        <?= csrf_field() ?>
        <div class="fb-hp"><label>Leave this blank <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <!-- Type: feedback vs testimonial -->
        <div class="fb-types" role="radiogroup" aria-label="Feedback type">
            <label class="fb-type<?= $isTestimonial ? '' : ' on' ?>" data-kind="feedback">
                <input type="radio" name="kind" value="feedback"<?= $isTestimonial ? '' : ' checked' ?>>
                <div class="fb-type-ico">💬</div>
                <div class="fb-type-t">Give feedback</div>
                <div class="fb-type-d">Thoughts, ideas, or a question</div>
            </label>
            <label class="fb-type<?= $isTestimonial ? ' on' : '' ?>" data-kind="testimonial">
                <input type="radio" name="kind" value="testimonial"<?= $isTestimonial ? ' checked' : '' ?>>
                <div class="fb-type-ico">⭐</div>
                <div class="fb-type-t">Leave a testimonial</div>
                <div class="fb-type-d">Say something nice we can share</div>
            </label>
        </div>

        <!-- Prompt (feedback only) -->
        <div class="fb-field" data-only="feedback">
            <label class="fb-lab" for="fb-prompt">What would you like to tell us?</label>
            <select name="prompt" id="fb-prompt" class="form-control">
                <?php foreach ($prompts as $k => $q): ?>
                    <option value="<?= $e($k) ?>"<?= ($old['prompt'] ?? '') === $k ? ' selected' : '' ?>><?= $e($q) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Rating (optional; encouraged for testimonials) -->
        <div class="fb-field">
            <label class="fb-lab">Your rating <span style="font-weight:400;color:var(--text-subtle)">(optional)</span></label>
            <div class="fb-stars">
                <?php for ($i = 5; $i >= 1; $i--): $rid = "fb-star-$i"; ?>
                    <input type="radio" name="rating" id="<?= $rid ?>" value="<?= $i ?>"<?= (int) ($old['rating'] ?? 0) === $i ? ' checked' : '' ?>>
                    <label for="<?= $rid ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</label>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Message -->
        <div class="fb-field">
            <label class="fb-lab" for="fb-message">Your message</label>
            <textarea name="message" id="fb-message" class="form-control" rows="5" maxlength="5000" required placeholder="Tell us more…"><?= $val('message') ?></textarea>
            <?php if (isset($errors['message'])): ?><div class="fb-err"><?= $e($errors['message']) ?></div><?php endif; ?>
        </div>

        <!-- Anonymous (feedback only) -->
        <div class="fb-field" data-only="feedback">
            <label class="fb-check"><input type="checkbox" name="is_anonymous" id="fb-anon" value="1"<?= $isAnon ? ' checked' : '' ?>> Submit anonymously</label>
        </div>

        <!-- Identity (hidden when anonymous) -->
        <div id="fb-identity">
            <div class="fb-field">
                <label class="fb-lab" for="fb-name">Your name <span data-only="testimonial" style="color:var(--color-danger-fg,#b91c1c)">*</span></label>
                <input type="text" name="name" id="fb-name" class="form-control" maxlength="200" value="<?= $val('name') ?>" placeholder="Jane Doe">
                <?php if (isset($errors['name'])): ?><div class="fb-err"><?= $e($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="fb-field">
                <label class="fb-lab" for="fb-email">Email <span style="font-weight:400;color:var(--text-subtle)">(optional)</span></label>
                <input type="email" name="email" id="fb-email" class="form-control" maxlength="254" value="<?= $val('email') ?>" placeholder="you@example.com">
                <?php if (isset($errors['email'])): ?><div class="fb-err"><?= $e($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="fb-field" data-only="feedback">
                <label class="fb-check"><input type="checkbox" name="request_response" id="fb-reply" value="1"<?= !empty($old['request_response']) ? ' checked' : '' ?>> I’d like a response <span class="fb-hint" style="margin:0 0 0 .25rem">(needs your email)</span></label>
            </div>
            <div class="fb-field" data-only="testimonial">
                <label class="fb-check"><input type="checkbox" name="consent_display" id="fb-consent" value="1"<?= !empty($old['consent_display']) ? ' checked' : '' ?>> I agree to display this testimonial publicly with my name.</label>
                <?php if (isset($errors['consent'])): ?><div class="fb-err"><?= $e($errors['consent']) ?></div><?php endif; ?>
            </div>
        </div>

        <?php $__captcha = function_exists('captcha_widget') ? captcha_widget() : ''; ?>
        <?php if ($__captcha !== ''): ?>
            <div class="fb-field"><?= $__captcha ?></div>
        <?php endif; ?>

        <button type="submit" class="fb-submit">Send</button>
    </form>
</div>

<script>
(function () {
    var form = document.currentScript.previousElementSibling;
    var typeInputs = form.querySelectorAll('input[name="kind"]');
    var anon = form.querySelector('#fb-anon');
    var identity = form.querySelector('#fb-identity');
    var nameInput = form.querySelector('#fb-name');
    function kind() { var c = form.querySelector('input[name="kind"]:checked'); return c ? c.value : 'feedback'; }
    function apply() {
        var k = kind();
        // highlight the chosen type card
        form.querySelectorAll('.fb-type').forEach(function (el) { el.classList.toggle('on', el.getAttribute('data-kind') === k); });
        // show/hide kind-specific fields
        form.querySelectorAll('[data-only]').forEach(function (el) { el.style.display = (el.getAttribute('data-only') === k) ? '' : 'none'; });
        // a testimonial is never anonymous → force identity visible + name required
        var anonOn = (k === 'feedback') && anon && anon.checked;
        identity.style.display = anonOn ? 'none' : '';
        if (nameInput) nameInput.required = (k === 'testimonial');
    }
    typeInputs.forEach(function (i) { i.addEventListener('change', apply); });
    if (anon) anon.addEventListener('change', apply);
    apply();
})();
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
