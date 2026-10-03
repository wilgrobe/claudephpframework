<?php
// tests/Unit/Database/SeedDoesNotBrandSitesTest.php
namespace Tests\Unit\Database;

use Tests\TestCase;

/**
 * The install seed must not write OUR name into somebody else's site.
 *
 * `site_tagline` is the default meta description on every page
 * (app/Views/layout/header.php → SeoManager), so the seeded
 * "Built with ClaudePHPFramework" was what a search result showed under a
 * site's link — found live on storiesden.com's pricing page, 2026-10-03.
 * An empty tagline emits no description tag at all, which is the honest default.
 */
final class SeedDoesNotBrandSitesTest extends TestCase
{
    public function test_the_seeded_tagline_is_empty(): void
    {
        $seed = (string) file_get_contents(BASE_PATH . '/database/migrations/0500_framework_data.php');
        $this->assertSame(1, preg_match("/\['site',\s*'site_tagline',\s*'([^']*)'/", $seed, $m), 'site_tagline seed row not found');
        $this->assertSame('', $m[1], 'the seed gives every new site a tagline it did not write');
        $this->assertStringNotContainsString('Built with ClaudePHPFramework', $seed);
    }
}
