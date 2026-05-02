<?php
// modules/api-keys/Controllers/ApiKeyController.php
namespace Modules\ApiKeys\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;
use Modules\ApiKeys\Services\ApiKeyService;

/**
 *   GET  /account/api-keys                  — user's key list
 *   POST /account/api-keys                  — mint (token shown once via flash)
 *   POST /account/api-keys/{id}/revoke      — revoke
 */
class ApiKeyController
{
    private Auth          $auth;
    private ApiKeyService $svc;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->svc  = new ApiKeyService();
    }

    public function index(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        // Pull the newly-minted token (if any) out of the session flash —
        // this is the ONLY time the plaintext will ever be visible.
        Session::start();
        $justMinted = $_SESSION['__api_key_just_minted'] ?? null;
        unset($_SESSION['__api_key_just_minted']);

        return Response::view('api_keys::public.index', [
            'keys'       => $this->svc->listForUser((int) $this->auth->id()),
            'justMinted' => $justMinted,
        ]);
    }

    public function mint(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');

        $name   = (string) $request->post('name');
        $scopes = array_filter(array_map('trim', explode(',', (string) $request->post('scopes'))));
        $expIn  = (int) $request->post('expires_in_days', 0);
        $expAt  = $expIn > 0 ? date('Y-m-d H:i:s', time() + $expIn * 86400) : null;

        try {
            $res = $this->svc->mint((int) $this->auth->id(), $name, $scopes, $expAt);
        } catch (\InvalidArgumentException $e) {
            return Response::redirect('/account/api-keys')->withFlash('error', $e->getMessage());
        }

        // Stash the plaintext token in the session so the index action
        // can render it once. No persistence beyond this request cycle.
        Session::start();
        $_SESSION['__api_key_just_minted'] = [
            'id'        => $res['id'],
            'token'     => $res['token'],
            'name'      => $name,
            'scopes'    => $scopes,
            'last_four' => $res['last_four'],
        ];
        return Response::redirect('/account/api-keys');
    }

    public function revoke(Request $request): Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        $id = (int) $request->param(0);
        $this->svc->revoke($id, (int) $this->auth->id());
        return Response::redirect('/account/api-keys')->withFlash('success', 'Key revoked.');
    }
}
