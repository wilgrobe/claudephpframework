<?php
// core/Theme/PresetLibrary.php
namespace Core\Theme;

/**
 * The framework's theme-preset catalogue. Ships the BASIC (free) presets that
 * every install gets. Premium presets are contributed by the builder layer
 * (App\Theme\PresetLibrary::premiumPresets()) and merged in here via a
 * class_exists soft-dep — a framework-only install sees just the basics; a
 * builder install (or one that's paid for premium) sees all of them.
 *
 * Each preset declares a small CORE map of { token_key => value } (its
 * `tokens` + per-mode `tokens_dark`). PresetExpander turns that core into the
 * full v2 token set when a preset is applied, so a one-click apply paints
 * every colour alias — surfaces, text, borders, accents, the four semantic
 * colours, hero, the five ordinal palettes, and chrome — consistently for
 * light + dark.
 *
 * Applied-preset tracking: the `theme.applied_preset` setting (slug). Cleared
 * the moment a tenant hand-edits a token (the link to a named preset is gone
 * once they fork).
 */
final class PresetLibrary
{
    /**
     * Every preset, basics + (when present) the builder's premium set.
     *
     * @return array<string, array{label:string, description:string, accent:string, tier:string, tokens:array<string,string>, tokens_dark?:array<string,string>}>
     */
    public static function all(): array
    {
        $presets = self::basics();

        // Soft-dep: the builder ships the premium catalogue. Merge it in when
        // present so paid installs see all presets; framework-only installs
        // stay on the basics.
        if (class_exists(\App\Theme\PresetLibrary::class)
            && method_exists(\App\Theme\PresetLibrary::class, 'premiumPresets')) {
            try {
                $presets += \App\Theme\PresetLibrary::premiumPresets();
            } catch (\Throwable $e) {
                error_log('[PresetLibrary] premium merge failed: ' . $e->getMessage());
            }
        }

        return $presets;
    }

