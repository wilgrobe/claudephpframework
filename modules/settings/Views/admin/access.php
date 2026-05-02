<?php $pageTitle = 'Registration & Access'; $activePanel = 'members'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/settings/members" style="color:#4f46e5;text-decoration:none">← Members</a>
</div>
<h1 style="margin:0 0 1rem;font-size:1.4rem">Registration &amp; Access</h1>

<div class="card">
    <form method="POST" action="/admin/settings/access">
        <?= csrf_field() ?>
        <div class="card-body">

            <!-- Allow public registration -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('allow_registration', !empty($values['allow_registration'])) ?>
                    Allow public registration
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    When off, <code>/register</code> shows "Registration is closed" and the
                    form won't accept new signups. Existing users can still log in, and
                    administrators can still create users manually from <code>/admin/users</code>.
                </div>
            </div>

            <!-- Require email verification -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('require_email_verify', !empty($values['require_email_verify'])) ?>
                    Require email verification before first login
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    New accounts receive a verification email; login is blocked until the
                    link is clicked. Depends on <code>MAIL_*</code> being configured — if the
                    mail driver is off, verification emails won't be dispatched and users
                    will be locked out. Turn this off for trusted intranet deployments or
                    during initial setup.
                </div>
            </div>

            <!-- COPPA / age gate -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('coppa_enabled', !empty($values['coppa_enabled'])) ?>
                    Require date-of-birth + age gate at registration (COPPA / GDPR Art. 8)
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    On — adds a date-of-birth field to <code>/register</code> and rejects
                    applicants below the minimum age with the configured message.
                    Defaults: <strong>13</strong> (US COPPA). Set to 16 for GDPR Art. 8
                    strict default; UK Children's Code uses 13. Rejections write
                    a <code>coppa.registration_blocked</code> audit row with the IP +
                    a hash of the email — no DOB stored on rejection.
                    Requires the <code>coppa</code> module.
                </div>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1rem">
                <label for="coppa_minimum_age" style="display:block;font-weight:500;margin:0 0 .4rem 0">Minimum age</label>
                <input type="number" name="coppa_minimum_age"
                       value="<?= (int) ($values['coppa_minimum_age'] ?? 13)?>"
                       min="1" max="21" style="width:100px" aria-label="Coppa minimum age">
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Applicants must be at least this many years old. Range 1-21
                    enforced server-side.
                </div>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label for="coppa_block_message" style="display:block;font-weight:500;margin:0 0 .4rem 0">Rejection message</label>
                <input type="text" name="coppa_block_message"
                       value="<?= e((string) ($values['coppa_block_message'] ?? ''))?>"
                       style="width:100%"
                       placeholder="Sorry — you must be at least {age} years old to create an account on this site." aria-label="Sorry — you must be at least {age} years old to create an account on this site.">
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Use <code>{age}</code> as a placeholder for the configured minimum.
                </div>
            </div>

            <!-- Maintenance mode -->
            <div class="form-group" style="padding:.85rem 1rem;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('maintenance_mode', !empty($values['maintenance_mode'])) ?>
                    Maintenance mode
                </label>
                <div style="font-size:12.5px;color:#92400e;margin-top:.35rem;line-height:1.5">
                    <strong>Site-wide off-switch.</strong> When on, every non-login route shows
                    a maintenance page. Only superadmins can still reach the dashboard —
                    so make sure you have superadmin access before flipping this on.
                    Login and logout continue to work; this is a "please come back later"
                    banner, not a firewall.
                </div>
            </div>

        </div>
        <div class="card-footer" style="padding:.75rem 1.25rem;background:#f9fafb;text-align:right">
            <a href="/admin/settings" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>

</main></div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
