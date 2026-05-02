<?php
// modules/settings/Controllers/SettingsController.php
namespace Modules\Settings\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Services\SettingsService;

/**
 * Ported from App\Controllers\Admin\SettingsController. Behavior unchanged.
 *
 * NOTE: the COLOR_DEFAULTS public constant is referenced by the live layout
 * as a fallback for CSS vars. The layout should be updated to reference
 * Modules\Settings\Controllers\SettingsController::COLOR_DEFAULTS when the
 * old class is removed.
 */
class SettingsController
{
    private const FOOTER_KEYS = [
        'footer_enabled'       => 'boolean',
        'footer_logo_text'     => 'string',
        'footer_tagline'       => 'string',
        'footer_copyright'     => 'string',
        'footer_powered_by'    => 'string',
        'footer_show_menu'     => 'boolean',
        'footer_menu_location' => 'string',
    ];

    // ── New-shell panel key sets ────────────────────────────────────────
    //
    // The hub introduced 2026-05-01 splits settings into 9 panels driven
    // by the partial in Views/admin/_nav.php. Each new panel below has
    // its own key map that the controller uses both to render the form
    // (which inputs to show, with which type widget) and to gate the
    // save (which keys to accept from POST).
    //
    // The pre-shell pages (FOOTER_KEYS, GROUP_KEYS, ACCESS_KEYS,
    // SECURITY_KEYS, APPEARANCE_KEYS, COLOR_DEFAULTS) are kept as-is —
    // their controllers remain the source of truth for those domains.
    // The new panels deliberately don't overlap.

    private const GENERAL_KEYS = [
        'site_name'           => 'string',
        'site_tagline'        => 'string',
        'site_logo_url'       => 'string',
        'site_url'            => 'string',
        'site_timezone'       => 'string',
        'site_default_locale' => 'string',
        'maintenance_mode'    => 'boolean', // also in ACCESS_KEYS for legacy reasons; both writes touch the same row
    ];

    private const LAYOUT_KEYS = [
        // Header
        'header_show_search'    => 'boolean',
        'header_show_logo'      => 'boolean',
        // Sidebar / orientation
        'layout_orientation'    => 'string',  // 'sidebar' or 'topbar'
        'sidebar_collapsed_default' => 'boolean',
        // Footer settings (managed here now — the legacy /admin/settings/footer
        // page remains as a redirect to /admin/settings/layout)
        'footer_enabled'       => 'boolean',
        'footer_logo_text'     => 'string',
        'footer_tagline'       => 'string',
        'footer_copyright'     => 'string',
        'footer_powered_by'    => 'string',
        'footer_show_menu'     => 'boolean',
        'footer_menu_location' => 'string',
    ];

    private const PRIVACY_KEYS = [
        // Master toggles. Detailed config (banner copy, opt-out text)
        // stays on the dedicated module admin pages — this panel is the
        // "switchboard" view, not the full editor.
        'cookieconsent_enabled'  => 'boolean',
        'ccpa_enabled'           => 'boolean',
        'ccpa_honor_gpc_signal'  => 'boolean',
    ];

    private const CONTENT_KEYS = [
        // Comments
        'comments_require_moderation'      => 'boolean',
        'comments_edit_window_seconds'     => 'integer',
        'comments_max_depth'               => 'integer',
        'comments_notify_moderators'       => 'boolean',
        'comments_notify_interval_minutes' => 'integer',
    ];

    private const COMMERCE_KEYS = [
        // Reviews
        'store.reviews_enabled'           => 'boolean',
        'store.reviews_badge_in_listing'  => 'boolean',
        // Currency + tax + checkout defaults — stubs for now; populate
        // via the form as the store grows. setting() reads return null
        // for unset keys so adding rows here is non-breaking.
        'store.currency_default'          => 'string',
        'store.tax_inclusive_pricing'     => 'boolean',
        'store.guest_checkout_enabled'    => 'boolean',
        'store.low_stock_threshold'       => 'integer',
    ];

    private const INTEGRATIONS_KEYS = [
        // Mail — read by MailService at send time. Driver=none disables
        // outbound mail entirely (verification + password reset etc still
        // queue but never go out).
        'mail_driver'        => 'string',
        'mail_from_address'  => 'string',
        'mail_from_name'     => 'string',
        // Analytics provider snippet emitted from the layout. Provider
        // values: 'none' | 'plausible' | 'ga' | 'umami'.
        'analytics_provider' => 'string',
        'analytics_site_id'  => 'string',
        // Sentry DSN for client + server error reporting. Empty disables.
        'sentry_dsn'         => 'string',
    ];

