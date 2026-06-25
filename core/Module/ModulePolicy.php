<?php
// core/Module/ModulePolicy.php
namespace Core\Module;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Services\SettingsService;

/**
 * Shared base for per-module site-policy services.
 *
 * Each module that needs a "who can do X / what's the public surface
 * look like / which behaviors are gated by site-level toggles" layer
 * subclasses this and overrides `settingPrefix()` and `permission()`.
 * The standard knobs (access mode, role/group gating, public visibility,
 * notifications on/off, moderation auto vs queue) live here so that
 * every module's policy file is ~20 lines instead of ~150.
 *
 * Setting keys follow `{prefix}_<knob>` convention:
 *   {prefix}_access_mode        all_users | admins_only | roles | groups
 *   {prefix}_access_roles       comma-separated role slugs
 *   {prefix}_access_groups      comma-separated group ids (numeric)
 *   {prefix}_public_visibility  public | auth_only | disabled
 *   {prefix}_notifications_enabled  boolean
 *   {prefix}_moderation_mode    auto | queue
 *
 * Defaults are conservative — `all_users` for access (preserve v1
 * behavior on existing installs), public for visibility, notifications
 * on, moderation auto. Subclasses can override any default by
 * overriding the corresponding method.
 *
 * Group gating reads `user_groups` directly so this base doesn't take
 * a hard dependency on the `groups` premium module — the setting is
 * still safe to use on installs without it (the membership query
 * returns no rows when the table is missing, so the gate fails closed).
 */
abstract class ModulePolicy
{
    public const ACCESS_ALL_USERS   = 'all_users';
    public const ACCESS_ADMINS_ONLY = 'admins_only';
    public const ACCESS_ROLES       = 'roles';
    public const ACCESS_GROUPS      = 'groups';

    public const VISIBILITY_PUBLIC   = 'public';
    public const VISIBILITY_AUTH     = 'auth_only';
    public const VISIBILITY_DISABLED = 'disabled';

    public const MOD_AUTO  = 'auto';
    public const MOD_QUEUE = 'queue';

    protected SettingsService $settings;
    protected ?Database       $db;

    public function __construct(?SettingsService $settings = null, ?Database $db = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->db       = $db; // lazy — only constructed if a groups check actually fires
    }

    /** Lowercase, snake_case key prefix — e.g. 'newsletter', 'forms', 'helpdesk'. */
    abstract protected function settingPrefix(): string;

    /** Permission slug consulted in the default `all_users` access mode. */
    abstract protected function permission(): string;

    // ── Standard knobs ───────────────────────────────────────────────

    public function accessMode(): string
    {
        $v = (string) $this->settings->get($this->key('access_mode'), self::ACCESS_ALL_USERS, 'site');
        return in_array($v, [self::ACCESS_ALL_USERS, self::ACCESS_ADMINS_ONLY, self::ACCESS_ROLES, self::ACCESS_GROUPS], true)
            ? $v
            : self::ACCESS_ALL_USERS;
    }

    /** @return string[] role slugs (lowercased, trimmed) */
    public function allowedRoles(): array
    {
        return $this->csv($this->key('access_roles'));
    }

    /** @return int[] group ids */
    public function allowedGroups(): array
    {
        return array_values(array_filter(array_map('intval', $this->csv($this->key('access_groups')))));
    }

    public function publicVisibility(): string
    {
        $v = (string) $this->settings->get($this->key('public_visibility'), self::VISIBILITY_PUBLIC, 'site');
        return in_array($v, [self::VISIBILITY_PUBLIC, self::VISIBILITY_AUTH, self::VISIBILITY_DISABLED], true)
            ? $v
            : self::VISIBILITY_PUBLIC;
    }

    // ── Static public-surface gate (wizard-parity) ───────────────────
    //
    // The two helpers below let ANY module gate its public-facing routes by
    // a site-configured visibility WITHOUT subclassing — a controller action
    // just calls ModulePolicy::publicGate('courses') at the top. This is the
    // toggle the demos hand-coded (e.g. a public course catalog vs a
    // members-only one): a wizard customer sets `{prefix}_public_visibility`
    // and the module's guest surface honors it.

