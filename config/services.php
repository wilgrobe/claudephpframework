<?php
// config/services.php
/**
 * Driver bindings for swappable services. Returned as a closure so the
 * container is in scope when bindings execute — lets us read other config
 * values, check env flags, or do conditional registration.
 *
 * Add new driver interfaces under Core\Contracts\ and bind them here.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * State of contract implementation (2026-04-21):
 *
 *   Fully contracted — `implements` on the service class, constructor
 *   type-hints resolve correctly:
 *     • MailDriver    ↔ MailService
 *     • SmsDriver     ↔ SmsService
 *     • SearchEngine  ↔ SearchService
 *     • PaymentGateway ↔ StripeService / SquareService / BraintreeService
 *       (picked by name in config/payments.php → drivers)
 *
 *   Aspirational — closure-bridged through the container today, but the
 *   concrete service does NOT `implements` the interface yet because the
 *   service surface and the interface surface don't line up cleanly:
 *     • StorageDriver  — FileUploadService is image-specific (takes
 *       $_FILES arrays, applies GD re-encoding). A general put/get/exists
 *       store would need a separate FlysystemStorageDriver wrapper around
 *       the internal Filesystem handle.
 *
 *   Note 2026-04-22: PaymentGateway is now fully contracted — Stripe/
 *   Square/Braintree all implement it, driver choice is data-driven via
 *   config/payments.php, and third-party drivers slot in by adding one
 *   line to that config. See docs/payments-adding-a-gateway.md.
 * ─────────────────────────────────────────────────────────────────────────
 */

use Core\Container\Container;

return function (Container $c): void {

    // ── Mail ──────────────────────────────────────────────────────────────
    // MailDriver interface picks SMTP/SendGrid/Mailgun/SES based on the
    // mail.driver config. Default stays on MailService (PHPMailer-backed SMTP)
    // so BC is preserved; switching to SendGrid becomes a one-line config edit
    // the day we extract it into SendGridMailDriver.
    $c->singleton(\Core\Contracts\MailDriver::class, function (Container $c) {
        // All drivers currently route through MailService, which already
        // picks the transport (SMTP / SendGrid / Mailgun / SES) from
        // IntegrationConfig at runtime. When we split MailService into
        // discrete driver classes (task 2), introduce a match here on
        // config('mail.driver') and return the right concrete.
        return $c->get(\Core\Services\MailService::class);
    });

    // ── SMS ───────────────────────────────────────────────────────────────
    $c->singleton(\Core\Contracts\SmsDriver::class, function (Container $c) {
        return $c->get(\Core\Services\SmsService::class);
    });

    // ── Search ────────────────────────────────────────────────────────────
    // MySQL FULLTEXT today; Meilisearch planned (see infra follow-up queue).
    // When MeilisearchDriver lands, set SEARCH_ENGINE=meilisearch in .env.
    $c->singleton(\Core\Contracts\SearchEngine::class, function (Container $c) {
        $engine = $_ENV['SEARCH_ENGINE'] ?? 'mysql';
        return match ($engine) {
            // 'meilisearch' => $c->get(\Core\Services\MeilisearchDriver::class),
            default => $c->get(\Core\Services\SearchService::class),
        };
    });

    // ── Storage ───────────────────────────────────────────────────────────
    // Local FS vs. S3/MinIO is already handled inside FileUploadService via
    // Flysystem. We expose it here under the StorageDriver contract so new
    // code can type-hint the interface.
    $c->singleton(\Core\Contracts\StorageDriver::class, function (Container $c) {
        return $c->get(\Core\Services\FileUploadService::class);
    });

    // ── Payments ──────────────────────────────────────────────────────────
    // Driver choice is config-driven via config/payments.php. Third-party
    // gateways add themselves by implementing Core\Contracts\PaymentGateway
    // and adding one line to the drivers map — no edits here needed.
    // See docs/payments-adding-a-gateway.md.
    $c->singleton(\Core\Contracts\PaymentGateway::class, function (Container $c) {
        $cfg     = config('payments');
        $name    = (string) ($cfg['gateway'] ?? 'stripe');
        $drivers = (array)  ($cfg['drivers'] ?? []);

        if (!isset($drivers[$name])) {
            throw new \RuntimeException(
                "Unknown payment gateway: '$name'. Register it in config/payments.php."
            );
        }

        $class = $drivers[$name];
        if (!is_string($class) || !class_exists($class)) {
            throw new \RuntimeException(
                "Payment gateway '$name' maps to a non-existent class: " . var_export($class, true)
            );
        }

        $driver = $c->get($class);
        if (!$driver instanceof \Core\Contracts\PaymentGateway) {
            throw new \RuntimeException(
                get_class($driver) . " must implement Core\\Contracts\\PaymentGateway."
            );
        }
        return $driver;
    });
};
