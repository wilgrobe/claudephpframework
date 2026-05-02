<?php
// tests/Unit/Modules/Subscriptions/SubscriptionServiceTest.php
namespace Tests\Unit\Modules\Subscriptions;

use Core\Database\Database;
use Modules\Subscriptions\Services\SubscriptionService;
use Tests\TestCase;

/**
 * Covers SubscriptionService logic that doesn't require hitting Stripe:
 *   - mapStatus: Stripe's status strings → our narrower enum
 *   - applyWebhookEvent: idempotent insert into subscription_events, and
 *     dispatch routing for known event types
 *
 * Network-touching paths (startCheckout, cancel, resume) are exercised via
 * integration on Will's machine with Stripe test keys.
 */

/**
 * DB double that captures inserts/updates and responds to a narrow set of
 * SELECT shapes the service issues during webhook dispatch.
 */
final class FakeSubsDb extends Database
{
    /** @var array<int, array{table:string, data:array}> */
    public array $inserts = [];
    /** @var array<int, array{table:string, data:array, where:string, bindings:array}> */
    public array $updates = [];
    /** Rows returnable from fetchOne keyed by "$table|$col|$value". */
    public array $rows = [];
    public int $nextId = 1;

    public function __construct() { /* skip parent */ }

    public function insert(string $table, array $data): int
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return $this->nextId++;
    }

    public function update(string $table, array $data, string $where, array $whereBindings = []): int
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where, 'bindings' => $whereBindings];
        return 1;
    }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        // subscription_events idempotency lookup
        if (str_contains($sql, 'FROM subscription_events')) {
            [$gw, $eventId] = $bindings;
            $k = "subscription_events|{$gw}|{$eventId}";
            return $this->rows[$k] ?? null;
        }
        // findSubscriptionByGatewayId
        if (str_contains($sql, 'FROM subscriptions') && str_contains($sql, 'gateway_subscription_id')) {
            [$gw, $subId] = $bindings;
            $k = "subscriptions|{$gw}|{$subId}";
            return $this->rows[$k] ?? null;
        }
        return null;
    }

    public function fetchAll(string $sql, array $bindings = []): array { return []; }
}

final class SubscriptionServiceTest extends TestCase
{
    private FakeSubsDb $db;
    private SubscriptionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeSubsDb();
        $this->mockDatabase($this->db);
        $this->svc = new SubscriptionService();
    }

    // ── mapStatus ─────────────────────────────────────────────────────────

    public function test_mapStatus_translates_stripe_to_local_enum(): void
    {
        $this->assertSame('trial',       $this->svc->mapStatus('trialing'));
        $this->assertSame('active',      $this->svc->mapStatus('active'));
        $this->assertSame('past_due',    $this->svc->mapStatus('past_due'));
        $this->assertSame('past_due',    $this->svc->mapStatus('unpaid'));
        $this->assertSame('canceled',    $this->svc->mapStatus('canceled'));
        $this->assertSame('canceled',    $this->svc->mapStatus('incomplete_expired'));
        $this->assertSame('incomplete',  $this->svc->mapStatus('incomplete'));
        $this->assertSame('incomplete',  $this->svc->mapStatus('something_weird'));
    }

    // ── Webhook dispatch ──────────────────────────────────────────────────

    public function test_applyWebhookEvent_persists_unknown_event_type(): void
    {
        $this->svc->applyWebhookEvent([
            'id'   => 'evt_test_unknown',
            'type' => 'some.event.not.handled',
            'data' => ['object' => ['id' => 'obj_1']],
        ]);

        $this->assertCount(1, $this->db->inserts);
        $row = $this->db->inserts[0];
        $this->assertSame('subscription_events', $row['table']);
        $this->assertSame('some.event.not.handled', $row['data']['event_type']);
        $this->assertSame('evt_test_unknown', $row['data']['event_id']);
    }

    public function test_applyWebhookEvent_is_idempotent_on_duplicate_event_id(): void
    {
        // Seed the dedupe row.
        $this->db->rows['subscription_events|stripe|evt_dup'] = ['id' => 99];

        $this->svc->applyWebhookEvent([
            'id'   => 'evt_dup',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_x', 'status' => 'active']],
        ]);

        $this->assertCount(0, $this->db->inserts,
            'Duplicate events must not double-insert into subscription_events');
    }

    public function test_invoice_payment_failed_marks_past_due(): void
    {
        // Seed existing local subscription.
        $this->db->rows['subscriptions|stripe|sub_42'] = [
            'id' => 77, 'user_id' => 1, 'plan_id' => 1,
            'status' => 'active',
        ];

        $this->svc->applyWebhookEvent([
            'id'   => 'evt_invoice_fail',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'subscription'       => 'sub_42',
                'last_payment_error' => ['message' => 'Your card was declined'],
            ]],
        ]);

        // One update to subscriptions, one insert to events.
        $this->assertCount(1, $this->db->updates);
        $u = $this->db->updates[0];
        $this->assertSame('subscriptions', $u['table']);
        $this->assertSame('past_due', $u['data']['status']);
        $this->assertSame('Your card was declined', $u['data']['last_payment_error']);
        $this->assertSame([77], $u['bindings']);
    }

    public function test_invoice_paid_restores_active_and_clears_error(): void
    {
        $this->db->rows['subscriptions|stripe|sub_42'] = [
            'id' => 77, 'user_id' => 1, 'plan_id' => 1, 'status' => 'past_due',
        ];

        $this->svc->applyWebhookEvent([
            'id'   => 'evt_invoice_paid',
            'type' => 'invoice.paid',
            'data' => ['object' => ['subscription' => 'sub_42']],
        ]);

        $this->assertCount(1, $this->db->updates);
        $u = $this->db->updates[0];
        $this->assertSame('active', $u['data']['status']);
        $this->assertNull($u['data']['last_payment_error']);
    }

    public function test_subscription_deleted_marks_canceled(): void
    {
        $this->db->rows['subscriptions|stripe|sub_42'] = [
            'id' => 77, 'user_id' => 1, 'status' => 'active',
        ];

        $this->svc->applyWebhookEvent([
            'id'   => 'evt_sub_del',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_42']],
        ]);

        $u = $this->db->updates[0];
        $this->assertSame('canceled', $u['data']['status']);
        $this->assertNotNull($u['data']['canceled_at']);
    }

    public function test_subscription_updated_without_metadata_updates_existing(): void
    {
        // No metadata in the payload — fall back to finding by gateway_subscription_id.
        $this->db->rows['subscriptions|stripe|sub_42'] = [
            'id' => 77, 'user_id' => 5, 'plan_id' => 3, 'status' => 'active',
        ];

        $this->svc->applyWebhookEvent([
            'id'   => 'evt_sub_upd',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id'              => 'sub_42',
                'status'          => 'past_due',
                'customer'        => 'cus_5',
                'cancel_at_period_end' => false,
                // no metadata.user_id — service should recover via lookup
            ]],
        ]);

        $this->assertGreaterThan(0, count($this->db->updates),
            'Updated event without metadata should still update the existing row');
    }
}