    private const GROUP_KEYS = [
        'single_group_only'    => 'boolean',
        'allow_group_creation' => 'boolean',
    ];

    private const SECURITY_KEYS = [
        // Whether users get a self-service /account/sessions page to
        // review + terminate their own devices. Off = page 404s.
        'account_sessions_enabled'        => 'boolean',
        // Whether Auth::startSession dispatches a "new sign-in from an
        // unrecognized device" email on first-time user_agent match.
        // Off = no detection email regardless of other mail config.
        'new_device_login_email_enabled'  => 'boolean',
        // Minutes a password-reset link stays valid after creation.
        // Read by AuthController::resetPassword; constrained to
        // PASSWORD_RESET_TTL_PRESETS on save so an admin can't slip
        // in a hostile value (negative, 10-year, non-numeric).
        'password_reset_ttl_minutes'      => 'integer',
        // Whether superadmins receive an email (in addition to the in-app
        // notification) when a module is auto-disabled because its
        // declared dependencies aren't met. ModuleRegistry::resolveDependencies
        // dispatches via NotificationService with channels='in_app,email'
        // when this is true, 'in_app' only when false. Default true so a
        // freshly-installed framework doesn't quietly hide install issues.
        'module_disabled_email_to_sa_enabled' => 'boolean',
        // HIBP "Have I Been Pwned" pwned-passwords check on registration,
        // password change, and admin user-create / -edit. Default on.
        // When the security module isn't installed, these settings are
        // inert (the controllers' class_exists guards short-circuit).
        'password_breach_check_enabled'   => 'boolean',
        // When true, a confirmed breach hit BLOCKS the action with an
        // error message. When false, the user sees a warning but can
        // proceed (warn-only). Defaults to true (block).
        'password_breach_check_block'     => 'boolean',
        // Sliding session inactivity timeout in minutes. 0 = disabled.
        // SessionIdleTimeoutMiddleware (security module) reads this.
        'session_idle_timeout_minutes'    => 'integer',
        // /admin/* IP allowlist (security module). Master toggle + CIDR list.
        'admin_ip_allowlist_enabled'      => 'boolean',
        'admin_ip_allowlist'              => 'text',
        // PII access logging — every GET to a PII admin surface
        // (/admin/users/*, /admin/sessions, /admin/audit-log, etc.)
        // writes a pii.viewed audit row. SOC2/ISO27001 expectation.
        'admin_pii_access_logging_enabled' => 'boolean',
        // Login anomaly detection (loginanomaly module). When on, every
        // sign-in does a geo lookup and compares to the user's prior
        // login; impossible-travel + country-jump findings are recorded
        // and (optionally) emailed to the user.
        'login_anomaly_enabled'             => 'boolean',
        'login_anomaly_email_enabled'       => 'boolean',
        'login_anomaly_threshold_kmh'       => 'integer',
        'login_anomaly_alert_threshold_kmh' => 'integer',
    ];

    /**
     * Allowed values for password_reset_ttl_minutes. Any "minutes in"
     * the form post that isn't one of these is rejected as invalid.
     * Values ≥ 60 are rendered in hours ("2 hours") on the admin page;
     * values < 60 render as minutes ("30 minutes").
     */
    public const PASSWORD_RESET_TTL_PRESETS = [15, 30, 60, 120, 240, 720, 1440];
    public const PASSWORD_RESET_TTL_DEFAULT = 120; // 2 hours

    private const ACCESS_KEYS = [
        // /register accepts new signups. Off → AuthController refuses and
        // shows "Registration is closed."
        'allow_registration'     => 'boolean',
        // Require users to click the verification link in their welcome
        // email before their first login succeeds.
        'require_email_verify'   => 'boolean',
        // Site-wide maintenance page — when on, only superadmins can
        // reach non-login routes; everyone else sees the maintenance view.
        'maintenance_mode'       => 'boolean',
        // COPPA / age-gate at registration. When `coppa_enabled` is on,
        // the registration form gains a date_of_birth field and applicants
        // under `coppa_minimum_age` are rejected with the configured
        // message. Defaults: off, 13y. (US COPPA min; set to 16 for GDPR
        // Art. 8 strict default.)
        'coppa_enabled'          => 'boolean',
        'coppa_minimum_age'      => 'integer',
        'coppa_block_message'    => 'string',
    ];

