<?php $pageTitle = 'Appearance Settings'; $activePanel = 'appearance'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<style>
.app-section {
    border: 1px solid var(--border-default); border-radius: 8px;
    margin-bottom: 1rem; overflow: hidden; background: var(--bg-panel);
}
.app-section > summary {
    list-style: none; cursor: pointer; user-select: none;
    padding: .85rem 1rem; font-weight: 600; font-size: 14px; color: var(--text-default);
    display: flex; align-items: center; gap: .5rem; background: var(--bg-page);
}
.app-section > summary::-webkit-details-marker { display: none; }
.app-section > summary::before { content: '\25b8'; color: var(--text-muted); transition: transform .15s; }
.app-section[open] > summary::before { transform: rotate(90deg); }
.app-section > summary:hover { background: var(--border-subtle); }
.app-section-body { padding: .85rem 1rem 1rem; border-top: 1px solid var(--border-default); }
.app-section-help { font-size: 12.5px; color: var(--text-muted); margin: 0 0 1rem; line-height: 1.5; }

.token-row {
    display: grid; gap: .5rem; align-items: center; margin-bottom: .5rem;
}
/* Color rows: label + light(color+text) + dark(color+text) + default note */
.token-row.token-row--color {
    grid-template-columns: 200px 50px 1fr 50px 1fr 130px;
}
/* Length rows: label + single text input + default note */
.token-row.token-row--length { grid-template-columns: 200px 1fr 130px; }

.token-row .token-label { font-size: 13px; font-weight: 500; }
.token-row .token-default { font-size: 11.5px; color: var(--text-subtle); }
.token-row input[type=text] {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 13px;
}
.token-row input[type=color] {
    width: 100%; height: 34px; padding: 0; border: 1px solid var(--border-strong);
    border-radius: 4px; cursor: pointer;
}
/* Mode-column header strip above each color section */
.token-modes {
    display: grid; gap: .5rem; align-items: center;
    grid-template-columns: 200px 1fr 1fr 130px;
    font-size: 10.5px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .06em;
    padding: 0 .25rem .25rem; margin-bottom: .35rem;
    border-bottom: 1px solid var(--border-subtle);
}
.token-modes .light  { padding-left: 60px; }
.token-modes .dark   { padding-left: 60px; }

/* Two-col layout: appearance form on the left, sticky preview on the right.
   Below 1100px the preview stacks under the form. */
.appearance-wrap {
    max-width: 1280px; margin: 0 auto;
    display: grid; gap: 1.25rem;
    grid-template-columns: minmax(0,1fr) 360px;
}
@media (max-width: 1100px) { .appearance-wrap { grid-template-columns: 1fr; } }
.appearance-form { min-width: 0; }
.appearance-preview {
    position: sticky; top: 80px; align-self: start;
    max-height: calc(100vh - 100px); overflow-y: auto;
}

/* Preview pane: a self-contained design surface that uses ITS OWN CSS
   variable scope so admin edits don't temporarily break the page chrome.
   The variables here mirror the token catalogue; the live-update JS
   writes new values onto #theme-preview directly so the preview reflects
   uncommitted edits without touching the rest of the page. */
#theme-preview {
    /* Defaults match ThemeService TOKEN_DEFINITIONS (light) literally,
       NOT as var() references. The preview must be an isolated sandbox
       so the body's theme-dark class doesn't bleed into it; only the
       preview's own Light/Dark sub-toggle should swap these values
       (via JS setting inline style.setProperty on this element). */
    /* v2 source-palette defaults (straight from TOKEN_DEFINITIONS). The
       live-update JS overwrites these inline as the admin edits each token;
       declared here so the per-group example bands have a value on first
       paint. */
<?php foreach (($tokenDefinitions ?? []) as $__pk => $__pd):
        if (($__pd['validator'] ?? '') !== 'color') continue; ?>
    --<?= $__pd['css'] ?>: <?= $__pd['default'] ?>;
