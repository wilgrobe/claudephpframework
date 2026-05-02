<?php
// modules/cookieconsent/Controllers/CookieConsentController.php
namespace Modules\Cookieconsent\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Services\SettingsService;
use Modules\Cookieconsent\Services\CookieConsentService;

/**
 * Public + admin endpoints for the cookie-consent banner.
 *
 * Public  POST /cookie-consent          → record an accept/reject/custom action
 * Public  POST /cookie-consent/withdraw → withdraw all non-essential consent
 * Admin   GET  /admin/cookie-consent    → settings + recent records
 * Admin   POST /admin/cookie-consent    → save settings
 */
class CookieConsentController
{
    private Auth                 $auth;
    private SettingsService      $settings;
    private CookieConsentService $consent;

    /**
     * Settings keys owned by the admin page. Same pattern as the rest of
     * /admin/settings/* — so the generic settings grid can hide them.
     */
    public const SETTING_KEYS = [
        'cookieconsent_enabled'           => 'boolean',
        'cookieconsent_policy_version'    => 'string',
        'cookieconsent_policy_url'        => 'string',
        'cookieconsent_title'             => 'string',
        'cookieconsent_body'              => 'string',
        'cookieconsent_label_necessary'   => 'string',
        'cookieconsent_label_preferences' => 'string',
        'cookieconsent_label_analytics'   => 'string',
        'cookieconsent_label_marketing'   => 'string',
        'cookieconsent_desc_necessary'    => 'string',
        'cookieconsent_desc_preferences'  => 'string',
        'cookieconsent_desc_analytics'    => 'string',
        'cookieconsent_desc_marketing'    => 'string',
    ];

    public function __construct()
    {
        $this->auth     = Auth::getInstance();
        $this->settings = new SettingsService();
        $this->consent  = new CookieConsentService(null, $this->settings);
    }

    // ── Public endpoints ───────────────────────────────────────────────

    /**
     * POST /cookie-consent
     *
     * Body: action=accept_all | reject_all | custom
     *       For action=custom, the per-category booleans are read from
     *       cookieconsent[preferences|analytics|marketing] (1/0).
     */
    public function save(Request $request): Response
    {
        $action = (string) $request->post('action', 'custom');
        $cats   = (array)  $request->post('cookieconsent', []);

        $userId = $this->auth->check() ? (int) $this->auth->id() : null;
        $this->consent->recordConsent($action, $cats, $userId);

        if ($userId !== null) {
            // Audit on a signed-in user — the anon_id alone proves nothing
            // for an authenticated user later if their account is deleted.
            $this->auth->auditLog('cookieconsent.save', null, null, null, [
                'action' => $action,
            ]);
        }

        // Polite for the browser fetch; UI also handles the redirect.
        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'action' => $action]);
        }
        return Response::redirect($request->server('HTTP_REFERER') ?: '/');
    }

    /**
     * POST /cookie-consent/withdraw
     *
     * Surfaced as a link in the footer / privacy page so users can revoke
     * their previous consent at any time. Equivalent to reject_all but
     * recorded with action='withdraw' for clarity in the audit trail.
     */
    public function withdraw(Request $request): Response
    {
        $userId = $this->auth->check() ? (int) $this->auth->id() : null;
        $this->consent->withdraw($userId);

        if ($userId !== null) {
            $this->auth->auditLog('cookieconsent.withdraw');
        }

        return Response::redirect($request->server('HTTP_REFERER') ?: '/')
            ->withFlash('success', 'Your cookie preferences have been reset. The banner will reappear on your next page view.');
    }

    // ── Admin endpoints ────────────────────────────────────────────────

    public function adminIndex(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $values = [];
        foreach (array_keys(self::SETTING_KEYS) as $key) {
            $values[$key] = $this->settings->get($key, null, 'site');
        }

        // Recent activity — last 50 events, summarised. Counts give the
        // admin a one-glance sense of accept/reject ratios over the last
        // 30 days.
        $db = \Core\Database\Database::getInstance();
        $recent = $db->fetchAll("
            SELECT cc.id, cc.user_id, cc.anon_id, cc.action,
                   cc.necessary, cc.preferences, cc.analytics, cc.marketing,
                   cc.policy_version, cc.created_at,
                   u.username AS username
            FROM cookie_consents cc
            LEFT JOIN users u ON u.id = cc.user_id
            ORDER BY cc.id DESC
            LIMIT 50
        ");
        $stats = $db->fetchOne("
            SELECT
              SUM(action='accept_all') AS accepts,
              SUM(action='reject_all') AS rejects,
              SUM(action='custom')     AS customs,
              SUM(action='withdraw')   AS withdraws,
              COUNT(*) AS total
            FROM cookie_consents
            WHERE created_at > NOW() - INTERVAL 30 DAY
        ") ?: ['accepts'=>0,'rejects'=>0,'customs'=>0,'withdraws'=>0,'total'=>0];

        return Response::view('cookieconsent::admin.index', [
            'values' => $values,
            'recent' => $recent,
            'stats'  => $stats,
            'user'   => $this->auth->user(),
        ]);
    }

    public function adminSave(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        foreach (self::SETTING_KEYS as $key => $type) {
            if ($type === 'boolean') {
                $value = $request->post($key) ? 'true' : 'false';
            } else {
                $value = (string) $request->post($key, '');
            }
            $this->settings->set($key, $value, $type, 'site');
        }

        $this->auth->auditLog('cookieconsent.settings.save', null, null, null, ['scope' => 'site']);

        return Response::redirect('/admin/cookie-consent')
            ->withFlash('success', 'Cookie consent settings saved.');
    }

    /**
     * POST /admin/cookie-consent/bump-version
     *
     * Increment the policy_version setting. Re-prompts every visitor on
     * their next page view. One-click button on the admin page next to
     * the version field, so admins don't have to remember the format.
     */
    public function bumpVersion(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $current = (int) ($this->settings->get('cookieconsent_policy_version', '1', 'site') ?? '1');
        $next    = max(1, $current) + 1;
        $this->settings->set('cookieconsent_policy_version', (string) $next, 'string', 'site');

        $this->auth->auditLog('cookieconsent.version.bump', null, null,
            ['policy_version' => (string) $current],
            ['policy_version' => (string) $next]
        );

        return Response::redirect('/admin/cookie-consent')
            ->withFlash('success', "Policy version bumped to v{$next}. All visitors will see the banner again on their next page view.");
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        // Either the dedicated permission OR superadmin (per-framework
        // convention — superadmins implicitly have every permission).
        return $this->auth->check()
            && ($this->auth->isSuperAdmin() || $this->auth->can('cookieconsent.manage'));
    }

    private function denied(): Response
    {
        Session::flash('error', 'You don\'t have permission to manage cookie consent.');
        return Response::redirect('/admin');
    }
}