    private const APPEARANCE_KEYS = [
        'layout_orientation' => 'string',
        'color_primary'      => 'string',
        'color_primary_dark' => 'string',
        'color_secondary'    => 'string',
        'color_success'      => 'string',
        'color_danger'       => 'string',
        'color_warning'      => 'string',
        'color_info'         => 'string',
    ];

    public const COLOR_DEFAULTS = [
        'color_primary'      => '#4f46e5',
        'color_primary_dark' => '#3730a3',
        'color_secondary'    => '#0ea5e9',
        'color_success'      => '#10b981',
        'color_danger'       => '#ef4444',
        'color_warning'      => '#f59e0b',
        'color_info'         => '#3b82f6',
    ];

    private SettingsService $settings;
    private Auth            $auth;

    public function __construct()
    {
        $this->settings = new SettingsService();
        $this->auth     = Auth::getInstance();
    }

    /**
     * Keys at scope='site' that are owned by a dedicated admin page
     * (Footer / Group Policy / Security / Access / Appearance, plus
     * any keys each module declares via ModuleProvider::settingsKeys()).
     *
     * The generic grid at /admin/settings?scope=site hides these so the
     * same setting never appears in two places — editing on the
     * dedicated page is the one source of truth, and deleting here
     * used to wipe the dedicated page's row until it was re-saved.
     * Delete attempts against any of these keys are also refused (see
     * delete()).
     *
     * Module-declared keys are collected at runtime from every loaded
     * provider, so adding a new toggle to a module is a one-line
     * change — implement settingsKeys() and the key drops out of the
     * generic grid automatically.
     */
    private static function managedSiteKeys(): array
    {
        $local = array_merge(
            array_keys(self::FOOTER_KEYS),
            array_keys(self::GROUP_KEYS),
            array_keys(self::SECURITY_KEYS),
            array_keys(self::ACCESS_KEYS),
            array_keys(self::APPEARANCE_KEYS),
            array_keys(self::COLOR_DEFAULTS),
            // New-shell panels
            array_keys(self::GENERAL_KEYS),
            array_keys(self::LAYOUT_KEYS),
            array_keys(self::PRIVACY_KEYS),
            array_keys(self::CONTENT_KEYS),
            array_keys(self::COMMERCE_KEYS),
            array_keys(self::INTEGRATIONS_KEYS),
        );

        // Theme tokens — every key declared by ThemeService::TOKEN_DEFINITIONS
        // is owned by the Appearance panel. APPEARANCE_KEYS / COLOR_DEFAULTS
        // above only cover the legacy 7 flat brand colors; the namespaced
        // theme.* tokens added in the theme refactor weren't filtered, so
        // they leaked into the Other / Unmanaged grid (every color, every
        // .dark sibling, every font/radius/layout token, plus the custom
        // font-links textarea). Pull them in dynamically so adding a new
        // token to ThemeService doesn't reintroduce the leak.
        foreach (\Core\Services\ThemeService::TOKEN_DEFINITIONS as $key => $def) {
            $local[] = $key;
            if (($def['validator'] ?? '') === 'color') {
                $local[] = $key . '.dark';
            }
        }
        // Free-form textarea persisted by the Appearance form. Not a
        // CSS-variable token (no --var emitted) but it lives on the same
        // panel and shouldn't reappear in Other / Unmanaged.
        $local[] = 'theme.font.custom_links';

        // Module-declared keys via the settingsKeys() hook on
        // ModuleProvider. Wrapped in try/catch so a misbehaving module
        // can't take the settings page down.
        try {
            $registry = \Core\Container\Container::global()->get(\Core\Module\ModuleRegistry::class);
            foreach ($registry->all() as $modName => $provider) {
                try {
                    $declared = $provider->settingsKeys();
                } catch (\Throwable $e) {
                    error_log("SettingsController: settingsKeys() failed for {$modName}: " . $e->getMessage());
                    continue;
                }
                foreach ($declared as $k) {
                    if (is_string($k) && $k !== '') $local[] = $k;
                }
            }
        } catch (\Throwable $e) {
            // Container or registry unavailable (early bootstrap?) —
            // fall back to local-only filtering. Better to show too
            // much than to fail the page entirely.
            error_log("SettingsController: module registry unavailable for managed-keys collection: " . $e->getMessage());
        }

        return array_values(array_unique($local));
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        // 2026-05-01 hub redesign: /admin/settings is now an empty
        // landing point that bounces the admin into the General panel.
        // Deep-links to /admin/settings/{panel} (and the legacy
        // /admin/settings/{footer,security,...}) still work directly.
        // Non-site scopes that previously rendered the generic grid
        // here are still reachable at /admin/settings/other?scope=...
        $scope = $request->query('scope', '');
        if ($scope === '' || $scope === 'site') {
            return Response::redirect('/admin/settings/general');
        }
        return Response::redirect('/admin/settings/other?scope=' . urlencode($scope));
    }

