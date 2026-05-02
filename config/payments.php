<?php
// config/payments.php
/**
 * Payment gateway configuration.
 *
 * Extension point for app developers: to plug in a new gateway (e.g. Adyen,
 * Paddle, a regional provider), implement Core\Contracts\PaymentGateway on
 * a class under app/Services or anywhere in your autoload tree, then add it
 * to the `drivers` map below and set PAYMENT_GATEWAY=<your-name> in .env.
 *
 * No framework code changes required — services.php reads this map by name
 * when resolving the PaymentGateway contract, so `app(PaymentGateway::class)`
 * returns your driver without touching the container wiring.
 */

return [
    /**
     * Active gateway. Must be a key in the `drivers` map.
     * Apps typically set this via the PAYMENT_GATEWAY env var so production /
     * staging / dev can diverge without a config edit.
     */
    'gateway' => strtolower(trim((string) ($_ENV['PAYMENT_GATEWAY'] ?? 'stripe'))),

    /**
     * Built-in drivers. Third-party drivers go below the fenced comment
     * line so you can spot them at a glance vs. shipped-with-the-framework
     * entries during reviews / upgrades.
     */
    'drivers' => [
        'stripe'    => \Core\Services\StripeService::class,
        'square'    => \Core\Services\SquareService::class,
        'braintree' => \Core\Services\BraintreeService::class,

        // ── Third-party drivers — add below this line ─────────────────────
        // 'adyen'   => \App\Services\AdyenService::class,
        // 'paddle'  => \App\Services\PaddleService::class,
    ],
];
