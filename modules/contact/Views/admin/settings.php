<?php
/**
 * @var string $contact_form_enabled
 * @var string $contact_notify_enabled
 * @var string $contact_recipient_emails
 * @var string $contact_autoreply_enabled
 * @var string $contact_autoreply_body
 * @var string $contact_min_seconds
 * @var string $legacy_contact_email
 * @var array  $resolved_recipients   live result of ContactService::resolveRecipients()
 */
$pageTitle   = 'Contact settings';
$activePanel = 'contact';
$bool = static fn($v) => (string) $v === '1';
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include BASE_PATH . '/modules/settings/Views/admin/_nav.php'; ?>

<h1 style="margin:0 0 .35rem;font-size:1.4rem">Contact form</h1>
<p style="color:var(--color-gray-500);font-size:13.5px;margin:0 0 1.25rem;max-width:600px;line-height:1.55;">
    The contact form lives at <a href="/contact" style="color:var(--color-primary)">/contact</a>.
    Submissions land in <a href="/admin/contact-messages" style="color:var(--color-primary)">the
    admin queue</a> and fire a notification email to every address listed below.
</p>

<form method="post" action="/admin/settings/contact" style="max-width:720px">
    <?= csrf_field() ?>

    <div class="card" style="margin-bottom:1rem">
        <div class="card-header"><h3 style="margin:0;font-size:.95rem">Form availability</h3></div>
        <div class="card-body">
            <div class="form-group" style="display:flex;gap:.55rem;align-items:flex-start">
                <input type="hidden" name="contact_form_enabled" value="0">
                <input type="checkbox" id="form_enabled" name="contact_form_enabled" value="1" <?= $bool($contact_form_enabled) ? 'checked' : '' ?> style="margin-top:3px">
                <div>
                    <label for="form_enabled" style="font-weight:600;font-size:13.5px;margin:0">Form is live</label>
                    <small style="display:block;color:var(--color-gray-500);font-size:12px;margin-top:.15rem">When off, POSTs to /contact are refused. Existing messages stay in the queue.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem">
        <div class="card-header"><h3 style="margin:0;font-size:.95rem">Recipients</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label for="recipients">Notification recipients</label>
                <input type="text" id="recipients" name="contact_recipient_emails"
                       class="form-control"
                       value="<?= e($contact_recipient_emails) ?>"
                       placeholder="alice@example.com, bob@example.com">
                <small style="color:var(--color-gray-500);font-size:12px;line-height:1.45">
                    Comma-separated. Each address gets its own email so reply-all doesn't
                    cc admins back to the submitter. When empty, falls back to the legacy
                    <code style="background:var(--color-gray-100);padding:1px 5px;border-radius:3px;font-size:11.5px"><?= e($legacy_contact_email ?: 'site.contact_email') ?></code>
                    setting.
                </small>
            </div>

            <?php if (!empty($resolved_recipients)): ?>
                <div style="background:var(--accent-subtle);border:1px solid var(--accent-subtle);border-radius:6px;padding:.65rem .85rem;font-size:13px;color:var(--color-primary-dark);margin-top:.35rem">
                    <strong>Currently active:</strong>
                    <?php foreach ($resolved_recipients as $r): ?>
                        <code style="background:white;padding:1px 6px;border-radius:3px;font-family:ui-monospace,Menlo,monospace;font-size:12px;margin:0 4px 4px 0;display:inline-block"><?= e($r) ?></code>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="background:var(--color-warning-bg);border:1px solid var(--color-warning-bg);border-radius:6px;padding:.65rem .85rem;font-size:13px;color:var(--color-warning-fg);margin-top:.35rem">
                    ⚠ No active recipients — submissions still save to the queue but no email
                    notifications will be sent. Add an address above or set
                    <code style="background:white;padding:1px 5px;border-radius:3px;font-size:11.5px">contact_email</code>
                    in the general site settings.
                </div>
            <?php endif; ?>

            <div class="form-group" style="display:flex;gap:.55rem;align-items:flex-start;margin-top:1rem">
                <input type="hidden" name="contact_notify_enabled" value="0">
                <input type="checkbox" id="notify_enabled" name="contact_notify_enabled" value="1" <?= $bool($contact_notify_enabled) ? 'checked' : '' ?> style="margin-top:3px">
                <div>
                    <label for="notify_enabled" style="font-weight:600;font-size:13.5px;margin:0">Send email notifications on new submissions</label>
                    <small style="display:block;color:var(--color-gray-500);font-size:12px;margin-top:.15rem">Turn off to only collect messages in the admin queue without alerting recipients.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem">
        <div class="card-header"><h3 style="margin:0;font-size:.95rem">Submitter autoreply</h3></div>
        <div class="card-body">
            <div class="form-group" style="display:flex;gap:.55rem;align-items:flex-start">
                <input type="hidden" name="contact_autoreply_enabled" value="0">
                <input type="checkbox" id="autoreply_enabled" name="contact_autoreply_enabled" value="1" <?= $bool($contact_autoreply_enabled) ? 'checked' : '' ?> style="margin-top:3px">
                <div>
                    <label for="autoreply_enabled" style="font-weight:600;font-size:13.5px;margin:0">Send confirmation email to the submitter</label>
                    <small style="display:block;color:var(--color-gray-500);font-size:12px;margin-top:.15rem">Tells the visitor we got their message. Reduces "did this go through?" follow-ups.</small>
                </div>
            </div>

            <div class="form-group" style="margin-top:1rem">
                <label for="autoreply_body">Autoreply body <small style="font-weight:400;color:var(--color-gray-500)">(optional)</small></label>
                <textarea id="autoreply_body" name="contact_autoreply_body"
                          class="form-control"
                          style="min-height:80px"
                          placeholder="Leave empty for the default 'we got it, will reply within 2 business days' template."><?= e($contact_autoreply_body) ?></textarea>
                <small style="color:var(--color-gray-500);font-size:12px">
                    Available tokens: <code style="background:var(--color-gray-100);padding:1px 5px;border-radius:3px;font-size:11.5px">{name}</code>,
                    <code style="background:var(--color-gray-100);padding:1px 5px;border-radius:3px;font-size:11.5px">{site_name}</code>.
                    Plain text only — HTML is auto-derived.
                </small>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem">
        <div class="card-header"><h3 style="margin:0;font-size:.95rem">Anti-spam</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label for="min_seconds">Minimum time to submit (seconds)</label>
                <input type="number" id="min_seconds" name="contact_min_seconds"
                       class="form-control"
                       style="max-width:120px"
                       value="<?= e($contact_min_seconds) ?>" min="0" max="30">
                <small style="display:block;color:var(--color-gray-500);font-size:12px;margin-top:.25rem;line-height:1.45">
                    Form must have been on the visitor's page for at least this many seconds
                    before submit. Bots POST in milliseconds; humans take 5-30+ seconds.
                    Set to 0 to disable. <em>(Honeypot + per-IP rate-limit are always on.)</em>
                </small>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save settings</button>
</form>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