    /**
     * Legacy generic-grid render. Preserved as a private helper because
     * the `save()` and `delete()` methods below still POST against the
     * old shape; if they succeed without redirect (validation failure
     * mid-save), the page would 404 without this. Public routing now
     * prefers `other()`.
     */
    private function legacyIndex(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $scope    = $request->query('scope', 'site');
        $scopeKey = $request->query('scope_key');
        // allWithMeta → [$key => ['raw'=>string, 'value'=>mixed, 'type'=>string]].
        // The generic grid needs per-row type info to pick the right input widget
        // AND to avoid clobbering the type column on save — the previous
        // hardcoded 'string' in the view was silently demoting every boolean
        // row to type='string' every time someone pressed Save All.
        $all = $this->settings->allWithMeta($scope, $scopeKey ?: null);

        // At scope='site' only, filter out keys that are edited on the
        // dedicated pages (Footer / Groups / Security / Access / Appearance).
        // Showing them in two places caused silent data loss: deleting a
        // row here erased the dedicated page's value, and saving here
        // clobbered the type column to 'string'. Other scopes still show
        // everything — they're free-form key/value overrides.
        $managed = [];
        if ($scope === 'site') {
            $managed = self::managedSiteKeys();
            $all = array_diff_key($all, array_flip($managed));
        }

        return Response::view('settings::admin.index', [
            'settings'    => $all,
            'scope'       => $scope,
            'scopeKey'    => $scopeKey,
            'managedKeys' => $managed,
            'user'        => $this->auth->user(),
        ]);
    }

    public function save(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $scope    = $request->post('scope', 'site');
        $scopeKey = $request->post('scope_key') ?: null;
        $items    = $request->post('settings', []);

        // Belt-and-suspenders: the generic grid doesn't render managed-key
        // rows, but a crafted POST could still try to set one here. Skip
        // those silently so the dedicated page stays the one source of truth.
        $managed = $scope === 'site' ? array_flip(self::managedSiteKeys()) : [];

        foreach ($items as $key => $value) {
            if (isset($managed[$key])) continue;
            $type = $request->post("types[$key]", 'string');
            $this->settings->set($key, $value, $scope, $scopeKey, $type);
        }

        if ($newKey = $request->post('new_key')) {
            // Don't let "Add Setting" re-create a managed key under the
            // generic grid — its type and value belong to the dedicated page.
            if (!isset($managed[$newKey])) {
                $this->settings->set(
                    $newKey,
                    $request->post('new_value', ''),
                    $scope,
                    $scopeKey,
                    $request->post('new_type', 'string')
                );
            }
        }

        $this->auth->auditLog('settings.save', null, null, null, ['scope' => $scope]);
        return Response::redirect("/admin/settings/other?scope=$scope")->withFlash('success', 'Settings saved.');
    }

    public function delete(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        $key      = $request->post('key');
        $scope    = $request->post('scope', 'site');
        $scopeKey = $request->post('scope_key') ?: null;

        // Refuse to delete keys owned by a dedicated page — last time this
        // happened, the row was wiped from the DB, the dedicated page
        // blanked out, and it took a manual re-save to restore it. The
        // view no longer renders delete buttons for managed keys, but this
        // also blocks crafted POSTs.
        if ($scope === 'site' && in_array($key, self::managedSiteKeys(), true)) {
            return Response::redirect("/admin/settings/other?scope=$scope")
                ->withFlash('error', "\"$key\" is managed on a dedicated settings page and can't be deleted here.");
        }

        $this->settings->delete($key, $scope, $scopeKey);
        return Response::redirect("/admin/settings/other?scope=$scope")->withFlash('success', 'Setting deleted.');
    }

