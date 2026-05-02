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
    --color-primary: #4f46e5;
    --color-primary-dark: #3730a3;
    --color-secondary: #0ea5e9;
    --color-success: #10b981;
    --color-danger: #ef4444;
    --color-warning: #f59e0b;
    --color-info: #3b82f6;
    --bg-page: #f9fafb; --bg-panel: #ffffff;
    --text-default: #111827; --text-muted: #6b7280; --text-subtle: #9ca3af;
    --border-default: #e5e7eb; --border-strong: #d1d5db; --border-subtle: #f3f4f6;
    --accent-subtle: #eef2ff; --accent-contrast: #ffffff;
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
</style>

<div style="max-width:1280px;margin:0 auto 1rem;display:flex;justify-content:space-between;align-items:center">
    <div>
        <div style="font-size:12px;color:var(--text-muted)">
            <a href="/admin/settings" style="color:var(--color-primary);text-decoration:none">&larr; All settings</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">Appearance</h1>
    </div>
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
        $isOpen = ($groupKey === 'brand');  // brand colors stay open by default for familiarity
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

        <h3 class="preview-h">How it looks</h3>
        <p class="preview-p">
            This panel updates as you edit. Sample text uses the body font and color.
            <a href="#" class="preview-a">A link</a> picks up the primary color.
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
       when the input is empty (admin hasn't customised this token).
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
        // hasn't customised any dark values yet.
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
        // (admin hasn't customised dark for that token).
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

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
