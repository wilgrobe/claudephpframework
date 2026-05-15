<?php $pageTitle = 'General Settings'; $activePanel = 'general'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:0 0 1rem;font-size:1.4rem">General</h1>
<p style="color:var(--color-gray-500);font-size:13.5px;margin:0 0 1.25rem;max-width:560px">
    Site identity, locale, and the master maintenance switch. These values
    are surfaced in the layout, in outbound emails, and as the default
    timezone / locale for date formatting throughout the framework.
</p>

<div class="card">
    <form method="post" action="/admin/settings/general">
        <?= csrf_field() ?>
        <div class="card-body">

            <div class="form-group">
                <label for="site_name">Site name</label>
                <input id="site_name" name="site_name" class="form-control"
                       value="<?= e((string) ($values['site_name'] ?? '')) ?>" maxlength="200">
                <small style="color:var(--color-gray-500)">Shown in the topbar logo and as the From-name on system emails.</small>
            </div>

            <div class="form-group">
                <label for="site_tagline">Tagline</label>
                <input id="site_tagline" name="site_tagline" class="form-control"
                       value="<?= e((string) ($values['site_tagline'] ?? '')) ?>" maxlength="300">
                <small style="color:var(--color-gray-500)">Used as the default <code>&lt;meta name="description"&gt;</code> when a page doesn't set its own.</small>
            </div>

            <div class="form-group">
                <label for="site_logo_url">Logo URL</label>
                <input id="site_logo_url" name="site_logo_url" class="form-control"
                       value="<?= e((string) ($values['site_logo_url'] ?? '')) ?>" placeholder="/assets/img/logo.svg">
                <small style="color:var(--color-gray-500)">Optional. Path or absolute URL. Leave empty to use the rocket emoji + site name.</small>
            </div>

            <div class="form-group">
                <label for="site_url">Canonical site URL</label>
                <input id="site_url" name="site_url" class="form-control" type="url"
                       value="<?= e((string) ($values['site_url'] ?? '')) ?>" placeholder="https://example.com">
                <small style="color:var(--color-gray-500)">Used in outbound email links (verification, password reset) and canonical SEO tags.</small>
            </div>

            <div class="form-group">
                <label for="site_timezone">Default timezone</label>
                <input id="site_timezone" name="site_timezone" class="form-control"
                       value="<?= e((string) ($values['site_timezone'] ?? 'UTC')) ?>" placeholder="UTC">
                <small style="color:var(--color-gray-500)">PHP timezone identifier — e.g. <code>America/Denver</code>, <code>Europe/Berlin</code>, <code>UTC</code>.</small>
            </div>

            <div class="form-group">
                <label for="site_default_locale">Default locale</label>
                <input id="site_default_locale" name="site_default_locale" class="form-control"
                       value="<?= e((string) ($values['site_default_locale'] ?? 'en_US')) ?>" placeholder="en_US" maxlength="10">
                <small style="color:var(--color-gray-500)">BCP-47 / ICU format. Used for date / number formatting fallback.</small>
            </div>


        </div>
        <div class="card-body" style="background:var(--color-gray-50);border-top:1px solid var(--color-gray-200);display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save General</button>
        </div>
    </form>
</div>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
