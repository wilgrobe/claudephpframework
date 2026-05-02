<?php
// tests/Unit/Services/PaymentsServiceTest.php
namespace Tests\Unit\Services;

use Core\Contracts\PaymentGateway;
use Core\Database\Database;
use Core\Services\PaymentsService;
use Tests\TestCase;

/** In-memory gateway for deterministic tests. */
final class FakePaymentGateway implements PaymentGateway
{
    /** Preset response for the next mutating call. */
    public array $nextResponse = ['ok' => true, 'id' => 'obj_1', 'status' => 'ok', 'raw' => [], 'error' => null];

    /** @var array<int, array{method:string, args:array}> */
    public array $calls = [];

    public function name(): string { return 'fake'; }
    public function isEnabled(): bool { return true; }

    public function charge(int $amountCents, string $currency, string $source, array $meta = []): array
    {
        $this->calls[] = ['method' => 'charge', 'args' => compact('amountCents','currency','source','meta')];
        return $this->nextResponse;
    }
    public function chargeCustomer(string $customerId, string $paymentMethodId, int $amountCents, string $currency, array $meta = []): array
    {
        $this->calls[] = ['method' => 'chargeCustomer', 'args' => compact('customerId','paymentMethodId','amountCents','currency','meta')];
        return $this->nextResponse;
    }
    public function createCustomer(array $fields): array
    {
        $this->calls[] = ['method' => 'createCustomer', 'args' => compact('fields')];
        return $this->nextResponse;
    }
    public function attachPaymentMethod(string $customerId, string $source, array $meta = []): array
    {
        $this->calls[] = ['method' => 'attachPaymentMethod', 'args' => compact('customerId','source','meta')];
        return $this->nextResponse;
    }
    public function listPaymentMethods(string $customerId): array
    {
        $this->calls[] = ['method' => 'listPaymentMethods', 'args' => compact('customerId')];
        return ['ok' => true, 'id' => '', 'status' => 'ok', 'raw' => [], 'methods' => [], 'error' => null];
    }
    public function detachPaymentMethod(string $paymentMethodId): array
    {
        $this->calls[] = ['method' => 'detachPaymentMethod', 'args' => compact('paymentMethodId')];
        return $this->nextResponse;
    }
    public function refund(string $chargeId, ?int $amountCents = null): array
    {
        $this->calls[] = ['method' => 'refund', 'args' => compact('chargeId','amountCents')];
        return $this->nextResponse;
    }
    public function verifyWebhook(string $payload, string $signature, array $context = []): ?array
    {
        return null;
    }
}

/**
 * Database double — records insert() calls, ignores everything else.
 * Bypasses parent ctor (no PDO handshake).
 */
final class FakePaymentsDb extends Database
{
    /** @var array<int, array{table:string, data:array}> */
    public array $inserts = [];
    public bool $shouldThrow = false;

    public function __construct() { /* skip parent */ }

    public function insert(string $table, array $data): int
    {
        if ($this->shouldThrow) throw new \RuntimeException('db down');
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return count($this->inserts);
    }
}

final class PaymentsServiceTest extends TestCase
{
    public function test_charge_writes_audit_row_and_returns_gateway_result(): void
    {
        $gw  = new FakePaymentGateway();
        $gw->nextResponse = ['ok' => true, 'id' => 'pi_123', 'status' => 'succeeded',
                              'raw' => ['amount' => 1000], 'error' => null];
        $db  = new FakePaymentsDb();
        $svc = new PaymentsService($gw, $db);

        $res = $svc->charge(1000, 'USD', 'pm_abc', userId: 42, meta: ['order_id' => 99]);

        // Gateway call was forwarded unchanged.
        $this->assertSame('pi_123', $res['id']);
        $this->assertTrue($res['ok']);

        // Exactly one audit row.
        $this->assertCount(1, $db->inserts);
        $row = $db->inserts[0]['data'];
        $this->assertSame('payments', $db->inserts[0]['table']);
        $this->assertSame('fake',   $row['gateway']);
        $this->assertSame('charge', $row['operation']);
        $this->assertSame(42,       $row['user_id']);
        $this->assertSame('pi_123', $row['gateway_id']);
        $this->assertSame('pm_abc', $row['source_ref']);
        $this->assertSame(1000,     $row['amount_cents']);
        $this->assertSame('USD',    $row['currency']);
        $this->assertSame(1,        $row['ok']);
        $this->assertSame('succeeded', $row['status']);
        $this->assertNull($row['error']);
    }

    public function test_failed_charge_still_writes_audit_row(): void
    {
        $gw = new FakePaymentGateway();
        $gw->nextResponse = ['ok' => false, 'id' => '', 'status' => 'card_declined',
                              'raw' => ['error_code' => 'card_declined'], 'error' => 'Your card was declined'];
        $db = new FakePaymentsDb();

        $res = (new PaymentsService($gw, $db))->charge(500, 'USD', 'pm_bad');

        $this->assertFalse($res['ok']);
        $this->assertCount(1, $db->inserts, 'Failures are the most important case to audit');
        $this->assertSame(0, $db->inserts[0]['data']['ok']);
        $this->assertSame('card_declined', $db->inserts[0]['data']['status']);
        $this->assertSame('Your card was declined', $db->inserts[0]['data']['error']);
    }

