<?php
// core/Theme/PresetExpander.php
namespace Core\Theme;

/**
 * Expands a theme preset's CORE colours into the FULL v2 token set so a
 * preset paints every colour alias — surfaces, text, borders, accents, the
 * four semantic colours, the hero role, the five ordinal palettes, and chrome
 * — for both light + dark mode.
 *
 * Each preset declares a small "core" (its `tokens` / `tokens_dark` maps):
 *   - theme.palette.primary.bg          (the brand colour) — required
 *   - theme.palette.primary.grad        (gradient stop 2 / hover) — optional
 *   - theme.palette.secondary.bg        — optional (rotated from brand if absent)
 *   - theme.palette.default.bg          (page) — required
 *   - theme.palette.default.surface     (panel) — optional (= bg)
 *   - theme.palette.default.text        (ink) — required
 *   - theme.palette.default.text-muted  — optional (derived from text↔bg)
 *   - theme.palette.default.{success,warning,danger,info} — optional (sane defaults)
 *   - any other token the preset wants to pin (it wins over the derived value)
 *
 * Everything else is DERIVED here with the same HSL / luminance math the
 * brand-colour deriver uses, so presets stay tiny + consistent. Aliases that
 * track the Brand colour (primary, hero, the ordinal tints) still get values,
 * but a configured Brand colour overrides them at runtime.
 *
 * Pure functions, no side effects. Self-contained (no dependency on a host's
 * brand-color deriver) so the framework can expand the basic presets on its own.
 */
final class PresetExpander
{
    /** Default semantic colours per mode — kept in their canonical hue family
     *  (success green, warning amber/orange, danger red, info blue/teal). A
     *  preset overrides any of these in its core to theme them. */
    private const SEMANTIC_DEFAULTS = [
        'light' => ['success' => '#15803d', 'warning' => '#b45309', 'danger' => '#dc2626', 'info' => '#0284c7'],
        'dark'  => ['success' => '#4ade80', 'warning' => '#fbbf24', 'danger' => '#f87171', 'info' => '#38bdf8'],
    ];

    /**
     * Expand a preset (its `tokens` + `tokens_dark` core maps) into the full
     * token set. Non-colour tokens (fonts, radii, font sizes) pass through
     * untouched. Returns ['tokens' => fullLight, 'tokens_dark' => fullDark].
     *
     * @param array{tokens?:array<string,string>, tokens_dark?:array<string,string>} $preset
     * @return array{tokens:array<string,string>, tokens_dark:array<string,string>}
     */
    public static function expand(array $preset): array
    {
        $light = (array) ($preset['tokens'] ?? []);
        // Dark core inherits the light core for anything it doesn't restate
        // (so a preset can give just a few dark surfaces + keep the rest).
        $dark  = ((array) ($preset['tokens_dark'] ?? [])) + $light;

        return [
            'tokens'      => self::expandMode($light, false),
            'tokens_dark' => self::expandMode($dark, true),
        ];
    }

