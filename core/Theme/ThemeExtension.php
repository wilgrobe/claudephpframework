<?php
// core/Theme/ThemeExtension.php
namespace Core\Theme;

/**
 * Extension point for theme features a host layers on top of the framework's
 * built-in theming: additional presets and brand-derived ordinal palette
 * tints.
 *
 * The framework ships {@see NullThemeExtension} (no extra presets, no derived
 * tints). A host binds its own implementation in
 * config/services.php. {@see PresetLibrary} and {@see \Core\Services\ThemeService}
 * resolve whatever is bound, so neither names a host-specific class.
 */
interface ThemeExtension
{
    /**
     * Extra theme presets to merge into the preset library, keyed by slug.
     * Same shape as PresetLibrary::basics() entries. Return [] for none.
     *
     * @return array<string, array<string, mixed>>
     */
    public function extraPresets(): array;

    /**
     * Derive the 6 ordinal-tint CSS vars (tertiary…quinary, light + the dark
     * band selected by $dark) from the site's primary/secondary brand colors,
     * or NULL to leave the framework's static token defaults in place.
     *
     * @return array<string, string>|null  cssVarName => hex, or null
     */
    public function deriveOrdinalTints(string $primaryHex, string $secondaryHex, bool $dark): ?array;
}
