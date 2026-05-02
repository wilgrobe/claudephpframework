<?php
// tests/Unit/Queue/JobTest.php
namespace Tests\Unit\Queue;

use Core\Queue\Job;
use Tests\TestCase;

/** Sample job with assorted public props for roundtrip testing. */
final class SampleTestJob extends Job
{
    public int    $userId  = 0;
    public string $email   = '';
    public array  $tags    = [];
    public ?int   $parent  = null;
    // Non-public — must NOT appear in the payload.
    protected string $secret = 'hidden';

    public function handle(): void
    {
        // noop for tests
    }
}

final class JobTest extends TestCase
{
    public function test_toPayload_contains_only_public_properties(): void
    {
        $job = new SampleTestJob();
        $job->userId = 42;
        $job->email  = 'a@b.com';
        $job->tags   = ['x', 'y'];
        $job->parent = null;

        $payload = $job->toPayload();

        $this->assertSame([
            'userId' => 42,
            'email'  => 'a@b.com',
            'tags'   => ['x', 'y'],
            'parent' => null,
        ], $payload);
        $this->assertArrayNotHasKey('secret', $payload);
    }

    public function test_fromPayload_restores_public_properties_roundtrip(): void
    {
        $original = new SampleTestJob();
        $original->userId = 7;
        $original->email  = 'will@example.com';
        $original->tags   = ['t1'];
        $original->parent = 99;

        $restored = SampleTestJob::fromPayload($original->toPayload());

        $this->assertInstanceOf(SampleTestJob::class, $restored);
        $this->assertSame(7, $restored->userId);
        $this->assertSame('will@example.com', $restored->email);
        $this->assertSame(['t1'], $restored->tags);
        $this->assertSame(99, $restored->parent);
    }

    public function test_fromPayload_ignores_unknown_keys(): void
    {
        $restored = SampleTestJob::fromPayload([
            'userId'    => 1,
            'ghostProp' => 'should be ignored',
        ]);

        $this->assertSame(1, $restored->userId);
        // Silent drop — no exception, no stray property.
        $this->assertFalse(property_exists($restored, 'ghostProp'));
    }

    public function test_default_queue_and_backoff_progression(): void
    {
        $job = new SampleTestJob();
        $this->assertSame('default', $job->queue());
        $this->assertSame(3, $job->maxAttempts());

        // Backoff grows across attempts, never shrinks.
        $this->assertSame(60,  $job->backoff(1));
        $this->assertSame(300, $job->backoff(2));
        $this->assertSame(900, $job->backoff(3));
        $this->assertSame(900, $job->backoff(10)); // ceiling
    }
}
