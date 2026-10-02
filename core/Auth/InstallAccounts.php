<?php
// core/Auth/InstallAccounts.php
namespace Core\Auth;

/**
 * The accounts a fresh install creates, and how their passwords are chosen.
 *
 * Until 2026-10-02 every install seeded admin@example.com (super-admin),
 * editor@ and viewer@ — all with the published password "Admin@123" and no
 * forced change. Any app deployed as shipped could be signed into as its
 * super-admin by anyone who had read the framework source.
 *
 * Now, outside development the install seeds ONE super-admin with a random
 * password, printed once by `php artisan migrate` and resettable with
 * `php artisan admin:password`. The known password and the two demo accounts
 * exist only when APP_ENV says development, where they save a round-trip.
 */
final class InstallAccounts
{
    /** bcrypt of "Admin@123" — DEVELOPMENT ONLY. */
    public const DEV_PASSWORD_HASH = '$2y$12$HFVsCqoJuxXGuRkIZfNsDeUYwsaj0RDwpDVbvld4ETM40dCr.30ru';

    private const DEV_ENVS = ['local', 'development', 'dev', 'testing'];

    /** An unset APP_ENV is production: the safe reading of a missing value. */
    public static function isDevelopment(): bool
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        return in_array(strtolower(trim((string) ($env ?: 'production'))), self::DEV_ENVS, true);
    }

    /** No look-alike characters, so it survives being read off a terminal. */
    public static function randomPassword(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /** What `migrate` prints the one time it creates the super-admin. */
    public static function announce(string $email, string $password): string
    {
        return "\n"
            . "  ┌─ First super-admin created ─────────────────────────────────\n"
            . "  │  email:    $email\n"
            . "  │  password: $password\n"
            . "  │  Shown ONCE. Sign in and change both, or run:\n"
            . "  │    php artisan admin:password --email=you@yourdomain.com\n"
            . "  └──────────────────────────────────────────────────────────────\n\n";
    }
}
