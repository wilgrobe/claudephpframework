<?php
// tests/Unit/Queue/DatabaseQueueReserveRetryTest.php
namespace Tests\Unit\Queue;

use Core\Database\Database;
use Core\Queue\DatabaseQueue;
use PHPUnit\Framework\TestCase;

/**
 * reserve() runs its claim again when MySQL reports a deadlock / lock-wait timeout (MarketOtter, Oct 2026:
 * 5 deadlocks in 4 days, each one failing the whole worker run). Any other error, a conflict inside a
 * caller's transaction, or a conflict that will not clear, still throws.
 */
final class DatabaseQueueReserveRetryTest extends TestCase
{
    /** A Database whose UPDATE throws the given exceptions in turn, then succeeds. */
    private function db(array $failures, bool $inTx = false): Database
    {
        $db = (new \ReflectionClass(ReserveRetryDb::class))->newInstanceWithoutConstructor();
        $db->failures = $failures;
        $db->tx = $inTx;
        return $db;
    }

    private static function pdoError(string $sqlstate, int $driverCode, string $msg): \PDOException
    {
        $e = new \PDOException("SQLSTATE[$sqlstate]: $msg");
        $e->errorInfo = [$sqlstate, $driverCode, $msg];
        return $e;
    }

    private static function deadlock(): \PDOException
    {
        return self::pdoError('40001', 1213, 'Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
    }

    public function test_a_deadlock_is_retried_and_the_claimed_jobs_come_back(): void
    {
        $db = $this->db([self::deadlock(), self::pdoError('HY000', 1205, 'General error: 1205 Lock wait timeout exceeded')]);
        $jobs = (new DatabaseQueue($db))->reserve('worker-1');
        $this->assertSame(3, $db->updates, 'two conflicts, then the claim that worked');
        $this->assertSame([['id' => 7]], $jobs, 'the SELECT of what was claimed still runs');
    }

    public function test_a_conflict_that_will_not_clear_still_throws_after_the_last_try(): void
    {
        $db = $this->db(array_fill(0, DatabaseQueue::RESERVE_TRIES, self::deadlock()));
        try {
            (new DatabaseQueue($db))->reserve('worker-1');
            $this->fail('expected the deadlock to be thrown');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('1213', $e->getMessage());
        }
        $this->assertSame(DatabaseQueue::RESERVE_TRIES, $db->updates);
    }

    public function test_other_errors_and_conflicts_inside_a_transaction_are_not_retried(): void
    {
        foreach ([[self::pdoError('42S22', 1054, "Column not found: 1054 Unknown column 'x'"), false], [self::deadlock(), true]] as [$err, $inTx]) {
            $db = $this->db([$err], $inTx);
            try { (new DatabaseQueue($db))->reserve('w'); $this->fail('expected a throw'); } catch (\PDOException) {}
            $this->assertSame(1, $db->updates, $inTx ? 'inside a transaction the whole transaction was rolled back' : 'a real error is not a lock conflict');
        }
    }

    public function test_lock_conflicts_are_recognised_by_code_or_message(): void
    {
        $this->assertTrue(DatabaseQueue::isLockConflict(self::deadlock()));
        $this->assertTrue(DatabaseQueue::isLockConflict(new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction')),
            'message only, no errorInfo');
        $byCode = new \PDOException('SQLSTATE[HY000]: General error');
        $byCode->errorInfo = ['HY000', 1205, ''];
        $this->assertTrue(DatabaseQueue::isLockConflict($byCode), 'driver code 1205 alone, nothing in the message');
        $this->assertFalse(DatabaseQueue::isLockConflict(self::pdoError('23000', 1062, 'Duplicate entry')));
        $this->assertFalse(DatabaseQueue::isLockConflict(new \PDOException('SQLSTATE[HY000] [2002] Connection refused')));
    }
}

/** Test double: counts UPDATEs, throws the queued failures first. */
class ReserveRetryDb extends Database
{
    public array $failures = [];
    public bool $tx = false;
    public int $updates = 0;

    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        if (str_starts_with(ltrim($sql), 'UPDATE jobs')) {
            $this->updates++;
            if ($this->failures) throw array_shift($this->failures);
        }
        return new \PDOStatement();
    }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        return [['id' => 7]];
    }

    public function inTransaction(): bool
    {
        return $this->tx;
    }
}
