<?php
// core/Theme/NullThemeExtension.php
namespace Core\Theme;

/**
 * Default {@see ThemeExtension}: no extra presets and no brand-derived ordinal
 * tints, so a standalone framework install renders on its built-in presets and
 * static token defaults. A host binds its own implementation to add presets /
 * brand-tint derivation.
 */
final class NullThemeExtension implements ThemeExtension
{
    public function extraPresets(): array
    {
        return [];
    }

    public function deriveOrdinalTints(string $primaryHex, string $secondaryHex, bool $dark): ?array
    {
        return null;
    }
}