    public function footer(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $values = [];
        foreach (array_keys(self::FOOTER_KEYS) as $key) {
            $values[$key] = $this->settings->get($key, null, 'site');
        }

        $locations = Database::getInstance()->fetchAll(
            "SELECT DISTINCT location FROM menus WHERE is_active = 1 ORDER BY location"
        );

        return Response::view('settings::admin.footer', [
            'values'    => $values,
            'locations' => array_column($locations, 'location'),
            'user'      => $this->auth->user(),
        ]);
    }

    public function saveFooter(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        foreach (self::FOOTER_KEYS as $key => $type) {
            if ($type === 'boolean') {
                $value = $request->post($key) ? 'true' : 'false';
            } else {
                $value = (string) $request->post($key, '');
            }
            $this->settings->set($key, $value, 'site', null, $type);
        }

        $this->auth->auditLog('settings.footer.save', null, null, null, ['scope' => 'site']);
        return Response::redirect('/admin/settings/footer')->withFlash('success', 'Footer settings saved.');
    }

    public function groups(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $values = [];
        foreach (array_keys(self::GROUP_KEYS) as $key) {
            $values[$key] = $this->settings->get($key, null, 'site');
        }

        return Response::view('settings::admin.groups', [
            'values' => $values,
            'user'   => $this->auth->user(),
        ]);
    }

    public function saveGroups(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        foreach (self::GROUP_KEYS as $key => $type) {
            if ($type === 'boolean') {
                $value = $request->post($key) ? 'true' : 'false';
            } else {
                $value = (string) $request->post($key, '');
            }
            $this->settings->set($key, $value, 'site', null, $type);
        }

        $this->auth->auditLog('settings.groups.save', null, null, null, ['scope' => 'site']);
        return Response::redirect('/admin/settings/groups')->withFlash('success', 'Group policy settings saved.');
    }

    public function security(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $values = [];
        foreach (array_keys(self::SECURITY_KEYS) as $key) {
            $values[$key] = $this->settings->get($key, null, 'site');
        }

        return Response::view('settings::admin.security', [
            'values' => $values,
            'user'   => $this->auth->user(),
        ]);
    }

    public function saveSecurity(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        // Validation pass — refuse the save outright on bad input rather
        // than persisting partial state.

        // (1) Idle timeout: clamp to a sensible range. 0 disables; > 0
        //     must be at least 1 (per-minute granularity) and we cap at
        //     a year so a typo doesn't mean "effectively never".
        $idleMin = (int) $request->post('session_idle_timeout_minutes', 0);
        if ($idleMin < 0)        $idleMin = 0;
        if ($idleMin > 525600)   $idleMin = 525600; // 1 year ceiling

        // (2) Admin IP allowlist parses as valid CIDRs; reject otherwise.
        $ipListText = (string) $request->post('admin_ip_allowlist', '');
        if (class_exists(\Modules\Security\Services\CidrMatcher::class)) {
            $err = \Modules\Security\Services\CidrMatcher::validate($ipListText);
            if ($err !== null) {
                Session::flash('error', 'IP allowlist: ' . $err);
                return Response::redirect('/admin/settings/security');
            }
        }

        // (3) Anti-lockout: if the admin is enabling the IP allowlist,
        //     verify their CURRENT IP is matched by the proposed list.
        //     Refuse with a clear error rather than locking out the
        //     person mid-save.
        $ipListEnabled = (bool) $request->post('admin_ip_allowlist_enabled', false);
        if ($ipListEnabled
            && class_exists(\Modules\Security\Services\CidrMatcher::class)
        ) {
            $entries = \Modules\Security\Services\CidrMatcher::parseList($ipListText);
            $myIp    = $request->ip();
            if (!empty($entries) && !\Modules\Security\Services\CidrMatcher::matches($myIp, $entries)) {
                Session::flash('error',
                    'Refusing to save: your current IP (' . $myIp . ') is not in the allowlist. '
                    . 'You would lock yourself out of /admin. Add ' . $myIp . '/32 first.');
                return Response::redirect('/admin/settings/security');
            }
        }

        foreach (self::SECURITY_KEYS as $key => $type) {
            if ($type === 'boolean') {
                $value = $request->post($key) ? 'true' : 'false';
            } elseif ($key === 'password_reset_ttl_minutes') {
                // Whitelist against the preset list — the view only renders
                // those options, but a crafted POST could submit anything
                // else. Fall back to the default on an unknown value rather
                // than persisting it.
                $submitted = (int) $request->post($key, self::PASSWORD_RESET_TTL_DEFAULT);
                $value     = (string) (in_array($submitted, self::PASSWORD_RESET_TTL_PRESETS, true)
                    ? $submitted
                    : self::PASSWORD_RESET_TTL_DEFAULT);
            } elseif ($key === 'session_idle_timeout_minutes') {
                $value = (string) $idleMin;  // already clamped above
            } else {
                $value = (string) $request->post($key, '');
            }
            $this->settings->set($key, $value, 'site', null, $type);
        }

        $this->auth->auditLog('settings.security.save', null, null, null, ['scope' => 'site']);
        return Response::redirect('/admin/settings/security')->withFlash('success', 'Security settings saved.');
    }

