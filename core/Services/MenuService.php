<?php
// core/Services/MenuService.php
namespace Core\Services;

use Core\Database\Database;
use Core\Auth\Auth;

/**
 * MenuService — loads menus with full conditional visibility filtering.
 *
 * Visibility rules:
 *   always       — always visible
 *   logged_in    — only for authenticated users
 *   logged_out   — only for guests
 *   role         — user must have condition_value role slug
 *   permission   — user must have condition_value permission slug
 *   group        — user must be in condition_value group slug
 */
class MenuService
{
    private Database $db;
    private Auth     $auth;
    /** Per-request memo of resolved menu trees. Keyed by location +
     *  currentPath + a viewer fingerprint so role/group-gated items resolve
     *  consistently for each viewer. The header + footer typically each
     *  render a menu, so without this the page pays for 4 queries; with
     *  it the second menu() call within the same request is free. */
    private array $treeMemo = [];

    public function __construct()
    {
        $this->db   = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    /**
     * Get a fully nested, visibility-filtered menu tree by location.
     */
    public function getMenu(string $location, string $currentPath = '/'): array
    {
        $memoKey = $this->memoKey($location, $currentPath);
        if (isset($this->treeMemo[$memoKey])) {
            return $this->treeMemo[$memoKey];
        }

        $menu = $this->db->fetchOne(
            "SELECT * FROM menus WHERE location = ? AND is_active = 1",
            [$location]
        );
        if (!$menu) return $this->treeMemo[$memoKey] = [];

        $allItems = $this->db->fetchAll(
            "SELECT * FROM menu_items WHERE menu_id = ? AND is_active = 1 ORDER BY sort_order ASC",
            [$menu['id']]
        );

        // Filter by visibility
        $visible = array_filter($allItems, fn($item) => $this->isVisible($item, $currentPath));

        return $this->treeMemo[$memoKey] = $this->buildTree($visible);
    }

    /**
     * Build the memo cache key. Includes a viewer fingerprint so that two
     * users hitting the same path don't share one another's role-filtered
     * menu (e.g. an admin-only "Manage" item leaking to a regular user).
     * The fingerprint is auth-state-aware: guest, user id, and SA-mode
     * status flip the cache key independently.
     */
    private function memoKey(string $location, string $currentPath): string
    {
        $u = $this->auth->user();
        $uid = is_array($u) && isset($u['id']) ? (int) $u['id'] : 0;
        $sa  = $this->auth->isSuperadminModeOn() ? 1 : 0;
        return "$location|$currentPath|$uid|$sa";
    }

    /**
     * Get all menus for admin management.
     */
    public function getAllMenus(): array
    {
        return $this->db->fetchAll("SELECT * FROM menus ORDER BY location, name");
    }

    public function getMenuItems(int $menuId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order ASC",
            [$menuId]
        );
    }

    private function isVisible(array $item, string $currentPath): bool
    {
        // Page restriction check
        if (!empty($item['show_on_pages'])) {
            $pages = json_decode($item['show_on_pages'], true) ?? [];
            if (!empty($pages) && !in_array(ltrim($currentPath, '/'), $pages, true)) {
                return false;
            }
        }

        $v = $item['visibility'] ?? 'always';

        switch ($v) {
            case 'always':
                return true;
            case 'logged_in':
                return $this->auth->check();
            case 'logged_out':
                return $this->auth->guest();
            case 'role':
                return $this->auth->check() && $this->auth->hasRole($item['condition_value'] ?? '');
            case 'permission':
                return $this->auth->check() && $this->auth->hasPermission($item['condition_value'] ?? '');
            case 'group':
                return $this->auth->check() && $this->auth->inGroup($item['condition_value'] ?? '');
            default:
                return true;
        }
    }

