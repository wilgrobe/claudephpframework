<?php
// core/Services/StripeService.php
namespace Core\Services;

/**
 * Minimal Stripe API client for applications that want to accept payments
 * without pulling in the full stripe/stripe-php SDK.
 *
 * Scope is deliberately narrow — it gives apps the plumbing they need
 * (API key management, signed request, webhook signature verification)
 * and leaves the product-specific concerns (which prices, which checkout
 * flow, how to model subscriptions) to the app itself.
 *
 * Disabled when STRIPE_SECRET_KEY is empty; every method returns a safe
 * null / false so callers can gate feature availability on isEnabled().
 *
 * Typical usage:
 *   $stripe = new StripeService();
 *   if ($stripe->isEnabled()) {
 *       $session = $stripe->call('POST', '/v1/checkout/sessions', [
 *           'mode'         => 'payment',
 *           'line_items[0][price]'    => 'price_abc',
 *           'line_items[0][quantity]' => 1,
 *           'success_url'  => config('app.url') . '/billing/success',
 *           'cancel_url'   => config('app.url') . '/billing/cancel',
 *       ]);
 *       // $session['url'] is the redirect target
 *   }
 *
 * Webhook verification:
 *   $event = $stripe->verifyWebhook($request->raw(), $_SERVER['HTTP_STRIPE_SIGNATURE']);
 *   if (!$event) return 400;
 */
class StripeService implements \Core\Contracts\PaymentGateway
{
    private array $config;