<?php endforeach; ?>
    /* Generic bridge — the component vars the cards / buttons / forms read
       resolve from the v2 sources (mirrors ThemeService::renderGenericLayerVars),
       so editing a source token cascades into the legacy-named consumers. */
    --color-primary: var(--primary-bg); --color-primary-dark: var(--primary-grad);
    --color-secondary: var(--secondary-bg);
    --color-success: var(--default-success); --color-danger: var(--default-danger);
    --color-warning: var(--default-warning); --color-info: var(--default-info);
    --bg-page: var(--default-bg); --bg-panel: var(--default-surface);
    --text-default: var(--default-text); --text-muted: var(--default-text-muted); --text-subtle: var(--default-text-subtle);
    --border-default: var(--default-border); --border-strong: var(--default-border-strong); --border-subtle: var(--default-border-subtle);
    --accent-subtle: var(--default-accent-tint); --accent-contrast: var(--default-accent-contrast);
    --radius-md: 8px; --radius-sm: 4px; --radius-lg: 12px;
    --font-family-body: 'Inter', system-ui, sans-serif;
    --font-family-heading: 'Inter', system-ui, sans-serif;
    --font-family-mono: ui-monospace, Menlo, monospace;
    --font-size-h1: 1.75rem; --font-size-h2: 1.5rem; --font-size-body: 14px;
    --font-size-small: 13px; --font-size-tiny: 11px;
    background: var(--bg-page);
    color: var(--text-default);
    font-family: var(--font-family-body);
    font-size: var(--font-size-body);
    border: 1px solid var(--border-default);
    border-radius: 8px;
    padding: 1rem;
    transition: background .12s, color .12s;
}
#theme-preview .preview-tabs {
    display: flex; gap: .25rem; margin-bottom: .85rem; padding: .25rem;
    background: var(--border-subtle); border-radius: 6px;
}
#theme-preview .preview-tab {
    flex: 1; text-align: center; padding: .35rem .5rem;
    font-size: 12.5px; font-weight: 600;
    border: 1px solid transparent; border-radius: 4px;
    cursor: pointer; color: var(--text-muted); background: transparent;
}
#theme-preview .preview-tab.is-active {
    background: var(--bg-panel); color: var(--text-default);
    border-color: var(--border-default);
}
#theme-preview .preview-card {
    background: var(--bg-panel);
    border: 1px solid var(--border-default);
    border-radius: var(--radius-md);
    overflow: hidden; margin-bottom: .85rem;
}
#theme-preview .preview-card-header {
    padding: .6rem .85rem; border-bottom: 1px solid var(--border-default);
    background: var(--border-subtle);
    font-weight: 600; font-size: var(--font-size-body);
    font-family: var(--font-family-heading);
}
#theme-preview .preview-card-body { padding: .85rem; }
#theme-preview h3.preview-h {
    margin: 0 0 .5rem; font-size: var(--font-size-h2);
    font-family: var(--font-family-heading);
    color: var(--text-default);
}
#theme-preview p.preview-p { margin: 0 0 .65rem; line-height: 1.55; }
#theme-preview a.preview-a { color: var(--color-primary); }
#theme-preview .preview-btn-row { display: flex; gap: .35rem; flex-wrap: wrap; margin-bottom: .85rem; }
#theme-preview .preview-btn {
    padding: .4rem .85rem; border: 1px solid var(--border-default);
    border-radius: var(--radius-md); background: var(--bg-panel);
    color: var(--text-default); cursor: pointer;
    font-family: var(--font-family-button, var(--font-family-body));
    font-size: var(--font-size-body); font-weight: 500;
}
#theme-preview .preview-btn-primary { background: var(--color-primary); color: var(--accent-contrast); border-color: var(--color-primary); }
#theme-preview .preview-btn-danger  { background: var(--color-danger);  color: var(--accent-contrast); border-color: var(--color-danger);  }
#theme-preview .preview-form label  { display: block; font-size: var(--font-size-small); font-weight: 500; margin-bottom: .25rem; color: var(--text-muted); }
#theme-preview .preview-form input,
#theme-preview .preview-form textarea {
    width: 100%; box-sizing: border-box; margin-bottom: .55rem;
    padding: .35rem .55rem; font-size: var(--font-size-body);
    font-family: var(--font-family-body);
    background: var(--bg-page); color: var(--text-default);
    border: 1px solid var(--border-strong);
    border-radius: var(--radius-sm);
}
#theme-preview .preview-table { width: 100%; border-collapse: collapse; font-size: var(--font-size-small); }
#theme-preview .preview-table th, #theme-preview .preview-table td {
    padding: .35rem .5rem; text-align: left;
    border-bottom: 1px solid var(--border-subtle);
}
#theme-preview .preview-table th { color: var(--text-subtle); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; font-size: var(--font-size-tiny); }
#theme-preview .preview-mono { font-family: var(--font-family-mono); font-size: var(--font-size-small); color: var(--text-muted); }