    /**
     * The 6 always-available framework presets.
     *
     * @return array<string, array<string,mixed>>
     */
    public static function basics(): array
    {
        return [
            'modern' => [
                'label'       => 'Modern',
                'description' => 'Clean sans-serif on a soft grey canvas with an indigo accent. The framework default tuned for crispness.',
                'accent'      => '#4f46e5',
                'tier'        => 'free',
                'tokens'      => [
                    'theme.palette.primary.bg'         => '#4f46e5',
                    'theme.palette.primary.grad'       => '#3730a3',
                    'theme.palette.secondary.bg'       => '#0ea5e9',
                    'theme.palette.default.bg'         => '#f9fafb',
                    'theme.palette.default.surface'    => '#ffffff',
                    'theme.palette.default.text'       => '#111827',
                    'theme.palette.default.text-muted' => '#6b7280',
                    'theme.palette.default.accent-tint'=> '#eef2ff',
                    'theme.font.family.heading'        => "'Inter', system-ui, sans-serif",
                    'theme.font.family.body'           => "'Inter', system-ui, sans-serif",
                    'theme.radius.md'                  => '8px',
                ],
                'tokens_dark' => [
                    'theme.palette.primary.bg'         => '#818cf8',
                    'theme.palette.primary.grad'       => '#4f46e5',
                    'theme.palette.default.bg'         => '#0f172a',
                    'theme.palette.default.surface'    => '#1e293b',
                    'theme.palette.default.text'       => '#f8fafc',
                    'theme.palette.default.text-muted' => '#94a3b8',
                    'theme.palette.default.accent-tint'=> '#312e81',
                ],
            ],

            'classic' => [
                'label'       => 'Classic',
                'description' => 'Serif headlines, ivory backgrounds, navy + gold. For editorial / professional sites.',
                'accent'      => '#1e3a8a',
                'tier'        => 'free',
                'tokens'      => [
                    'theme.palette.primary.bg'         => '#1e3a8a',
                    'theme.palette.primary.grad'       => '#172554',
                    'theme.palette.secondary.bg'       => '#b45309',
                    'theme.palette.default.bg'         => '#fdfbf6',
                    'theme.palette.default.surface'    => '#ffffff',
                    'theme.palette.default.text'       => '#1c1917',
                    'theme.palette.default.text-muted' => '#57534e',
                    'theme.palette.default.accent-tint'=> '#fef3c7',
                    'theme.font.family.heading'        => "'Playfair Display', Georgia, 'Times New Roman', serif",
                    'theme.font.family.body'           => "'Source Sans 3', system-ui, sans-serif",
                    'theme.radius.md'                  => '4px',
                    'theme.radius.lg'                  => '6px',
                ],
                'tokens_dark' => [
                    'theme.palette.primary.bg'         => '#60a5fa',
                    'theme.palette.primary.grad'       => '#3b82f6',
                    'theme.palette.default.bg'         => '#1c1917',
                    'theme.palette.default.surface'    => '#292524',
                    'theme.palette.default.text'       => '#fafaf9',
                    'theme.palette.default.text-muted' => '#a8a29e',
                    'theme.palette.default.accent-tint'=> '#451a03',
                ],
            ],

            'bold' => [
                'label'       => 'Bold',
                'description' => 'Hot rose accent + yellow secondary. Statement pages — high contrast in either light or dark mode.',
                'accent'      => '#fb7185',
                'tier'        => 'free',
                'tokens'      => [
                    'theme.palette.primary.bg'           => '#e11d48',
                    'theme.palette.primary.grad'         => '#9f1239',
                    'theme.palette.secondary.bg'         => '#facc15',
                    'theme.palette.default.bg'           => '#ffffff',
                    'theme.palette.default.surface'      => '#ffffff',
                    'theme.palette.default.text'         => '#0a0a0a',
                    'theme.palette.default.text-muted'   => '#525252',
                    'theme.palette.default.border'       => '#171717',
                    'theme.palette.default.border-strong'=> '#0a0a0a',
                    'theme.palette.default.accent-tint'  => '#fef2f2',
                    'theme.font.family.heading'          => "'Space Grotesk', system-ui, sans-serif",
                    'theme.font.family.body'             => "'Inter', system-ui, sans-serif",
                    'theme.radius.md'                    => '2px',
                    'theme.radius.lg'                    => '4px',
                ],
                'tokens_dark' => [
                    'theme.palette.primary.bg'           => '#fb7185',
                    'theme.palette.primary.grad'         => '#e11d48',
                    'theme.palette.secondary.bg'         => '#fde047',
                    'theme.palette.default.bg'           => '#0a0a0a',
                    'theme.palette.default.surface'      => '#171717',
                    'theme.palette.default.text'         => '#fafafa',
                    'theme.palette.default.text-muted'   => '#a3a3a3',
                    'theme.palette.default.border'       => '#262626',
                    'theme.palette.default.border-strong'=> '#404040',
                    'theme.palette.default.accent-tint'  => '#3f1d2c',
                ],
            ],

            'minimal' => [
                'label'       => 'Minimal',
                'description' => 'Very low-chroma, generous whitespace, single subtle accent. Reads as quiet and considered.',
                'accent'      => '#57534e',
                'tier'        => 'free',
                'tokens'      => [
                    'theme.palette.primary.bg'           => '#57534e',
                    'theme.palette.primary.grad'         => '#292524',
                    'theme.palette.secondary.bg'         => '#a8a29e',
                    'theme.palette.default.bg'           => '#ffffff',
                    'theme.palette.default.surface'      => '#ffffff',
                    'theme.palette.default.text'         => '#1c1917',
                    'theme.palette.default.text-muted'   => '#78716c',
                    'theme.palette.default.border'       => '#e7e5e4',
                    'theme.palette.default.border-subtle'=> '#f5f5f4',
                    'theme.palette.default.accent-tint'  => '#f5f5f4',
                    'theme.font.family.heading'          => "'Inter', system-ui, sans-serif",
                    'theme.font.family.body'             => "'Inter', system-ui, sans-serif",
                    'theme.radius.md'                    => '0px',
                    'theme.radius.lg'                    => '2px',
                ],
                'tokens_dark' => [
                    'theme.palette.primary.bg'           => '#d6d3d1',
                    'theme.palette.primary.grad'         => '#a8a29e',
                    'theme.palette.default.bg'           => '#0c0a09',
                    'theme.palette.default.surface'      => '#1c1917',
                    'theme.palette.default.text'         => '#fafaf9',
                    'theme.palette.default.text-muted'   => '#a8a29e',
                    'theme.palette.default.border'       => '#292524',
                    'theme.palette.default.border-subtle'=> '#1c1917',
                    'theme.palette.default.accent-tint'  => '#292524',
                ],
            ],

            'dark_studio' => [
                'label'       => 'Dark Studio',
                'description' => 'Mono-coded developer tooling vibe with neon green accent. Light mode for daytime work, dark for night sessions.',
                'accent'      => '#10b981',
                'tier'        => 'free',
                'tokens'      => [
                    'theme.palette.primary.bg'           => '#059669',
                    'theme.palette.primary.grad'         => '#047857',
                    'theme.palette.secondary.bg'         => '#2563eb',
                    'theme.palette.default.bg'           => '#f1f5f9',
                    'theme.palette.default.surface'      => '#ffffff',
                    'theme.palette.default.text'         => '#0f172a',
                    'theme.palette.default.text-muted'   => '#475569',
                    'theme.palette.default.border'       => '#cbd5e1',
                    'theme.palette.default.border-strong' => '#94a3b8',
                    'theme.palette.default.accent-tint'  => '#d1fae5',
                    'theme.font.family.heading'          => "'JetBrains Mono', ui-monospace, monospace",
                    'theme.font.family.body'             => "'Inter', system-ui, sans-serif",
                    'theme.radius.md'                    => '6px',
                ],
                'tokens_dark' => [
                    'theme.palette.primary.bg'           => '#34d399',
                    'theme.palette.primary.grad'         => '#10b981',
                    'theme.palette.secondary.bg'         => '#60a5fa',
                    'theme.palette.default.bg'           => '#0f172a',
                    'theme.palette.default.surface'      => '#1e293b',
                    'theme.palette.default.text'         => '#e2e8f0',
                    'theme.palette.default.text-muted'   => '#94a3b8',
                    'theme.palette.default.border'       => '#334155',
                    'theme.palette.default.border-strong' => '#475569',
                    'theme.palette.default.accent-tint'  => '#064e3b',
                ],
            ],

            'editorial' => [
                'label'       => 'Editorial',
                'description' => 'Serif-everything, warm cream paper, single muted accent. Long-form reading.',
                'accent'      => '#7c2d12',
                'tier'        => 'free',
                'tokens'      => [
                    'theme.palette.primary.bg'           => '#7c2d12',
                    'theme.palette.primary.grad'         => '#431407',
                    'theme.palette.secondary.bg'         => '#a16207',
                    'theme.palette.default.bg'           => '#faf8f3',
                    'theme.palette.default.surface'      => '#fefdfb',
                    'theme.palette.default.text'         => '#1c1917',
                    'theme.palette.default.text-muted'   => '#57534e',
                    'theme.palette.default.border'       => '#e7e5e4',
                    'theme.palette.default.accent-tint'  => '#fef3c7',
                    'theme.font.family.heading'          => "'Crimson Pro', Georgia, serif",
                    'theme.font.family.body'             => "'Crimson Pro', Georgia, serif",
                    'theme.font.size.body'               => '17px',
                    'theme.radius.md'                    => '4px',
                ],
                'tokens_dark' => [
                    'theme.palette.primary.bg'           => '#fb923c',
                    'theme.palette.primary.grad'         => '#f97316',
                    'theme.palette.default.bg'           => '#1c1917',
                    'theme.palette.default.surface'      => '#292524',
                    'theme.palette.default.text'         => '#faf5e9',
                    'theme.palette.default.text-muted'   => '#d6d3d1',
                    'theme.palette.default.border'       => '#44403c',
                    'theme.palette.default.accent-tint'  => '#451a03',
                ],
            ],
        ];
    }

