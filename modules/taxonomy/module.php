<?php
// modules/taxonomy/module.php
use Core\Container\Container;
use Core\Module\ModuleProvider;

/**
 * Taxonomy module — tree primitive for the framework.
 *
 * Multi-vocabulary classification system backed by an adjacency list +
 * closure table hybrid. Each vocabulary ("set") is an independent tree.
 * Terms can be attached polymorphically to any entity via a type string
 * and id — same convention as comments.
 *
 * View helpers (taxonomy_tree, terms_for, attach_term, detach_term) are
 * registered globally in register() so any module can call them without
 * explicit service resolution.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'taxonomy'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function register(Container $container): void
    {
        require_once __DIR__ . '/Helpers/helpers.php';
    }
};
