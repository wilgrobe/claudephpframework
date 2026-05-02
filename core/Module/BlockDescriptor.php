<?php
// core/Module/BlockDescriptor.php
namespace Core\Module;

/**
 * Describes a single content block contributed by a module.
 *
 * Modules return arrays of BlockDescriptors from `ModuleProvider::blocks()`;
 * the BlockRegistry indexes them by `key` so the page composer can render
 * them on demand. The render closure is responsible for producing the final
 * HTML; if the block shouldn't appear (e.g. permission / scope check fails)
 * the closure should return an empty string and the page renderer will
 * skip the cell.
 *
 * Keys must be globally unique. Convention: `<module_name>.<block_slug>`,
 * e.g. `groups.my_groups_tile`. The dot makes it easy to grep for every
 * block a given module contributes.
 *
 * The render closure receives:
 *   - $context  — page context (viewer, page row, scope hints)
 *   - $settings — per-placement settings, merged on top of $defaultSettings
 */
final class BlockDescriptor
{
    /** Acceptable values for $defaultSize. */
    public const SIZES = ['small', 'medium', 'large'];

    /**
     * Acceptable values for $audience. Hint metadata only — the block's
     * own render closure is the actual gate.
     *
     *   any   — renders for everyone (guest + auth + admin)
     *   auth  — renders for any signed-in user; render returns '' for guests
     *   admin — renders for admins/superadmins only; '' for everyone else
     *
     * The placement editor surfaces this as a badge next to each block in
     * the picker so admins know what to pair with `visible_to` on the
     * placement. Without it, dropping `social.my_feed` (auth-only) into
     * a placement with `visible_to=any` looks broken to a guest who sees
     * an empty cell.
     */
    public const AUDIENCES = ['any', 'auth', 'admin'];

    /**
     * Acceptable values for $settingsSchema field type. Drives the
     * structured settings modal in the page composer:
     *
     *   text     — single-line input
     *   textarea — multi-line input (auto-grows in the modal)
     *   number   — numeric input (HTML5 type=number)
     *   checkbox — boolean
     *   select   — dropdown; field must declare an `options` array of
     *              [value => label] (or a flat list, which is treated
     *              as both value and label)
     *   json     — escape hatch for complex array fields — renders a
     *              monospace textarea pre-populated with JSON. Prefer
     *              `repeater` for arrays of objects.
     *   repeater — array of homogeneous item objects. Field must
     *              declare an `item_schema` array (same shape as
     *              settingsSchema; nested repeater + string_list are
     *              both allowed). Optional `item_label` controls the
     *              "+ Add X" button copy and the per-row title.
     *   string_list — flat array of strings. Each entry is a single
     *                 text input with reorder/remove controls. Use for
     *                 things like a plan's bullet-point features.
     *
     * Blocks that don't declare a settingsSchema fall back to a single
     * raw-JSON textarea covering all settings — same shape the
     * placement editor used pre-modal.
     */
    public const SCHEMA_TYPES = ['text', 'textarea', 'number', 'checkbox', 'select', 'json', 'repeater', 'string_list'];

    public function __construct(
        public readonly string  $key,
        public readonly string  $label,
        public readonly string  $description,
        public readonly string  $category,
        /** @var \Closure(array, array): string */
        public readonly \Closure $render,
        public readonly string  $defaultSize     = 'medium',
        public readonly array   $defaultSettings = [],
        public readonly string  $audience        = 'any',
        /**
         * Optional settings schema for the structured modal in the
         * page composer. Each entry shape:
         *   ['key' => 'limit', 'label' => 'Limit',
         *    'type' => 'number', 'default' => 5,
         *    'help' => 'Max items shown', 'placeholder' => '5',
         *    'options' => ['a' => 'A', 'b' => 'B']]  // for select
         *
         * When empty, the modal renders a single JSON textarea
         * covering every setting — same UX as the original placement
         * editor.
         *
         * @var array<int, array<string, mixed>>
         */
        public readonly array   $settingsSchema  = [],
    ) {
        if (!preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $this->key)) {
            throw new \InvalidArgumentException(
                "Block key must look like `module.slug` (lowercase a-z0-9_); got: $this->key"
            );
        }
        if (!in_array($this->defaultSize, self::SIZES, true)) {
            throw new \InvalidArgumentException(
                "Block defaultSize must be one of: " . implode(', ', self::SIZES) . "; got: $this->defaultSize"
            );
        }
        if (!in_array($this->audience, self::AUDIENCES, true)) {
            throw new \InvalidArgumentException(
                "Block audience must be one of: " . implode(', ', self::AUDIENCES) . "; got: $this->audience"
            );
        }
    }
}