    /**
     * Expand one mode. Derives the full colour set from the core, then overlays
     * the core's explicit values (preset always wins). Non-colour tokens pass
     * through.
     *
     * @param array<string,string> $core
     * @return array<string,string>
     */
    private static function expandMode(array $core, bool $dark): array
    {
        $get = static fn(string $k, ?string $fallback = null): ?string => self::isHex((string) ($core[$k] ?? '')) ? strtolower((string) $core[$k]) : $fallback;

        // ── Required-ish seeds (with sane fallbacks so a sparse preset can't blow up)
        $primary   = $get('theme.palette.primary.bg', $dark ? '#818cf8' : '#4f46e5');
        $secondary = $get('theme.palette.secondary.bg', self::rotate($primary, 150.0));
        $bg        = $get('theme.palette.default.bg', $dark ? '#0f172a' : '#f9fafb');
        $surface   = $get('theme.palette.default.surface', $dark ? '#1e293b' : '#ffffff');
        $text      = $get('theme.palette.default.text', $dark ? '#f8fafc' : '#111827');

        $primaryGrad   = $get('theme.palette.primary.grad', self::darken($primary, 0.14));
        $secondaryGrad = $get('theme.palette.secondary.grad', self::darken($secondary, 0.14));
        $textMuted     = $get('theme.palette.default.text-muted', self::mix($text, $bg, 0.62));
        $textSubtle    = $get('theme.palette.default.text-subtle', self::mix($text, $bg, 0.46));

        $primaryInk   = self::contrast($primary);
        $secondaryInk = self::contrast($secondary);

        $sem = self::SEMANTIC_DEFAULTS[$dark ? 'dark' : 'light'];

        $derived = [
            // ── default palette — surfaces / text / borders / accents / line / semantics
            'theme.palette.default.bg'              => $bg,
            'theme.palette.default.surface'         => $surface,
            'theme.palette.default.text'            => $text,
            'theme.palette.default.text-muted'      => $textMuted,
            'theme.palette.default.text-subtle'     => $textSubtle,
            'theme.palette.default.border'          => self::mix($text, $bg, 0.16),
            'theme.palette.default.border-strong'   => self::mix($text, $bg, 0.28),
            'theme.palette.default.border-subtle'   => self::mix($text, $bg, 0.07),
            'theme.palette.default.accent'          => $primary,
            'theme.palette.default.accent-contrast' => $primaryInk,
            'theme.palette.default.accent-tint'     => self::mix($primary, $surface, 0.12),
            'theme.palette.default.line'            => self::withAlpha(self::mix($text, $bg, 0.5), $dark ? '1a' : '24'),
            'theme.palette.default.success'         => $sem['success'],
            'theme.palette.default.warning'         => $sem['warning'],
            'theme.palette.default.danger'          => $sem['danger'],
            'theme.palette.default.info'            => $sem['info'],

            // ── hero (gradient header + CTA)
            'theme.palette.hero.bg'         => $primary,
            'theme.palette.hero.grad'       => $primaryGrad,
            'theme.palette.hero.text'       => $primaryInk,
            'theme.palette.hero.text-muted' => self::withAlpha($primaryInk, 'e6'),
            'theme.palette.hero.cta-bg'     => $primaryInk === '#ffffff' ? '#ffffff' : '#1c1917',
            'theme.palette.hero.cta-text'   => $primary,

            // ── primary bold palette
            'theme.palette.primary.bg'          => $primary,
            'theme.palette.primary.grad'        => $primaryGrad,
            'theme.palette.primary.text'        => $primaryInk,
            'theme.palette.primary.text-muted'  => self::withAlpha($primaryInk, 'e6'),
            'theme.palette.primary.text-subtle' => self::withAlpha($primaryInk, 'b3'),

            // ── secondary bold palette
            'theme.palette.secondary.bg'          => $secondary,
            'theme.palette.secondary.grad'        => $secondaryGrad,
            'theme.palette.secondary.text'        => $secondaryInk,
            'theme.palette.secondary.text-muted'  => self::withAlpha($secondaryInk, 'e6'),
            'theme.palette.secondary.text-subtle' => self::withAlpha($secondaryInk, 'b3'),

            // ── chrome (sidebar + footer + header) — a deep brand-tinted band
            'theme.color.chrome.header_bg'    => self::chromeBg($primary),
            'theme.color.chrome.header_text'  => self::chromeText($primary),
            'theme.color.chrome.sidebar_bg'   => self::chromeBg($primary),
            'theme.color.chrome.sidebar_text' => self::chromeText($primary),
            'theme.color.chrome.footer_bg'    => self::chromeBg($primary),
            'theme.color.chrome.footer_text'  => self::chromeText($primary),
        ];

        // ── ordinal section palettes (tertiary / quaternary / quinary) — tints
        foreach (self::ordinalTints($primary, $secondary, $dark) as $slot => $pair) {
            $derived["theme.palette.{$slot}.bg"]          = $pair['bg'];
            $derived["theme.palette.{$slot}.grad"]        = $pair['grad'];
            // Tints are pale (light) / deep (dark); the neutral ink already
            // flips with the mode, so reuse it for the ordinal text.
            $derived["theme.palette.{$slot}.text"]        = $text;
            $derived["theme.palette.{$slot}.text-muted"]  = $textMuted;
            $derived["theme.palette.{$slot}.text-subtle"] = $textSubtle;
        }

        // Overlay the preset's explicit core LAST (it always wins), including
        // any non-colour tokens (fonts / radii / sizes) which we pass through.
        return array_merge($derived, $core);
    }

    /** Tertiary/quaternary/quinary bg+grad tints (mirrors the host brand deriver). */
    private static function ordinalTints(string $primary, string $secondary, bool $dark): array
    {
        [$hb, $sb, ] = self::rgbToHsl(...self::hexToRgb($primary));
        [$hs, $ss, ] = self::hexToRgb($secondary) ? self::rgbToHsl(...self::hexToRgb($secondary)) : [$hb, $sb, 0.5];

        $tint = static function (float $h, float $s, float $scale, float $smin, float $smax, float $l): string {
            $sat = min($smax, max($smin, $s * $scale));
            return self::rgbToHex(...self::hslToRgb($h, $sat, $l));
        };

        if ($dark) {
            return [
                'tertiary'   => ['bg' => $tint($hb, $sb, 0.55, 0.22, 0.48, 0.17), 'grad' => $tint($hb, $sb, 0.50, 0.18, 0.42, 0.13)],
                'quaternary' => ['bg' => $tint($hs, $ss, 0.55, 0.22, 0.48, 0.17), 'grad' => $tint($hs, $ss, 0.50, 0.18, 0.42, 0.13)],
                'quinary'    => ['bg' => $tint($hb, $sb, 0.16, 0.05, 0.14, 0.145), 'grad' => $tint($hb, $sb, 0.14, 0.04, 0.12, 0.12)],
            ];
        }
        return [
            'tertiary'   => ['bg' => $tint($hb, $sb, 0.42, 0.18, 0.50, 0.93), 'grad' => $tint($hb, $sb, 0.34, 0.12, 0.40, 0.965)],
            'quaternary' => ['bg' => $tint($hs, $ss, 0.42, 0.18, 0.50, 0.93), 'grad' => $tint($hs, $ss, 0.34, 0.12, 0.40, 0.965)],
            'quinary'    => ['bg' => $tint($hb, $sb, 0.18, 0.06, 0.16, 0.95), 'grad' => $tint($hb, $sb, 0.16, 0.05, 0.14, 0.92)],
        ];
    }