    public function access(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $values = [];
        foreach (array_keys(self::ACCESS_KEYS) as $key) {
            $values[$key] = $this->settings->get($key, null, 'site');
        }

        return Response::view('settings::admin.access', [
            'values' => $values,
            'user'   => $this->auth->user(),
        ]);
    }

    public function saveAccess(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        foreach (self::ACCESS_KEYS as $key => $type) {
            if ($type === 'boolean') {
                $value = $request->post($key) ? 'true' : 'false';
            } else {
                $value = (string) $request->post($key, '');
            }
            $this->settings->set($key, $value, 'site', null, $type);
        }

        $this->auth->auditLog('settings.access.save', null, null, null, ['scope' => 'site']);
        return Response::redirect('/admin/settings/access')->withFlash('success', 'Registration & access settings saved.');
    }

    public function appearance(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        // Theme tokens are now declared by ThemeService::TOKEN_DEFINITIONS;
        // we read all of them here so the admin form can render every
        // section without each one being hardcoded twice (once here, once
        // in the view). Color tokens additionally read their `.dark`
        // sibling key for the dark-mode column.
        $theme = new \Core\Services\ThemeService($this->settings);
        $values = [];
        foreach ($theme->allKeys() as $key) {
            $values[$key] = $this->settings->get($key, null, 'site');
        }
        foreach ($theme->colorKeys() as $key) {
            $values[$key . '.dark'] = $this->settings->get($key . '.dark', null, 'site');
        }
        // Keep the layout_orientation read - it isn't a theme token but
        // lives on this same admin page.
        $values['layout_orientation'] = $this->settings->get('layout_orientation', null, 'site');

        // Custom font <link>s aren't a CSS-variable token (no --var to emit)
        // so we read it directly here rather than threading it through
        // ThemeService::TOKEN_DEFINITIONS. The admin UI surfaces it as a
        // textarea below the font-slot pickers.
        $values['theme.font.custom_links'] = $this->settings->get('theme.font.custom_links', null, 'site');

        return Response::view('settings::admin.appearance', [
            'values'           => $values,
            'tokenDefinitions' => \Core\Services\ThemeService::TOKEN_DEFINITIONS,
            'groupOrder'       => \Core\Services\ThemeService::GROUP_ORDER,
            'fontLibrary'      => \Core\Services\ThemeService::FONT_LIBRARY,
            'colorDefaults'    => self::COLOR_DEFAULTS,
            'user'             => $this->auth->user(),
        ]);
    }

