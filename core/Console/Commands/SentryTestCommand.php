<?php
// core/Console/Commands/SentryTestCommand.php
namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Services\SentryService;

/**
 * Fire a deliberate test exception at Sentry to confirm error reporting is
 * wired up end to end (DSN, auth, network, delivery).
 *
 * Respects the active environment via the same path the app uses at runtime:
 * when SENTRY_DSN is empty for the current APP_ENV (e.g. local dev, where the
 * DEV_ overlay is intentionally blank) it reports "disabled" and sends nothing.
 * Run it on the production host (APP_ENV=production) to verify the live DSN.
 *
 *   php artisan sentry:test
 *   php artisan sentry:test --message="custom label"
 *
 * Exit codes:
 *   0  event accepted by Sentry, OR Sentry disabled (expected in dev),
 *      OR sent via the HTTP fallback client (no id to confirm)
 *   1  SDK is active but the send failed (bad DSN / no connectivity)
 *
 * The event is tagged with the resolved environment + release (APP_VERSION)
 * and carries a clearly-labelled message so it's obvious in the Sentry
 * dashboard that it's a deliberate test — safe to resolve/ignore.
 */
class SentryTestCommand extends Command
{
    public function name(): string        { return 'sentry:test'; }
    public function description(): string { return 'Send a test exception to Sentry to verify error reporting'; }

    public function usage(): string
    {
        return 'php artisan sentry:test [--message="..."]';
    }

    public function handle(array $argv): int
    {
        SentryService::init();

        $env     = (string) ($_ENV['SENTRY_ENVIRONMENT'] ?? ($_ENV['APP_ENV'] ?? 'production'));
        $release = (string) ($_ENV['APP_VERSION'] ?? '');

        $this->line('[' . date('Y-m-d H:i:s') . '] sentry:test');
        $this->line('  Environment: ' . $env);
        $this->line('  Release:     ' . ($release !== '' ? $release : '(unset)'));

        if (!SentryService::isEnabled()) {
            $this->line('');
            $this->warn('Sentry is DISABLED in this environment — nothing sent.');
            $this->line('  SENTRY_DSN is empty for the active environment (APP_ENV=' . ($_ENV['APP_ENV'] ?? '?') . ').');
            $this->line('  This is expected in local/dev. To send a real event, set the DSN');
            $this->line('  (e.g. PROD_SENTRY_DSN) and run with APP_ENV=production.');
            return 0;
        }

        $message = $this->option($argv, 'message')
            ?: 'Sentry test event from `artisan sentry:test` — intentional, safe to ignore/resolve.';

        $this->line('');
        $this->line('  Throwing a deliberate exception and capturing it...');

        try {
            $this->raiseTestException($message);
        } catch (\Throwable $e) {
            SentryService::captureException($e);
            $this->success('Captured ' . $e::class . ': ' . $e->getMessage());

            // The official SDK assigns + returns an event id; the HTTP fallback
            // client does not. Detect the SDK the same way SentryService does.
            $sdkActive = function_exists('\Sentry\captureException')
                && class_exists(\Sentry\SentrySdk::class);

            if ($sdkActive) {
                $id = \Sentry\SentrySdk::getCurrentHub()->getLastEventId();
                // Flush before exit — a short-lived CLI process must push
                // buffered events or the transport may never send them.
                SentryService::flush();

                if ($id) {
                    $this->success('Sentry accepted the event — id: ' . (string) $id);
                    $this->line('');
                    $this->line('  Check your Sentry project → Issues (environment: ' . $env . ').');
                    return 0;
                }

                $this->error('Sentry did not return an event id — the send failed.');
                $this->line('  Verify the DSN is valid and outbound HTTPS to your Sentry host works.');
                return 1;
            }

            // HTTP fallback path (SDK not installed): best-effort POST, no id.
            SentryService::flush(); // no-op on the fallback
            $this->line('  Sent via the HTTP fallback client (SDK not installed) — no event id to confirm.');
            $this->line('  Check Sentry → Issues (environment: ' . $env . ') to verify receipt.');
            return 0;
        }

        // Unreachable: raiseTestException() always throws.
        $this->error('Test exception was not raised — this should never happen.');
        return 1;
    }

    /** Nested so the captured stack trace carries a realistic frame. */
    private function raiseTestException(string $message): void
    {
        throw new \RuntimeException($message);
    }
}