    /**
     * The fully-EXPANDED token set for a preset, ready to persist as overrides.
     * Returns ['tokens' => fullLight, 'tokens_dark' => fullDark] — every colour
     * alias derived, fonts/radii passed through.
     *
     * @return array{tokens:array<string,string>, tokens_dark:array<string,string>}|null
     */
    public static function expanded(string $slug): ?array
    {
        $preset = self::get($slug);
        if ($preset === null) return null;
        return PresetExpander::expand($preset);
    }

    /**
     * Derive a 6-swatch palette from a preset for the UI swatch row.
     *
     * @param array{accent?:string, tokens?:array<string,string>} $preset
     * @return array<int, string>  six hex strings
     */
    public static function palette(array $preset): array
    {
        $t = $preset['tokens'] ?? [];
        $a = (string) ($preset['accent'] ?? '#888888');
        return [
            (string) ($t['theme.palette.primary.bg']        ?? $a),
            (string) ($t['theme.palette.primary.grad']      ?? $a),
            (string) ($t['theme.palette.secondary.bg']      ?? $a),
            (string) ($t['theme.palette.default.bg']        ?? '#ffffff'),
            (string) ($t['theme.palette.default.text']      ?? '#111827'),
            (string) ($t['theme.palette.default.accent-tint'] ?? '#eef2ff'),
        ];
    }

    /** Return a single preset by slug, or null if unknown. */
    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** @return string[] preset slugs in display order */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    /** @return string[] preset slugs filtered by tier ('free' or 'premium'). */
    public static function slugsByTier(string $tier): array
    {
        $out = [];
        foreach (self::all() as $slug => $p) {
            if (($p['tier'] ?? 'free') === $tier) $out[] = $slug;
        }
        return $out;
    }
}
