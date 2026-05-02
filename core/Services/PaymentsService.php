<?php
// core/Services/PaymentsService.php
namespace Core\Services;

use Core\Contracts\PaymentGateway;
use Core\Database\Database;

/**
 * PaymentsService — thin wrapper over the active PaymentGateway that writes
 * an audit row to the `payments` table for every call.
 *
 * App code should prefer calling PaymentsService over the raw gateway so
 * the audit trail is complete. Calling the gateway directly is fine but
 * leaves no `payments` row.
 *
 * Usage:
 *   $res = app(PaymentsService::class)->charge(2000, 'USD', $stripePm, $userId);
 *   if (!$res['ok']) { ... }  // handle failure
 *
 * Every method mirrors a PaymentGateway method and returns the gateway's
 * normalized result unchanged. The audit write happens on both success
 * AND failure — failures are the most important thing to capture.
 */
class PaymentsService
{
    public function __construct(
        private PaymentGateway $gateway,
        private Database $db,
    ) {}

    // ── Guest charges ─────────────────────────────────────────────────────

    public function charge(
        int    $amountCents,
        string $currency,
        string $source,
        ?int   $userId = null,
        array  $meta = []
    ): array {
        $res = $this->gateway->charge($amountCents, $currency, $source, $meta);
        $this->log('charge', $userId, $amountCents, $currency, source: $source, res: $res, meta: $meta);
        return $res;
    }

    // ── Vault ─────────────────────────────────────────────────────────────

    public function createCustomer(array $fields, ?int $userId = null): array
    {
        $res = $this->gateway->createCustomer($fields);
        $this->log('create_customer', $userId, null, null, res: $res, meta: $fields);
        return $res;
    }

    public function attachPaymentMethod(
        string $customerId,
        string $source,
        ?int   $userId = null,
        array  $meta = []
    ): array {
        $res = $this->gateway->attachPaymentMethod($customerId, $source, $meta);
        $this->log('attach_payment_method', $userId, null, null,
            source: $source, customer: $customerId, res: $res, meta: $meta);
        return $res;
    }

    /**
     * List payment methods. Does NOT write an audit row (reads are cheap,
     * high-frequency, and create log noise that obscures mutations).
     */
    public function listPaymentMethods(string $customerId): array
    {
        return $this->gateway->listPaymentMethods($customerId);
    }

    public function detachPaymentMethod(string $paymentMethodId, ?int $userId = null): array
    {
        $res = $this->gateway->detachPaymentMethod($paymentMethodId);
        $this->log('detach_payment_method', $userId, null, null,
            source: $paymentMethodId, res: $res);
        return $res;
    }

    public function chargeCustomer(
        string $customerId,
        string $paymentMethodId,
        int    $amountCents,
        string $currency,
        ?int   $userId = null,
        array  $meta = []
    ): array {
        $res = $this->gateway->chargeCustomer($customerId, $paymentMethodId, $amountCents, $currency, $meta);
        $this->log('charge_customer', $userId, $amountCents, $currency,
            source: $paymentMethodId, customer: $customerId, res: $res, meta: $meta);
        return $res;
    }

    // ── Refunds ───────────────────────────────────────────────────────────

    public function refund(string $chargeId, ?int $amountCents = null, ?int $userId = null): array
    {
        $res = $this->gateway->refund($chargeId, $amountCents);
        // `source` doubles as the charge being refunded — useful for joining
        // refund rows back to their originating charge row via gateway_id.
        $this->log('refund', $userId, $amountCents, null, source: $chargeId, res: $res);
        return $res;
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * Insert one audit row. `meta` is the request-shape input we'd want to
     * see when investigating — sanitized (no secrets, no PII beyond what
     * the app already stores in its own DB).
     */
    private function log(
        string  $operation,
        ?int    $userId,
        ?int    $amountCents,
        ?string $currency,
        ?string $source   = null,
        ?string $customer = null,
        array   $res      = [],
        array   $meta     = [],
    ): void {
        try {
            $this->db->insert('payments', [
                'gateway'       => $this->gateway->name(),
                'operation'     => $operation,
                'user_id'       => $userId,
                'gateway_id'    => substr((string) ($res['id'] ?? ''), 0, 191),
                'customer_ref'  => $customer !== null ? substr($customer, 0, 191) : null,
                'source_ref'    => $source   !== null ? substr($source,   0, 191) : null,
                'amount_cents'  => $amountCents,
                'currency'      => $currency ? substr($currency, 0, 8) : null,
                'ok'            => !empty($res['ok']) ? 1 : 0,
                'status'        => substr((string) ($res['status'] ?? 'unknown'), 0, 64),
                'error'         => isset($res['error']) && $res['error'] !== null
                    ? substr((string) $res['error'], 0, 500) : null,
                'request_json'  => json_encode($this->sanitizeMeta($meta), JSON_UNESCAPED_SLASHES),
                'response_json' => json_encode($res['raw'] ?? $res, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
        } catch (\Throwable $e) {
            // Audit-write failure must NEVER mask a successful gateway call.
            // Log to error_log so ops sees the problem and keep moving.
            error_log('[payments] audit write failed: ' . $e->getMessage());
        }
    }

    /**
     * Strip known-sensitive keys from the meta before persisting. Callers
     * shouldn't be passing raw card numbers, but defense in depth — even
     * accidental logging of a CVV in a debug dump is bad.
     */
    private function sanitizeMeta(array $meta): array
    {
        $banned = ['card_number', 'cvv', 'cvc', 'pin', 'password', 'secret'];
        $walk = function (array $in) use (&$walk, $banned): array {
            $out = [];
            foreach ($in as $k => $v) {
                if (in_array(strtolower((string) $k), $banned, true)) {
                    $out[$k] = '[REDACTED]';
                } elseif (is_array($v)) {
                    $out[$k] = $walk($v);
                } else {
                    $out[$k] = $v;
                }
            }
            return $out;
        };
        return $walk($meta);
    }
}
