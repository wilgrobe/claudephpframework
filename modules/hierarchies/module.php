<?php
// modules/hierarchies/module.php
use Core\Container\Container;
use Core\Module\ModuleProvider;

/**
 * Hierarchies module — navigable trees (nav menus, catalog sections,
 * org charts) with drag-and-drop reorder + move.
 *
 * Complementary to the taxonomy module, not a replacement:
 *   • taxonomy is for *classification* — tag posts/products with terms
 *     from a vocabulary, filter by term.
 *   • hierarchies is for *navigable structure* — render a menu, a
 *     section tree, a product catalog; clicks lead somewhere.
 *
 * Globally registers `hierarchy_tree($slug)` and
 * `render_hierarchy_nav($slug)` helpers so any view can consume a
 * hierarchy without explicit service resolution.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'hierarchies'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function register(Container $container): void
    {
        require_once __DIR__ . '/Helpers/helpers.php';
    }
};