/* Per-palette example groups — one block per override section on the left,
   in the same order, so a colour edit is visible on its own example. */
#theme-preview .pv-group { margin-bottom: 1.1rem; }
#theme-preview .pv-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
    color: var(--text-subtle); margin: 0 0 .4rem;
}
#theme-preview .pv-band {
    border-radius: var(--radius-md); padding: .85rem 1rem; overflow: hidden;
}
#theme-preview .pv-band h4 { margin: 0 0 .25rem; font-size: 1.05rem; font-family: var(--font-family-heading); }
#theme-preview .pv-band .pv-muted  { margin: 0; font-size: 12.5px; line-height: 1.5; }
#theme-preview .pv-band .pv-subtle { display: inline-block; margin-top: .4rem; font-size: 11px; }
#theme-preview .pv-cta {
    margin-top: .6rem; padding: .4rem .9rem; border: none; border-radius: var(--radius-sm);
    font-weight: 600; font-size: 12.5px; cursor: pointer; font-family: var(--font-family-button, var(--font-family-body));
}
#theme-preview .pv-chip-row { display: flex; gap: .4rem; flex-wrap: wrap; margin-top: .6rem; }
#theme-preview .pv-chip {
    display: inline-block; padding: .15rem .55rem; border-radius: 999px;
    font-size: 11px; font-weight: 600; color: #fff;
}
#theme-preview .pv-chrome { border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-subtle); }
#theme-preview .pv-chrome-bar { padding: .5rem .8rem; font-size: 12.5px; font-weight: 600; }
#theme-preview .pv-chrome-body { display: flex; }
#theme-preview .pv-chrome-side { padding: .65rem .8rem; font-size: 11.5px; min-width: 92px; line-height: 1.5; }
#theme-preview .pv-chrome-main { flex: 1; padding: .65rem .8rem; font-size: 11.5px; background: var(--bg-panel); color: var(--text-muted); }
</style>

<div style="max-width:1280px;margin:0 auto 1rem;display:flex;justify-content:space-between;align-items:center">
    <div>
        <div style="font-size:12px;color:var(--text-muted)">
            <a href="/admin/settings" style="color:var(--color-primary);text-decoration:none">&larr; All settings</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">Appearance</h1>
        <?php if (!empty($appliedPreset) && isset($presets[$appliedPreset])): ?>
            <div style="font-size:12px;color:var(--text-muted);margin-top:.2rem">
                Based on the <strong style="color:var(--text-default)"><?= htmlspecialchars($presets[$appliedPreset]['label']) ?></strong> preset
            </div>
        <?php endif; ?>
    </div>
    <button type="button" class="btn btn-primary" onclick="openPresetModal()" style="white-space:nowrap">🎨 Theme presets</button>
</div>

