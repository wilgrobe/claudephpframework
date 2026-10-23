<?php
// tests/Unit/Database/ConnectRetryTest.php
namespace Tests\Unit\Database;

use Core\Database\Database;
use PHPUnit\Framework\TestCase;

/**
 * Database::connectWithRetry() waits and connects again when MySQL refuses the connection (Builder queue,
 * 21 Oct 2026: "[2002] Connection refused" 5× while the database restarted). Errors that waiting cannot fix
 * are thrown at once, and a refusal that outlasts every wait is thrown after the last try.
 */
final class ConnectRetryTest extends TestCase
{
    private static function refused(): \PDOException
    {
        // What PDO throws on a refused connect: the message carries [2002], getCode() is 2002, no errorInfo.
        return new \PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002);
    }

    /** A connector that throws $failures in turn, then returns a PDO; $calls counts attempts. */
    private static function connector(array $failures, int &$calls): callable
    {
        return static function () use (&$failures, &$calls): \PDO {
            $calls++;
            if ($failures) throw array_shift($failures);
            return (new \ReflectionClass(\PDO::class))->newInstanceWithoutConstructor();   // no driver needed
        };
    }

    public function test_a_refused_connect_is_retried_after_each_wait_and_then_succeeds(): void
    {
        $calls = 0; $slept = [];
        $pdo = Database::connectWithRetry(self::connector([self::refused(), self::refused()], $calls), [500, 2000, 5000],
            function (int $ms) use (&$slept): void { $slept[] = $ms; });
        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertSame(3, $calls, 'two refusals, then the connect that worked');
        $this->assertSame([500, 2000], $slept, 'it waited before each new try, in order, and not after success');
    }

    public function test_a_refusal_that_outlasts_every_wait_is_thrown_after_the_last_try(): void
    {
        $calls = 0; $slept = [];
        try {
            Database::connectWithRetry(self::connector(array_fill(0, 3, self::refused()), $calls), [250, 1000],
                function (int $ms) use (&$slept): void { $slept[] = $ms; });
            $this->fail('expected the refusal to be thrown');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('[2002]', $e->getMessage());
        }
        $this->assertSame(3, $calls, 'one try per wait plus the final one');
        $this->assertSame([250, 1000], $slept);
    }

    public function test_errors_waiting_cannot_fix_are_thrown_at_once(): void
    {
        foreach ([new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'x'@'localhost'", 1045),
                  new \PDOException("SQLSTATE[HY000] [1049] Unknown database 'nope'", 1049)] as $err) {
            $calls = 0; $slept = [];
            try {
                Database::connectWithRetry(self::connector([$err], $calls), [500, 2000],
                    function (int $ms) use (&$slept): void { $slept[] = $ms; });
                $this->fail('expected ' . $err->getMessage());
            } catch (\PDOException $e) {
                $this->assertSame($err, $e);
            }
            $this->assertSame(1, $calls, $err->getMessage() . ' was retried');
            $this->assertSame([], $slept);
        }
    }

    public function test_no_waits_under_tests_or_when_switched_off_and_the_right_waits_otherwise(): void
    {
        $this->assertSame([], Database::connectRetryDelays('', true, true), 'under PHPUnit');
        $this->assertSame([], Database::connectRetryDelays('', null, true), 'this very run is under PHPUnit');
        foreach (['0', 'false', 'OFF', ' no '] as $off) {
            $this->assertSame([], Database::connectRetryDelays($off, false, true), "DB_CONNECT_RETRY=$off");
        }
        $this->assertSame(Database::CONNECT_RETRY_MS_CLI, Database::connectRetryDelays('', false, true), 'CLI');
        $this->assertSame(Database::CONNECT_RETRY_MS_WEB, Database::connectRetryDelays('1', false, false), 'web');
    }

    public function test_which_errors_count_as_transient(): void
    {
        foreach ([2002, 2003, 2006, 2013, 1040] as $code) {
            $this->assertTrue(Database::isTransientConnectError(new \PDOException("SQLSTATE[HY000] [$code] x", $code)), "code $code");
        }
        // A driver code carried only in errorInfo, as PDO sets it on a dropped query connection.
        $e = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        $this->assertTrue(Database::isTransientConnectError($e));
        // The code only in the message text.
        $this->assertTrue(Database::isTransientConnectError(new \PDOException('SQLSTATE[HY000] [2002] No such file or directory')));
        foreach ([1045, 1049, 1064] as $code) {
            $this->assertFalse(Database::isTransientConnectError(new \PDOException("SQLSTATE[HY000] [$code] x", $code)), "code $code");
        }
        $this->assertFalse(Database::isTransientConnectError(new \RuntimeException('Connection refused')), 'only PDO errors with a MySQL code');
    }
}
