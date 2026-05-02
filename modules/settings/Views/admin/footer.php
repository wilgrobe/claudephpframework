<?php $pageTitle = 'Footer Settings'; $activePanel = 'layout'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/settings/layout" style="color:#4f46e5;text-decoration:none">← Layout</a>
</div>
<h1 style="margin:0 0 .35rem;font-size:1.4rem">Footer</h1>
<p style="color:#6b7280;font-size:13px;margin:0 0 1rem">
    Footer settings now live on the unified <a href="/admin/settings/layout" style="color:#4f46e5">Layout</a> panel
    alongside header + sidebar config. This page is a deep-link to the same settings.
</p>

<div class="card">
    <div class="card-header"><h2 style="margin:0;font-size:1rem">Site Footer Configuration</h2></div>
    <div class="card-body">
        <form method="POST" action="/admin/settings/footer">
            <?= csrf_field() ?>

            <!-- Master toggle -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('footer_enabled', !empty($values['footer_enabled'])) ?>
                    Show footer across the site
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    When off, no footer is rendered on any page. The individual fields below stay saved
                    — uncheck this to hide the footer temporarily without clearing its contents.
                </div>
            </div>

            <!-- Branding: logo + tagline -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1rem;background:#fff">
                <div style="font-weight:600;font-size:13px;margin-bottom:.75rem;color:#374151">Branding</div>
                <div class="form-group">
                    <label for="footer_logo_text">Logo text</label>
                    <input type="text" name="footer_logo_text" class="form-control"
                           value="<?= e($values['footer_logo_text'] ?? '')?>"
                           placeholder="🚀 My Application" maxlength="255" id="footer_logo_text">
                    <div style="font-size:12px;color:#6b7280;margin-top:.25rem">
                        Plain text and emoji are fine — no HTML. Leave blank to omit the logo.
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="footer_tagline">Tagline</label>
                    <input type="text" name="footer_tagline" class="form-control"
                           value="<?= e($values['footer_tagline'] ?? '')?>"
                           placeholder="One-line blurb shown under the logo" maxlength="255" id="footer_tagline">
                </div>
            </div>

            <!-- Menu -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1rem;background:#fff">
                <div style="font-weight:600;font-size:13px;margin-bottom:.75rem;color:#374151">Navigation menu</div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400">
                        <?= toggle_switch('footer_show_menu', !empty($values['footer_show_menu'])) ?>
                        Show menu links in the footer
                    </label>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="footer_menu_location">Menu location</label>
                    <?php
                    $current = $values['footer_menu_location'] ?? 'footer';
                    // Always make sure the current value is selectable even if
                    // it isn't in the active-menus list (e.g. a typo, or a
                    // disabled menu) — don't silently drop it on save.
                    $options = array_unique(array_merge($locations, [$current]));
                    sort($options);
                    ?>
                    <select name="footer_menu_location" class="form-control" id="footer_menu_location">
                        <?php foreach ($options as $loc): ?>
                        <option value="<?= e($loc) ?>" <?= $loc === $current ? 'selected' : '' ?>><?= e($loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:12px;color:#6b7280;margin-top:.25rem">
                        Which menu's items to render. Manage the items themselves under
                        <a href="/admin/menus" style="color:#4f46e5">Menus</a>.
                    </div>
                </div>
            </div>

            <!-- Legal / attribution -->
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1rem;background:#fff">
                <div style="font-weight:600;font-size:13px;margin-bottom:.75rem;color:#374151">Legal &amp; attribution</div>
                <div class="form-group">
                    <label for="footer_copyright">Copyright</label>
                    <input type="text" name="footer_copyright" class="form-control"
                           value="<?= e($values['footer_copyright'] ?? '')?>"
                           placeholder="© {{year}} My Company" maxlength="500" id="footer_copyright">
                    <div style="font-size:12px;color:#6b7280;margin-top:.25rem">
                        Use <code>{{year}}</code> to insert the current year at render time so this
                        never goes stale.
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label for="footer_powered_by">Powered by</label>
                    <input type="text" name="footer_powered_by" class="form-control"
                           value="<?= e($values['footer_powered_by'] ?? '')?>"
                           placeholder="Powered by …" maxlength="500" id="footer_powered_by">
                </div>
            </div>

            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary">Save Footer Settings</button>
                <a href="/admin/settings" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

</main></div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