    public function __construct()
    {
        // Pull from env directly; no DB roundtrip.
        $this->config = [
            'secret_key'     => trim((string) ($_ENV['STRIPE_SECRET_KEY']     ?? '')),
            'public_key'     => trim((string) ($_ENV['STRIPE_PUBLIC_KEY']     ?? '')),
            'webhook_secret' => trim((string) ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? '')),
        ];
    }

    public function name(): string { return 'stripe'; }

    public function isEnabled(): bool
    {
        return $this->config['secret_key'] !== '';
    }

    public function publicKey(): string
    {
        return $this->config['public_key'];
    }

    // ── PaymentGateway surface ────────────────────────────────────────────

    public function charge(int $amountCents, string $currency, string $source, array $meta = []): array
    {
        // PaymentIntent with confirm=true is Stripe's modern single-call
        // charge. $source is a pm_xxx payment-method id (client-side collected
        // via Stripe.js / Elements). SCA/3DS is handled transparently — when
        // additional action is required, status comes back as
        // 'requires_action' and the caller redirects to next_action.
        $body = array_merge([
            'amount'              => $amountCents,
            'currency'            => strtolower($currency),
            'payment_method'      => $source,
            'confirm'             => 'true',
            'automatic_payment_methods[enabled]'         => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
        ], $this->flattenMeta($meta));

        return $this->normalize($this->call('POST', '/v1/payment_intents', $body));
    }

    public function chargeCustomer(
        string $customerId,
        string $paymentMethodId,
        int    $amountCents,
        string $currency,
        array  $meta = []
    ): array {
        // off_session=true tells Stripe this is a merchant-initiated charge
        // against a previously-authorized payment method, which affects how
        // banks treat SCA exemptions.
        $body = array_merge([
            'amount'              => $amountCents,
            'currency'            => strtolower($currency),
            'customer'            => $customerId,
            'payment_method'      => $paymentMethodId,
            'confirm'             => 'true',
            'off_session'         => 'true',
            'automatic_payment_methods[enabled]'         => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
        ], $this->flattenMeta($meta));

        return $this->normalize($this->call('POST', '/v1/payment_intents', $body));
    }

    public function createCustomer(array $fields): array
    {
        $body = [];
        foreach (['email', 'name', 'phone', 'description'] as $k) {
            if (!empty($fields[$k])) $body[$k] = $fields[$k];
        }
        $body = array_merge($body, $this->flattenMeta(['metadata' => $fields['metadata'] ?? []]));

        return $this->normalize($this->call('POST', '/v1/customers', $body), statusOverride: 'ok');
    }

    public function attachPaymentMethod(string $customerId, string $source, array $meta = []): array
    {
        // $source is a pm_xxx payment-method id, typically from Stripe.js
        // SetupIntent or Elements. Attach binds it to the customer so future
        // chargeCustomer() calls can reuse it.
        $res = $this->call('POST', "/v1/payment_methods/$source/attach", [
            'customer' => $customerId,
        ]);
        return $this->normalize($res, statusOverride: 'ok');
    }

    public function listPaymentMethods(string $customerId): array
    {
        $res = $this->call('GET', '/v1/payment_methods', [
            'customer' => $customerId,
            'type'     => 'card',
            'limit'    => 20,
        ]);
        if (!is_array($res) || !isset($res['data'])) {
            return ['ok' => false, 'id' => '', 'status' => 'failed', 'raw' => $res ?? [], 'error' => 'list_failed'];
        }
        $methods = array_map(static fn(array $pm) => [
            'id'    => (string) ($pm['id'] ?? ''),
            'brand' => (string) ($pm['card']['brand'] ?? ''),
            'last4' => (string) ($pm['card']['last4'] ?? ''),
            'exp'   => sprintf('%02d/%04d',
                (int) ($pm['card']['exp_month'] ?? 0),
                (int) ($pm['card']['exp_year']  ?? 0)
            ),
        ], $res['data']);

        return [
            'ok'      => true,
            'id'      => '',
            'status'  => 'ok',
            'raw'     => $res,
            'methods' => $methods,
            'error'   => null,
        ];
    }

    public function detachPaymentMethod(string $paymentMethodId): array
    {
        return $this->normalize(
            $this->call('POST', "/v1/payment_methods/$paymentMethodId/detach"),
            statusOverride: 'ok'
        );
    }

    public function refund(string $chargeId, ?int $amountCents = null): array
    {
        // Accept either a payment_intent (pi_) or a charge (ch_). Stripe's
        // refunds endpoint takes either under different keys.
        $body = str_starts_with($chargeId, 'pi_')
            ? ['payment_intent' => $chargeId]
            : ['charge' => $chargeId];
        if ($amountCents !== null) $body['amount'] = $amountCents;

        return $this->normalize($this->call('POST', '/v1/refunds', $body));
    }

    /**
     * Make an HTTP call against the Stripe API. Returns the decoded JSON
     * response on 2xx, or null on any failure (auth, network, 4xx/5xx).
     * Errors are logged so the app's logs capture the response body; the
     * caller just sees null and degrades gracefully.
     *
     * @param string $method HTTP verb (GET / POST / DELETE)
     * @param string $path   API path starting with / (e.g. /v1/customers)
     * @param array  $body   Form-encoded parameters; Stripe uses
     *                       application/x-www-form-urlencoded with bracketed
     *                       keys for nested fields.
     */
    public function call(string $method, string $path, array $body = []): ?array
    {
        if (!$this->isEnabled()) return null;

        $url = 'https://api.stripe.com' . $path;
        $headers = [
            'Authorization: Bearer ' . $this->config['secret_key'],
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2024-06-20',
        ];

        $ctx = [
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $headers) . "\r\n",
                'content'       => $method === 'GET' ? '' : http_build_query($body, '', '&', PHP_QUERY_RFC1738),
                'timeout'       => 15.0,
                'ignore_errors' => true, // read body on 4xx/5xx for error messages
            ],
        ];
        if ($method === 'GET' && !empty($body)) {
            $url .= '?' . http_build_query($body);
        }

        $raw = @file_get_contents($url, false, stream_context_create($ctx));
        if ($raw === false) {
            error_log('[stripe] network failure calling ' . $path);
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log('[stripe] non-JSON response from ' . $path);
            return null;
        }
        if (isset($data['error'])) {
            error_log('[stripe] ' . ($data['error']['type'] ?? 'error') . ' calling ' . $path
                . ': ' . ($data['error']['message'] ?? ''));
            return null;
        }
        return $data;
    }

    /**
     * Verify a Stripe webhook signature and return the decoded event on
     * success, or null on any failure (missing secret, bad signature,
     * stale timestamp, malformed body).
     *
     * The signature scheme is documented at
     * https://stripe.com/docs/webhooks/signatures. We implement it
     * directly so apps don't need the SDK.
     *
     * Calls to this method are safe under replay attacks because the
     * timestamp in the header must be within $toleranceSeconds of now.
     */
    public function verifyWebhook(string $payload, string $signature, array $context = []): ?array
    {
        $secret    = $this->config['webhook_secret'];
        $tolerance = (int) ($context['tolerance'] ?? 300);
        if ($secret === '' || $payload === '' || $signature === '') return null;

        // Header format: t=<timestamp>,v1=<hex>,v1=<hex>,v0=<hex>
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signature) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') $timestamp = (int) $v;
            if ($k === 'v1') $signatures[] = $v;
        }
        if ($timestamp === null || empty($signatures)) return null;
        if (abs(time() - $timestamp) > $tolerance) return null;

        $signed  = $timestamp . '.' . $payload;
        $expect  = hash_hmac('sha256', $signed, $secret);

        $ok = false;
        foreach ($signatures as $s) {
            if (hash_equals($expect, $s)) { $ok = true; break; }
        }
        if (!$ok) return null;

        $event = json_decode($payload, true);
        return is_array($event) ? $event : null;
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * Collapse a normalized Stripe response into the PaymentGateway result
     * shape. Stripe returns fairly rich objects — we pick id + status + keep
     * the raw for audit, and set ok according to status.
     */
    private function normalize(?array $res, ?string $statusOverride = null): array
    {
        if ($res === null) {
            return ['ok' => false, 'id' => '', 'status' => 'failed', 'raw' => [], 'error' => 'request_failed'];
        }
        $id     = (string) ($res['id'] ?? '');
        $status = $statusOverride ?? (string) ($res['status'] ?? 'unknown');
        // PaymentIntent success statuses: 'succeeded', 'processing' (async),
        // 'requires_action' (3DS redirect needed). We treat the first two as
        // ok=true; the caller inspects status to branch.
        $ok = in_array($status, ['succeeded', 'processing', 'ok'], true);
        return [
            'ok'     => $ok,
            'id'     => $id,
            'status' => $status,
            'raw'    => $res,
            'error'  => $ok ? null : (string) ($res['last_payment_error']['message'] ?? $status),
        ];
    }

    /**
     * Flatten an app-level metadata array into Stripe's form-encoded
     * metadata[key] = value idiom. Skip nested non-scalars — Stripe rejects
     * them anyway.
     */
    private function flattenMeta(array $meta): array
    {
        $out = [];
        $metaSubset = $meta['metadata'] ?? null;
        if (is_array($metaSubset)) {
            foreach ($metaSubset as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $out["metadata[$k]"] = (string) $v;
                }
            }
        }
        // Common top-level pass-throughs used by Stripe.
        foreach (['description', 'statement_descriptor', 'receipt_email'] as $k) {
            if (isset($meta[$k]) && is_scalar($meta[$k])) $out[$k] = (string) $meta[$k];
        }
        return $out;
    }
}
