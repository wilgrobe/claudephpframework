<?php
// tests/Unit/Queue/WorkerTest.php
namespace Tests\Unit\Queue;

use Core\Queue\DatabaseQueue;
use Core\Queue\Job;
use Core\Queue\Worker;
use Tests\TestCase;

/** Job that always succeeds. */
final class AlwaysOkJob extends Job
{
    public static int $runs = 0;
    public int $x = 0;
    public function handle(): void { self::$runs++; }
}

/** Job that always throws. */
final class AlwaysFailJob extends Job
{
    public int $x = 0;
    public function handle(): void { throw new \RuntimeException('boom'); }
}

/**
 * Fake queue that swaps DB I/O for in-memory arrays so runBatch's
 * orchestration is observable without a real database.
 */
final class FakeQueue extends DatabaseQueue
{
    /** Rows that the next reserve() call should hand out. */
    public array $nextReserved = [];
    /** @var int[] */
    public array $completed = [];
    /** @var array<int, array{0:int, 1:\DateTimeImmutable, 2:string}> */
    public array $released = [];
    /** @var array<int, array{0:int, 1:string}> */
    public array $failed = [];

    public function __construct() { /* skip parent — no DB */ }

    public function reserve(string $workerId, string $queue = 'default', int $limit = 10): array
    {
        $take = array_slice($this->nextReserved, 0, $limit);
        $this->nextReserved = array_slice($this->nextReserved, $limit);
        return $take;
    }

    public function complete(int $jobId): void { $this->completed[] = $jobId; }
    public function release(int $jobId, \DateTimeImmutable $at, string $err): void
    {
        $this->released[] = [$jobId, $at, $err];
    }
    public function fail(int $jobId, string $err): void { $this->failed[] = [$jobId, $err]; }
}

final class WorkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AlwaysOkJob::$runs = 0;
    }

    public function test_successful_job_is_completed(): void
    {
        $q = new FakeQueue();
        $q->nextReserved = [[
            'id'           => 10,
            'class'        => AlwaysOkJob::class,
            'payload'      => json_encode(['x' => 5]),
            'attempts'     => 1,
            'max_attempts' => 3,
        ]];

        $w = new Worker($q, 'test-worker');
        $r = $w->runBatch('default', 5);

        $this->assertSame(1, AlwaysOkJob::$runs);
        $this->assertSame([10], $q->completed);
        $this->assertSame([], $q->released);
        $this->assertSame([], $q->failed);
        $this->assertSame(['picked' => 1, 'succeeded' => 1, 'failed' => 0, 'released' => 0, 'ids' => [10]], $r);
    }

    public function test_transient_failure_releases_with_backoff(): void
    {
        $q = new FakeQueue();
        $q->nextReserved = [[
            'id'           => 11,
            'class'        => AlwaysFailJob::class,
            'payload'      => json_encode(['x' => 0]),
            'attempts'     => 1,               // post-reserve() increment
            'max_attempts' => 3,
        ]];

        $w = new Worker($q, 'test-worker');
        $w->runBatch();

        $this->assertSame([], $q->completed);
        $this->assertSame([], $q->failed);
        $this->assertCount(1, $q->released);
        [$id, $availableAt, $error] = $q->released[0];
        $this->assertSame(11, $id);
        $this->assertStringContainsString('boom', $error);
        // Default backoff for attempt 1 is 60s — released time should be roughly now+60s.
        $delta = $availableAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        $this->assertGreaterThanOrEqual(55, $delta);
        $this->assertLessThanOrEqual(65, $delta);
    }

    public function test_attempts_exhausted_marks_failed(): void
    {
        $q = new FakeQueue();
        $q->nextReserved = [[
            'id'           => 12,
            'class'        => AlwaysFailJob::class,
            'payload'      => json_encode(['x' => 0]),
            'attempts'     => 3,
            'max_attempts' => 3,
        ]];

        $w = new Worker($q, 'test-worker');
        $r = $w->runBatch();

        $this->assertSame([], $q->completed);
        $this->assertSame([], $q->released);
        $this->assertCount(1, $q->failed);
        [$id, $error] = $q->failed[0];
        $this->assertSame(12, $id);
        $this->assertStringContainsString('boom', $error);
        $this->assertSame(1, $r['failed']);
    }

    public function test_bad_class_fails_the_job(): void
    {
        $q = new FakeQueue();
        $q->nextReserved = [[
            'id'           => 13,
            'class'        => 'Tests\\Unit\\Queue\\ClassThatDoesNotExist',
            'payload'      => json_encode([]),
            'attempts'     => 3,
            'max_attempts' => 3,
        ]];

        $w = new Worker($q, 'test-worker');
        $w->runBatch();

        $this->assertCount(1, $q->failed);
        $this->assertStringContainsString('does not exist', $q->failed[0][1]);
    }

    public function test_non_job_class_is_rejected(): void
    {
        $q = new FakeQueue();
        $q->nextReserved = [[
            'id'           => 14,
            'class'        => \stdClass::class,
            'payload'      => json_encode([]),
            'attempts'     => 3,
            'max_attempts' => 3,
        ]];

        $w = new Worker($q, 'test-worker');
        $w->runBatch();

        $this->assertCount(1, $q->failed);
        $this->assertStringContainsString('not a Core\\Queue\\Job', $q->failed[0][1]);
    }
}
