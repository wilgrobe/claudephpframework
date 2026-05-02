<?php $pageTitle = 'Security Settings'; $activePanel = 'security'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:0 0 1rem;font-size:1.4rem">Security</h1>

<div class="card">
    <form method="POST" action="/admin/settings/security">
        <?= csrf_field() ?>
        <div class="card-body">

            <!-- Account sessions page -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('account_sessions_enabled', !empty($values['account_sessions_enabled'])) ?>
                    Users can review + terminate their own active sessions
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Exposes <code>/account/sessions</code> in the user menu. Users see every
                    active device (current browser, mobile app, other logins) and can sign
                    out any one individually. Turning this off removes the link and returns
                    404 on the URL.
                </div>
            </div>

            <!-- New-device login email -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('new_device_login_email_enabled', !empty($values['new_device_login_email_enabled'])) ?>
                    Email users when they sign in from an unrecognized device
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Detection compares the sign-in's <code>User-Agent</code> string against
                    the user's prior sessions. When the combination is new (no previous
                    session for this user had the same User-Agent), an informational email
                    is dispatched via the configured mail driver. Relies on
                    <code>MAIL_*</code> being configured — silently skipped when the mail
                    driver is disabled or returns an error, so login is never blocked.
                </div>
            </div>

            <!-- Pwned-password breach check (HIBP) -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('password_breach_check_enabled', !empty($values['password_breach_check_enabled'])) ?>
                    Check new passwords against the HIBP breach corpus
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    On registration, password reset, and admin user create/edit,
                    the new password is checked against the
                    <a href="https://haveibeenpwned.com/Passwords" target="_blank" rel="noopener" style="color:#4338ca;text-decoration:underline">Have I Been Pwned</a>
                    Pwned Passwords corpus using k-anonymity (only the first
                    5 hex chars of the SHA-1 are sent). Failures to reach HIBP
                    fail open — never blocks signups due to a network outage.
                    Requires the <code>security</code> module.
                </div>
            </div>

            <!-- Block-on-breach behavior -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('password_breach_check_block', !empty($values['password_breach_check_block'])) ?>
                    Block on confirmed breach
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    On — password is rejected with an error. Off — user sees
                    a warning but can proceed anyway. NIST 800-63B recommends
                    block-mode for high-risk surfaces; warn-only is friendlier
                    for lower-stakes accounts. Only takes effect when the
                    breach check above is on.
                </div>
            </div>

            <!-- Module-disabled SA email -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('module_disabled_email_to_sa_enabled', !empty($values['module_disabled_email_to_sa_enabled'])) ?>
                    Email superadmins when a module is auto-disabled
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    A module gets auto-disabled at boot when its declared
                    <code>requires()</code> dependencies aren't met. Superadmins
                    always see the event in their in-app notification bell;
                    when this is on, they ALSO get an email so a fresh deploy
                    with a missing dependency surfaces immediately. The
                    notification fires once per state transition (active → disabled),
                    not on every request — toggling won't generate a flood.
                    See <a href="/admin/modules" style="color:#4338ca;text-decoration:underline">/admin/modules</a>
                    for current state.
                </div>
            </div>

            <!-- Password-reset link TTL -->
            <?php
                $ttlPresets = \Modules\Settings\Controllers\SettingsController::PASSWORD_RESET_TTL_PRESETS;
                $ttlDefault = \Modules\Settings\Controllers\SettingsController::PASSWORD_RESET_TTL_DEFAULT;
                $ttlCurrent = (int) ($values['password_reset_ttl_minutes'] ?? $ttlDefault);
                if (!in_array($ttlCurrent, $ttlPresets, true)) $ttlCurrent = $ttlDefault;
                // Render minutes as "N minutes" below 60; as "N hours" at 60+.
                $labelFor = static function (int $minutes): string {
                    if ($minutes < 60) return $minutes . ' minutes';
                    $hours = intdiv($minutes, 60);
                    return $hours . ' hour' . ($hours === 1 ? '' : 's');
                };
            ?>
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label for="password_reset_ttl_minutes" style="display:block;font-weight:500;margin:0 0 .4rem 0">
                    Password-reset link lifetime
                </label>
                <select name="password_reset_ttl_minutes" id="password_reset_ttl_minutes"
                        class="form-control" style="max-width:220px;font-size:13px">
                    <?php foreach ($ttlPresets as $m): ?>
                    <option value="<?= (int) $m ?>" <?= $m === $ttlCurrent ? 'selected' : '' ?>>
                        <?= e($labelFor($m)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.45rem;line-height:1.5">
                    How long a reset link stays valid after the user requests it. Shorter
                    values limit the attack window if an email is intercepted; longer
                    values are friendlier to users whose mail gets caught in spam filters
                    or who don't check email right away. Links are single-use either way —
                    redeeming or requesting a new one invalidates the previous token.
                    Default is 2 hours.
                </div>
            </div>

            <!-- Sliding session inactivity timeout -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label for="session_idle_timeout_minutes" style="display:block;font-weight:500;margin:0 0 .4rem 0">
                    Session inactivity timeout (minutes)
                </label>
                <input type="number" id="session_idle_timeout_minutes" name="session_idle_timeout_minutes"
                       value="<?= (int) ($values['session_idle_timeout_minutes'] ?? 0) ?>"
                       min="0" max="525600" step="1"
                       class="form-control" style="max-width:140px;font-size:13px">
                <div style="font-size:12.5px;color:#4338ca;margin-top:.45rem;line-height:1.5">
                    Forced logout after N minutes with no requests. Sliding window —
                    every authenticated request resets the clock. <strong>0 disables</strong>
                    (falls back to PHP's absolute session lifetime). SOC2 evaluators
                    typically expect 15-30 min for high-trust surfaces; 60 min for
                    general SaaS. Requires the <code>security</code> module.
                </div>
            </div>

            <!-- Admin IP allowlist -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('admin_ip_allowlist_enabled', !empty($values['admin_ip_allowlist_enabled'])) ?>
                    Restrict <code>/admin/*</code> to allowlisted IPs
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    When on, every request to a path starting with <code>/admin/</code>
                    is checked against the CIDR list below. Misses get a 403 — even
                    a logged-in admin from a non-allowlisted IP can't reach the surface.
                    The save handler refuses to enable this if your current IP isn't
                    matched by the list (anti-lockout).
                </div>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label for="admin_ip_allowlist" style="display:block;font-weight:500;margin:0 0 .4rem 0">
                    Allowlist (one CIDR or IP per line; bare IPs are exact-match)
                </label>
                <textarea id="admin_ip_allowlist" name="admin_ip_allowlist" rows="5"
                          class="form-control" style="font-family:ui-monospace,monospace;font-size:12.5px"
                          placeholder="203.0.113.5
198.51.100.0/24
2001:db8::/32"><?= e((string) ($values['admin_ip_allowlist'] ?? '')) ?></textarea>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.45rem;line-height:1.5">
                    Lines starting with <code>#</code> are comments. IPv4 + IPv6 supported.
                    Your current IP detected by the framework: <code><?= e((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?></code>
                    <?php if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && empty($_ENV['TRUSTED_PROXY'])): ?>
                        <br><strong>Note:</strong> X-Forwarded-For header detected but
                        <code>TRUSTED_PROXY</code> isn't set in <code>.env</code> — the
                        framework will use the direct connection IP, not the forwarded one.
                    <?php endif; ?>
                </div>
            </div>

            <!-- Login anomaly detection -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('login_anomaly_enabled', !empty($values['login_anomaly_enabled'])) ?>
                    Detect impossible-travel sign-ins (geo + speed)
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Looks up each sign-in's IP via
                    <a href="https://ip-api.com" target="_blank" rel="noopener" style="color:#4338ca;text-decoration:underline">ip-api.com</a>
                    (free, ~45 req/min, results cached 30 days), compares
                    geo to the user's prior login, and flags anomalies.
                    Findings appear at <a href="/admin/security/anomalies" style="color:#4338ca;text-decoration:underline">/admin/security/anomalies</a>.
                    Off by default because it makes outbound API calls on
                    every login.
                </div>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('login_anomaly_email_enabled', !empty($values['login_anomaly_email_enabled'])) ?>
                    Email user on warn / alert anomalies
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    On — when an anomaly fires at warn or alert severity
                    the user gets a "Suspicious sign-in" email with the
                    detected location + speed and a link to manage their
                    sessions. Info-level findings (country jumps at
                    plausible speed) are logged but don't email — most
                    are travelers, not attackers.
                </div>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1rem">
                <label for="login_anomaly_threshold_kmh" style="display:block;font-weight:500;margin:0 0 .4rem 0">Impossible-travel threshold (km/h)</label>
                <div style="display:flex;gap:.5rem;align-items:center">
                    <input type="number" name="login_anomaly_threshold_kmh"
                           value="<?= (int) ($values['login_anomaly_threshold_kmh'] ?? 900) ?>"
                           min="100" max="10000" style="width:120px" id="login_anomaly_threshold_kmh">
                    <span style="font-size:12.5px;color:#4338ca">→ severity = <strong>warn</strong></span>
                </div>
                <div style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem">
                    <input type="number" name="login_anomaly_alert_threshold_kmh"
                           value="<?= (int) ($values['login_anomaly_alert_threshold_kmh'] ?? 2000) ?>"
                           min="100" max="20000" style="width:120px" aria-label="Login anomaly alert threshold kmh">
                    <span style="font-size:12.5px;color:#4338ca">→ severity = <strong>alert</strong></span>
                </div>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.45rem;line-height:1.5">
                    Speed = distance-from-prior-login / elapsed-time. 900 km/h
                    is roughly commercial flight cruise + 1h airport buffer;
                    2000 km/h is fast enough to almost certainly indicate a
                    VPN or proxy hop rather than physical travel.
                </div>
            </div>

            <!-- PII access logging -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('admin_pii_access_logging_enabled', !empty($values['admin_pii_access_logging_enabled'])) ?>
                    Log admin reads of personal data (PII access logging)
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Every admin GET to a PII-bearing surface
                    (<code>/admin/users/*</code>, <code>/admin/sessions</code>,
                    <code>/admin/audit-log</code>, <code>/admin/dsar/*</code>,
                    <code>/admin/gdpr/*</code>, <code>/admin/email-suppressions/*</code>)
                    writes a <code>pii.viewed</code> audit row tagged with the admin's
                    id + the path. SOC2 / ISO 27001 evaluators specifically check for
                    this. Identical (admin, path) reads within 30 seconds are
                    de-duplicated to avoid noise from refresh clicks.
                    Requires the <code>security</code> module.
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