    public function saveAppearance(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        // Layout orientation - not a theme token, but lives on the same
        // admin form. Constrained to a known enum.
        $orientation = (string) $request->post('layout_orientation', 'sidebar');
        if (!in_array($orientation, ['sidebar', 'topbar'], true)) {
            $orientation = 'sidebar';
        }
        $this->settings->set('layout_orientation', $orientation, 'site', null, 'string');

        // Theme tokens. Each gets validated against its declared validator
        // type; values that fail validation are stored as empty string so
        // the renderer falls back to the hardcoded default. Color tokens
        // additionally accept a `.dark` sibling stored as <key>.dark.
        //
        // ⚠ PHP converts dots (and spaces) to underscores in $_POST keys -
        // this is a register_globals backward-compat quirk that survives
        // even with the feature long-deprecated. So an input named
        // "theme.color.bg.page" lands at $_POST["theme_color_bg_page"].
        // We translate when reading; storage stays dotted for the
        // namespaced naming convention. Bug was silently dropping every
        // dotted-key save from Batch B onward until 2026-04-28.
        $theme = new \Core\Services\ThemeService($this->settings);
        $postKey = fn(string $k): string => str_replace('.', '_', $k);
        foreach (\Core\Services\ThemeService::TOKEN_DEFINITIONS as $key => $def) {
            $raw   = trim((string) $request->post($postKey($key), ''));
            $valid = $raw === '' || $theme->validate($raw, (string) $def['validator']);
            $this->settings->set($key, $valid ? $raw : '', 'site', null, 'string');

            if (($def['validator'] ?? '') === 'color') {
                $darkRaw   = trim((string) $request->post($postKey($key . '.dark'), ''));
                $darkValid = $darkRaw === '' || $theme->validate($darkRaw, 'color');
                $this->settings->set($key . '.dark', $darkValid ? $darkRaw : '', 'site', null, 'string');
            }
        }

        // Custom font <link> URLs textarea. One URL per line; lines that
        // don't pass the http(s) URL check are dropped at render time.
        // We store the blob verbatim (after trimming + length cap) so the
        // admin can re-edit; rendering applies the per-line filter.
        $customLinks = trim((string) $request->post('theme_font_custom_links', ''));
        if (strlen($customLinks) > 4000) $customLinks = substr($customLinks, 0, 4000);
        $this->settings->set('theme.font.custom_links', $customLinks, 'site', null, 'text');

        $this->auth->auditLog('settings.appearance.save', null, null, null, ['scope' => 'site']);
        return Response::redirect('/admin/settings/appearance')->withFlash('success', 'Appearance settings saved.');
    }

    // ── New-shell panel handlers ─────────────────────────────────────────
    //
    // Each panel reuses two private helpers: loadValues() pulls every
    // declared key out of SettingsService into the view; saveValues()
    // writes them back, type-casting via the constant's type column.
    //
    // The legacy methods above (footer / groups / security / access /
    // appearance) keep their custom logic — those panels have form-level
    // validation (CIDR check, anti-lockout, etc.) that wouldn't fit the
    // generic helper. The new panels are pure key/value forms.

    /**
     * @param array<string, string> $keymap key => type
     * @return array<string, mixed> key => persisted value
     */
    private function loadValues(array $keymap): array
    {
        $out = [];
        foreach (array_keys($keymap) as $key) {
            $out[$key] = $this->settings->get($key, null, 'site');
        }
        return $out;
    }

    /**
     * @param array<string, string> $keymap key => type ('boolean' | 'integer' | 'string' | 'text')
     */
    private function saveValues(Request $request, array $keymap, string $auditTag): void
    {
        // PHP $_POST silently rewrites dots + spaces in keys to underscores
        // (register_globals legacy). Translate when reading so dotted keys
        // (store.reviews_enabled, theme.color.*) round-trip correctly.
        $postKey = static fn(string $k): string => str_replace(['.', ' '], '_', $k);

        foreach ($keymap as $key => $type) {
            $rawKey = $postKey($key);
            if ($type === 'boolean') {
                $value = $request->post($rawKey) ? 'true' : 'false';
            } elseif ($type === 'integer') {
                $value = (string) (int) $request->post($rawKey, 0);
            } else {
                $value = (string) $request->post($rawKey, '');
            }
            $this->settings->set($key, $value, 'site', null, $type);
        }

        $this->auth->auditLog($auditTag, null, null, null, ['scope' => 'site']);
    }

    public function general(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        return Response::view('settings::admin.general', [
            'values' => $this->loadValues(self::GENERAL_KEYS),
            'user'   => $this->auth->user(),
        ]);
    }

    public function saveGeneral(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        $this->saveValues($request, self::GENERAL_KEYS, 'settings.general.save');
        return Response::redirect('/admin/settings/general')->withFlash('success', 'General settings saved.');
    }

    public function layout(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        $locations = Database::getInstance()->fetchAll(
            "SELECT DISTINCT location FROM menus WHERE is_active = 1 ORDER BY location"
        );
        return Response::view('settings::admin.layout', [
            'values'    => $this->loadValues(self::LAYOUT_KEYS),
            'locations' => array_column($locations, 'location'),
            'user'      => $this->auth->user(),
        ]);
    }

