<?php
// core/Services/SquareService.php
namespace Core\Services;

/**
 * Minimal Square API client, parallel in shape to StripeService.
 *
 * Square's REST API uses a Bearer token (SQUARE_ACCESS_TOKEN). The
 * environment (sandbox / production) selects the host, and most
 * merchants also need a location id for payment operations.
 *
 * Webhook signature verification uses an HMAC-SHA256 over the full
 * request URL + body, keyed by SQUARE_WEBHOOK_SIGNATURE_KEY.
 *
 * isEnabled() gates feature availability. Every method no-ops
 * gracefully when the service isn't configured.
 */
class SquareService implements \Core\Contracts\PaymentGateway
{
    private array $config;

    public function __construct()
    {
        $this->config = [
            'access_token'          => trim((string) ($_ENV['SQUARE_ACCESS_TOKEN']         ?? '')),
            'application_id'        => trim((string) ($_ENV['SQUARE_APPLICATION_ID']       ?? '')),
            'location_id'           => trim((string) ($_ENV['SQUARE_LOCATION_ID']          ?? '')),
            'environment'           => strtolower(trim((string) ($_ENV['SQUARE_ENVIRONMENT'] ?? 'sandbox'))),
            'webhook_signature_key' => trim((string) ($_ENV['SQUARE_WEBHOOK_SIGNATURE_KEY'] ?? '')),
        ];
    }

    public function name(): string { return 'square'; }

    public function isEnabled(): bool
    {
        return $this->config['access_token'] !== '' && $this->config['location_id'] !== '';
    }

    public function environment(): string
    {
        return $this->config['environment'] === 'production' ? 'production' : 'sandbox';
    }

    private function host(): string
    {
        return $this->environment() === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    public function locationId(): string
    {
        return $this->config['location_id'];
    }

    /**
     * Make a call against the Square API. Returns the decoded JSON
     * response on success (2xx), null on failure. Errors are logged.
     *
     * @param string $method HTTP verb
     * @param string $path   API path starting with / (e.g. /v2/payments)
     * @param array  $body   JSON body, assoc array
     */
    public function call(string $method, string $path, array $body = []): ?array
    {
        if (!$this->isEnabled()) return null;

        $url = $this->host() . $path;
        $headers = [
            'Authorization: Bearer ' . $this->config['access_token'],
            'Content-Type: application/json',
            'Square-Version: 2024-10-17',
        ];

        $ctx = [
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $headers) . "\r\n",
                'timeout'       => 15.0,
                'ignore_errors' => true,
            ],
        ];
        if (!empty($body) && $method !== 'GET') {
            $ctx['http']['content'] = json_encode($body);
        }
        if ($method === 'GET' && !empty($body)) {
            $url .= '?' . http_build_query($body);
        }

