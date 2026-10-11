<?php
namespace Core\Auth;

/**
 * How long a password has to be — one number, read by every form and rule.
 *
 * It was the literal 12 in eleven places (register, reset, the admin user
 * form, the profile change, the live strength meter, the signup block), so a
 * site could not choose its own minimum without editing all eleven and
 * missing one — the form would then accept what the server refused.
 *
 * Set per site with PASSWORD_MIN_LENGTH in .env (StoriesDen uses 10, the
 * owner's call on 2026-10-10). Unset keeps 12. Clamped to 8–64: below 8 is
 * not a password policy, and the strength rule still applies on top.
 */
final class PasswordPolicy
{
    public const DEFAULT_MIN = 12;
    public const FLOOR       = 8;
    public const CEILING     = 64;

    public static function minLength(): int
    {
        $raw = $_ENV['PASSWORD_MIN_LENGTH'] ?? getenv('PASSWORD_MIN_LENGTH');
        if ($raw === false || $raw === null || trim((string) $raw) === '' || !is_numeric(trim((string) $raw))) {
            return self::DEFAULT_MIN;
        }

        return max(self::FLOOR, min(self::CEILING, (int) $raw));
    }

    /** The validator rule, e.g. "min:10". */
    public static function rule(): string
    {
        return 'min:' . self::minLength();
    }
}