    /** Read `{prefix}_public_visibility` directly (no instance needed). */
    public static function publicVisibilityFor(string $prefix): string
    {
        $v = (string) (new SettingsService())->get($prefix . '_public_visibility', self::VISIBILITY_PUBLIC, 'site');
        return in_array($v, [self::VISIBILITY_PUBLIC, self::VISIBILITY_AUTH, self::VISIBILITY_DISABLED], true)
            ? $v
            : self::VISIBILITY_PUBLIC;
    }

    /**
     * Gate a module's PUBLIC surface by its site-configured visibility.
     * Returns a Response to short-circuit the action, or null to proceed:
     *   public    → null (anyone, default — back-compat)
     *   auth_only → null if logged in; else redirect to /login (intended set
     *               from $request so post-login returns to the page)
     *   disabled  → 404 for everyone except admins/super-admins (so the owner
     *               can still preview); null for them
     *
     * Fail-OPEN: any error resolving the setting/auth → null (never hide a
     * surface by accident). Call at the very top of a public controller
     * action:  if ($r = ModulePolicy::publicGate('courses', $request)) return $r;
     */
    public static function publicGate(string $prefix, ?\Core\Request $request = null): ?\Core\Response
    {
        try {
            $vis = self::publicVisibilityFor($prefix);
            if ($vis === self::VISIBILITY_PUBLIC) return null;

            $auth = Auth::getInstance();

            if ($vis === self::VISIBILITY_AUTH) {
                if ($auth->check()) return null;
                $path = $request ? $request->path() : '/';
                if (is_string($path) && $path !== '' && $path[0] === '/') {
                    \Core\Session::set('intended', $path);
                }
                return \Core\Response::redirect('/login');
            }

            // disabled — owners/admins may still preview; everyone else 404s.
            if ($auth->check() && ($auth->isSuperAdmin() || $auth->hasRole(['admin', 'super-admin']))) {
                return null;
            }
            return new \Core\Response('Not found', 404);
        } catch (\Throwable) {
            return null; // fail open
        }
    }

    public function notificationsEnabled(): bool
    {
        return (bool) $this->settings->get($this->key('notifications_enabled'), true, 'site');
    }

    public function moderationMode(): string
    {
        $v = (string) $this->settings->get($this->key('moderation_mode'), self::MOD_AUTO, 'site');
        return in_array($v, [self::MOD_AUTO, self::MOD_QUEUE], true) ? $v : self::MOD_AUTO;
    }

    // ── canManage — the meat ─────────────────────────────────────────

    public function canManage(Auth $auth): bool
    {
        if (!$auth->check()) return false;
        if ($auth->isSuperAdmin()) return true;

        switch ($this->accessMode()) {
            case self::ACCESS_ADMINS_ONLY:
                return $auth->hasRole(['admin', 'super-admin']);

            case self::ACCESS_ROLES:
                $roles = $this->allowedRoles();
                return !empty($roles) && $auth->hasRole($roles);

            case self::ACCESS_GROUPS:
                $groupIds = $this->allowedGroups();
                if (empty($groupIds)) return false;
                return $this->isMemberOfAny((int) $auth->id(), $groupIds);

            case self::ACCESS_ALL_USERS:
            default:
                return $auth->can($this->permission());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    protected function key(string $suffix): string
    {
        return $this->settingPrefix() . '_' . $suffix;
    }

    /** Read a CSV-typed setting → trimmed lowercased array of non-empty strings. */
    protected function csv(string $settingKey): array
    {
        $raw = (string) $this->settings->get($settingKey, '', 'site');
        if (trim($raw) === '') return [];
        $parts = array_map('trim', explode(',', strtolower($raw)));
        return array_values(array_filter($parts, fn($p) => $p !== ''));
    }

    /**
     * Membership lookup against the `groups` module's `user_groups`
     * table. We query directly so this base doesn't import any
     * premium-module code (tier integrity), and we tolerate the table
     * being missing (no groups module installed) by failing closed
     * with a try/catch.
     */
    protected function isMemberOfAny(int $userId, array $groupIds): bool
    {
        if ($userId <= 0 || empty($groupIds)) return false;
        try {
            $db = $this->db ?? Database::getInstance();
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $row = $db->fetchOne(
                "SELECT 1 FROM user_groups WHERE user_id = ? AND group_id IN ($placeholders) LIMIT 1",
                array_merge([$userId], $groupIds)
            );
            return (bool) $row;
        } catch (\Throwable) {
            // Groups module not installed (table missing) → fail closed.
            return false;
        }
    }
}
