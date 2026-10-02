<?php
// tests/Unit/Modules/Gdpr/TablesKeyedByEmailAreErasedTest.php
namespace Tests\Unit\Modules\Gdpr;

use Core\Database\Database;
use Modules\Gdpr\Services\DataPurger;
use Modules\Gdpr\Services\GdprHandler;
use Modules\Gdpr\Services\GdprRegistry;
use Tests\TestCase;

/**
 * password_resets, login_attempts and message_log never stored a user id —
 * they are filed by the email (or phone) they were sent to, and lockouts by
 * `email:<sha256>`. The core handlers matched them by user_id, so on every
 * site an erasure logged "Unknown column 'user_id'" for each and removed
 * nothing (found on StoriesDen, 2026-10-02).
 */
final class IdentityPurgeDb extends Database
{
    /** @var list<array{0:string,1:array}> */
    public array $log = [];

    public function __construct(private ?array $user) {}
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function fetchAll(string $sql, array $bindings = []): array { return []; }
    public function fetchColumn(string $sql, array $bindings = [], int $col = 0): mixed { return null; }
    public function insert(string $table, array $data): int { return 1; }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        return str_contains($sql, 'FROM users WHERE id = ?') ? $this->user : null;
    }

    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        $this->log[] = [trim((string) preg_replace('/\s+/', ' ', $sql)), $bindings];
        return (new \ReflectionClass(\PDOStatement::class))->newInstanceWithoutConstructor();
    }

    /** The bindings of the DELETE that hit $table, in order. */
    public function deletesOn(string $table): array
    {
        $out = [];
        foreach ($this->log as [$sql, $b]) {
            if (str_starts_with($sql, "DELETE FROM `$table`")) { $out[] = [$sql, $b]; }
        }
        return $out;
    }
}

final class TablesKeyedByEmailAreErasedTest extends TestCase
{
    private const IDENTITY = ['id' => 7, 'email' => 'ann@example.test', 'phone' => '+15550100'];

    public function test_each_match_mode_binds_the_right_identifier(): void
    {
        $t = ['table' => 't', 'user_column' => 'c'];
        $this->assertSame(['`c` = ?', 7], GdprHandler::matchFor($t, self::IDENTITY));
        $this->assertSame(['LOWER(`c`) = ?', 'ann@example.test'], GdprHandler::matchFor($t + ['match' => 'email'], self::IDENTITY));
        $this->assertSame(['`c` = ?', '+15550100'], GdprHandler::matchFor($t + ['match' => 'phone'], self::IDENTITY));
        $this->assertSame(['`c` = ?', 'email:' . hash('sha256', 'ann@example.test')],
            GdprHandler::matchFor($t + ['match' => 'email_sha256', 'key_prefix' => 'email:'], self::IDENTITY));
    }

    public function test_nothing_on_file_or_an_unknown_mode_matches_nothing(): void
    {
        $t = ['table' => 't', 'user_column' => 'c'];
        $none = ['id' => 7, 'email' => '', 'phone' => ''];
        $this->assertNull(GdprHandler::matchFor($t + ['match' => 'email'], $none));
        $this->assertNull(GdprHandler::matchFor($t + ['match' => 'phone'], $none));
        $this->assertNull(GdprHandler::matchFor($t + ['match' => 'email_sha256'], $none));
        // Never fall back to the id: a column of emails matched against "7" is a silent miss at best.
        $this->assertNull(@GdprHandler::matchFor($t + ['match' => 'username'], self::IDENTITY));
    }

    /** The lockout key must be byte-for-byte what RateLimiter writes, whatever case the user typed. */
    public function test_the_login_attempt_key_is_the_rate_limiters_own(): void
    {
        $rl  = (new \ReflectionClass(\Core\Auth\RateLimiter::class))->newInstanceWithoutConstructor();
        $key = new \ReflectionMethod(\Core\Auth\RateLimiter::class, 'key');
        $written = $key->invoke($rl, 'email', '  Ann@Example.TEST ');

        $db = new IdentityPurgeDb(['email' => 'Ann@Example.test', 'phone' => null]);
        $identity = GdprHandler::identityFor($db, 7);
        $match = GdprHandler::matchFor(
            ['table' => 'login_attempts', 'user_column' => 'attempt_key', 'match' => 'email_sha256', 'key_prefix' => 'email:'],
            $identity
        );
        $this->assertSame($written, $match[1]);
    }

