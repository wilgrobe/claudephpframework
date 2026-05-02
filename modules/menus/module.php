<?php
// modules/menus/module.php
use Core\Module\ModuleProvider;

/**
 * Menus module - admin CRUD for the MenuService-backed navigation trees,
 * plus the menus.embed BlockDescriptor so menus can land on any page via
 * the page composer.
 *
 * The `menu('location')` helper in core/helpers.php is what views call;
 * this module provides the admin UI for editing what that helper returns
 * AND the embed block for surfacing menus mid-page.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'menus'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        return [
            new \Core\Module\BlockDescriptor(
                key:         'menus.embed',
                label:       'Embed Menu',
                description: 'Render an admin-built menu inline. Settings: menu (id), style, show_icons, max_depth.',
                category:    'Site Building',
                defaultSize: 'medium',
                defaultSettings: [
                    'menu_id'     => null,
                    'style'       => 'horizontal',
                    'show_icons'  => true,
                    'max_depth'   => 2,
                ],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'menu_id', 'label' => 'Menu', 'type' => 'select', 'default' => '',
                     'options' => self::menuOptions(),
                     'help' => 'Pick which admin-built menu to render. Manage menus in /admin/menus.'],
                    ['key' => 'style', 'label' => 'Layout', 'type' => 'select', 'default' => 'horizontal',
                     'options' => [
                         'horizontal' => 'Horizontal (inline row)',
                         'vertical'   => 'Vertical (stacked)',
                         'dropdown'   => 'Dropdown (single trigger)',
                     ]],
                    ['key' => 'show_icons', 'label' => 'Show icons', 'type' => 'checkbox', 'default' => true],
                    ['key' => 'max_depth',  'label' => 'Max nesting depth', 'type' => 'number', 'default' => 2,
                     'help' => '1 = top-level only. 2 = parents + first nest. Most menus stop at 2.'],
                ],
                render: function (array $context, array $settings): string {
                    $menuId   = isset($settings['menu_id']) && $settings['menu_id'] !== '' ? (int) $settings['menu_id'] : 0;
                    $style    = (string) ($settings['style'] ?? 'horizontal');
                    $showIcon = !empty($settings['show_icons']);
                    $maxDepth = max(1, min(5, (int) ($settings['max_depth'] ?? 2)));

                    if ($menuId <= 0) {
                        $auth = \Core\Auth\Auth::getInstance();
                        return $auth->hasRole(['super-admin','admin'])
                            ? '<div class="card"><div class="card-body" style="padding:1rem 1.25rem;color:#92400e;background:#fef3c7;border-radius:6px;font-size:13px">Menu block: pick a menu in this block\'s settings.</div></div>'
                            : '';
                    }

                    // Per-request memo for the (menu_id -> rendered HTML) map.
                    // Multiple menus.embed instances on the same page (header + sidebar
                    // + page composer) for the same menu_id all share one render.
                    static $renderCache = [];
                    $cacheKey = $menuId . '|' . $style . '|' . ($showIcon ? '1' : '0') . '|' . $maxDepth
                              . '|' . ((string) ($_SERVER['REQUEST_URI'] ?? '/'));
                    if (isset($renderCache[$cacheKey])) return $renderCache[$cacheKey];

                    $svc = app(\Core\Services\MenuService::class);
                    $row = \Core\Database\Database::getInstance()
                        ->fetchOne("SELECT location FROM menus WHERE id = ?", [$menuId]);
                    if (!$row) return $renderCache[$cacheKey] = '';
                    $tree = $svc->getMenu((string) $row['location'], (string) ($_SERVER['REQUEST_URI'] ?? '/'));
                    if (empty($tree)) return $renderCache[$cacheKey] = '';

                    return $renderCache[$cacheKey] = self::renderTree($tree, $style, $showIcon, $maxDepth);
                }
            ),
        ];
    }

    public function linkSources(): array { return []; }

    /**
     * Build the menu_id select options at descriptor-construction time.
     * Falls back gracefully if no menus table exists yet.
     */
    private static function menuOptions(): array
    {
        try {
            $rows = \Core\Database\Database::getInstance()
                ->fetchAll("SELECT id, name, location FROM menus ORDER BY name ASC");
            $out = ['' => '- Select a menu -'];
            foreach ($rows as $r) {
                $out[(string) $r['id']] = $r['name'] . ' (' . $r['location'] . ')';
            }
            return $out;
        } catch (\Throwable) {
            return ['' => '- Select a menu -'];
        }
    }

    /**
     * Render the nested tree from MenuService::getMenu in the chosen
     * style. Each item carries 'children' (recursively populated). The
     * tree is already visibility-filtered + sorted by the service.
     */
    private static function renderTree(array $tree, string $style, bool $showIcon, int $maxDepth): string
    {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5); };

        $renderItem = null;
        $renderItem = function (array $item, int $depth) use (&$renderItem, $h, $showIcon, $maxDepth, $style): string {
            if ($depth > $maxDepth) return '';
            $kind  = (string) ($item['kind'] ?? ($item['url'] === null ? 'holder' : 'link'));
            $label = $h($item['label'] ?? '');
            $url   = (string) ($item['url'] ?? '');
            $icon  = $showIcon && !empty($item['icon']) ? $h($item['icon']) . ' ' : '';
            $target= ($item['target'] ?? '_self') === '_blank' ? ' target="_blank" rel="noopener"' : '';

            $children = $item['children'] ?? [];
            $childHtml = '';
            if (!empty($children) && $depth < $maxDepth) {
                foreach ($children as $c) $childHtml .= $renderItem($c, $depth + 1);
            }

            // Holder: non-clickable label that wraps its children
            if ($kind === 'holder') {
                if ($style === 'horizontal') {
                    return '<li class="menu-block-item menu-block-item--holder">'
                         . '<span class="menu-block-holder-label">' . $icon . $label . '</span>'
                         . ($childHtml ? '<ul class="menu-block-sublist">' . $childHtml . '</ul>' : '')
                         . '</li>';
                }
                return '<li class="menu-block-item menu-block-item--holder">'
                     . '<div class="menu-block-holder-label">' . $icon . $label . '</div>'
                     . ($childHtml ? '<ul class="menu-block-sublist">' . $childHtml . '</ul>' : '')
                     . '</li>';
            }

            // Regular link
            $href = $url !== '' ? ' href="' . $h($url) . '"' : '';
            $tag  = $url !== '' ? 'a' : 'span';
            return '<li class="menu-block-item">'
                 . '<' . $tag . $href . $target . ' class="menu-block-link">' . $icon . $label . '</' . $tag . '>'
                 . ($childHtml ? '<ul class="menu-block-sublist">' . $childHtml . '</ul>' : '')
                 . '</li>';
        };

        $itemsHtml = '';
        foreach ($tree as $top) $itemsHtml .= $renderItem($top, 1);

        // Styles live in public/assets/css/app.css (Perf H, 2026-04-30) so
        // multiple menus.embed instances on one page don't repeat the same
        // ~1.4KB of CSS in the body. The selectors are stable; if a future
        // change adds a new style variant, mirror it in app.css.
        $css = '';

        if ($style === 'dropdown') {
            // JS-driven open/close on the dropdown's trigger.
            $js = '<script>(function(){'
                . 'document.querySelectorAll(".menu-block--dropdown").forEach(function(d){'
                .   'var btn=d.querySelector(".menu-block-trigger");'
                .   'if(!btn)return;'
                .   'btn.addEventListener("click",function(e){e.stopPropagation();d.classList.toggle("is-open");});'
                . '});'
                . 'document.addEventListener("click",function(e){'
                .   'document.querySelectorAll(".menu-block--dropdown.is-open").forEach(function(d){'
                .     'if(!d.contains(e.target))d.classList.remove("is-open");'
                .   '});'
                . '});'
                . '})();</script>';
            return $css
                 . '<div class="menu-block menu-block--dropdown">'
                 . '<button type="button" class="menu-block-trigger">Menu &#9662;</button>'
                 . '<ul>' . $itemsHtml . '</ul>'
                 . '</div>'
                 . $js;
        }

        $cls = $style === 'vertical' ? 'menu-block menu-block--vertical' : 'menu-block menu-block--horizontal';
        return $css . '<ul class="' . $cls . '">' . $itemsHtml . '</ul>';
    }
};
