<?php
// modules/cookieconsent/Services/CookieConsentService.php
namespace Modules\Cookieconsent\Services;

use Core\Database\Database;
use Core\Services\SettingsService;

/**
 * GDPR cookie-consent storage + cookie ↔ DB plumbing.
 *
 * Two surfaces of state:
 *   - The CONSENT_COOKIE on the visitor's device tells the BANNER whether
 *     to render and tells controllers/views (via consent_allowed())
 *     whether a given cookie category is permitted to fire.
 *   - The cookie_consents TABLE stores the audit trail, one row per
 *     accept / reject / customize / withdraw event, so the controller
 *     can prove later that consent was given (GDPR Art. 7(1)).
 *
 * The cookie payload is a small JSON object — version + the four
 * category booleans + the anon_id. It is signed with HMAC-SHA256 of
 * APP_KEY so a hostile client can't flip categories on a visitor's
 * device by hand-editing the cookie. (We re-validate categories on
 * the server anyway, but the cookie is the ground truth for guest
 * visits where the page render needs to know without a DB hit.)
 */
class CookieConsentService
{
    public const COOKIE_NAME = 'cookie_consent';
    public const COOKIE_TTL  = 60 * 60 * 24 * 365; // 1 year

    public const CATEGORIES  = ['necessary', 'preferences', 'analytics', 'marketing'];

    private Database        $db;
    private SettingsService $settings;

    public function __construct(?Database $db = null, ?SettingsService $settings = null)
    {
        $this->db       = $db       ?? Database::getInstance();
        $this->settings = $settings ?? new SettingsService();
    }

    /**
     * Whether the banner should render on the next page view. False when:
     *   - the master toggle is off
     *   - or a valid consent cookie exists for the CURRENT policy version
     */
    public function shouldShowBanner(): bool
    {
        if (!$this->isEnabled()) return false;

        $payload = $this->readCookie();
        if ($payload === null) return true;

        return ((string) ($payload['v'] ?? '')) !== $this->policyVersion();
    }

