<?php
// tests/Unit/Modules/SiteblocksGridShrinksTest.php
namespace Tests\Unit\Modules;

use Tests\TestCase;

/**
 * 2026-10-09: feature_grid and stats_showcase laid their tiles out with a fixed
 * `repeat(N, minmax(180px|140px, 1fr))`, which can never be narrower than N × minimum + gaps — ~564px for three
 * feature columns, ~732px for five stats. On a phone the whole page then scrolled sideways (the Builder's own
 * home page did). The track list is now "at most N columns": N while they fit, wrapping below the minimum, and
 * never wider than the container. auto-fill (not auto-fit) keeps empty tracks, so the desktop layout is unchanged.
 */
final class SiteblocksGridShrinksTest extends TestCase
{
    private static function block(string $key): \Core\Module\BlockDescriptor
    {
        $provider = require BASE_PATH . '/modules/siteblocks/module.php';
        foreach ($provider->blocks() as $b) {
            if ($b->key === $key) return $b;
        }
        self::fail("block $key not found");
    }

    /** @return array<string, array{string, array<string, mixed>, int, string}> */
    public static function grids(): array
    {
        $features = array_fill(0, 3, ['icon' => '★', 'title' => 'T', 'description' => 'D']);
        $stats    = array_fill(0, 5, ['value' => '9', 'label' => 'L']);
        return [
            'feature_grid, 3 columns' => ['siteblocks.feature_grid', ['columns' => 3, 'features' => $features], 3, '180px'],
            'stats_showcase, 5 stats' => ['siteblocks.stats_showcase', ['stats' => $stats], 5, '140px'],
        ];
    }

    /** @dataProvider grids */
    public function test_the_tile_grid_can_shrink_to_a_phone(string $key, array $settings, int $cols, string $min): void
    {
        $html = (string) (self::block($key)->render)([], $settings);
        $this->assertMatchesRegularExpression('/grid-template-columns:([^"]+)"/', $html);
        preg_match('/grid-template-columns:([^"]+)"/', $html, $m);
        $tracks = $m[1];

        // The fixed form that caused the overflow must be gone.
        $this->assertStringNotContainsString("repeat($cols,minmax($min,1fr))", $tracks);
        // Wraps (auto-fill), never wider than the container (min(100%, …)), and still N columns when wide.
        $this->assertStringStartsWith('repeat(auto-fill,minmax(min(100%,max(' . $min . ',calc((100% - ' . ($cols - 1) . ' * ', $tracks);
        $this->assertStringContainsString(') / ' . $cols . '))),1fr))', $tracks);
    }
}