    public function saveLayout(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        // Constrain orientation to a known enum before falling through to
        // the generic helper — defends against crafted POSTs.
        $orientation = (string) $request->post('layout_orientation', 'sidebar');
        if (!in_array($orientation, ['sidebar', 'topbar'], true)) {
            $request->post(); // no-op; clarity that we discard the bad value
        } else {
            $this->settings->set('layout_orientation', $orientation, 'site', null, 'string');
        }
        // Drop layout_orientation from the generic save — already handled.
        $rest = self::LAYOUT_KEYS;
        unset($rest['layout_orientation']);
        $this->saveValues($request, $rest, 'settings.layout.save');
        return Response::redirect('/admin/settings/layout')->withFlash('success', 'Layout settings saved.');
    }

    public function privacy(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        return Response::view('settings::admin.privacy', [
            'values' => $this->loadValues(self::PRIVACY_KEYS),
            'user'   => $this->auth->user(),
        ]);
    }

    public function savePrivacy(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        $this->saveValues($request, self::PRIVACY_KEYS, 'settings.privacy.save');
        return Response::redirect('/admin/settings/privacy')->withFlash('success', 'Privacy settings saved.');
    }

    public function content(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        return Response::view('settings::admin.content', [
            'values' => $this->loadValues(self::CONTENT_KEYS),
            'user'   => $this->auth->user(),
        ]);
    }

    public function saveContent(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        $this->saveValues($request, self::CONTENT_KEYS, 'settings.content.save');
        return Response::redirect('/admin/settings/content')->withFlash('success', 'Content settings saved.');
    }

    public function commerce(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        return Response::view('settings::admin.commerce', [
            'values' => $this->loadValues(self::COMMERCE_KEYS),
            'user'   => $this->auth->user(),
        ]);
    }

    public function saveCommerce(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        $this->saveValues($request, self::COMMERCE_KEYS, 'settings.commerce.save');
        return Response::redirect('/admin/settings/commerce')->withFlash('success', 'Commerce settings saved.');
    }

    public function integrations(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        return Response::view('settings::admin.integrations', [
            'values' => $this->loadValues(self::INTEGRATIONS_KEYS),
            'user'   => $this->auth->user(),
        ]);
    }

    public function saveIntegrations(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        $this->saveValues($request, self::INTEGRATIONS_KEYS, 'settings.integrations.save');
        return Response::redirect('/admin/settings/integrations')->withFlash('success', 'Integrations saved.');
    }

    /**
     * "Members" panel — a unified view that links to the existing
     * Access (registration / verify / COPPA) and Groups (group policy)
     * editors. Rather than duplicate the validation logic, the Members
     * page renders the same form sections by including those views as
     * partials, sharing the new shell. Save still POSTs to
     * /admin/settings/access and /admin/settings/groups respectively
     * so the existing validators stay authoritative.
     */
    public function members(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $access = [];
        foreach (array_keys(self::ACCESS_KEYS) as $key) {
            $access[$key] = $this->settings->get($key, null, 'site');
        }
        $groups = [];
        foreach (array_keys(self::GROUP_KEYS) as $key) {
            $groups[$key] = $this->settings->get($key, null, 'site');
        }

        return Response::view('settings::admin.members', [
            'access' => $access,
            'groups' => $groups,
            'user'   => $this->auth->user(),
        ]);
    }

    /**
     * "Other / Unmanaged" panel — same generic key/value grid the
     * legacy /admin/settings index used to render. Filtered by
     * managedSiteKeys so only ad-hoc / plug-in / module-extension
     * keys appear here.
     */
    public function other(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();

        $scope    = $request->query('scope', 'site');
        $scopeKey = $request->query('scope_key');
        $all      = $this->settings->allWithMeta($scope, $scopeKey ?: null);

        $managed = [];
        if ($scope === 'site') {
            $managed = self::managedSiteKeys();
            $all = array_diff_key($all, array_flip($managed));
        }

        return Response::view('settings::admin.other', [
            'settings'    => $all,
            'scope'       => $scope,
            'scopeKey'    => $scopeKey,
            'managedKeys' => $managed,
            'user'        => $this->auth->user(),
        ]);
    }

    private function denied(): Response
    {
        return Response::redirect('/admin')->withFlash('error', 'Superadmin access required.');
    }
}
