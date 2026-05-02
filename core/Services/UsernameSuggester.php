<?php
// core/Services/UsernameSuggester.php
namespace Core\Services;

use Core\Database\Database;

/**
 * Generates candidate usernames from a user's email + name and checks
 * uniqueness against `users.username`.
 *
 * The pattern: lowercase ASCII letters / digits / underscores / hyphens
 * only, 3-50 chars (matching the column's VARCHAR(50)). Anything outside
 * that range gets normalised — Unicode chars stripped, whitespace
 * collapsed to underscores, leading / trailing punctuation trimmed.
 *
 * Suggestion order (first available wins for the *primary* suggestion;
 * `suggest()` returns the full list of available alternatives):
 *
 *   1. email-local-part (everything before @)         e.g. "alice.bob"
 *   2. firstname.lastname                              "alice.brown"
 *   3. firstnamelastname                               "alicebrown"
 *   4. firstname (when last name absent)               "alice"
 *   5. {primary}{N} for N=1..9 if any of 1-4 collide   "alice2", "alicebrown7"
 *
 * Falls back to a random 8-hex-char suffix on the candidate's email
 * stem if every numeric variant is taken — extremely unlikely on any
 * site that isn't already at scale.
 */
class UsernameSuggester
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 50;

    /** Pattern allowed in the final stored username. */
    public const VALID_PATTERN = '/^[a-z0-9](?:[a-z0-9._-]{1,48}[a-z0-9])?$/';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Validate a user-supplied username (form input). Returns null on
     * success, error message on failure. Doesn't check uniqueness —
     * call isAvailable() separately for that (it's a DB hit).
     */
    public function validate(string $username): ?string
    {
        $u = strtolower(trim($username));
        if ($u === '') return 'Username is required.';
        if (mb_strlen($u) < self::MIN_LENGTH) return 'Username must be at least ' . self::MIN_LENGTH . ' characters.';
        if (mb_strlen($u) > self::MAX_LENGTH) return 'Username must be at most ' . self::MAX_LENGTH . ' characters.';
        if (!preg_match(self::VALID_PATTERN, $u)) {
            return 'Username may contain only letters, numbers, dots, underscores, and hyphens; must start + end with a letter or digit.';
        }
        if (in_array($u, self::reservedWords(), true)) {
            return 'That username is reserved. Please choose another.';
        }
        return null;
    }

    /**
     * Is this username currently free in the users table?
     */
    public function isAvailable(string $username, ?int $excludeUserId = null): bool
    {
        $u = strtolower(trim($username));
        if ($u === '') return false;

        $sql = "SELECT id FROM users WHERE LOWER(username) = ?";
        $args = [$u];
        if ($excludeUserId !== null) {
            $sql .= " AND id != ?";
            $args[] = $excludeUserId;
        }
        $row = $this->db->fetchOne($sql, $args);
        return $row === null;
    }

    /**
     * Build a list of CANDIDATE usernames derived from the inputs +
     * filter to those that pass validate() AND isAvailable().
     *
     * Returns up to $limit available suggestions (default 5). If even
     * the random-suffix fallback is taken, returns whatever subset is
     * free — possibly empty (extreme corner).
     *
     * @return string[]
     */
    public function suggest(?string $email, ?string $firstName, ?string $lastName, int $limit = 5): array
    {
        $candidates = $this->candidates($email, $firstName, $lastName);
        $out = [];

        foreach ($candidates as $c) {
            if (count($out) >= $limit) break;
            if ($this->validate($c) !== null) continue;
            if (!$this->isAvailable($c)) continue;
            if (in_array($c, $out, true)) continue;
            $out[] = $c;
        }

        // If we ran out of structured candidates, fall back to the
        // first valid stem + random suffix, repeating until we hit limit
        // or 10 attempts.
        if (count($out) < $limit) {
            $stem = $this->primaryStem($email, $firstName, $lastName);
            for ($i = 0; $i < 10 && count($out) < $limit; $i++) {
                $candidate = $stem . bin2hex(random_bytes(2));
                if ($this->validate($candidate) !== null) continue;
                if (!$this->isAvailable($candidate)) continue;
                if (in_array($candidate, $out, true)) continue;
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * Just the first available suggestion — convenient for the
     * registration auto-fill on blur.
     */
    public function suggestOne(?string $email, ?string $firstName, ?string $lastName): ?string
    {
        $list = $this->suggest($email, $firstName, $lastName, 1);
        return $list[0] ?? null;
    }

    /**
     * Candidates in priority order. May yield duplicates / invalid
     * forms; suggest() filters them.
     *
     * @return string[]
     */
    private function candidates(?string $email, ?string $firstName, ?string $lastName): array
    {
        $emailLocal = $this->normalise($this->beforeAt((string) $email));
        $first      = $this->normalise((string) $firstName);
        $last       = $this->normalise((string) $lastName);

        $list = [];

        if ($emailLocal !== '')           $list[] = $emailLocal;
        if ($first !== '' && $last !== '') {
            $list[] = $first . '.' . $last;
            $list[] = $first . $last;
            $list[] = substr($first, 0, 1) . $last;             // 'abrown'
        }
        if ($first !== '' && $last === '') $list[] = $first;
        if ($last !== '' && $first === '') $list[] = $last;

        // Numeric-suffix variants of each base, 2..9
        $bases = $list;
        foreach ($bases as $b) {
            for ($n = 2; $n <= 9; $n++) {
                $list[] = $b . $n;
            }
        }

        return $list;
    }

    /**
     * Pick the most-stem-like base for random-suffix fallback.
     * Email local part beats name when both are available — typically
     * shorter and more unique.
     */
    private function primaryStem(?string $email, ?string $firstName, ?string $lastName): string
    {
        $emailLocal = $this->normalise($this->beforeAt((string) $email));
        if ($emailLocal !== '') return $emailLocal;

        $first = $this->normalise((string) $firstName);
        $last  = $this->normalise((string) $lastName);
        if ($first !== '' && $last !== '') return $first . $last;
        if ($first !== '') return $first;
        if ($last !== '')  return $last;
        return 'user';
    }

    private function beforeAt(string $email): string
    {
        $at = strpos($email, '@');
        return $at === false ? $email : substr($email, 0, $at);
    }

    /**
     * Strip everything outside the allowed pattern, lowercase, trim
     * leading/trailing punctuation, collapse repeated separators.
     */
    private function normalise(string $s): string
    {
        $s = strtolower(trim($s));
        // Replace whitespace with underscore
        $s = preg_replace('/\s+/', '_', $s) ?? $s;
        // Drop everything outside the pattern
        $s = preg_replace('/[^a-z0-9._-]+/', '', $s) ?? $s;
        // Collapse repeated separators
        $s = preg_replace('/[._-]{2,}/', '_', $s) ?? $s;
        // Trim leading / trailing punctuation
        $s = trim($s, '._-');
        // Cap length
        return mb_substr($s, 0, self::MAX_LENGTH);
    }

    /**
     * Reserved usernames that would conflict with framework routes
     * or look impersonating. Defensive — prevents `admin`, `support`,
     * `root`, etc. from being claimed by a regular user.
     *
     * @return string[]
     */
    private static function reservedWords(): array
    {
        return [
            'admin', 'administrator', 'root', 'system', 'support',
            'help', 'staff', 'moderator', 'mod', 'official', 'api',
            'about', 'login', 'logout', 'register', 'profile', 'account',
            'settings', 'dashboard', 'feed', 'shop', 'cart', 'checkout',
            'orders', 'billing', 'admin_user', 'noreply', 'no-reply',
            'postmaster', 'webmaster', 'hostmaster', 'abuse',
            'security', 'privacy', 'legal', 'terms', 'compliance',
            'ccpa', 'gdpr', 'coppa', 'unsubscribe',
        ];
    }
}