        $raw = @file_get_contents($url, false, stream_context_create($ctx));
        if ($raw === false) {
            error_log('[square] network failure calling ' . $path);
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log('[square] non-JSON response from ' . $path);
            return null;
        }
        if (!empty($data['errors'])) {
            $firstErr = $data['errors'][0] ?? [];
            error_log('[square] ' . ($firstErr['category'] ?? 'error') . ' calling ' . $path
                . ': ' . ($firstErr['detail'] ?? ''));
            return null;
        }
        return $data;
    }

    /**
     * Verify a Square webhook signature. Square signs the URL + body, so
     * we need the notification URL — pass via $context['url']. If absent
     * we fall back to reconstructing from $_SERVER (less portable; prefer
     * passing it explicitly from the controller).
     *
     * https://developer.squareup.com/docs/webhooks/step3validate
     */
    public function verifyWebhook(string $payload, string $signature, array $context = []): ?array
    {
        $secret = $this->config['webhook_signature_key'];
        if ($secret === '' || $payload === '' || $signature === '') return null;

        $url = (string) ($context['url'] ?? '');
        if ($url === '' && !empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI'])) {
            $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https://' : 'http://';
            $url    = $scheme . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }
        if ($url === '') return null;

        $expected = base64_encode(hash_hmac('sha256', $url . $payload, $secret, true));
        if (!hash_equals($expected, $signature)) return null;

        $event = json_decode($payload, true);
        return is_array($event) ? $event : null;
    }

    // ── PaymentGateway surface ────────────────────────────────────────────

    public function charge(int $amountCents, string $currency, string $source, array $meta = []): array
    {
        // Square's /v2/payments takes source_id (a one-time nonce from the
        // Web Payments SDK) + amount_money + location_id. Idempotency key
        // is required; generate one per call so retries stay safe.
        $body = [
            'source_id'       => $source,
            'idempotency_key' => bin2hex(random_bytes(16)),
            'amount_money'    => [
                'amount'   => $amountCents,
                'currency' => strtoupper($currency),
            ],
            'location_id'     => $this->locationId(),
            'autocomplete'    => true,
        ];
        if (!empty($meta['order_id']))    $body['reference_id'] = (string) $meta['order_id'];
        if (!empty($meta['note']))        $body['note']         = (string) $meta['note'];

        $res = $this->call('POST', '/v2/payments', $body);
        return $this->normalize($res['payment'] ?? null, $res);
    }

    public function chargeCustomer(
        string $customerId,
        string $paymentMethodId,
        int    $amountCents,
        string $currency,
        array  $meta = []
    ): array {
        // For vaulted cards we use the stored card id as source_id and add
        // customer_id. Same /v2/payments endpoint.
        $body = [
            'source_id'       => $paymentMethodId,
            'customer_id'     => $customerId,
            'idempotency_key' => bin2hex(random_bytes(16)),
            'amount_money'    => [
                'amount'   => $amountCents,
                'currency' => strtoupper($currency),
            ],
            'location_id'     => $this->locationId(),
            'autocomplete'    => true,
        ];
        if (!empty($meta['order_id'])) $body['reference_id'] = (string) $meta['order_id'];
        if (!empty($meta['note']))     $body['note']         = (string) $meta['note'];

        $res = $this->call('POST', '/v2/payments', $body);
        return $this->normalize($res['payment'] ?? null, $res);
    }

    public function createCustomer(array $fields): array
    {
        $body = [];
        foreach (['email_address' => 'email', 'phone_number' => 'phone',
                  'given_name' => 'first_name', 'family_name' => 'last_name',
                  'nickname' => 'nickname'] as $squareKey => $appKey) {
            if (!empty($fields[$appKey])) $body[$squareKey] = (string) $fields[$appKey];
        }
        // Square accepts `email_address` directly as a fallback label.
        if (!empty($fields['email']) && empty($body['email_address'])) {
            $body['email_address'] = (string) $fields['email'];
        }

        $res = $this->call('POST', '/v2/customers', $body);
        $cust = $res['customer'] ?? null;
        if (!$cust) {
            return ['ok' => false, 'id' => '', 'status' => 'failed', 'raw' => $res ?? [], 'error' => 'create_failed'];
        }
        return [
            'ok'     => true,
            'id'     => (string) ($cust['id'] ?? ''),
            'status' => 'ok',
            'raw'    => $res,
            'error'  => null,
        ];
    }

    public function attachPaymentMethod(string $customerId, string $source, array $meta = []): array
    {
        // Square stores cards via /v2/cards with source_id = a one-time
        // nonce (from the Web Payments SDK) + customer_id.
        $body = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'source_id'       => $source,
            'card'            => ['customer_id' => $customerId],
        ];
        $res  = $this->call('POST', '/v2/cards', $body);
        $card = $res['card'] ?? null;
        if (!$card) {
            return ['ok' => false, 'id' => '', 'status' => 'failed', 'raw' => $res ?? [], 'error' => 'attach_failed'];
        }
        return [
            'ok'     => true,
            'id'     => (string) ($card['id'] ?? ''),
            'status' => 'ok',
            'raw'    => $res,
            'error'  => null,
        ];
    }

    public function listPaymentMethods(string $customerId): array
    {
        $res = $this->call('GET', '/v2/cards', [
            'customer_id' => $customerId,
            'limit'       => 20,
        ]);
        if (!is_array($res)) {
            return ['ok' => false, 'id' => '', 'status' => 'failed', 'raw' => [], 'error' => 'list_failed'];
        }
        $cards = $res['cards'] ?? [];
        $methods = array_map(static fn(array $c) => [
            'id'    => (string) ($c['id'] ?? ''),
            'brand' => (string) ($c['card_brand'] ?? ''),
            'last4' => (string) ($c['last_4'] ?? ''),
            'exp'   => sprintf('%02d/%04d',
                (int) ($c['exp_month'] ?? 0),
                (int) ($c['exp_year']  ?? 0)
            ),
        ], $cards);

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
        $res = $this->call('POST', "/v2/cards/$paymentMethodId/disable");
        if (!is_array($res)) {
            return ['ok' => false, 'id' => $paymentMethodId, 'status' => 'failed', 'raw' => [], 'error' => 'detach_failed'];
        }
        return [
            'ok'     => true,
            'id'     => $paymentMethodId,
            'status' => 'ok',
            'raw'    => $res,
            'error'  => null,
        ];
    }

    public function refund(string $chargeId, ?int $amountCents = null): array
    {
        // Square needs amount_money explicitly. For "full refund", caller
        // must pass the original amount; there's no implicit "full" on the
        // API level. Default to the original payment amount by looking it up.
        if ($amountCents === null) {
            $lookup = $this->call('GET', "/v2/payments/$chargeId");
            $amt    = (int) ($lookup['payment']['amount_money']['amount'] ?? 0);
            $cur    = (string) ($lookup['payment']['amount_money']['currency'] ?? 'USD');
            if ($amt <= 0) {
                return ['ok' => false, 'id' => '', 'status' => 'failed', 'raw' => $lookup ?? [], 'error' => 'amount_lookup_failed'];
            }
            $amountCents = $amt;
        } else {
            $cur = 'USD';
        }

        $body = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'payment_id'      => $chargeId,
            'amount_money'    => ['amount' => $amountCents, 'currency' => $cur],
        ];
        $res = $this->call('POST', '/v2/refunds', $body);
        return $this->normalize($res['refund'] ?? null, $res);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** Shape a Square object (payment/refund) into the common result. */
    private function normalize(?array $obj, ?array $rawEnvelope): array
    {
        if (!$obj) {
            return ['ok' => false, 'id' => '', 'status' => 'failed', 'raw' => $rawEnvelope ?? [], 'error' => 'request_failed'];
        }
        $id     = (string) ($obj['id'] ?? '');
        $status = strtolower((string) ($obj['status'] ?? 'unknown'));
        // Square statuses: COMPLETED, APPROVED, PENDING, CANCELED, FAILED
        $ok = in_array($status, ['completed', 'approved', 'pending'], true);
        return [
            'ok'     => $ok,
            'id'     => $id,
            'status' => $status,
            'raw'    => $rawEnvelope ?? [],
            'error'  => $ok ? null : $status,
        ];
    }
}
