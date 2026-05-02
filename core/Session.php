<?php
// core/Session.php
namespace Core;

class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        // Short-circuit once the session is up — avoids repeated session_status()
        // syscalls on every helper call during a single request.
        if (self::$started) return;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        self::$started = true;
    }

    public static function destroy(): void
    {
        self::start();
        session_destroy();
        $_SESSION = [];
        self::$started = false;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Flash: set a value that is read once then deleted.
     * When called with only $key, reads and removes the flash value.
     * When called with $key + $value, stores a flash value.
     */
    public static function flash(string $key, mixed $value = null): mixed
    {
        self::start();
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $val = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $val;
    }

    public static function allFlash(): array
    {
        self::start();
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

}