    /**
     * Build a nested tree from a flat array of items.
     * O(n): bucket items by parent_id in a single pass, then assemble.
     */
    private function buildTree(array $items, ?int $parentId = null): array
    {
        // Bucket items by parent id (0 / null both map to "root").
        $byParent = [];
        foreach ($items as $item) {
            $pid = empty($item['parent_id']) ? 0 : (int) $item['parent_id'];
            $byParent[$pid][] = $item;
        }
        return $this->assembleTree($byParent, $parentId ?? 0);
    }

    private function assembleTree(array &$byParent, int $parentId, array $visited = [], int $depth = 0): array
    {
        // replaceItems prevents cycles via the forward-only idMap, but a manual
        // SQL UPDATE could still create one - guard with a visited set + depth
        // bail so a malformed parent_id chain can't infinite-loop the renderer.
        if (empty($byParent[$parentId])) return [];
        if (isset($visited[$parentId]) || $depth > 64) {
            error_log('[menus] assembleTree bailed at parent_id=' . $parentId
                . ' (cycle or excessive depth=' . $depth . ')');
            return [];
        }
        $visited[$parentId] = true;
        $tree = [];
        foreach ($byParent[$parentId] as $item) {
            $item['children'] = $this->assembleTree($byParent, (int) $item['id'], $visited, $depth + 1);
            $tree[] = $item;
        }
        return $tree;
    }

    // ── Admin CRUD ────────────────────────────────────────────────────────────

    public function createMenu(array $data): int
    {
        return $this->db->insert('menus', $data);
    }

    public function updateMenu(int $id, array $data): int
    {
        return $this->db->update('menus', $data, 'id = ?', [$id]);
    }

    public function deleteMenu(int $id): int
    {
        return $this->db->delete('menus', 'id = ?', [$id]);
    }

    public function createItem(array $data): int
    {
        return $this->db->insert('menu_items', $data);
    }

    public function updateItem(int $id, array $data): int
    {
        return $this->db->update('menu_items', $data, 'id = ?', [$id]);
    }

    public function deleteItem(int $id): int
    {
        return $this->db->delete('menu_items', 'id = ?', [$id]);
    }

    public function reorderItems(array $order): void
    {
        foreach ($order as $sortOrder => $itemId) {
            $this->db->update('menu_items', ['sort_order' => $sortOrder], 'id = ?', [(int) $itemId]);
        }
    }