    /** Master toggle. */
    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('cookieconsent_enabled', true, 'site');
    }

    /** Current policy version string; bumping this re-prompts everyone. */
    public function policyVersion(): string
    {
        return (string) ($this->settings->get('cookieconsent_policy_version', '1', 'site') ?? '1');
    }

    /**
     * Has the visitor allowed the given category? `necessary` is always
     * true; the other three default to false until the visitor accepts.
     *
     * Designed to be called from any view / controller / block that
     * wants to gate a tracking script, e.g.:
     *
     *     <?php if (consent_allowed('analytics')): ?>
     *         <script src="https://plausible.io/js/script.js"></script>
     *     <?php endif; ?>
     */
    public function isAllowed(string $category): bool
    {
        if ($category === 'necessary') return true;
        if (!in_array($category, self::CATEGORIES, true)) return false;

        $payload = $this->readCookie();
        if ($payload === null) return false;

        // Old policy version — treat as no consent until the visitor
        // re-acks the new policy. Conservative; aligns with GDPR.
        if (((string) ($payload['v'] ?? '')) !== $this->policyVersion()) {
            return false;
        }

        return !empty($payload['c'][$category]);
    }

    /**
     * Persist a consent action: write the audit row AND the client cookie.
     *
     * @param string  $action     One of: accept_all, reject_all, custom, withdraw
     * @param array   $categories Category booleans (necessary always 1)
     * @param ?int    $userId     Signed-in user id, if any
     * @return string             The anon_id stored on the cookie (returned
     *                            so callers can chain audit_log entries to it).
     */
    public function recordConsent(string $action, array $categories, ?int $userId = null): string
    {
        $action = in_array($action, ['accept_all','reject_all','custom','withdraw'], true)
            ? $action : 'custom';

        // Coerce + always-true necessary
        $necessary   = 1;
        $preferences = !empty($categories['preferences']) ? 1 : 0;
        $analytics   = !empty($categories['analytics'])   ? 1 : 0;
        $marketing   = !empty($categories['marketing'])   ? 1 : 0;

        if ($action === 'accept_all') {
            $preferences = $analytics = $marketing = 1;
        } elseif ($action === 'reject_all' || $action === 'withdraw') {
            $preferences = $analytics = $marketing = 0;
        }

        // Carry the anon_id forward across the same device. New visitor
        // → mint a fresh 32-char hex token.
        $existing = $this->readCookie();
        $anonId   = (string) ($existing['a'] ?? bin2hex(random_bytes(16)));

        // IP is stored as VARBINARY(16) so v4 + v6 fit one column.
        $ipPacked = null;
        $rawIp    = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($rawIp !== null) {
            $packed = @inet_pton($rawIp);
            if ($packed !== false) $ipPacked = $packed;
        }

        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(strip_tags($_SERVER['HTTP_USER_AGENT']), 0, 500)
            : null;

        $this->db->insert('cookie_consents', [
            'user_id'        => $userId,
            'anon_id'        => $anonId,
            'action'         => $action,
            'necessary'      => $necessary,
            'preferences'    => $preferences,
            'analytics'      => $analytics,
            'marketing'      => $marketing,
            'policy_version' => $this->policyVersion(),
            'ip_address'     => $ipPacked,
            'user_agent'     => $userAgent,
        ]);

        $this->writeCookie([
            'a' => $anonId,
            'v' => $this->policyVersion(),
            't' => time(),
            'c' => [
                'necessary'   => true,
                'preferences' => (bool) $preferences,
                'analytics'   => (bool) $analytics,
                'marketing'   => (bool) $marketing,
            ],
        ]);

        return $anonId;
    }

    /**
     * Withdraw all non-essential consent. Equivalent to recordConsent('withdraw').
     * Provided as a separate method so the audit trail and call sites read
     * cleanly when this is invoked from the user-facing "Withdraw consent"
     * link in the footer / privacy page.
     */
    public function withdraw(?int $userId = null): void
    {
        $this->recordConsent('withdraw', [], $userId);
    }

    // ── Cookie I/O ─────────────────────────────────────────────────────

    /**
     * Decode + verify the consent cookie. Returns null when:
     *   - no cookie present
     *   - decode fails
     *   - HMAC mismatch (tampered or from a different APP_KEY)
     */
    public function readCookie(): ?array
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($raw) || $raw === '') return null;

        // Format: base64(json).hex(hmac)
        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) return null;
        [$b64, $sig] = $parts;

        $expected = hash_hmac('sha256', $b64, $this->signingKey());
        if (!hash_equals($expected, (string) $sig)) return null;

        $json = base64_decode($b64, true);
        if ($json === false) return null;

        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }

    private function writeCookie(array $payload): void
    {
        $b64 = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        // base64_decode in readCookie() handles URL-safe chars too (it's
        // permissive). We strip padding to keep the cookie compact.
        $b64Plain = base64_encode((string) json_encode($payload));
        $sig      = hash_hmac('sha256', $b64Plain, $this->signingKey());
        $value    = $b64Plain . '.' . $sig;

        // Reflect into $_COOKIE so the same-request render branches see it.
        $_COOKIE[self::COOKIE_NAME] = $value;

        if (headers_sent()) {
            // Best-effort — if headers are already flushed, the next
            // request will pick up the cookie via the JS path's setCookie
            // fallback in the popup.
            return;
        }

        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => false,  // JS reads this to gate analytics scripts
            'samesite' => 'Lax',
        ]);
    }

    private function signingKey(): string
    {
        // Reuse APP_KEY when present; fall back to a derived value so
        // dev installs don't crash before the key is generated.
        $k = (string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?? '');
        return $k !== '' ? $k : 'cookieconsent-fallback-key-change-me';
    }
}
