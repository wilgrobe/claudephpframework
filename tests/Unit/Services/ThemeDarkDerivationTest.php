<?php
// tests/Unit/Services/ThemeDarkDerivationTest.php
namespace Tests\Unit\Services;

use Core\Services\SettingsService;
use Core\Services\ThemeService;
use Tests\TestCase;

/**
 * Board #403 (2026-10-07). A site that customised its LIGHT colours and never set dark ones got the STOCK dark
 * palette for those tokens — a different design: StoriesDen's warm brown sidebar went indigo (#1e1b4b), its
 * white header went slate (#111827) under a dark-text logo, its brand rust #a85632 became the stock coral.
 * Dark values are now derived from the site's own light colours. This file is shared byte-for-byte with the
 * framework; the test is in both repos.
 */
final class ThemeDarkDerivationTest extends TestCase
{
    private function theme(array $settings): ThemeService
    {
        $stub = new class($settings) extends SettingsService {
            public function __construct(private array $v) {}
            public function get(string $key, mixed $default = null, string $scope = 'site', ?string $scopeKey = null): mixed
            {
                return $this->v[$key] ?? $default;
            }
        };
        return new ThemeService($stub);
    }

    public function test_a_surface_already_dark_in_light_mode_keeps_its_colour(): void
    {
        // StoriesDen's sidebar: chosen dark for a light theme, so it is right for dark mode too.
        $this->assertSame('#241d18', ThemeService::deriveDark('#241d18', '#1e1b4b', '#1e1b4b'));
        $this->assertSame('#241d18', $this->theme(['theme.color.chrome.sidebar_bg' => '#241d18'])->resolveTokens('dark')['chrome-sidebar-bg'],
            'the warm sidebar turned into the stock indigo again');
    }

    public function test_a_brand_colour_keeps_its_hue_and_brightens_like_the_stock_palette_does(): void
    {
        $dark = $this->theme(['theme.palette.primary.bg' => '#a85632'])->resolveTokens('dark')['primary-bg'];

        $this->assertNotSame('#d4521e', $dark, 'the brand fell back to the stock coral');
        [$h0] = $this->hsl('#a85632'); [$h1, , $l1] = $this->hsl($dark); [, , $l0] = $this->hsl('#a85632');
        $this->assertEqualsWithDelta($h0, $h1, 0.01, 'the brand hue changed');
        $this->assertGreaterThan($l0, $l1, 'the stock dark palette lifts the brand; the site\'s brand should lift the same way');
    }

    public function test_a_light_surface_flips_dark_and_a_colourless_one_borrows_the_page_hue(): void
    {
        $page = '#1c1917';   // the stock warm dark page
        $white = ThemeService::deriveDark('#ffffff', '#ffffff', '#111827', $page);
        [, , $l] = $this->hsl($white);
        $this->assertLessThan(0.2, $l, 'a white header must turn dark in dark mode');
        [$hp, $sp] = $this->hsl($page); [$hw, $sw] = $this->hsl($white);
        $this->assertEqualsWithDelta($hp, $hw, 0.02, 'a colourless surface took a hue that is not the page\'s');
        $this->assertGreaterThan(0.0, $sw);

        $cream = ThemeService::deriveDark('#faf7f2', '#faf7f2', '#1c1917');
        [, , $lc] = $this->hsl($cream);
        $this->assertLessThan(0.2, $lc);
    }

    public function test_a_flipping_token_the_site_already_made_dark_is_left_as_chosen(): void
    {
        // The header flips (white → dark) in the stock palette. A site that gave its LIGHT theme a dark header
        // already chose a colour that works in dark mode; re-deriving it would replace the owner's choice.
        $this->assertSame('#2a2420', ThemeService::deriveDark('#2a2420', '#ffffff', '#231e1b', '#1c1917'));
        // …and the same for text the other way: light text on a token whose text flips dark → light.
        $this->assertSame('#f0e6d6', ThemeService::deriveDark('#f0e6d6', '#1c1917', '#f5f5f4'));
    }

    public function test_dark_text_flips_light_and_light_text_already_right_is_kept(): void
    {
        [, , $l] = $this->hsl((string) ThemeService::deriveDark('#1c1917', '#1c1917', '#f5f5f4'));
        $this->assertGreaterThan(0.8, $l);
        $this->assertSame('#e7dcc8', ThemeService::deriveDark('#e7dcc8', '#c7d2fe', '#c7d2fe'), 'cream sidebar text was changed');
    }

    public function test_alpha_is_kept_and_only_hex_is_derived(): void
    {
        $line = (string) ThemeService::deriveDark('#78716c24', '#78716c24', '#e7e2da1a');
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}24$/', $line, 'the line colour lost its transparency');
        $this->assertNull(ThemeService::deriveDark('red', '#c2410c', '#d4521e'));
        $this->assertNull(ThemeService::deriveDark('rgb(1,2,3)', '#c2410c', '#d4521e'));
    }

    public function test_an_explicit_dark_value_still_wins_and_unset_tokens_keep_the_stock_default(): void
    {
        $t = $this->theme([
            'theme.palette.primary.bg' => '#a85632', 'theme.palette.primary.bg.dark' => '#123456',
        ])->resolveTokens('dark');
        $this->assertSame('#123456', $t['primary-bg'], 'an owner\'s own dark colour was overridden');

        $none = $this->theme([])->resolveTokens('dark');
        $this->assertSame('#1e1b4b', $none['chrome-sidebar-bg'], 'a site that set nothing must be unchanged');
        $this->assertSame('#231e1b', $none['chrome-header-bg'], 'the stock dark header is the warm one now');
    }

    /** @return array{0:float,1:float,2:float} */
    private function hsl(string $hex): array
    {
        $h = ltrim($hex, '#');
        [$r, $g, $b] = array_map(static fn ($x) => hexdec($x) / 255, str_split(substr($h, 0, 6), 2));
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
        if ($max === $min) return [0.0, 0.0, $l];
        $d = $max - $min; $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $hh = $max === $r ? ($g - $b) / $d + ($g < $b ? 6 : 0) : ($max === $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4);
        return [$hh / 6, $s, $l];
    }
}
