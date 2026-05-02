<?php $pageTitle = 'Integrations'; $activePanel = 'integrations'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:0 0 1rem;font-size:1.4rem">Integrations</h1>
<p style="color:#6b7280;font-size:13.5px;margin:0 0 1.25rem;max-width:560px">
    External services this site talks to — outbound mail, analytics,
    error reporting. Per-key tokens (Stripe, OAuth providers) live on
    the <a href="/admin/integrations" style="color:#4f46e5">Integrations
    catalog</a>; this panel covers the always-on infrastructure.
</p>

<div class="card">
    <form method="post" action="/admin/settings/integrations">
        <?= csrf_field() ?>
        <div class="card-body">

            <h3 style="margin:0 0 .85rem;font-size:1.05rem">Outbound mail</h3>

            <div class="form-group">
                <label for="mail_driver">Mail driver</label>
                <select id="mail_driver" name="mail_driver" class="form-control">
                    <?php $driver = (string) ($values['mail_driver'] ?? 'smtp'); ?>
                    <option value="smtp"     <?= $driver === 'smtp'     ? 'selected' : '' ?>>SMTP</option>
                    <option value="sendmail" <?= $driver === 'sendmail' ? 'selected' : '' ?>>Sendmail (server-local)</option>
                    <option value="log"      <?= $driver === 'log'      ? 'selected' : '' ?>>Log only (development)</option>
                    <option value="none"     <?= $driver === 'none'     ? 'selected' : '' ?>>None (disable outbound mail)</option>
                </select>
                <small style="color:#6b7280">SMTP credentials live in <code>.env</code> (<code>MAIL_HOST</code>, <code>MAIL_PORT</code>, <code>MAIL_USERNAME</code>, <code>MAIL_PASSWORD</code>) — not editable here for security.</small>
            </div>

            <div class="form-group">
                <label for="mail_from_address">From address</label>
                <input id="mail_from_address" name="mail_from_address" type="email" class="form-control"
                       value="<?= e((string) ($values['mail_from_address'] ?? '')) ?>" placeholder="noreply@example.com">
            </div>

            <div class="form-group">
                <label for="mail_from_name">From name</label>
                <input id="mail_from_name" name="mail_from_name" class="form-control"
                       value="<?= e((string) ($values['mail_from_name'] ?? '')) ?>" placeholder="Site Name">
                <small style="color:#6b7280">Defaults to the General panel's site name when blank.</small>
            </div>

            <hr style="margin:1.5rem 0;border:0;border-top:1px solid #e5e7eb">
            <h3 style="margin:0 0 .85rem;font-size:1.05rem">Analytics</h3>

            <div class="form-group">
                <label for="analytics_provider">Provider</label>
                <select id="analytics_provider" name="analytics_provider" class="form-control">
                    <?php $prov = (string) ($values['analytics_provider'] ?? 'none'); ?>
                    <option value="none"      <?= $prov === 'none'      ? 'selected' : '' ?>>None</option>
                    <option value="plausible" <?= $prov === 'plausible' ? 'selected' : '' ?>>Plausible</option>
                    <option value="ga"        <?= $prov === 'ga'        ? 'selected' : '' ?>>Google Analytics 4</option>
                    <option value="umami"     <?= $prov === 'umami'     ? 'selected' : '' ?>>Umami</option>
                </select>
                <small style="color:#6b7280">Snippet emitted from the layout. None = no tracking.</small>
            </div>

            <div class="form-group">
                <label for="analytics_site_id">Site / measurement ID</label>
                <input id="analytics_site_id" name="analytics_site_id" class="form-control"
                       value="<?= e((string) ($values['analytics_site_id'] ?? '')) ?>" placeholder="example.com / G-XXXXXXX / your-id">
            </div>

            <hr style="margin:1.5rem 0;border:0;border-top:1px solid #e5e7eb">
            <h3 style="margin:0 0 .85rem;font-size:1.05rem">Error reporting</h3>

            <div class="form-group">
                <label for="sentry_dsn">Sentry DSN</label>
                <input id="sentry_dsn" name="sentry_dsn" class="form-control"
                       value="<?= e((string) ($values['sentry_dsn'] ?? '')) ?>" placeholder="https://abcdef@o123456.ingest.sentry.io/789">
                <small style="color:#6b7280">Empty disables Sentry. Project user-context is set per the
                    <a href="/admin/audit-log" style="color:#4f46e5">audit log policy</a> (id + email + IP).</small>
            </div>

        </div>
        <div class="card-body" style="background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save Integrations</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><h3 style="margin:0;font-size:.95rem">System &amp; developer</h3></div>
    <div class="card-body" style="display:grid;gap:.5rem;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr))">
        <a href="/admin/integrations" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Integrations catalog</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Per-provider OAuth + API key management.</div>
        </a>
        <a href="/admin/modules" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Modules</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Enable / disable installed modules + dependency status.</div>
        </a>
        <a href="/admin/feature-flags" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Feature flags</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Per-flag rollout + per-user / per-role overrides.</div>
        </a>
    </div>
</div>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
