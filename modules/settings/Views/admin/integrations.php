<?php $pageTitle = 'Integrations'; $activePanel = 'integrations'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:0 0 1rem;font-size:1.4rem">Integrations</h1>
<p style="color:var(--color-gray-500);font-size:13.5px;margin:0 0 1.25rem;max-width:560px">
    External services this site talks to — outbound mail, analytics,
    error reporting. Per-key tokens (Stripe, OAuth providers) live on
    the <a href="/admin/integrations" style="color:var(--color-primary)">Integrations
    catalog</a>; this panel covers the always-on infrastructure.
</p>

<div class="card">
    <form method="post" action="/admin/settings/integrations">
        <?= csrf_field() ?>
        <div class="card-body">

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
                <small style="color:var(--color-gray-500)">Snippet emitted from the layout. None = no tracking.</small>
            </div>

            <div class="form-group">
                <label for="analytics_site_id">Site / measurement ID</label>
                <input id="analytics_site_id" name="analytics_site_id" class="form-control"
                       value="<?= e((string) ($values['analytics_site_id'] ?? '')) ?>" placeholder="example.com / G-XXXXXXX / your-id">
            </div>

        </div>
        <div class="card-body" style="background:var(--color-gray-50);border-top:1px solid var(--color-gray-200);display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save Integrations</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-body" style="background:var(--color-gray-50)">
        <h3 style="margin:0 0 .5rem;font-size:.95rem">Mail + error reporting</h3>
        <p style="margin:0;color:var(--color-gray-500);font-size:13px;line-height:1.5">
            Outbound mail (driver, SMTP host, from-address, from-name) and Sentry
            DSN are configured via <code>.env</code> for security — see
            <code>MAIL_DRIVER</code>, <code>MAIL_HOST</code>, <code>MAIL_PORT</code>,
            <code>MAIL_USERNAME</code>, <code>MAIL_PASSWORD</code>,
            <code>MAIL_FROM_ADDRESS</code>, <code>MAIL_FROM_NAME</code>, and
            <code>SENTRY_DSN</code>. Restart the web server to pick up changes.
        </p>
    </div>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><h3 style="margin:0;font-size:.95rem">System &amp; developer</h3></div>
    <div class="card-body" style="display:grid;gap:.5rem;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr))">
        <a href="/admin/integrations" style="padding:.75rem;border:1px solid var(--color-gray-200);border-radius:6px;color:inherit;text-decoration:none">
            <strong>Integrations catalog</strong>
            <div style="color:var(--color-gray-500);font-size:12.5px;margin-top:.2rem">Per-provider OAuth + API key management.</div>
        </a>
        <a href="/admin/modules" style="padding:.75rem;border:1px solid var(--color-gray-200);border-radius:6px;color:inherit;text-decoration:none">
            <strong>Modules</strong>
            <div style="color:var(--color-gray-500);font-size:12.5px;margin-top:.2rem">Enable / disable installed modules + dependency status.</div>
        </a>
        <a href="/admin/feature-flags" style="padding:.75rem;border:1px solid var(--color-gray-200);border-radius:6px;color:inherit;text-decoration:none">
            <strong>Feature flags</strong>
            <div style="color:var(--color-gray-500);font-size:12.5px;margin-top:.2rem">Per-flag rollout + per-user / per-role overrides.</div>
        </a>
    </div>
</div>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
