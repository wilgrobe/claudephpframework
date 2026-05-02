# Adding a new payment gateway

The framework's payments layer is driver-based: `app(Core\Contracts\PaymentGateway::class)`
returns whatever driver is selected in `config/payments.php`. Adding a new
gateway — regional provider, private processor, anything implementing the
contract — takes three steps and no framework code changes.

## 1. Implement the contract

Create a class under `app/Services/` (or anywhere in your autoload tree)
that implements `Core\Contracts\PaymentGateway`. Every method returns the
normalized result shape documented on the interface:

```
[
  'ok'     => bool,
  'id'     => string,
  'status' => string,
  'raw'    => array,     // full provider response, for audit
  'error'  => string|null,
]
```

Methods you don't support yet should return `ok: false, status: 'not_supported'`
with a helpful `error` message rather than throwing — callers branch on
the result, not on driver type. See `Core\Services\BraintreeService::charge()`
for the reference pattern.

```php
namespace App\Services;

use Core\Contracts\PaymentGateway;

class AdyenService implements PaymentGateway
{
    public function name(): string        { return 'adyen'; }
    public function isEnabled(): bool     { /* check env */ }

    public function charge(int $amountCents, string $currency, string $source, array $meta = []): array { ... }
    public function chargeCustomer(string $customerId, string $paymentMethodId, int $amountCents, string $currency, array $meta = []): array { ... }
    public function createCustomer(array $fields): array { ... }
    public function attachPaymentMethod(string $customerId, string $source, array $meta = []): array { ... }
    public function listPaymentMethods(string $customerId): array { ... }
    public function detachPaymentMethod(string $paymentMethodId): array { ... }
    public function refund(string $chargeId, ?int $amountCents = null): array { ... }
    public function verifyWebhook(string $payload, string $signature, array $context = []): ?array { ... }
}
```

## 2. Register in `config/payments.php`

Add one line to the `drivers` map:

```php
return [
    'gateway' => strtolower(trim((string) ($_ENV['PAYMENT_GATEWAY'] ?? 'stripe'))),
    'drivers' => [
        'stripe'    => \Core\Services\StripeService::class,
        'square'    => \Core\Services\SquareService::class,
        'braintree' => \Core\Services\BraintreeService::class,
        'adyen'     => \App\Services\AdyenService::class,  // ← new
    ],
];
```

## 3. Activate in `.env`

```
PAYMENT_GATEWAY=adyen
```

Anything that calls `app(Core\Contracts\PaymentGateway::class)` or
`app(Core\Services\PaymentsService::class)` now uses your driver. The
`PaymentsService` audit wrapper records every call to the `payments` table
regardless of driver, so you get the audit trail for free.

## Tips

- **Vaulting is the high-value path.** Modern gateways store payment
  methods server-side and give you opaque tokens (`pm_xxx`, `card_id`,
  etc.). Your `createCustomer` / `attachPaymentMethod` / `chargeCustomer`
  implementations keep card data out of your DB entirely. This is what
  users are expecting when they pick a new gateway — invest there first.
- **Webhooks don't have to follow the two-arg shape.** Pass provider-specific
  signals via `$context` — Square uses `$context['url']` for URL-in-signature,
  Stripe accepts `$context['tolerance']` for clock skew.
- **`raw` should be the full provider response.** The `payments` table's
  `response_json` column stores this verbatim so incidents can be
  reconstructed without re-hitting the API. Don't trim it.
- **Test in the gateway's sandbox mode.** Every supported provider has a
  sandbox/test environment with the same API. Stripe uses test keys
  (`sk_test_…`); Square uses `SQUARE_ENVIRONMENT=sandbox`; Braintree uses
  `BRAINTREE_ENVIRONMENT=sandbox`. Your driver should respect the same
  convention.
