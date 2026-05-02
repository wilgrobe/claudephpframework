<?php
// modules/taxonomy/Helpers/helpers.php
/**
 * View / glue helpers for the taxonomy module.
 *
 *   $tree  = taxonomy_tree('product-categories');
 *   $terms = terms_for('content', $item['id']);
 *   attach_term('content', $item['id'], 'article-tags', 'featured');
 *
 * Registered once per request by the module provider (see module.php ->
 * register). function_exists guards make re-inclusion harmless.
 */

if (!function_exists('taxonomy_tree')) {
    /**
     * Nested tree for a vocabulary. Each node has 'children' (recursive).
     * Returns [] if the set doesn't exist.
     *
     * @return array<int, array<string, mixed>>
     */
    function taxonomy_tree(string $setSlug): array
    {
        return (new \Modules\Taxonomy\Services\TaxonomyService())->tree($setSlug);
    }
}

if (!function_exists('terms_for')) {
    /**
     * All terms attached to an entity, ordered by set slug + term name.
     * Each row includes 'set_slug' so views can group by vocabulary.
     *
     * @return array<int, array<string, mixed>>
     */
    function terms_for(string $entityType, int $entityId): array
    {
        return (new \Modules\Taxonomy\Services\TaxonomyService())->termsFor($entityType, $entityId);
    }
}

if (!function_exists('attach_term')) {
    /**
     * Attach a term identified by (setSlug, termSlug) to an entity. Returns
     * false if the term doesn't exist. Idempotent — already-attached rows
     * are a no-op thanks to INSERT IGNORE.
     */
    function attach_term(string $entityType, int $entityId, string $setSlug, string $termSlug): bool
    {
        $svc  = new \Modules\Taxonomy\Services\TaxonomyService();
        $term = $svc->findTermBySlug($setSlug, $termSlug);
        if (!$term) return false;
        return $svc->attach($entityType, $entityId, (int) $term['id']);
    }
}

if (!function_exists('detach_term')) {
    /** Detach a term identified by (setSlug, termSlug) from an entity. */
    function detach_term(string $entityType, int $entityId, string $setSlug, string $termSlug): void
    {
        $svc  = new \Modules\Taxonomy\Services\TaxonomyService();
        $term = $svc->findTermBySlug($setSlug, $termSlug);
        if (!$term) return;
        $svc->detach($entityType, $entityId, (int) $term['id']);
    }
}
