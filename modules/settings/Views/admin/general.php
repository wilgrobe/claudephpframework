<?php $pageTitle = 'General Settings'; $activePanel = 'general'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:0 0 1rem;font-size:1.4rem">General</h1>
<p style="color:var(--color-gray-500);font-size:13.5px;margin:0 0 1.25rem;max-width:560px">
    Site identity. These values are surfaced in the layout, in outbound
    emails, and in SEO meta tags. Timezone + locale + logo URL live in
    <code>.env</code> (<code>APP_TIMEZONE</code>, <code>APP_LOCALE</code>) or are
    handled by the per-tenant
    <a href="/admin/settings/appearance" style="color:var(--color-primary)">Appearance</a>
    panel.
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
                <label for="site_url">Canonical site URL</label>
                <input id="site_url" name="site_url" class="form-control" type="url"
                       value="<?= e((string) ($values['site_url'] ?? '')) ?>" placeholder="https://example.com">
                <small style="color:var(--color-gray-500)">Used in outbound email links (verification, password reset) and canonical SEO tags.</small>
            </div>

        </div>
        <div class="card-body" style="background:var(--color-gray-50);border-top:1px solid var(--color-gray-200);display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save General</button>
        </div>
    </form>
</div>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
