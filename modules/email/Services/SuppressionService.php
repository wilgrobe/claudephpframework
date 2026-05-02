<?php
// modules/email/Services/SuppressionService.php
namespace Modules\Email\Services;

use Core\Database\Database;

/**
 * Authoritative suppression check + mutation for outbound email.
 *
 * Lifecycle:
 *   - isAllowed($email, $category)        — pre-send gate. Called from
 *                                           MailService::send. Returns
 *                                           false if a row exists in
 *                                           mail_suppressions for this
 *                                           (email, category) OR for
 *                                           the special wildcard 'all'.
 *   - suppress($email, $category, $reason) — manual / automated opt-out.
 *                                           Idempotent via UNIQUE KEY.
 *   - unsuppress($email, $category)       — admin un-block. Removes the
 *                                           row. Use cautiously — can
 *                                           re-trigger CAN-SPAM exposure
 *                                           if the user previously
 *                                           opted out.
 *   - listForEmail($email)                — for the user's preference
 *                                           center. Returns the set of
 *                                           categories currently suppressed.
 *   - blockSend(...)                      — log skipped sends.
 *
 * Token mint/verify uses HMAC-SHA256 over (email|category|expires_at).
 * Tokens are URL-safe base64. Default TTL 90 days — long enough for a
 * user to click the unsubscribe link from an old archived email,
 * short enough that a leaked link doesn't have permanent effect.
 */
class SuppressionService
{
    public const WILDCARD_CATEGORY = 'all';
    public const TOKEN_TTL_SECONDS = 90 * 86400;
    public const REASON_USER_UNSUBSCRIBE = 'user_unsubscribe';
    public const REASON_HARD_BOUNCE      = 'hard_bounce';
    public const REASON_COMPLAINT        = 'complaint';
    public const REASON_MANUAL_ADMIN     = 'manual_admin';
    public const REASON_API              = 'api';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Is this email allowed to receive a message of this category?
     *
     * Returns false if a row exists in mail_suppressions for either:
     *   (email, category)   — specifically opted out
     *   (email, 'all')      — opted out of every category (hard bounce
     *                         or complaint suppresses ALL)
     *
     * Transactional is checked the same way — but in practice a user
     * can't put themselves on the transactional suppression list (the
     * preference center hides transactional rows). Hard bounces and
     * complaints DO suppress transactional via the wildcard, which is
     * correct: dead address = don't keep trying.
     */
    public function isAllowed(string $email, string $category): bool
    {
        $email = strtolower(trim($email));
        $row = $this->db->fetchOne("
            SELECT 1 FROM mail_suppressions
            WHERE email = ? AND category_slug IN (?, ?)
            LIMIT 1
        ", [$email, $category, self::WILDCARD_CATEGORY]);
        return !$row;
    }

    /**
     * Add a suppression. UNIQUE KEY makes this idempotent — re-suppressing
     * a (email, category) pair is a no-op.
     */
    public function suppress(string $email, string $category, string $reason, ?int $userId = null, ?string $notes = null): void
    {
        $email = strtolower(trim($email));
        $allowedReasons = [
            self::REASON_USER_UNSUBSCRIBE,
            self::REASON_HARD_BOUNCE,
            self::REASON_COMPLAINT,
            self::REASON_MANUAL_ADMIN,
            self::REASON_API,
            'spam_report',
        ];
        if (!in_array($reason, $allowedReasons, true)) {
            $reason = self::REASON_API;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ipPacked = ($ip && @inet_pton($ip) !== false) ? @inet_pton($ip) : null;

        $this->db->query("
            INSERT IGNORE INTO mail_suppressions
              (email, category_slug, reason, source_ip, user_id, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$email, $category, $reason, $ipPacked, $userId, $notes]);
    }

    public function unsuppress(string $email, string $category): void
    {
        $email = strtolower(trim($email));
        $this->db->query(
            "DELETE FROM mail_suppressions WHERE email = ? AND category_slug = ?",
            [$email, $category]
        );
    }

    /** @return string[] category slugs currently suppressed for this email */
    public function listForEmail(string $email): array
    {
        $email = strtolower(trim($email));
        $rows = $this->db->fetchAll(
            "SELECT category_slug FROM mail_suppressions WHERE email = ?",
            [$email]
        );
        return array_map(fn($r) => (string) $r['category_slug'], $rows);
    }

    public function blockSend(string $email, string $category, ?string $subject = null): void
    {
        $this->db->insert('mail_suppression_blocks', [
            'email'         => strtolower(trim($email)),
            'category_slug' => $category,
            'subject'       => $subject !== null ? mb_substr($subject, 0, 500) : null,
        ]);
    }

    public function listCategories(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM mail_categories ORDER BY sort_order ASC, id ASC"
        );
    }

    public function findCategory(string $slug): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM mail_categories WHERE slug = ?",
            [$slug]
        );
        return $row ?: null;
    }

    // ── Token signing for one-click unsubscribe ─────────────────────

    /**
     * Mint an unsubscribe token for an email + category.
     * Format: base64url(payload).hex(hmac).
     */
    public function mintToken(string $email, string $category, ?int $ttlSeconds = null): string
    {
        $payload = json_encode([
            'e' => strtolower(trim($email)),
            'c' => $category,
            'x' => time() + ($ttlSeconds ?? self::TOKEN_TTL_SECONDS),
        ]);
        $b64    = $this->base64UrlEncode((string) $payload);
        $sig    = hash_hmac('sha256', $b64, $this->signingKey());
        return $b64 . '.' . $sig;
    }

    /**
     * Verify a token. Returns ['email'=>..,'category'=>..] on success,
     * null on:
     *   - malformed
     *   - HMAC mismatch (tampered or different APP_KEY)
     *   - expired
     */
    public function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;
        [$b64, $sig] = $parts;

        $expected = hash_hmac('sha256', $b64, $this->signingKey());
        if (!hash_equals($expected, $sig)) return null;

        $json = $this->base64UrlDecode($b64);
        if ($json === null) return null;

        $payload = json_decode($json, true);
        if (!is_array($payload)) return null;

        $expires = (int) ($payload['x'] ?? 0);
        if ($expires < time()) return null;

        return [
            'email'    => (string) ($payload['e'] ?? ''),
            'category' => (string) ($payload['c'] ?? ''),
        ];
    }

    private function signingKey(): string
    {
        $k = (string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?? '');
        return $k !== '' ? $k : 'email-suppress-fallback-key-CHANGE-ME';
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $b64): ?string
    {
        $padded = $b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $raw    = base64_decode(strtr($padded, '-_', '+/'), true);
        return $raw === false ? null : $raw;
    }
}
