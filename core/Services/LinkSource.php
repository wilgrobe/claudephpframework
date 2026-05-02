<?php
// core/Services/LinkSource.php
namespace Core\Services;

/**
 * Contract for "things you can link to from a menu" providers.
 *
 * Each LinkSource is a small read-only adapter that knows how to enumerate
 * a single category of internal URLs (pages from the page builder, forms,
 * built-in framework routes, products in a store module, etc). The
 * LinkSourceRegistry collects every active source and presents them in the
 * menu builder's palette so admins can pick from a single grouped list
 * instead of typing URLs by hand.
 *
 * Modules opt in by overriding ModuleProvider::linkSources() to return
 * an array of class names; the registry instantiates them lazily on
 * first use. A source returning [] is fine - it just means "I don't
 * have any items to contribute right now" (e.g. a forms module on a
 * site with no enabled forms).
 *
 * Why a registry of small classes vs. a single mega-table:
 *   - Each module owns its own enumeration logic (queries, visibility
 *     rules) without the menu module needing to know about every other
 *     module's schema.
 *   - Sources are cheap to add - one file, one class - so module
 *     authors can drop in their own without touching framework code.
 *   - The menu builder can show / hide groups based on which modules
 *     are active, automatically.
 */
interface LinkSource
{
    /**
     * Stable machine name. Used as the group key in the registry's
     * grouped output and as a deduplication anchor when multiple sources
     * could surface the same URL.
     */
    public function name(): string;

    /**
     * Human-readable label for the palette section header, e.g. "Pages",
     * "Forms", "Built-in".
     */
    public function label(): string;

    /**
     * Return the items this source contributes RIGHT NOW. Each item:
     *   ['label' => string, 'url' => string, 'icon' => ?string]
     *
     * Order matters - the menu builder presents items in the returned
     * order within each group. Empty array is fine.
     *
     * @return array<int, array{label:string,url:string,icon?:?string}>
     */
    public function items(): array;
}
