<?php
// tests/Unit/Scheduling/SchedulerTest.php
namespace Tests\Unit\Scheduling;

use Core\Database\Database;
use Core\Queue\DatabaseQueue;
use Core\Scheduling\Scheduler;
use Cron\CronExpression;
use Tests\TestCase;

/**
 * Minimal Database double — answers fetchAll/update the way Scheduler::tick
 * expects, holding scheduled_tasks rows in memory.
 */
final class FakeSchedulerDb extends Database
{
    /** @var array<int, array<string, mixed>> */
    public array $tasks = [];

    /** @var array<int, array{0:string, 1:array}> */
    public array $updates = [];

    public function __construct() { /* skip parent */ }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        // The only fetchAll the scheduler issues is the "due tasks" query.
        // Filter by enabled=1 AND (next_run_at IS NULL OR next_run_at <= $nowStr).
        $now = $bindings[0] ?? null;
        return array_values(array_filter($this->tasks, function ($t) use ($now) {
            if (($t['enabled'] ?? 1) != 1) return false;
            $next = $t['next_run_at'] ?? null;
            return $next === null || ($now !== null && $next <= $now);
        }));
    }

    public function update(string $table, array $data, string $where, array $whereBindings = []): int
    {
        $this->updates[] = [$table, $data];
        $id = (int) ($whereBindings[0] ?? 0);
        foreach ($this->tasks as $i => $row) {
            if ((int) $row['id'] === $id) {
                $this->tasks[$i] = array_merge($row, $data);
                return 1;
            }
        }
        return 0;
    }
}

/** Minimal DatabaseQueue double — records pushRaw() calls. */
final class FakeSchedulerQueue extends DatabaseQueue
{
    public array $pushed = [];
    public int $nextId = 100;

    public function __construct() { /* skip parent */ }

    public function pushRaw(
        string $class,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        ?\DateTimeImmutable $availableAt = null,
    ): int {
        $id = $this->nextId++;
        $this->pushed[] = compact('class', 'payload', 'queue', 'maxAttempts') + ['id' => $id];
        return $id;
    }
}

final class SchedulerTest extends TestCase
{
    public function test_computeNextRunAt_returns_null_for_invalid_expression(): void
    {
        if (!class_exists(CronExpression::class)) {
            // Fallback path advances by one minute; it never returns null.
            $this->markTestSkipped('dragonmantank/cron-expression not installed in this environment');
        }

        $s = new Scheduler(new FakeSchedulerDb(), new FakeSchedulerQueue());
        $this->assertNull($s->computeNextRunAt('not a cron', new \DateTimeImmutable('2026-01-01 00:00:00')));
    }

    public function test_computeNextRunAt_every_minute_expression(): void
    {
        if (!class_exists(CronExpression::class)) {
            $this->markTestSkipped('dragonmantank/cron-expression not installed in this environment');
        }

        $s    = new Scheduler(new FakeSchedulerDb(), new FakeSchedulerQueue());
        $from = new \DateTimeImmutable('2026-01-01 10:00:15');
        $next = $s->computeNextRunAt('* * * * *', $from);

        $this->assertNotNull($next);
        // The next firing of "every minute" from 10:00:15 is 10:01:00.
        $this->assertSame('2026-01-01 10:01:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_computeNextRunAt_every_five_minutes_expression(): void
    {
        if (!class_exists(CronExpression::class)) {
            $this->markTestSkipped('dragonmantank/cron-expression not installed in this environment');
        }

        $s    = new Scheduler(new FakeSchedulerDb(), new FakeSchedulerQueue());
        $from = new \DateTimeImmutable('2026-01-01 10:03:00');
        $next = $s->computeNextRunAt('*/5 * * * *', $from);

        $this->assertNotNull($next);
        $this->assertSame('2026-01-01 10:05:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_tick_promotes_due_task_and_advances_next_run_at(): void
    {
        if (!class_exists(CronExpression::class)) {
            $this->markTestSkipped('dragonmantank/cron-expression not installed in this environment');
        }

        $now = new \DateTimeImmutable('2026-01-01 10:00:00');

        $db          = new FakeSchedulerDb();
        $db->tasks[] = [
            'id'                  => 1,
            'name'                => 'retry-messages',
            'class'               => 'Core\\Queue\\Jobs\\CallCommandJob',
            'payload'             => json_encode(['command' => 'retry-messages', 'args' => ['20']]),
            'schedule_expression' => '* * * * *',
            'queue'               => 'default',
            'enabled'             => 1,
            'next_run_at'         => '2026-01-01 10:00:00', // due
        ];

        // A second task that is NOT due — should NOT be promoted.
        $db->tasks[] = [
            'id'                  => 2,
            'name'                => 'future-task',
            'class'               => 'Core\\Queue\\Jobs\\CallCommandJob',
            'payload'             => json_encode(['command' => 'help']),
            'schedule_expression' => '* * * * *',
            'queue'               => 'default',
            'enabled'             => 1,
            'next_run_at'         => '2026-01-01 11:00:00', // not due
        ];

        $queue = new FakeSchedulerQueue();
        $s     = new Scheduler($db, $queue);
        $r     = $s->tick($now);

        $this->assertSame(1, $r['promoted']);
        $this->assertSame(['retry-messages'], $r['names']);
        $this->assertCount(1, $queue->pushed);
        $this->assertSame('Core\\Queue\\Jobs\\CallCommandJob', $queue->pushed[0]['class']);
        $this->assertSame(['command' => 'retry-messages', 'args' => ['20']], $queue->pushed[0]['payload']);

        // Task 1's next_run_at should be advanced to 10:01:00.
        $advanced = null;
        foreach ($db->tasks as $t) {
            if ($t['id'] === 1) { $advanced = $t['next_run_at']; break; }
        }
        $this->assertSame('2026-01-01 10:01:00', $advanced);
    }

    public function test_tick_still_advances_next_run_at_when_enqueue_fails(): void
    {
        if (!class_exists(CronExpression::class)) {
            $this->markTestSkipped('dragonmantank/cron-expression not installed in this environment');
        }

        $now = new \DateTimeImmutable('2026-01-01 10:00:00');

        $db          = new FakeSchedulerDb();
        $db->tasks[] = [
            'id'                  => 1,
            'name'                => 'broken',
            'class'               => 'Core\\Queue\\Jobs\\CallCommandJob',
            'payload'             => json_encode([]),
            'schedule_expression' => '* * * * *',
            'queue'               => 'default',
            'enabled'             => 1,
            'next_run_at'         => '2026-01-01 10:00:00',
        ];

        // Queue double that throws on push — simulates DB down during tick.
        $queue = new class extends DatabaseQueue {
            public function __construct() {}
            public function pushRaw(string $class, array $payload, string $queue = 'default',
                                    int $maxAttempts = 3, ?\DateTimeImmutable $availableAt = null): int {
                throw new \RuntimeException('db down');
            }
        };

        $s = new Scheduler($db, $queue);
        $s->tick($now);

        // next_run_at advanced so a broken rule doesn't refire every tick.
        $this->assertSame('2026-01-01 10:01:00', $db->tasks[0]['next_run_at']);
        $this->assertStringStartsWith('error:', (string) $db->tasks[0]['last_run_status']);
    }
}