    // ── colour helpers ─────────────────────────────────────────────────────

    private static function chromeBg(string $hex): string
    {
        [$h, $s, ] = self::rgbToHsl(...self::hexToRgb($hex));
        return self::rgbToHex(...self::hslToRgb($h, min(1.0, $s * 0.4), 0.12));
    }
    private static function chromeText(string $hex): string
    {
        [$h, $s, ] = self::rgbToHsl(...self::hexToRgb($hex));
        return self::rgbToHex(...self::hslToRgb($h, min(1.0, max(0.3, $s * 0.45)), 0.82));
    }

    /** Lerp between two hexes — $tA is the weight of $a (0..1). */
    private static function mix(string $a, string $b, float $tA): string
    {
        $ra = self::hexToRgb($a); $rb = self::hexToRgb($b);
        if ($ra === null || $rb === null) return $a;
        $tA = max(0.0, min(1.0, $tA)); $tB = 1.0 - $tA;
        return self::rgbToHex(
            (int) round($ra[0] * $tA + $rb[0] * $tB),
            (int) round($ra[1] * $tA + $rb[1] * $tB),
            (int) round($ra[2] * $tA + $rb[2] * $tB),
        );
    }

    private static function darken(string $hex, float $by): string
    {
        $rgb = self::hexToRgb($hex);
        if ($rgb === null) return $hex;
        [$h, $s, $l] = self::rgbToHsl(...$rgb);
        return self::rgbToHex(...self::hslToRgb($h, $s, max(0.04, $l - $by)));
    }

    /** Rotate hue by $deg degrees (keeps S/L), for a fallback secondary. */
    private static function rotate(string $hex, float $deg): string
    {
        $rgb = self::hexToRgb($hex);
        if ($rgb === null) return $hex;
        [$h, $s, $l] = self::rgbToHsl(...$rgb);
        return self::rgbToHex(...self::hslToRgb(fmod($h + $deg / 360.0, 1.0), $s, $l));
    }

    /** Near-black or white ink, by relative luminance. */
    private static function contrast(string $hex): string
    {
        $rgb = self::hexToRgb($hex);
        if ($rgb === null) return '#111827';
        return self::relativeLuminance(...$rgb) > 0.5 ? '#1c1917' : '#ffffff';
    }

    private static function withAlpha(string $hex, string $alpha): string
    {
        $rgb = self::hexToRgb($hex);
        return $rgb === null ? $hex : self::rgbToHex(...$rgb) . $alpha;
    }

    private static function isHex(string $v): bool
    {
        return (bool) preg_match('/^#?[0-9a-fA-F]{3}$|^#?[0-9a-fA-F]{6}$|^#?[0-9a-fA-F]{8}$/', $v);
    }

    // ── colour-space helpers (self-contained copy) ─────────────────────────

    /** @return array{int,int,int}|null */
    private static function hexToRgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 8) $hex = substr($hex, 0, 6); // drop alpha for math
        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return null;
        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }

    /** @return array{float,float,float} */
    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
        if ($max === $min) return [0.0, 0.0, $l];
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max === $r)     $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
        elseif ($max === $g) $h = ($b - $r) / $d + 2;
        else                 $h = ($r - $g) / $d + 4;
        return [$h / 6, $s, $l];
    }

    /** @return array{int,int,int} */
    private static function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s === 0.0) { $v = (int) round($l * 255); return [$v, $v, $v]; }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        return [
            (int) round(self::hue($p, $q, $h + 1/3) * 255),
            (int) round(self::hue($p, $q, $h)       * 255),
            (int) round(self::hue($p, $q, $h - 1/3) * 255),
        ];
    }

    private static function hue(float $p, float $q, float $t): float
    {
        if ($t < 0) $t += 1; if ($t > 1) $t -= 1;
        if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1/2) return $q;
        if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
        return $p;
    }

    private static function relativeLuminance(int $r, int $g, int $b): float
    {
        $c = static fn(int $x): float => ($v = $x / 255) <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        return 0.2126 * $c($r) + 0.7152 * $c($g) + 0.0722 * $c($b);
    }
}
