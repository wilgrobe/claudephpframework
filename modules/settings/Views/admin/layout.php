<?php $pageTitle = 'Layout Settings'; $activePanel = 'layout'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:0 0 1rem;font-size:1.4rem">Layout</h1>
<p style="color:#6b7280;font-size:13.5px;margin:0 0 1.25rem;max-width:560px">
    Site chrome — what wraps every page. Header, sidebar/topbar
    orientation, footer block, and the menus assigned to each region.
    Visual styling (colors, fonts) lives under
    <a href="/admin/settings/appearance" style="color:#4f46e5">Appearance</a>.
</p>

<div class="card">
    <form method="post" action="/admin/settings/layout">
        <?= csrf_field() ?>
        <div class="card-body">

            <h3 style="margin:0 0 .85rem;font-size:1.05rem">Orientation</h3>
            <div class="form-group">
                <label for="layout_orientation">Navigation placement</label>
                <select id="layout_orientation" name="layout_orientation" class="form-control">
                    <?php $orient = (string) ($values['layout_orientation'] ?? 'sidebar'); ?>
                    <option value="sidebar" <?= $orient === 'sidebar' ? 'selected' : '' ?>>Sidebar (default — left rail)</option>
                    <option value="topbar"  <?= $orient === 'topbar'  ? 'selected' : '' ?>>Topbar (horizontal nav row)</option>
                </select>
                <small style="color:#6b7280">Sidebar suits admin-heavy sites; topbar reads better for content-first sites.</small>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('sidebar_collapsed_default', !empty($values['sidebar_collapsed_default']) && $values['sidebar_collapsed_default'] !== 'false') ?>
                    Start sidebar collapsed by default
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Users can still toggle on a per-session basis; this only sets the
                    initial state for first-load.
                </div>
            </div>

            <hr style="margin:1.5rem 0;border:0;border-top:1px solid #e5e7eb">
            <h3 style="margin:0 0 .85rem;font-size:1.05rem">Header</h3>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:.75rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('header_show_logo', !empty($values['header_show_logo']) && $values['header_show_logo'] !== 'false') ?>
                    Show logo in header
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Logo image (or fallback site name + emoji) appears at the top-left.
                </div>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('header_show_search', !empty($values['header_show_search']) && $values['header_show_search'] !== 'false') ?>
                    Show search box in header
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Inline search input that posts to <code>/search</code>. Disable on sites
                    where global search isn't useful.
                </div>
            </div>

            <hr style="margin:1.5rem 0;border:0;border-top:1px solid #e5e7eb">
            <h3 style="margin:0 0 .85rem;font-size:1.05rem">Footer</h3>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:.75rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('footer_enabled', !empty($values['footer_enabled']) && $values['footer_enabled'] !== 'false') ?>
                    Show footer
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Renders the standard footer on guest + auth pages. Turn off for
                    minimal-chrome layouts.
                </div>
            </div>

            <div class="form-group">
                <label for="footer_logo_text">Footer logo text</label>
                <input id="footer_logo_text" name="footer_logo_text" class="form-control"
                       value="<?= e((string) ($values['footer_logo_text'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="footer_tagline">Footer tagline</label>
                <input id="footer_tagline" name="footer_tagline" class="form-control"
                       value="<?= e((string) ($values['footer_tagline'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="footer_copyright">Copyright line</label>
                <input id="footer_copyright" name="footer_copyright" class="form-control"
                       value="<?= e((string) ($values['footer_copyright'] ?? '')) ?>" placeholder="© 2026 Your Company">
            </div>

            <div class="form-group">
                <label for="footer_powered_by">Powered-by line</label>
                <input id="footer_powered_by" name="footer_powered_by" class="form-control"
                       value="<?= e((string) ($values['footer_powered_by'] ?? '')) ?>">
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:.75rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('footer_show_menu', !empty($values['footer_show_menu']) && $values['footer_show_menu'] !== 'false') ?>
                    Render a menu in the footer
                </label>
            </div>

            <div class="form-group">
                <label for="footer_menu_location">Footer menu location</label>
                <select id="footer_menu_location" name="footer_menu_location" class="form-control">
                    <option value="">— pick a menu —</option>
                    <?php foreach ($locations ?? [] as $loc): ?>
                    <option value="<?= e($loc) ?>" <?= ($values['footer_menu_location'] ?? '') === $loc ? 'selected' : '' ?>>
                        <?= e($loc) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#6b7280">Menu picker reads from the menus module — define menus first at <a href="/admin/menus" style="color:#4f46e5">/admin/menus</a>.</small>
            </div>

        </div>
        <div class="card-body" style="background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save Layout</button>
        </div>
    </form>
</div>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
