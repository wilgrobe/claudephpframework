<?php
// modules/seo/module.php
use Core\Container\Container;
use Core\Module\ModuleProvider;
use Core\Router\Router;

/**
 * "Get found" — search engine and AI-answer basics for every site (Will, 17 Nov 2026: free and ON for every site,
 * each piece can be turned OFF per site at /admin/settings/search).
 *
 *   - one main address: www. and other addresses 301 to it; http:// → https:// when the site has HTTPS
 *   - a canonical link on every page that lacks one; noindex on sign-in and account pages
 *   - robots.txt with an AI Content-Signal (default search=yes, ai-input=yes, ai-train=no) and sitemap.xml on the
 *     main host without account pages
 *   - search engine verification tags and schema.org structured data by business type, on the homepage
 *   - /llms.txt and the homepage's Link header to it
 *   - IndexNow: /indexnow-key.txt + `seo:indexnow` (daily), with a record of what was sent
 *
 * On the hosted Builder, demos and free preview (proofing) sites are hidden from search (noindex everywhere, empty
 * sitemap, no llms.txt, no IndexNow) unless an operator turns search on for a demo (`demo:search`).
 *
 * Shared with the framework (claudephpframework/modules/seo, byte-identical): everything Builder-specific is
 * class_exists-guarded, so a standalone install gets the same canonical/noindex defaults with the same switches.
 */
return new class extends ModuleProvider {
    public function name(): string { return 'seo'; }

    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function commands(): array
    {
        return [\Modules\Seo\Console\Commands\IndexNowCommand::class];
    }

    /** Edited only on /admin/settings/search, never in the generic settings grid. */
    public function settingsKeys(): array
    {
        return array_merge(
            array_keys(\Modules\Seo\Services\SearchSettings::defaults()),
            [\Modules\Seo\Services\IndexNow::SETTING, \Modules\Seo\Services\IndexNow::LAST]
        );
    }

    public function settingsPanels(): array
    {
        return [
            ['key' => 'search', 'label' => 'Search & AI', 'icon' => '🔎',
             'desc' => 'Search engines, AI policy, structured data', 'url' => '/admin/settings/search'],
        ];
    }

    public function boot(Router $router, Container $c): void
    {
        \Modules\Seo\Services\SearchHead::install($_SERVER);
    }
};
