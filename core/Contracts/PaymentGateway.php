<?php
// core/Contracts/PaymentGateway.php
namespace Core\Contracts;

/**
 * PaymentGateway — single interface for every payment backend (Stripe /
 * Square / Braintree today, plus any third-party gateway an app developer
 * plugs in).
 *
 * Extension model (see docs/payments-adding-a-gateway.md):
 *   1. Write a service class implementing this interface.
 *   2. Add it to config/payments.php under `drivers`.
 *   3. Set PAYMENT_GATEWAY=<your-name> in .env.
 * No framework edits required.
 *
 * Normalized result shape for every mutating method:
 *   [
 *     'ok'     => bool,           // true when the call succeeded end-to-end
 *     'id'     => string,         // provider-side id (pm_xxx, card_id, token, …)
 *     'status' => string,         // provider-side status or 'ok'/'failed'
 *     'raw'    => array,          // full decoded provider response, for audit
 *     'error'  => string|null,    // short human-readable error when ok=false
 *   ]
 *
 * The `raw` field matters for the audit trail PaymentsService writes to
 * the `payments` table — downstream tooling can reconstruct exactly what
 * the provider said without re-hitting the API.
 *
 * Method groups:
 *   Info             — name(), isEnabled()
 *   Guest charges    — charge()
 *   Vault            — createCustomer(), attachPaymentMethod(),
 *                      listPaymentMethods(), detachPaymentMethod(),
 *                      chargeCustomer()
 *   Refunds          — refund()
 *   Webhooks         — verifyWebhook()
 *
 * A gateway that doesn't support a given operation (e.g. a minimal
 * custom driver that only does one-off charges) should return
 * ['ok' => false, 'error' => 'not_supported', …] rather than throwing —
 * callers are expected to handle the degraded case, not branch on driver
 * type.
 */
interface PaymentGateway
{
    // ── Info ──────────────────────────────────────────────────────────────

    /** Driver name, matching the key in config/payments.php drivers map. */
    public function name(): string;

    /** True when the driver is configured (env/credentials present). */
    public function isEnabled(): bool;

    // ── Guest charges ─────────────────────────────────────────────────────

    /**
     * One-off charge against a raw payment source (Stripe payment_method id,
     * Square nonce, Braintree nonce). No customer/vault involvement.
     *
     * @param int    $amountCents Smallest currency unit (cents)
     * @param string $currency    ISO-4217 code
     * @param string $source      Provider-specific one-use token
     * @param array  $meta        Optional keys: order_id, description, customer_email
     */
    public function charge(int $amountCents, string $currency, string $source, array $meta = []): array;

    // ── Vault ─────────────────────────────────────────────────────────────

    /**
     * Create a customer record at the provider. The `id` returned is the
     * opaque token your app stores (users.gateway_customer_id or similar).
     *
     * @param array $fields Optional: email, name, phone, metadata[]
     */
    public function createCustomer(array $fields): array;

    /**
     * Attach a payment method to a customer (vaulting). Card data stays
     * with the provider; you get back a reusable payment-method token.
     */
    public function attachPaymentMethod(string $customerId, string $source, array $meta = []): array;

    /** List payment methods already vaulted on the customer. */
    public function listPaymentMethods(string $customerId): array;

    /** Detach a payment method. Most providers also support "set default". */
    public function detachPaymentMethod(string $paymentMethodId): array;

    /**
     * Charge a vaulted payment method. The happy path for repeat customers —
     * no card data flows through your server.
     */
    public function chargeCustomer(
        string $customerId,
        string $paymentMethodId,
        int    $amountCents,
        string $currency,
        array  $meta = []
    ): array;

    // ── Refunds ───────────────────────────────────────────────────────────

    /** Refund a prior charge, fully or partially. */
    public function refund(string $chargeId, ?int $amountCents = null): array;

    // ── Webhooks ──────────────────────────────────────────────────────────

    /**
     * Verify a webhook and return the parsed payload.
     *
     * $context is a per-provider escape hatch for signals that don't fit in
     * (payload, signature) — Square signs the full request URL, for example.
     * Common keys:
     *   'url'       — full request URL (Square)
     *   'tolerance' — max allowed clock skew in seconds
     * Drivers that don't need these ignore the array.
     */
    public function verifyWebhook(string $payload, string $signature, array $context = []): ?array;
}
