<?php
// tests/Unit/Modules/Scheduling/SchedulingServiceTest.php
namespace Tests\Unit\Modules\Scheduling;

use Core\Database\Database;
use Modules\Scheduling\Services\SchedulingService;
use Tests\TestCase;

/**
 * Unit tests for SchedulingService — focuses on the logic that's
 * testable without a live DB: slot generation math and status
 * validation.
 *
 * Transactional booking flow (book() with SELECT FOR UPDATE) is
 * integration territory — exercised against Will's real MySQL.
 */
final class FakeSchedulingDb extends Database
{
    /** rows keyed by "table|col|value" */
    public array $rows = [];
    /** fetchAll responses keyed by a contains-substring of the SQL */
    public array $lists = [];
    public array $cols = [];

    public function __construct() { /* skip parent */ }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        if (str_contains($sql, 'FROM scheduling_resources WHERE id = ?')) {
            return $this->rows['scheduling_resources|id|' . (int) $bindings[0]] ?? null;
        }
        return null;
    }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        // availabilityFor or availableSlots window lookup
        if (str_contains($sql, 'FROM scheduling_availability')) {
            return $this->lists['availability'] ?? [];
        }
        // bookingCountsForDay
        if (str_contains($sql, 'FROM scheduling_bookings')) {
            return $this->lists['bookings'] ?? [];
        }
        return [];
    }

    public function fetchColumn(string $sql, array $bindings = [], int $col = 0): mixed
    {
        return $this->cols[md5($sql)] ?? 0;
    }
}

final class SchedulingServiceTest extends TestCase
{
    private FakeSchedulingDb $db;
    private SchedulingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeSchedulingDb();
        $this->mockDatabase($this->db);
        $this->svc = new SchedulingService();
    }

    /** Resource row matching the service's shape. */
    private function setupResource(int $duration = 30, int $capacity = 1, string $tz = 'UTC'): void
    {
        $this->db->rows['scheduling_resources|id|1'] = [
            'id' => 1, 'name' => 'Test', 'slug' => 'test', 'description' => null,
            'owner_user_id' => null, 'duration_minutes' => $duration,
            'capacity' => $capacity, 'buffer_minutes' => 0, 'timezone' => $tz,
            'active' => 1, 'public' => 1,
        ];
    }

    // ── availableSlots ────────────────────────────────────────────────────

    public function test_availableSlots_empty_when_resource_inactive(): void
    {
        $this->setupResource();
        $this->db->rows['scheduling_resources|id|1']['active'] = 0;
        $this->assertSame([], $this->svc->availableSlots(1, '2026-06-08'));
    }

    public function test_availableSlots_empty_when_no_windows(): void
    {
        $this->setupResource();
        $this->db->lists['availability'] = [];
        $this->assertSame([], $this->svc->availableSlots(1, '2026-06-08'));
    }

    public function test_availableSlots_generates_duration_sized_chunks(): void
    {
        // Monday Jun 8 2026; 9:00–11:00 with 30-minute duration → 4 slots.
        $this->setupResource(duration: 30);
        $this->db->lists['availability'] = [
            ['start_time' => '09:00:00', 'end_time' => '11:00:00'],
        ];
        $this->db->lists['bookings'] = [];

        $slots = $this->svc->availableSlots(1, '2026-06-08');
        $this->assertCount(4, $slots);
        $this->assertSame('09:00', $slots[0]['start']->format('H:i'));
        $this->assertSame('09:30', $slots[1]['start']->format('H:i'));
        $this->assertSame('10:00', $slots[2]['start']->format('H:i'));
        $this->assertSame('10:30', $slots[3]['start']->format('H:i'));
        $this->assertSame('11:00', $slots[3]['end']->format('H:i'));
    }

    public function test_availableSlots_respects_duration_60(): void
    {
        $this->setupResource(duration: 60);
        $this->db->lists['availability'] = [
            ['start_time' => '09:00:00', 'end_time' => '12:00:00'],
        ];
        $this->db->lists['bookings'] = [];
        $slots = $this->svc->availableSlots(1, '2026-06-08');
        $this->assertCount(3, $slots);
        $this->assertSame('09:00', $slots[0]['start']->format('H:i'));
        $this->assertSame('11:00', $slots[2]['start']->format('H:i'));
    }

    public function test_availableSlots_drops_partial_tail(): void
    {
        // 9:00–10:15 with 30-minute duration → only 2 slots (9:00, 9:30).
        // 10:00 would run to 10:30 which exceeds 10:15, so it's dropped.
        $this->setupResource(duration: 30);
        $this->db->lists['availability'] = [
            ['start_time' => '09:00:00', 'end_time' => '10:15:00'],
        ];
        $this->db->lists['bookings'] = [];
        $slots = $this->svc->availableSlots(1, '2026-06-08');
        $this->assertCount(2, $slots);
    }

    public function test_availableSlots_filters_fully_booked_capacity_1(): void
    {
        $this->setupResource(duration: 30, capacity: 1);
        $this->db->lists['availability'] = [
            ['start_time' => '09:00:00', 'end_time' => '10:00:00'],
        ];
        // 9:00 is fully booked; 9:30 is free.
        $this->db->lists['bookings'] = [
            ['starts_at' => '2026-06-08 09:00:00', 'n' => 1],
        ];

        $slots = $this->svc->availableSlots(1, '2026-06-08');
        $this->assertCount(1, $slots);
        $this->assertSame('09:30', $slots[0]['start']->format('H:i'));
    }

    public function test_availableSlots_capacity_gt_1_reports_remaining(): void
    {
        $this->setupResource(duration: 30, capacity: 5);
        $this->db->lists['availability'] = [
            ['start_time' => '09:00:00', 'end_time' => '10:00:00'],
        ];
        $this->db->lists['bookings'] = [
            ['starts_at' => '2026-06-08 09:00:00', 'n' => 3],
        ];
        $slots = $this->svc->availableSlots(1, '2026-06-08');
        $this->assertCount(2, $slots);
        $this->assertSame(2, $slots[0]['available']); // 5 - 3 = 2 remaining at 09:00
        $this->assertSame(5, $slots[1]['available']); // 9:30 untouched
    }

    public function test_availableSlots_multiple_windows_same_day(): void
    {
        // Split shift: 9-12 and 13-17 (skipping lunch).
        $this->setupResource(duration: 60);
        $this->db->lists['availability'] = [
            ['start_time' => '09:00:00', 'end_time' => '12:00:00'],
            ['start_time' => '13:00:00', 'end_time' => '17:00:00'],
        ];
        $this->db->lists['bookings'] = [];
        $slots = $this->svc->availableSlots(1, '2026-06-08');
        // 3 morning + 4 afternoon = 7
        $this->assertCount(7, $slots);
    }

    public function test_availableSlots_timezone_converts_to_utc_in_slot_objects(): void
    {
        // 9:00 America/New_York on 2026-06-08 = 13:00 UTC (summer, EDT = UTC-4)
        $this->setupResource(duration: 30, tz: 'America/New_York');
        $this->db->lists['availability'] = [
            ['start_time' => '09:00:00', 'end_time' => '09:30:00'],
        ];
        $this->db->lists['bookings'] = [];
        $slots = $this->svc->availableSlots(1, '2026-06-08');
        $this->assertCount(1, $slots);

        $utc = $slots[0]['start']->setTimezone(new \DateTimeZone('UTC'));
        $this->assertSame('2026-06-08 13:00:00', $utc->format('Y-m-d H:i:s'));
    }

    // ── setStatus validation ──────────────────────────────────────────────

    public function test_setStatus_rejects_unknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setStatus(1, 'not-a-status');
    }
}
