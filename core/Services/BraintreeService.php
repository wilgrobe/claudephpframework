<?php
// core/Services/BraintreeService.php
namespace Core\Services;

/**
 * Minimal Braintree API client, parallel in shape to StripeService.
 *
 * Braintree's REST-style API uses HTTP Basic auth keyed by a 3-tuple of
 * merchant_id + public_key + private_key. The "environment" (sandbox or
 * production) controls which host we hit.
 *
 * Disabled when BRAINTREE_MERCHANT_ID / PUBLIC_KEY / PRIVATE_KEY aren't
 * all set. isEnabled() gates feature availability in the app; every
 * other method safely returns null / false when disabled.
 *
 * The full Braintree integration story typically uses the braintree/braintree_php
 * SDK for webhook signatures and GraphQL. We ship the thin, SDK-free
 * layer here; apps can composer-require the SDK for anything deeper.
 */
class BraintreeService implements \Core\Contracts\PaymentGateway
{
    private array $config;

    public function __construct()
    {
        $this->config = [
            'merchant_id' => trim((string) ($_ENV['BRAINTREE_MERCHANT_ID'] ?? '')),
            'public_key'  => trim((string) ($_ENV['BRAINTREE_PUBLIC_KEY']  ?? '')),
            'private_key' => trim((string) ($_ENV['BRAINTREE_PRIVATE_KEY'] ?? '')),
            'environment' => strtolower(trim((string) ($_ENV['BRAINTREE_ENVIRONMENT'] ?? 'sandbox'))),
        ];
    }

    public function name(): string { return 'braintree'; }

    public function isEnabled(): bool
    {
        return $this->config['merchant_id'] !== ''
            && $this->config['public_key']  !== ''
            && $this->config['private_key'] !== '';
    }

    /** "sandbox" or "production". */
    public function environment(): string
    {
        return $this->config['environment'] === 'production' ? 'production' : 'sandbox';
    }

    /** Base host for the active environment. */
    private function host(): string
    {
        return $this->environment() === 'production'
            ? 'https://api.braintreegateway.com'
            : 'https://api.sandbox.braintreegateway.com';
    }

    /**
     * Make a call against the Braintree REST API. Returns the decoded
     * response on success, null on any failure. Errors are logged so
     * they appear in the app log; callers get the safe-to-gate null.
     *
     * Braintree expects XML bodies for most endpoints — we pass through
     * whatever string the caller gives in $body so apps can use their
     * preferred format (Braintree also accepts JSON on newer endpoints).
     *
     * @param string      $method   HTTP verb
     * @param string      $path     API path under /merchants/{merchant_id}
     * @param string|null $body     Raw request body (XML or JSON)
     * @param string      $contentType MIME type of the body
     */
    public function call(string $method, string $path, ?string $body = null, string $contentType = 'application/xml'): ?array
    {
        if (!$this->isEnabled()) return null;

        $url = $this->host() . '/merchants/' . rawurlencode($this->config['merchant_id']) . $path;
        $auth = base64_encode($this->config['public_key'] . ':' . $this->config['private_key']);

        $headers = [
            'Authorization: Basic ' . $auth,
            'Accept: application/xml',
            'Content-Type: ' . $contentType,
            'X-ApiVersion: 6',
        ];

        $ctx = [
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $headers) . "\r\n",
                'timeout'       => 15.0,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null && $method !== 'GET') {
            $ctx['http']['content'] = $body;
        }

        $raw = @file_get_contents($url, false, stream_context_create($ctx));
        if ($raw === false) {
            error_log('[braintree] network failure calling ' . $path);
            return null;
        }

        // Best-effort: try JSON first (newer endpoints), else hand back the
        // raw body in an 'xml' key so callers can parse with SimpleXML.
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
        return ['xml' => $raw];
    }

    // ── PaymentGateway surface ────────────────────────────────────────────
    //
    // Braintree's transaction/vault endpoints expect XML or GraphQL
    // payloads whose correctness is difficult to get right without the
    // braintree/braintree_php SDK. Rather than ship fragile XML builders,
    // these methods return the contract's `ok:false, error:'not_supported_yet'`
    // shape and log an informative message. Apps using Braintree today
    // have two paths:
    //   (a) composer require braintree/braintree_php + bypass this service
    //       and use the SDK directly for Customer/Transaction operations.
    //   (b) subclass this service and override these methods with your
    //       preferred implementation — the container will still pick up
    //       the override if you register it in config/payments.php.
    //
    // Framework-level first-class support is tracked in the infra queue.
    //
    // verifyWebhook IS implemented because Braintree's webhook signature
    // scheme is well-defined and doesn't need the SDK.

    public function charge(int $amountCents, string $currency, string $source, array $meta = []): array
    {
        return $this->notSupported('charge');
    }

    public function chargeCustomer(
        string $customerId,
        string $paymentMethodId,
        int    $amountCents,
        string $currency,
        array  $meta = []
    ): array {
        return $this->notSupported('chargeCustomer');
    }

    public function createCustomer(array $fields): array
    {
        return $this->notSupported('createCustomer');
    }

    public function attachPaymentMethod(string $customerId, string $source, array $meta = []): array
    {
        return $this->notSupported('attachPaymentMethod');
    }

    public function listPaymentMethods(string $customerId): array
    {
        return $this->notSupported('listPaymentMethods');
    }

    public function detachPaymentMethod(string $paymentMethodId): array
    {
        return $this->notSupported('detachPaymentMethod');
    }

    public function refund(string $chargeId, ?int $amountCents = null): array
    {
        return $this->notSupported('refund');
    }

    /**
     * Braintree webhook signature format:
     *   bt_signature = "<public_key>|<hmac_sha1>"
     *   hmac_sha1    = hash_hmac('sha1', bt_payload, sha1(private_key))
     *
     * We verify the provided signature matches what we'd compute with our
     * keys, then json_decode the payload. Returns null on any mismatch.
     */
    public function verifyWebhook(string $payload, string $signature, array $context = []): ?array
    {
        if (!$this->isEnabled() || $payload === '' || $signature === '') return null;

        // Signature may contain multiple pipe-separated segments; the first
        // valid one wins. Defensive against Braintree rotating keys.
        foreach (explode('&', $signature) as $candidate) {
            [$pub, $sig] = array_pad(explode('|', trim($candidate), 2), 2, '');
            if ($pub === '' || $sig === '') continue;
            if (!hash_equals($this->config['public_key'], $pub)) continue;

            $key    = sha1($this->config['private_key'], true);
            $expect = hash_hmac('sha1', $payload, $key);
            if (hash_equals($expect, $sig)) {
                $event = json_decode($payload, true);
                return is_array($event) ? $event : null;
            }
        }
        return null;
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function notSupported(string $method): array
    {
        error_log("[braintree] $method not yet supported on the SDK-free driver; " .
                  "use braintree/braintree_php directly or subclass BraintreeService.");
        return [
            'ok'     => false,
            'id'     => '',
            'status' => 'not_supported',
            'raw'    => [],
            'error'  => "Braintree driver does not yet implement $method — see class comment.",
        ];
    }
}