<div class="appearance-wrap">
<div class="appearance-form"><form method="POST" action="/admin/settings/appearance">
    <?= csrf_field() ?>

    <!-- Navigation layout (not a theme token, but lives here) -->
    <details class="app-section" open>
        <summary>Navigation layout</summary>
        <div class="app-section-body">
            <?php $orientation = $values['layout_orientation'] ?? 'sidebar'; ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <label style="display:block;padding:1rem;border:2px solid <?= $orientation === 'sidebar' ? 'var(--color-primary)' : 'var(--border-default)' ?>;border-radius:8px;cursor:pointer;background:var(--bg-panel)">
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <input type="radio" name="layout_orientation" value="sidebar" <?= $orientation === 'sidebar' ? 'checked' : '' ?>>
                        <strong>Sidebar (default)</strong>
                    </div>
                    <div style="font-size:12.5px;color:var(--text-muted);margin-top:.4rem;line-height:1.5">
                        Persistent navigation runs down the left side of every page.
                    </div>
                </label>
                <label style="display:block;padding:1rem;border:2px solid <?= $orientation === 'topbar' ? 'var(--color-primary)' : 'var(--border-default)' ?>;border-radius:8px;cursor:pointer;background:var(--bg-panel)">
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <input type="radio" name="layout_orientation" value="topbar" <?= $orientation === 'topbar' ? 'checked' : '' ?>>
                        <strong>Top bar</strong>
                    </div>
                    <div style="font-size:12.5px;color:var(--text-muted);margin-top:.4rem;line-height:1.5">
                        Horizontal nav across the top; main content takes the full width.
                    </div>
                </label>
            </div>
        </div>
    </details>

    <?php
    // Render one collapsible section per token group, in the order
    // defined by ThemeService::GROUP_ORDER. Each section iterates through
    // the tokens in that group and renders a row. Color tokens get a
    // color picker + text input pair; length tokens get just a text
    // input (color pickers don't accept '8px').
    foreach ($groupOrder as $groupKey => $groupLabel):
        $tokens = array_filter($tokenDefinitions, fn($d) => ($d['group'] ?? '') === $groupKey);
        if (empty($tokens)) continue;
        $isOpen = ($groupKey === 'pal_default');  // the default palette stays open by default for familiarity
    ?>
    <details class="app-section" <?= $isOpen ? 'open' : '' ?>>
        <summary><?= e($groupLabel) ?></summary>
        <div class="app-section-body">
            <p class="app-section-help">
                Override values are saved to the <code>site</code> scope. Leave a field blank to use the framework default shown next to it.
            </p>
            <?php
            // Color sections get a small "Light / Dark" header strip
            // above the rows so admins know which column is which. Length
            // sections only have one column so we skip it.
            $sectionIsColor = false;
            foreach ($tokens as $d) { if (($d['validator'] ?? '') === 'color') { $sectionIsColor = true; break; } }
            ?>
            <?php if ($sectionIsColor): ?>
            <div class="token-modes">
                <span></span>
                <span class="light">Light mode</span>
                <span class="dark">Dark mode</span>
                <span></span>
            </div>
            <?php endif; ?>

            <?php foreach ($tokens as $key => $def):
                $type        = (string) ($def['validator'] ?? 'color');
                $current     = (string) ($values[$key] ?? '');
                $default     = (string) ($def['default'] ?? '');
                $defaultDark = (string) ($def['default_dark'] ?? '');
                $isColor     = $type === 'color';
                $isFont      = $type === 'font_family';
                $currentDark = $isColor ? (string) ($values[$key . '.dark'] ?? '') : '';
                $swatch      = ($isColor && preg_match('/^#[0-9a-f]{3,8}$/i', $current))     ? $current     : $default;
                $swatchDark  = ($isColor && preg_match('/^#[0-9a-f]{3,8}$/i', $currentDark)) ? $currentDark : $defaultDark;
            ?>
            <div class="token-row token-row--<?= e($type) ?>">
                <label class="token-label" for="in-<?= e($key) ?>"><?= e($def['label']) ?></label>
                <?php if ($isColor): ?>
                <input type="color"
                       value="<?= e(preg_match('/^#[0-9a-f]{3,8}$/i', (string) $swatch) ? $swatch : '#000000') ?>"
                       aria-label="<?= e($def['label']) ?> color picker"
                       oninput="document.getElementById('in-<?= e($key) ?>').value = this.value">
                <input type="text"
                       id="in-<?= e($key) ?>"
                       name="<?= e($key) ?>"
                       value="<?= e($current) ?>"
                       placeholder="<?= e($default) ?>"
                       class="form-control">
                <input type="color"
                       value="<?= e(preg_match('/^#[0-9a-f]{3,8}$/i', (string) $swatchDark) ? $swatchDark : '#000000') ?>"
                       aria-label="<?= e($def['label']) ?> dark mode color picker"
                       oninput="document.getElementById('in-<?= e($key) ?>-dark').value = this.value">
                <input type="text"
                       id="in-<?= e($key) ?>-dark"
                       name="<?= e($key) ?>.dark"
                       value="<?= e($currentDark) ?>"
                       placeholder="<?= e($defaultDark) ?>"
                       aria-label="<?= e($def['label']) ?> dark mode color value"
                       class="form-control">
                <?php elseif ($isFont): ?>
                <input type="text"
                       id="in-<?= e($key) ?>"
                       name="<?= e($key) ?>"
                       value="<?= e($current) ?>"
                       placeholder="<?= e($default) ?>"
                       list="font-library"
                       autocomplete="off"
                       class="form-control"
                       style="font-family: <?= e($current !== '' ? $current : $default) ?>; font-size:14px">
                <?php elseif ($type === 'angle'): ?>
                <?php /* Gradient direction — same dropdown the wizard step-6
                          customizer uses (ThemeService::GRADIENT_DIRECTIONS).
                          Blank value = "use default", which falls through the
                          'angle' validator's empty-allowed path on save. */ ?>
                <select id="in-<?= e($key) ?>" name="<?= e($key) ?>" class="form-control">
                    <option value="">&mdash; Default (<?= e($default) ?>) &mdash;</option>
                    <?php foreach (($gradientDirections ?? []) as $dVal => $dLabel): ?>
                    <option value="<?= e($dVal) ?>" <?= $current === $dVal ? 'selected' : '' ?>><?= e($dLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="text"
                       id="in-<?= e($key) ?>"
                       name="<?= e($key) ?>"
                       value="<?= e($current) ?>"
                       placeholder="<?= e($default) ?>"
                       class="form-control">
                <?php endif; ?>
                <span class="token-default">
                    default: <code><?= e($default) ?></code>
                    <?php if ($isColor && $defaultDark !== ''): ?>
                    <br><code style="color:var(--text-subtle)"><?= e($defaultDark) ?></code> (dark)
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endforeach; ?>

    <?php /* Shared <datalist> consumed by every font_family input. Lists
              every entry in FONT_LIBRARY by family value. Browsers
              autocomplete on focus + show suggestions as the admin types,
              while still allowing custom strings. */ ?>
    <datalist id="font-library">
        <?php foreach ($fontLibrary as $key => $entry): ?>
        <option value="<?= e((string) $entry['family']) ?>"><?= e((string) $entry['label']) ?> &mdash; <?= e((string) $entry['category']) ?></option>
        <?php endforeach; ?>
    </datalist>

    <details class="app-section">
        <summary>Custom font URLs</summary>
        <div class="app-section-body">
            <p class="app-section-help">
                One URL per line. Each line becomes a <code>&lt;link&gt;</code> tag
                emitted in the page <code>&lt;head&gt;</code>. Use this for fonts not
                in the curated allowlist (other Google Fonts, Adobe Fonts,
                self-hosted faces, etc.). Only http(s) URLs are emitted; other
                lines are dropped silently at render.
            </p>
            <textarea name="theme.font.custom_links" rows="4"
                      style="width:100%;box-sizing:border-box;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px"
                      placeholder="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;700&display=swap"
             aria-label="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;700&display=swap"><?= e((string) ($values['theme.font.custom_links'] ?? '')) ?></textarea>
        </div>
    </details>

    <div style="display:flex;gap:.75rem;margin-top:1rem">
        <button type="submit" class="btn btn-primary">Save Appearance</button>
        <a href="/admin/settings" class="btn btn-secondary">Cancel</a>
    </div>
</form>
</div>

<aside class="appearance-preview" aria-label="Live preview">
    <div id="theme-preview">
        <div class="preview-tabs" role="tablist" aria-label="Preview mode">
            <button type="button" class="preview-tab is-active" data-preview-mode="light">Light</button>
            <button type="button" class="preview-tab"           data-preview-mode="dark">Dark</button>
        </div>

        <!-- Default palette — surfaces / text / borders / accents / semantics -->
        <div class="pv-group">
            <div class="pv-label">Default palette — surfaces · text · borders · semantics</div>
            <h3 class="preview-h">How it looks</h3>
            <p class="preview-p">
                This panel updates as you edit. Sample text uses the body font and colour.
                <a href="#" class="preview-a">A link</a> picks up the primary colour.
            </p>
            <div class="preview-btn-row">
                <button type="button" class="preview-btn preview-btn-primary">Primary</button>
                <button type="button" class="preview-btn">Secondary</button>
                <button type="button" class="preview-btn preview-btn-danger">Delete</button>
            </div>
            <div class="preview-card">
                <div class="preview-card-header">Card header</div>
                <div class="preview-card-body">
                    Cards use the panel background, default border, medium radius. Headings inherit the heading font.
                </div>
            </div>
            <div class="preview-form">
                <label>Email</label>
                <input type="email" placeholder="you@example.com" disabled aria-label="you@example.com">
                <label>Message</label>
                <textarea rows="2" placeholder="Sample textarea" aria-label="Sample textarea"></textarea>
                <button type="button" class="preview-btn preview-btn-primary">Submit</button>
            </div>
            <table class="preview-table" style="margin-top:.85rem">
                <thead><tr><th>Name</th><th>Status</th></tr></thead>
                <tbody>
                    <tr><td>Sample row</td><td><span class="preview-mono">active</span></td></tr>
                    <tr><td>Another row</td><td><span class="preview-mono">pending</span></td></tr>
                </tbody>
            </table>
            <div class="pv-chip-row">
                <span class="pv-chip" style="background:var(--color-success)">Success</span>
                <span class="pv-chip" style="background:var(--color-warning)">Warning</span>
                <span class="pv-chip" style="background:var(--color-danger)">Danger</span>
                <span class="pv-chip" style="background:var(--color-info)">Info</span>
            </div>
        </div>

        <!-- Chrome — header / sidebar / footer band -->
        <div class="pv-group">
            <div class="pv-label">Chrome — header · sidebar · footer</div>
            <div class="pv-chrome">
                <div class="pv-chrome-bar" style="background:var(--chrome-header-bg);color:var(--chrome-header-text)">Header bar</div>
                <div class="pv-chrome-body">
                    <div class="pv-chrome-side" style="background:var(--chrome-sidebar-bg);color:var(--chrome-sidebar-text)">Dashboard<br>Settings<br>Reports</div>
                    <div class="pv-chrome-main">Content area on the page surface.</div>
                </div>
                <div class="pv-chrome-bar" style="background:var(--chrome-footer-bg);color:var(--chrome-footer-text);font-size:11px;font-weight:400">Footer · © Your site</div>
            </div>
        </div>

        <!-- Hero — gradient band + CTA -->
        <div class="pv-group">
            <div class="pv-label">Hero — gradient + CTA</div>
            <div class="pv-band" style="background:linear-gradient(var(--hero-grad-dir,135deg), var(--hero-bg), var(--hero-grad, var(--hero-bg)));color:var(--hero-text)">
                <h4 style="color:var(--hero-text)">Hero heading</h4>
                <p class="pv-muted" style="color:var(--hero-text-muted)">Supporting hero subtext in the muted hero tone.</p>
                <button type="button" class="pv-cta" style="background:var(--hero-cta-bg);color:var(--hero-cta-text)">Call to action</button>
            </div>
        </div>

        <?php
        // Primary / Secondary / Tertiary / Quaternary / Quinary — the section
        // palettes, each rendered as its own gradient band in the same order
        // as the override sections on the left.
        foreach ([
            'primary'    => 'Primary',
            'secondary'  => 'Secondary',
            'tertiary'   => 'Tertiary',
            'quaternary' => 'Quaternary',
            'quinary'    => 'Quinary',
        ] as $g => $glabel): ?>
        <div class="pv-group">
            <div class="pv-label"><?= $glabel ?></div>
            <div class="pv-band" style="background:linear-gradient(var(--<?= $g ?>-grad-dir,135deg), var(--<?= $g ?>-bg), var(--<?= $g ?>-grad, var(--<?= $g ?>-bg)));color:var(--<?= $g ?>-text)">
                <h4 style="color:var(--<?= $g ?>-text)"><?= $glabel ?> section</h4>
                <p class="pv-muted" style="color:var(--<?= $g ?>-text-muted)">Muted body text on the <?= strtolower($glabel) ?> palette.</p>
                <span class="pv-subtle" style="color:var(--<?= $g ?>-text-subtle)">Subtle caption / metadata</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</aside>

</div>

<script>
/* ============================================================
   Live preview wiring for /admin/settings/appearance.
   Maps every token input to a CSS variable on #theme-preview.
   ============================================================ */
(function() {
    const preview = document.getElementById('theme-preview');
    if (!preview) return;

    /* ── Server-emitted token catalogue ──
       Each entry: { css, default, default_dark }. Used to look up the
       css-var name for each input AND to fall back to shipped defaults
       when the input is empty (admin hasn't customized this token).
       Without that fallback, switching to Dark on the preview's own
       sub-toggle does nothing because most dark inputs are empty. */
    const TOKEN_DEFAULTS = <?= json_encode(array_map(
        fn($d) => [
            'css'          => (string) $d['css'],
            'default'      => (string) ($d['default'] ?? ''),
            'default_dark' => (string) ($d['default_dark'] ?? ($d['default'] ?? '')),
        ],
        $tokenDefinitions
    ), JSON_UNESCAPED_SLASHES) ?>;

    /* Current preview mode (which input feeds: light or dark). */
    let mode = 'light';

    /* Permissive validators mirroring ThemeService server-side. We don't
       want a half-typed value (e.g. "rgb(2") to break the preview. */
    const COLOR_RE  = /^(?:#[0-9a-f]{3,8}|(?:rgba?|hsla?)\(\s*[0-9.,%\s\/]+\)|[a-z]{3,30})$/i;
    const LENGTH_RE = /^\d{1,5}(?:\.\d{1,3})?(?:px|rem|em|%)$/i;
    /* Font-family: same shape as server isValidFontFamily. */
    const FONT_RE   = /^[A-Za-z0-9\s,'".\-]+$/;

    /* Apply ONE input's value to the preview. */
    function applyInput(key, val, isDark) {
        // Only apply dark inputs when previewing dark; light inputs only
        // when previewing light. This is what makes the Light/Dark
        // sub-toggle on the preview meaningful.
        if (isDark && mode !== 'dark')  return;
        if (!isDark && mode !== 'light') return;

        const def = TOKEN_DEFAULTS[key];
        if (!def) return;
        val = (val || '').trim();

        // Validate against the same shapes the server validators accept.
        // Invalid values fall back to the shipped default for the mode.
        if (val !== '' && !COLOR_RE.test(val) && !LENGTH_RE.test(val) && !FONT_RE.test(val)) {
            val = '';
        }

        // Empty input means "use the shipped default for this mode" - that's
        // what makes the dark mode actually look dark when the admin
        // hasn't customized any dark values yet.
        if (val === '') {
            val = isDark ? def.default_dark : def.default;
        }
        if (val === '') return;  // truly nothing to apply

        preview.style.setProperty('--' + def.css, val);
        // Special case: --font-family-body also drives --font on the
        // framework chrome (see ThemeService::renderOverrideStyle).
        if (def.css === 'font-family-body') {
            preview.style.setProperty('--font', val);
        }
    }

    /* Wire every input. Text/select/datalist all fire 'input'; checkbox
       and radios fire 'change'. We listen for both. */
    function inputForKey(key, isDark) {
        // Light input id is "in-{key}", dark is "in-{key}-dark"
        return document.getElementById('in-' + key + (isDark ? '-dark' : ''));
    }

    function applyAllForMode() {
        const dark = mode === 'dark';
        for (const key of Object.keys(TOKEN_DEFAULTS)) {
            const el = inputForKey(key, dark);
            if (el) applyInput(key, el.value, dark);
        }
        // For dark mode, also flip the preview's bg/text via the dark
        // defaults of any tokens that have NO matching input value
        // (admin hasn't customized dark for that token).
    }

    document.querySelectorAll('input[name], select[name], textarea[name]').forEach(el => {
        const name = el.getAttribute('name') || '';
        if (!name) return;
        const isDark = name.endsWith('.dark');
        const key    = isDark ? name.slice(0, -5) : name;
        if (!Object.prototype.hasOwnProperty.call(TOKEN_DEFAULTS, key)) return;
        const evt = (el.type === 'checkbox' || el.type === 'radio') ? 'change' : 'input';
        el.addEventListener(evt, () => applyInput(key, el.value, isDark));
    });

    /* Light/Dark sub-toggle on the preview. */
    document.querySelectorAll('[data-preview-mode]').forEach(btn => {
        btn.addEventListener('click', () => {
            mode = btn.dataset.previewMode;
            document.querySelectorAll('[data-preview-mode]').forEach(b => b.classList.toggle('is-active', b === btn));
            // Reset preview's inline vars and reapply for the new mode.
            preview.removeAttribute('style');
            applyAllForMode();
        });
    });

    /* Initial paint: run through every input once so the preview reflects
       any saved overrides on page load. */
    applyAllForMode();
})();
</script>

</main></div>

<!-- ── Theme preset picker modal ──────────────────────────────────────── -->
<div id="presetModal" class="preset-modal" style="display:none" aria-hidden="true">
    <div class="preset-modal-backdrop" onclick="closePresetModal()"></div>
    <div class="preset-modal-card" role="dialog" aria-modal="true" aria-label="Theme presets">
        <div class="preset-modal-head">
            <div>
                <h2 style="margin:0;font-size:1.1rem;font-weight:700">🎨 Theme presets</h2>
                <p style="margin:.3rem 0 0;font-size:12.5px;color:var(--text-muted);line-height:1.5">
                    One click paints every colour alias — surfaces, text, borders, accents,
                    semantics, hero, sections, and chrome — for both light + dark. You can fine-tune
                    any token afterward, then Save.
                </p>
            </div>
            <button type="button" class="preset-modal-x" onclick="closePresetModal()" aria-label="Close">&times;</button>
        </div>
        <div class="preset-modal-body">
            <?php
            $byTier = ['free' => [], 'premium' => []];
            foreach (($presets ?? []) as $slug => $p) {
                $byTier[($p['tier'] ?? 'free') === 'premium' ? 'premium' : 'free'][$slug] = $p;
            }
            $renderGroup = function (string $heading, array $group) use ($appliedPreset) {
                if (!$group) return;
                echo '<div class="preset-group-h">' . htmlspecialchars($heading) . '</div>';
                echo '<div class="preset-grid">';
                foreach ($group as $slug => $p) {
                    $sw = \Core\Theme\PresetLibrary::palette($p);
                    $isApplied = ($slug === $appliedPreset);
                    echo '<div class="preset-card' . ($isApplied ? ' is-applied' : '') . '">';
                    echo '<div class="preset-swatches">';
                    foreach ($sw as $hex) {
                        echo '<span style="background:' . htmlspecialchars($hex) . '"></span>';
                    }
                    echo '</div>';
                    echo '<div class="preset-name">' . htmlspecialchars($p['label'] ?? $slug);
                    if (($p['tier'] ?? 'free') === 'premium') echo ' <span class="preset-badge">Premium</span>';
                    if ($isApplied) echo ' <span class="preset-badge preset-badge--on">Applied</span>';
                    echo '</div>';
                    echo '<div class="preset-desc">' . htmlspecialchars($p['description'] ?? '') . '</div>';
                    echo '<form method="POST" action="/admin/settings/appearance/preset" '
                       . 'onsubmit="return confirm(\'Apply this preset? It overwrites every colour token (you can still fine-tune + Save afterward).\')">';
                    echo csrf_field();
                    echo '<input type="hidden" name="preset" value="' . htmlspecialchars($slug) . '">';
                    echo '<button type="submit" class="btn ' . ($isApplied ? 'btn-secondary' : 'btn-primary') . ' preset-apply">'
                       . ($isApplied ? 'Re-apply' : 'Apply') . '</button>';
                    echo '</form>';
                    echo '</div>';
                }
                echo '</div>';
            };
            $renderGroup('Basic', $byTier['free']);
            $renderGroup('Premium', $byTier['premium']);
            ?>
        </div>
    </div>
</div>

<style>
.preset-modal { position: fixed; inset: 0; z-index: 1000; }
.preset-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.5); }
.preset-modal-card {
    position: relative; max-width: 960px; margin: 4vh auto 0; max-height: 90vh;
    background: var(--bg-panel); border: 1px solid var(--border-default); border-radius: 12px;
    display: flex; flex-direction: column; overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
}
.preset-modal-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-default); background: var(--bg-page);
}
.preset-modal-x { background: none; border: 0; font-size: 1.6rem; line-height: 1; cursor: pointer; color: var(--text-muted); }
.preset-modal-body { padding: 1rem 1.25rem 1.5rem; overflow-y: auto; }
.preset-group-h {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
    color: var(--text-muted); margin: 1rem 0 .6rem; padding-bottom: .3rem;
    border-bottom: 1px solid var(--border-subtle);
}
.preset-group-h:first-child { margin-top: 0; }
.preset-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .85rem; }
.preset-card {
    border: 1px solid var(--border-default); border-radius: 10px; padding: .75rem; background: var(--bg-page);
    display: flex; flex-direction: column; gap: .5rem;
}
.preset-card.is-applied { border-color: var(--color-primary); box-shadow: 0 0 0 1px var(--color-primary); }
.preset-swatches { display: flex; height: 30px; border-radius: 6px; overflow: hidden; border: 1px solid var(--border-subtle); }
.preset-swatches span { flex: 1; }
.preset-name { font-size: 13.5px; font-weight: 600; color: var(--text-default); display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
.preset-badge {
    font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    padding: .1rem .35rem; border-radius: 4px; background: var(--accent-subtle); color: var(--color-primary);
}
.preset-badge--on { background: var(--color-primary); color: var(--accent-contrast); }
.preset-desc { font-size: 12px; color: var(--text-muted); line-height: 1.45; flex: 1; }
.preset-apply { width: 100%; }
</style>
<script>
function openPresetModal() {
    var m = document.getElementById('presetModal');
    if (m) { m.style.display = 'block'; m.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; }
}
function closePresetModal() {
    var m = document.getElementById('presetModal');
    if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closePresetModal();
});
</script>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