    /**
     * Atomically replace the entire item list for a menu. Used by the
     * composer-style menu builder which posts the whole tree as one
     * payload rather than sending a per-item add/edit/delete sequence.
     *
     * Items arrive as a flat ordered list; each carries its own
     * `parent_id` (NULL for top level, or an "old_id" referring to a
     * prior row, or a fresh "tmp:1" client-side id - see remap below).
     * Algorithm:
     *   1. DELETE all existing rows for this menu_id (FK cascades children)
     *   2. INSERT each row in payload order, mapping client-side ids to
     *      newly-allocated database ids so children's parent_id values
     *      resolve correctly.
     *   3. sort_order is rebuilt as 10/20/30/... (keeps room for manual
     *      SQL inserts later). The payload order IS the new sort order.
     *
     * Inputs are validated client-side AND server-side - any item missing
     * a label or with an out-of-band kind value is silently dropped so
     * an attacker-crafted POST cannot inject bad rows.
     *
     * @param array<int,array<string,mixed>> $items each: [id?, parent_id?, label, url?, kind, icon?, target?, visibility?, condition_value?, show_on_pages?]
     */
    public function replaceItems(int $menuId, array $items): void
    {
        $allowedKinds = ['link', 'holder'];
        $allowedVisibility = ['always', 'logged_in', 'logged_out', 'role', 'permission', 'group'];
        $allowedTargets = ['_self', '_blank'];
        // URL scheme allowlist. Renderers escape angle-brackets and quotes
        // via htmlspecialchars but DO NOT strip JS/data URIs - so a URL like
        // "javascript:alert(1)" would emit as a live anchor and execute on
        // click. Whitelist what we actually use:
        //   - http://  https://     external links
        //   - mailto:  tel:         contact links
        //   - /...     #...         site-internal absolute / fragment
        // Anything else gets the URL nulled at save (item stays, renders as
        // a non-clickable span). Admin can fix and resave; meanwhile no
        // viewer is at risk.
        $isSafeMenuUrl = function (string $u): bool {
            if ($u === '') return true;
            if ($u[0] === '#') return true;
            // Absolute path "/foo" is fine, but reject "//evil.com" - protocol-
            // relative URLs would let an admin smuggle a third-party host past
            // the scheme check.
            if ($u[0] === '/' && (!isset($u[1]) || $u[1] !== '/')) return true;
            return (bool) preg_match('#^(?:https?://|mailto:|tel:)#i', $u);
        };

        $this->db->transaction(function () use ($menuId, $items, $allowedKinds, $allowedVisibility, $allowedTargets, $isSafeMenuUrl) {
            // Wipe all existing rows. parent_id FK has ON DELETE CASCADE,
            // so children disappear with their parents - we do top-level
            // deletes from a single DELETE for simplicity.
            $this->db->delete('menu_items', 'menu_id = ?', [$menuId]);

            // Map client-side ids ("tmp:5", "old:42", or just an integer) to
            // newly-inserted DB ids so the second pass can resolve parent
            // references in the payload.
            $idMap = [];
            $sort = 0;

            // First pass: insert everything top-level (parent_id null) so
            // children-first references can resolve. The payload order
            // matters - admins arrange the tree and the builder serialises
            // it depth-first, parents before children.
            foreach ($items as $item) {
                $clientId = (string) ($item['client_id'] ?? '');
                $kind     = (string) ($item['kind'] ?? 'link');
                if (!in_array($kind, $allowedKinds, true)) $kind = 'link';

                $label = trim((string) ($item['label'] ?? ''));
                if ($label === '') continue;

                $url = isset($item['url']) ? trim((string) $item['url']) : '';
                if ($kind === 'holder') $url = ''; // holders never carry a URL
                if ($url !== '' && !$isSafeMenuUrl($url)) {
                    // Drop the URL but keep the item so the admin can see
                    // their save partially succeeded. Log so the cause is
                    // discoverable without a CSI-level trace.
                    error_log('[menus] dropped unsafe URL on save: ' . substr($url, 0, 120));
                    $url = '';
                }

                $vis = (string) ($item['visibility'] ?? 'always');
                if (!in_array($vis, $allowedVisibility, true)) $vis = 'always';

                $target = (string) ($item['target'] ?? '_self');
                if (!in_array($target, $allowedTargets, true)) $target = '_self';

                // Resolve parent: payload may carry parent_client_id (a key
                // into $idMap) or NULL for top-level.
                $parentId = null;
                $parentClientId = $item['parent_client_id'] ?? null;
                if ($parentClientId !== null && $parentClientId !== '' && isset($idMap[(string) $parentClientId])) {
                    $parentId = $idMap[(string) $parentClientId];
                }

                $sort += 10;
                $newId = $this->db->insert('menu_items', [
                    'menu_id'         => $menuId,
                    'parent_id'       => $parentId,
                    'label'           => substr($label, 0, 255),
                    'url'             => $url === '' ? null : substr($url, 0, 1000),
                    'kind'            => $kind,
                    'icon'            => isset($item['icon']) ? substr((string) $item['icon'], 0, 100) : null,
                    'target'          => $target,
                    'sort_order'      => $sort,
                    'visibility'      => $vis,
                    'condition_value' => isset($item['condition_value']) && $item['condition_value'] !== ''
                        ? substr((string) $item['condition_value'], 0, 255)
                        : null,
                    'show_on_pages'   => isset($item['show_on_pages']) && is_array($item['show_on_pages'])
                        ? json_encode($item['show_on_pages'])
                        : null,
                    'is_active'       => 1,
                ]);

                if ($clientId !== '') $idMap[$clientId] = $newId;
            }
        });
    }
}
