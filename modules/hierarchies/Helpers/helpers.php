<?php
// modules/hierarchies/Helpers/helpers.php
/**
 * View glue for the hierarchies module.
 *
 *   $tree = hierarchy_tree('main-nav');
 *   foreach ($tree as $node) { ... }
 *
 * Nodes carry label, slug, url, icon, color, metadata_json, and a
 * `children` array (recursive). See modules/hierarchies/Views/public/
 * sample_nav.php for a rendering example.
 *
 * Registered once per request via ModuleProvider::register (see
 * module.php). function_exists guards make re-inclusion harmless.
 */

if (!function_exists('hierarchy_tree')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function hierarchy_tree(string $slug): array
    {
        return (new \Modules\Hierarchies\Services\HierarchyService())->treeBySlug($slug);
    }
}

if (!function_exists('render_hierarchy_nav')) {
    /**
     * Render a hierarchy as a nested <ul>. Cheap default renderer —
     * apps with a specific markup shape should read the tree directly
     * and render their own.
     */
    function render_hierarchy_nav(string $slug, int $maxDepth = 5): string
    {
        $tree = hierarchy_tree($slug);
        if (!$tree) return '';

        $walk = function(array $nodes, int $depth) use (&$walk, $maxDepth): string {
            if ($depth > $maxDepth) return '';
            $html = '<ul>';
            foreach ($nodes as $n) {
                $label = htmlspecialchars((string) $n['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url   = isset($n['url']) && $n['url'] !== null
                    ? htmlspecialchars((string) $n['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    : '#';
                $html .= '<li><a href="' . $url . '">' . $label . '</a>';
                if (!empty($n['children'])) {
                    $html .= $walk($n['children'], $depth + 1);
                }
                $html .= '</li>';
            }
            return $html . '</ul>';
        };

        return $walk($tree, 1);
    }
}