    public function test_the_purge_erases_the_core_tables_by_email_and_phone(): void
    {
        $db = new IdentityPurgeDb(['email' => 'Ann@Example.test', 'phone' => '+15550100']);
        $registry = new GdprRegistry(new class extends \Core\Module\ModuleRegistry {
            public function __construct() {}
            public function all(): array { return []; }
            public function skippedModules(): array { return []; }
            public function adminDisabledModules(): array { return []; }
        });

        (new DataPurger($db, $registry))->purge(7, 1);

        $resets = $db->deletesOn('password_resets');
        $this->assertCount(1, $resets);
        $this->assertStringContainsString('LOWER(`email`) = ?', $resets[0][0]);
        $this->assertSame(['ann@example.test'], $resets[0][1]);

        $attempts = $db->deletesOn('login_attempts');
        $this->assertCount(1, $attempts);
        $this->assertSame(['email:' . hash('sha256', 'ann@example.test')], $attempts[0][1]);

        $messages = array_map(static fn ($d) => $d[1][0], $db->deletesOn('message_log'));
        $this->assertSame(['ann@example.test', '+15550100'], $messages);

        // Still by id where the table has one.
        $this->assertSame([7], $db->deletesOn('sessions')[0][1]);
        // …and the users row is scrubbed AFTER the email was read, not before.
        $last = array_key_last(array_filter($db->log, static fn ($q) => str_starts_with($q[0], 'DELETE FROM `message_log`')));
        $scrub = array_key_first(array_filter($db->log, static fn ($q) => str_starts_with($q[0], 'UPDATE users')));
        $this->assertNotNull($scrub);
        $this->assertGreaterThan($last, $scrub);
    }

    /** loginanomaly: a NOT NULL user_id must not be nulled — that failed the whole UPDATE, scrubbing nothing. */
    public function test_keep_link_scrubs_the_columns_and_leaves_the_link(): void
    {
        $db = new IdentityPurgeDb(['email' => 'ann@example.test', 'phone' => null]);
        $registry = new class extends GdprRegistry {
            public function __construct() {}
            public function all(): array
            {
                return [new GdprHandler('loginanomaly', 'x', [[
                    'table' => 'login_anomalies', 'user_column' => 'user_id', 'action' => GdprHandler::ACTION_ANONYMIZE,
                    'keep_link' => true, 'anonymize_columns' => ['ip_address' => null, 'city' => null],
                ]])];
            }
        };
        (new DataPurger($db, $registry))->purge(7, 1);

        $upd = array_values(array_filter($db->log, static fn ($q) => str_starts_with($q[0], 'UPDATE `login_anomalies`')));
        $this->assertCount(1, $upd);
        $this->assertStringNotContainsString('`user_id` = NULL', $upd[0][0]);
        $this->assertStringContainsString('`ip_address` = ?', $upd[0][0]);
        $this->assertStringContainsString('WHERE `user_id` = ?', $upd[0][0]);
        $this->assertSame([null, null, 7], $upd[0][1]);
    }

    public function test_a_user_without_a_phone_does_not_erase_every_sms(): void
    {
        $db = new IdentityPurgeDb(['email' => 'ann@example.test', 'phone' => null]);
        $registry = new GdprRegistry(new class extends \Core\Module\ModuleRegistry {
            public function __construct() {}
            public function all(): array { return []; }
            public function skippedModules(): array { return []; }
            public function adminDisabledModules(): array { return []; }
        });
        (new DataPurger($db, $registry))->purge(7, 1);

        // An empty phone must not become `recipient = ''` — or worse, match by nothing at all.
        $this->assertSame([['ann@example.test']], array_map(static fn ($d) => $d[1], $db->deletesOn('message_log')));
    }
}
