<?php
/**
 * Default public contact form view. Rendered by ContactController::show
 * when no custom `pages` row exists at slug='contact'.
 *
 * @var string $pageTitle
 * @var bool   $formEnabled  master switch from settings.contact_form_enabled
 * @var array  $old          flashed POST values from a failed submit
 * @var array  $errors       per-field validation errors
 * @var string $siteName
 */
$pageTitle ??= 'Contact us';
$old       ??= [];
$errors    ??= [];
$formEnabled ??= true;
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<style>
.contact-shell { max-width: 640px; margin: 0 auto; padding: 1.5rem 1.25rem 2.5rem; }
.contact-shell h1 { font-size: 1.6rem; margin: 0 0 .35rem; font-weight: 700; }
.contact-shell .intro { color: var(--color-gray-600); font-size: 14.5px; margin: 0 0 1.5rem; line-height: 1.55; }

.contact-field { margin-bottom: 1rem; }
.contact-field label { display: block; font-size: 13.5px; font-weight: 600; color: var(--color-gray-700); margin-bottom: .35rem; }
.contact-field label .req { color: var(--color-danger); margin-left: 2px; }
.contact-field input[type="text"],
.contact-field input[type="email"],
.contact-field input[type="tel"],
.contact-field textarea {
    width: 100%; padding: .6rem .75rem;
    border: 1px solid var(--color-gray-300); border-radius: 4px;
    font: inherit; font-size: 14px; box-sizing: border-box;
}
.contact-field textarea { min-height: 140px; resize: vertical; line-height: 1.5; }
.contact-field input:focus, .contact-field textarea:focus {
    outline: none; border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--accent-subtle, rgba(79,70,229,.15));
}
.contact-field.has-error input, .contact-field.has-error textarea { border-color: var(--color-danger); }
.contact-field .err {
    color: var(--color-danger); font-size: 12.5px; margin-top: .3rem;
}

/* Honeypot — invisible to humans but bots auto-fill it. */
.contact-honey { position: absolute; left: -9999px; height: 0; overflow: hidden; opacity: 0; }

.contact-submit {
    background: var(--color-primary); color: white; border: 0;
    padding: .75rem 1.5rem; border-radius: 6px;
    font: inherit; font-size: 14.5px; font-weight: 600; cursor: pointer;
    width: 100%;
}
.contact-submit:hover { filter: brightness(95%); }
.contact-submit:disabled { background: var(--color-gray-300); cursor: not-allowed; }

.contact-disabled {
    background: var(--bg-panel, var(--bg-panel)); border: 1px solid var(--color-gray-200);
    border-radius: 8px; padding: 2rem 1.5rem; text-align: center;
}
.contact-disabled p { margin: 0; color: var(--color-gray-500); }
</style>

<div class="contact-shell">
    <h1>Contact us</h1>
    <p class="intro">Got a question, idea, or feedback? Send us a message — we read every one and reply within two business days.</p>

    <?php if (!$formEnabled): ?>
        <div class="contact-disabled">
            <p>The contact form is temporarily unavailable. Please check back later.</p>
        </div>
    <?php else: ?>
        <form method="post" action="/contact" autocomplete="on" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="rendered_at" value="<?= (int) time() ?>">
            <input type="hidden" name="return_url" value="/contact">

            <div class="contact-honey" aria-hidden="true">
                <label for="contact_website">Website (leave blank)</label>
                <input type="text" id="contact_website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="contact-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label for="contact_name">Your name <span class="req">*</span></label>
                <input type="text" id="contact_name" name="name" required maxlength="200"
                       autocomplete="name"
                       value="<?= $e($old['name'] ?? '') ?>">
                <?php if (isset($errors['name'])): ?><div class="err"><?= $e($errors['name']) ?></div><?php endif; ?>
            </div>

            <div class="contact-field <?= isset($errors['email']) ? 'has-error' : '' ?>">
                <label for="contact_email">Email <span class="req">*</span></label>
                <input type="email" id="contact_email" name="email" required maxlength="254"
                       autocomplete="email"
                       value="<?= $e($old['email'] ?? '') ?>">
                <?php if (isset($errors['email'])): ?><div class="err"><?= $e($errors['email']) ?></div><?php endif; ?>
            </div>

            <div class="contact-field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
                <label for="contact_phone">Phone <small style="font-weight:400;color:var(--color-gray-500)">(optional)</small></label>
                <input type="tel" id="contact_phone" name="phone" maxlength="40"
                       autocomplete="tel"
                       value="<?= $e($old['phone'] ?? '') ?>">
                <?php if (isset($errors['phone'])): ?><div class="err"><?= $e($errors['phone']) ?></div><?php endif; ?>
            </div>

            <div class="contact-field <?= isset($errors['subject']) ? 'has-error' : '' ?>">
                <label for="contact_subject">Subject <small style="font-weight:400;color:var(--color-gray-500)">(optional)</small></label>
                <input type="text" id="contact_subject" name="subject" maxlength="255"
                       value="<?= $e($old['subject'] ?? '') ?>">
                <?php if (isset($errors['subject'])): ?><div class="err"><?= $e($errors['subject']) ?></div><?php endif; ?>
            </div>

            <div class="contact-field <?= isset($errors['body']) ? 'has-error' : '' ?>">
                <label for="contact_body">Message <span class="req">*</span></label>
                <textarea id="contact_body" name="body" required maxlength="8000"><?= $e($old['body'] ?? '') ?></textarea>
                <?php if (isset($errors['body'])): ?><div class="err"><?= $e($errors['body']) ?></div><?php endif; ?>
            </div>

            <?php $__captcha = function_exists('captcha_widget') ? captcha_widget() : ''; ?>
            <?php if ($__captcha !== ''): ?>
                <div class="contact-field"><?= $__captcha ?></div>
            <?php endif; ?>

            <button type="submit" class="contact-submit">Send message</button>
        </form>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
