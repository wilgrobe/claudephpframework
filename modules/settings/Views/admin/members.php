<?php $pageTitle = 'Members'; $activePanel = 'members'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<style>
/* ⓘ tooltip — native title attribute does the actual reveal so we
   don't need any JS. The icon is just a visual hint that the label
   carries extra context. Keep it muted; not an action. */
.help-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #e5e7eb;
    color: #6b7280;
    font-size: 11px;
    font-weight: 600;
    cursor: help;
    margin-left: .35rem;
    user-select: none;
}
.help-icon:hover { background: #d1d5db; color: #374151; }

.field-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: .85rem 0;
    border-bottom: 1px solid #f3f4f6;
}
.field-row:last-child { border-bottom: 0; }
.field-row label.field-label {
    flex: 1;
    font-size: 14px;
    font-weight: 500;
    color: #111827;
    cursor: pointer;
    margin: 0;
    display: flex;
    align-items: center;
}
.field-row .field-control { flex-shrink: 0; }
.field-row .field-control input[type="number"] {
    width: 110px;
    padding: .35rem .5rem;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 14px;
}
.field-row .field-control input[type="text"] {
    width: 240px;
    padding: .35rem .5rem;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 14px;
}
</style>

<h1 style="margin:0 0 1rem;font-size:1.4rem">Members</h1>
<p style="color:#6b7280;font-size:13.5px;margin:0 0 1.25rem">
    Who can join the site, how their accounts get verified, and the
    rules that govern group membership. Hover the
    <span class="help-icon">i</span> icons for details on each setting.
</p>

<!-- Access form: registration, verification, COPPA. POSTs to the
     existing /admin/settings/access endpoint so its CIDR / age-range
     validators stay authoritative. -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">Registration &amp; access</h3></div>
    <form method="post" action="/admin/settings/access">
        <?= csrf_field() ?>
        <div class="card-body" style="padding:0 1.25rem">

            <div class="field-row">
                <label class="field-label" for="allow_registration">
                    Allow new registrations
                    <span class="help-icon" title="When off, /register refuses signups and shows 'Registration is closed.' Existing users are unaffected. Useful during invite-only periods or while you're polishing onboarding.">i</span>
                </label>
                <div class="field-control">
                    <?= toggle_switch('allow_registration', !empty($access['allow_registration']) && $access['allow_registration'] !== 'false') ?>
                </div>
            </div>

            <div class="field-row">
                <label class="field-label" for="require_email_verify">
                    Require email verification before first login
                    <span class="help-icon" title="When on, new accounts must click the verification link in their welcome email before their first login succeeds. Recommended unless you have an alternate identity-verification path.">i</span>
                </label>
                <div class="field-control">
                    <?= toggle_switch('require_email_verify', !empty($access['require_email_verify']) && $access['require_email_verify'] !== 'false') ?>
                </div>
            </div>

            <div class="field-row">
                <label class="field-label" for="maintenance_mode">
                    Site-wide maintenance mode
                    <span class="help-icon" title="When on, only superadmins can reach non-login routes. Everyone else sees a maintenance page. Use during deployments or schema changes.">i</span>
                </label>
                <div class="field-control">
                    <?= toggle_switch('maintenance_mode', !empty($access['maintenance_mode']) && $access['maintenance_mode'] !== 'false') ?>
                </div>
            </div>

            <div class="field-row">
                <label class="field-label" for="coppa_enabled">
                    COPPA / age-gate at registration
                    <span class="help-icon" title="When on, the registration form gains a date-of-birth field. Applicants under the minimum age are rejected with the configured message. US COPPA min is 13; GDPR Art. 8 strict default is 16.">i</span>
                </label>
                <div class="field-control">
                    <?= toggle_switch('coppa_enabled', !empty($access['coppa_enabled']) && $access['coppa_enabled'] !== 'false') ?>
                </div>
            </div>

            <div class="field-row">
                <label class="field-label" for="coppa_minimum_age">
                    Minimum age (years)
                    <span class="help-icon" title="Below this age the registration is blocked. Only applies when COPPA is enabled above.">i</span>
                </label>
                <div class="field-control">
                    <input id="coppa_minimum_age" name="coppa_minimum_age" type="number" min="0" max="99"
                           value="<?= e((string) ($access['coppa_minimum_age'] ?? '13')) ?>">
                </div>
            </div>

            <div class="field-row">
                <label class="field-label" for="coppa_block_message">
                    COPPA block message
                    <span class="help-icon" title="Shown to applicants who are rejected by the age gate. Keep it neutral — telling them the exact threshold can be a privacy ask.">i</span>
                </label>
                <div class="field-control">
                    <input id="coppa_block_message" name="coppa_block_message" type="text"
                           value="<?= e((string) ($access['coppa_block_message'] ?? '')) ?>"
                           placeholder="You must be older to register.">
                </div>
            </div>

        </div>
        <div class="card-body" style="background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save Access</button>
        </div>
    </form>
</div>

<!-- Group policy form. POSTs to /admin/settings/groups so the existing
     handler stays the source of truth. -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">Group policy</h3></div>
    <form method="post" action="/admin/settings/groups">
        <?= csrf_field() ?>
        <div class="card-body" style="padding:0 1.25rem">

            <div class="field-row">
                <label class="field-label" for="single_group_only">
                    Limit users to a single group
                    <span class="help-icon" title="When on, joining a group automatically removes the user from any other group. Useful for orgs where groups represent mutually-exclusive teams or tenants.">i</span>
                </label>
                <div class="field-control">
                    <?= toggle_switch('single_group_only', !empty($groups['single_group_only']) && $groups['single_group_only'] !== 'false') ?>
                </div>
            </div>

            <div class="field-row">
                <label class="field-label" for="allow_group_creation">
                    Let non-admin users create groups
                    <span class="help-icon" title="When on, any authenticated user can create a new group from the Groups page. They become the group_owner of what they create. Off = only admins can create groups.">i</span>
                </label>
                <div class="field-control">
                    <?= toggle_switch('allow_group_creation', !empty($groups['allow_group_creation']) && $groups['allow_group_creation'] !== 'false') ?>
                </div>
            </div>

        </div>
        <div class="card-body" style="background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save Groups</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:.95rem">Member tools</h3></div>
    <div class="card-body" style="display:grid;gap:.5rem;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr))">
        <a href="/admin/users" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Users</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Search, edit, deactivate, role assignment.</div>
        </a>
        <a href="/admin/roles" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Roles &amp; permissions</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Custom role definitions + permission grants.</div>
        </a>
        <a href="/admin/groups" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Groups</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Group membership + per-group roles.</div>
        </a>
        <a href="/admin/coppa" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>COPPA rejections</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Audit-log review of registrations blocked under the age gate.</div>
        </a>
    </div>
</div>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
