<?php
// core/Console/Commands/AdminPasswordCommand.php
namespace Core\Console\Commands;

use Core\Auth\InstallAccounts;
use Core\Console\Command;
use Core\Database\Database;

/**
 * Give the super-admin a new random password (and optionally a real email).
 *
 * A fresh install creates its super-admin with a random password printed once
 * by `migrate`. This is the way back when that output is lost — the password
 * reset email would go to admin@example.com, which nobody reads. It also signs
 * that account out everywhere, since the old password may be the reason.
 */
class AdminPasswordCommand extends Command
{
    public function name(): string        { return 'admin:password'; }
    public function description(): string { return 'Set a new random password for the super-admin (optionally --email=you@domain)'; }

    public function usage(): string
    {
        return "php artisan admin:password [--email=you@yourdomain.com] [--user=<id|email>]\n"
             . "  --user   which account (default: the first super-admin)\n"
             . "  --email  also change that account's email address";
    }

    public function handle(array $argv): int
    {
        $opt = [];
        foreach ($argv as $a) {
            if (preg_match('/^--(user|email)=(.+)$/', (string) $a, $m)) { $opt[$m[1]] = trim($m[2]); }
        }
        $db = Database::getInstance();

        if (isset($opt['user'])) {
            $user = ctype_digit($opt['user'])
                ? $db->fetchOne('SELECT id, email FROM users WHERE id = ?', [(int) $opt['user']])
                : $db->fetchOne('SELECT id, email FROM users WHERE email = ?', [strtolower($opt['user'])]);
        } else {
            $user = $db->fetchOne('SELECT id, email FROM users WHERE is_superadmin = 1 ORDER BY id LIMIT 1');
        }
        if (!$user) {
            $this->error(isset($opt['user']) ? "No user {$opt['user']}." : 'No super-admin account exists.');
            return 1;
        }

        $email = (string) $user['email'];
        if (isset($opt['email'])) {
            $new = strtolower($opt['email']);
            if (!filter_var($new, FILTER_VALIDATE_EMAIL)) { $this->error("Not an email address: {$opt['email']}"); return 1; }
            $taken = $db->fetchColumn('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$new, (int) $user['id']]);
            if ((int) $taken > 0) { $this->error("$new already belongs to another account."); return 1; }
            $email = $new;
        }

        $password = InstallAccounts::randomPassword();
        $db->query(
            'UPDATE users SET email = ?, `password` = ?, is_active = 1 WHERE id = ?',
            [$email, password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]
        );
        try { $db->query('DELETE FROM sessions WHERE user_id = ?', [(int) $user['id']]); } catch (\Throwable $e) {}

        $this->line("  Account #{$user['id']}");
        $this->line("  email:    $email");
        $this->line("  password: $password");
        $this->line('  Shown once. Every existing session for this account was signed out.');
        return 0;
    }
}
