<?php
// tests/Unit/Services/NewDeviceLoginNotifyTest.php
namespace Tests\Unit\Services;

use Core\Auth\Auth;
use Core\Database\Database;
use Tests\TestCase;

/**
 * Smoke test for Auth::notifyOnNewDeviceLogin (private). Covers the three
 * short-circuit branches added 2026-04-23:
 *
 *   1. Setting disabled              → returns before any session query
 *   2. last_login_at is null         → returns (first-ever login)
 *   3. Matching UA in sessions table → returns (known device)
 *
 * The happy path (no-match → MailService::send) isn't directly asserted
 * here because the method `new`s MailService inline, which would exercise
 * the live mail driver. That branch is covered by the QA checklist.
 *
 * Strategy: swap Database::getInstance() for a pattern-dispatching stub
 * that returns different rows per query signature. Both SettingsService and
 * Auth call through this singleton, so we can control both sides.
 */
final class NewDeviceLoginNotifyTest extends TestCase
{
    /** Track of fetchOne calls by SQL fragment. */
    private array $calls = [];

    private function installDb(array $routes): Database
    {
        $this->calls = [];
        $calls = &$this->calls;

        $db = new class($routes, $calls) extends Database {
            private array $routes;
            private array $callsRef;
            public function __construct(array $routes, array &$callsRef) {
                $this->routes = $routes;
                $this->callsRef = &$callsRef;
            }
            public function fetchOne(string $sql, array $bind = []): ?array {
                $this->callsRef[] = ['sql' => $sql, 'bind' => $bind];
                foreach ($this->routes as $fragment => $return) {
                    if (str_contains($sql, $fragment)) return $return;
                }
                return null;
            }
            // SettingsService now bulk-loads via fetchAll(scope, scope_key).
            // Translate the 'FROM settings' route fragment into a single
            // synthetic row keyed by 'new_device_login_email_enabled' so the
            // service caches it under that key. The Auth code reads exactly
            // that key, so the test's setting value flows through.
            public function fetchAll(string $sql, array $bind = []): array {
                $this->callsRef[] = ['sql' => $sql, 'bind' => $bind];
                if (str_contains($sql, 'FROM settings') && isset($this->routes['FROM settings'])) {
                    $row = $this->routes['FROM settings'];
                    return [[
                        'key'   => 'new_device_login_email_enabled',
                        'value' => $row['value'] ?? '',
                        'type'  => $row['type']  ?? 'string',
                    ]];
                }
                return [];
            }
            public function insert(string $table, array $data): int { return 0; }
            public function update(string $table, array $data, string $where = '', array $bind = []): int { return 0; }
        };

        $this->mockDatabase($db);
        return $db;
    }

    private function makeAuth(): Auth
    {
        // Bypass singleton + constructor session work.
        $ref  = new \ReflectionClass(Auth::class);
        $auth = $ref->newInstanceWithoutConstructor();
        $dbProp = $ref->getProperty('db');
        $dbProp->setValue($auth, Database::getInstance());
        return $auth;
    }

    private function invokeNotify(Auth $auth, array $user): void
    {
        $m = new \ReflectionMethod(Auth::class, 'notifyOnNewDeviceLogin');
        $m->invoke($auth, $user);
    }

    /** Count calls whose SQL contains the given fragment. */
    private function callCountMatching(string $fragment): int
    {
        $n = 0;
        foreach ($this->calls as $c) if (str_contains($c['sql'], $fragment)) $n++;
        return $n;
    }

    public function test_skips_when_setting_disabled(): void
    {
        // settings.value lookup returns "0" (disabled).
        $this->installDb([
            'FROM settings' => ['value' => '0', 'type' => 'boolean'],
        ]);
        $auth = $this->makeAuth();

        $this->invokeNotify($auth, [
            'id' => 42, 'email' => 'a@example.com',
            'last_login_at' => '2026-04-22 10:00:00',
        ]);

        $this->assertSame(0, $this->callCountMatching('FROM sessions'),
            'DB sessions table must not be queried when setting is off');
    }

    public function test_skips_when_last_login_at_is_null(): void
    {
        // Setting on, but user has no prior login.
        $this->installDb([
            'FROM settings' => ['value' => '1', 'type' => 'boolean'],
        ]);
        $auth = $this->makeAuth();

        $this->invokeNotify($auth, [
            'id' => 42, 'email' => 'a@example.com',
            'last_login_at' => null,
        ]);

        $this->assertSame(0, $this->callCountMatching('FROM sessions'),
            'First-ever logins must not query the sessions table');
    }

    public function test_skips_when_email_is_empty(): void
    {
        $this->installDb([
            'FROM settings' => ['value' => '1', 'type' => 'boolean'],
        ]);
        $auth = $this->makeAuth();

        $this->invokeNotify($auth, [
            'id' => 42, 'email' => '',
            'last_login_at' => '2026-04-22 10:00:00',
        ]);

        $this->assertSame(0, $this->callCountMatching('FROM sessions'),
            'Users with no email must not trigger the sessions query');
    }

    public function test_skips_when_prior_session_with_same_ua_exists(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 TestBrowser';

        $this->installDb([
            'FROM settings' => ['value' => '1', 'type' => 'boolean'],
            'FROM sessions' => ['1' => 1],   // known device — UA already seen
        ]);
        $auth = $this->makeAuth();

        $this->invokeNotify($auth, [
            'id' => 42, 'email' => 'a@example.com',
            'last_login_at' => '2026-04-22 10:00:00',
        ]);

        // Exactly one sessions-table probe, then early-return (no mail path).
        $this->assertSame(1, $this->callCountMatching('FROM sessions'));

        // Bind vars on the sessions lookup — user id + UA
        $sessionCall = null;
        foreach ($this->calls as $c) {
            if (str_contains($c['sql'], 'FROM sessions')) { $sessionCall = $c; break; }
        }
        $this->assertSame(true, $sessionCall !== null, 'A sessions-table query must have been recorded');
        $this->assertSame([42, 'Mozilla/5.0 TestBrowser'], $sessionCall['bind']);

        unset($_SERVER['HTTP_USER_AGENT']);
    }
}