    public function test_listPaymentMethods_does_not_audit(): void
    {
        // Reads are high-frequency and noisy — the service deliberately
        // skips audit on list. If this ever changes (e.g. compliance demand
        // full call logging), update this test with the new expectation.
        $gw  = new FakePaymentGateway();
        $db  = new FakePaymentsDb();
        $res = (new PaymentsService($gw, $db))->listPaymentMethods('cus_1');

        $this->assertTrue($res['ok']);
        $this->assertSame([], $db->inserts);
    }

    public function test_chargeCustomer_records_customer_ref_and_source_ref(): void
    {
        $gw  = new FakePaymentGateway();
        $gw->nextResponse = ['ok' => true, 'id' => 'pi_456', 'status' => 'succeeded', 'raw' => [], 'error' => null];
        $db  = new FakePaymentsDb();

        (new PaymentsService($gw, $db))->chargeCustomer('cus_99', 'pm_xyz', 2500, 'eur', userId: 7);

        $row = $db->inserts[0]['data'];
        $this->assertSame('charge_customer', $row['operation']);
        $this->assertSame('cus_99',          $row['customer_ref']);
        $this->assertSame('pm_xyz',          $row['source_ref']);
        $this->assertSame(2500,              $row['amount_cents']);
        $this->assertSame('eur',             $row['currency']);
    }

    public function test_refund_records_charge_id_as_source_ref(): void
    {
        $gw = new FakePaymentGateway();
        $gw->nextResponse = ['ok' => true, 'id' => 're_1', 'status' => 'succeeded', 'raw' => [], 'error' => null];
        $db = new FakePaymentsDb();

        (new PaymentsService($gw, $db))->refund('pi_123', 500, userId: 42);

        $row = $db->inserts[0]['data'];
        $this->assertSame('refund', $row['operation']);
        $this->assertSame('re_1',   $row['gateway_id']);
        $this->assertSame('pi_123', $row['source_ref']);
        $this->assertSame(500,      $row['amount_cents']);
    }

    public function test_meta_sanitization_redacts_sensitive_keys(): void
    {
        $gw = new FakePaymentGateway();
        $db = new FakePaymentsDb();

        (new PaymentsService($gw, $db))->charge(100, 'USD', 'pm_x', meta: [
            'order_id'    => 1,
            'card_number' => '4242424242424242',   // should never be here, but just in case
            'nested'      => ['cvv' => '123', 'ok' => 'yes'],
        ]);

        $request = json_decode($db->inserts[0]['data']['request_json'], true);
        $this->assertSame(1, $request['order_id']);
        $this->assertSame('[REDACTED]', $request['card_number']);
        $this->assertSame('[REDACTED]', $request['nested']['cvv']);
        $this->assertSame('yes',        $request['nested']['ok']);
    }

    public function test_audit_write_failure_does_not_propagate(): void
    {
        // If the DB is down, the gateway call still happened and the caller
        // needs to see the result. We surface nothing; error_log picks up
        // the warning for ops.
        $gw = new FakePaymentGateway();
        $db = new FakePaymentsDb();
        $db->shouldThrow = true;

        $res = (new PaymentsService($gw, $db))->charge(100, 'USD', 'pm_x');
        $this->assertTrue($res['ok'], 'Caller must see the gateway result even if audit fails');
    }

    public function test_stripe_service_implements_contract(): void
    {
        $this->assertContains(
            PaymentGateway::class,
            class_implements(\Core\Services\StripeService::class) ?: [],
            'StripeService must implement PaymentGateway'
        );
    }

    public function test_square_service_implements_contract(): void
    {
        $this->assertContains(
            PaymentGateway::class,
            class_implements(\Core\Services\SquareService::class) ?: [],
            'SquareService must implement PaymentGateway'
        );
    }

    public function test_braintree_service_implements_contract(): void
    {
        $this->assertContains(
            PaymentGateway::class,
            class_implements(\Core\Services\BraintreeService::class) ?: [],
            'BraintreeService must implement PaymentGateway'
        );
    }

    public function test_braintree_unsupported_methods_return_structured_error(): void
    {
        // BraintreeService intentionally returns ok=false with
        // status=not_supported rather than throwing for vault/charge ops.
        // This contract is what lets callers detect the gap and degrade.
        $bt = (new \ReflectionClass(\Core\Services\BraintreeService::class))->newInstanceWithoutConstructor();
        // Manually populate $config so isEnabled semantics don't matter for this test.
        $ref = new \ReflectionObject($bt);
        $prop = $ref->getProperty('config');
        $prop->setValue($bt, [
            'merchant_id' => 'x', 'public_key' => 'x',
            'private_key' => 'x', 'environment' => 'sandbox',
        ]);

        /** @var PaymentGateway $bt */
        $res = $bt->charge(100, 'USD', 'nonce');
        $this->assertFalse($res['ok']);
        $this->assertSame('not_supported', $res['status']);
        $this->assertNotEmpty($res['error']);
    }
}
