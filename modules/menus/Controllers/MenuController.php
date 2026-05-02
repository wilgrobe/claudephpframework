<?php
// modules/menus/Controllers/MenuController.php
namespace Modules\Menus\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Validation\Validator;
use Core\Services\MenuService;

/**
 * Admin CRUD for menus + menu items. Ported from
 * App\Controllers\Admin\MenuController. Only changes: namespace moved under
 * Modules\Menus\Controllers and Response::view() calls use the 'menus::' prefix.
 */
class MenuController
{
    private MenuService $menus;
    private Auth        $auth;

    public function __construct()
    {
        $this->menus = new MenuService();
        $this->auth  = Auth::getInstance();
    }

    public function index(Request $request): Response
    {
        if ($this->auth->cannot('menus.manage')) return $this->denied();
        return Response::view('menus::admin.index', [
            'menus' => $this->menus->getAllMenus(),
            'user'  => $this->auth->user(),
        ]);
    }

    public function items(Request $request): Response
    {
        if ($this->auth->cannot('menus.manage')) return $this->denied();
        $menuId = (int) $request->param(0);
        $db = \Core\Database\Database::getInstance();
        $menu  = $db->fetchOne("SELECT * FROM menus WHERE id = ?", [$menuId]);
        if (!$menu) return new Response('Menu not found', 404);
        $items = $this->menus->getMenuItems($menuId);

        // Walk every active LinkSource (built-in + module-contributed) so
        // the palette can render a grouped list of every internal URL
        // an admin might want to add. Each entry is [name => [label, items]].
        $linkSources = (new \Core\Services\LinkSourceRegistry())->all();

        return Response::view('menus::admin.items', [
            'menu'        => $menu,
            'items'       => $items,
            'linkSources' => $linkSources,
            'user'        => $this->auth->user(),
        ]);
    }

    public function createMenu(Request $request): Response
    {
        if ($this->auth->cannot('menus.manage')) return $this->denied();
        $v = new Validator($request->post());
        $v->validate(['name' => 'required|min:2', 'location' => 'required|min:2']);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect('/admin/menus');
        }
        $this->menus->createMenu([
            'name'      => $v->get('name'),
            'location'  => $v->get('location'),
            'description'=> $v->get('description'),
            'is_active' => 1,
        ]);
        $this->auth->auditLog('menu.create');
        return Response::redirect('/admin/menus')->withFlash('success', 'Menu created.');
    }

    public function storeItem(Request $request): Response
    {
        if ($this->auth->cannot('menus.manage')) return $this->denied();
        $menuId = (int) $request->param(0);
        $v = new Validator($request->post());
        $v->validate(['label' => 'required|min:1|max:255']);
        if ($v->fails()) {
            Session::flash('errors', $v->errors());
            return Response::redirect("/admin/menus/$menuId/items");
        }
        $showOnPages = $request->post('show_on_pages', '');
        $pagesArr    = array_filter(array_map('trim', explode(',', $showOnPages)));

        $this->menus->createItem([
            'menu_id'         => $menuId,
            'parent_id'       => ($request->post('parent_id') ?: null),
            'label'           => $v->get('label'),
            'url'             => ($request->post('url') ?: null),
            'icon'            => $v->get('icon'),
            'target'          => $request->post('target', '_self'),
            'sort_order'      => (int) $request->post('sort_order', 0),
            'visibility'      => $request->post('visibility', 'always'),
            'condition_value' => ($request->post('condition_value') ?: null),
            'show_on_pages'   => $pagesArr ? json_encode($pagesArr) : null,
            'is_active'       => 1,
        ]);
        $this->auth->auditLog('menu.item_create', 'menus', $menuId);
        return Response::redirect("/admin/menus/$menuId/items")->withFlash('success', 'Item added.');
    }

    public function deleteItem(Request $request): Response
    {
        if ($this->auth->cannot('menus.manage')) return $this->denied();
        $itemId = (int) $request->param(0);

        // The form on items.php submits a regular POST (not AJAX), so we
        // must return a redirect, not JSON — otherwise the browser just
        // renders "{\"success\":true}" as the whole page. The reorder
        // endpoint stays JSON because it's driven by drag events and has
        // no page navigation semantic. Fetch the parent menu_id before
        // deleting so the redirect lands back on the right items page.
        $row = \Core\Database\Database::getInstance()->fetchOne(
            "SELECT menu_id FROM menu_items WHERE id = ?",
            [$itemId]
        );
        $menuId = (int) ($row['menu_id'] ?? 0);

        $this->menus->deleteItem($itemId);
        $this->auth->auditLog('menu.item_delete', 'menus', $menuId, null, ['item_id' => $itemId]);

        $back = $menuId ? "/admin/menus/$menuId/items" : '/admin/menus';
        return Response::redirect($back)->withFlash('success', 'Item deleted.');
    }

    public function reorder(Request $request): Response
    {
        if ($this->auth->cannot('menus.manage')) return $this->denied();
        $order = $request->post('order', []);
        $this->menus->reorderItems((array) $order);
        return Response::json(['success' => true]);
    }

    private function denied(): Response
    {
        return Response::redirect('/admin')->withFlash('error', 'Access denied.');
    }

    /**
     * Save the entire menu structure as one POST. The composer admin UI
     * sends a JSON-encoded `items` payload describing every node + its
     * parent + its order; the service does the atomic delete/reinsert.
     *
     * Errors during JSON parsing or validation are flashed back; valid
     * payloads land in the DB inside one transaction so the menu is
     * never half-saved.
     */
    public function builderSave(Request $request): Response
    {
        if ($this->auth->cannot('menus.manage')) return $this->denied();

        $menuId = (int) $request->param(0);
        $rawJson = (string) $request->post('items', '');
        $items   = json_decode($rawJson, true);
        if (!is_array($items)) {
            Session::flash('errors', ['_builder' => ['Builder payload was not valid JSON.']]);
            return Response::redirect("/admin/menus/$menuId/items");
        }

        $this->menus->replaceItems($menuId, $items);
        $this->auth->auditLog('menu.builderSave', 'menus', $menuId);
        return Response::redirect("/admin/menus/$menuId/items")
            ->withFlash('success', 'Menu saved.');
    }

}
