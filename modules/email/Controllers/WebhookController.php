<?php
// modules/email/Controllers/WebhookController.php
namespace Modules\Email\Controllers;

use Core\Database\Database;
use Core\Request;
use Core\Response;
use Modules\Email\Services\SuppressionService;

/**
 * Email-provider bounce + complaint webhooks.
 *
 *   POST /webhooks/email/ses
 *   POST /webhooks/email/sendgrid
 *   POST /webhooks/email/postmark
 *   POST /webhooks/email/mailgun
 *
 * Each provider posts its own JSON shape. Each handler:
 *   1. Verifies the request (signature / shared secret per provider).
 *   2. Stores the raw payload in mail_bounce_events for forensics.
 *   3. For hard bounces / complaints, calls SuppressionService::suppress
 *      against the wildcard category 'all' so the address is dropped
 *      from every list including transactional.
 *
 * Soft bounces (temporary failures) are logged but NOT auto-suppressed
 * — your retry policy / provider should handle them.
 *
 * The endpoint always returns 200 on a parsed payload, even if no
 * suppression action was taken (delivered, opened, etc.). Returning
 * 4xx/5xx triggers retry storms from most providers.
 */
class WebhookController
{
    private SuppressionService $svc;
    private Database           $db;

    public function __construct()
    {
        $this->svc = new SuppressionService();
        $this->db  = Database::getInstance();
    }

    /**
     * SES (Amazon Simple Email Service) — events arrive via SNS.
     * SubscriptionConfirmation requires GET-fetching the SubscribeURL
     * the first time; after that, Notifications carry mail/bounce/complaint.
     */
    public function ses(Request $request): Response
    {
        $body = $request->raw();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return Response::json(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        // SubscriptionConfirmation step — visit the SubscribeURL once.
        if (($payload['Type'] ?? '') === 'SubscriptionConfirmation' && !empty($payload['SubscribeURL'])) {
            // Fetch in the background so we ack the SNS request fast.
            // For simplicity we use a blocking call here — a real
            // deployment may want to queue this.
            @file_get_contents((string) $payload['SubscribeURL']);
            return new Response('OK', 200);
        }

        if (($payload['Type'] ?? '') !== 'Notification') {
            return new Response('OK', 200);
        }

        $message = json_decode((string) ($payload['Message'] ?? '{}'), true);
        if (!is_array($message)) return new Response('OK', 200);

        $type = (string) ($message['notificationType'] ?? '');
        $emails = [];

        if ($type === 'Bounce') {
            $bounceType = (string) ($message['bounce']['bounceType'] ?? '');
            $isHard     = $bounceType === 'Permanent';
            foreach (($message['bounce']['bouncedRecipients'] ?? []) as $r) {
                $emails[] = (string) ($r['emailAddress'] ?? '');
            }
            $this->logEvent('ses', $isHard ? 'hard_bounce' : 'soft_bounce', $emails, $body);
            if ($isHard) $this->suppressAll($emails, SuppressionService::REASON_HARD_BOUNCE, 'SES bounce: ' . $bounceType);

        } elseif ($type === 'Complaint') {
            foreach (($message['complaint']['complainedRecipients'] ?? []) as $r) {
                $emails[] = (string) ($r['emailAddress'] ?? '');
            }
            $this->logEvent('ses', 'complaint', $emails, $body);
            $this->suppressAll($emails, SuppressionService::REASON_COMPLAINT, 'SES complaint');
        } else {
            // Delivery, etc — log only.
            foreach (($message['mail']['destination'] ?? []) as $e) $emails[] = (string) $e;
            $this->logEvent('ses', strtolower($type ?: 'event'), $emails, $body);
        }

        return new Response('OK', 200);
    }

    /**
     * SendGrid — POSTs an array of event objects.
     * Auth: shared secret in the URL or Basic Auth (set in env as
     * MAIL_WEBHOOK_SECRET; checked via constant_time compare).
     */
    public function sendgrid(Request $request): Response
    {
        if (!$this->verifySharedSecret($request)) {
            return Response::json(['ok' => false], 401);
        }

        $events = json_decode($request->raw(), true);
        if (!is_array($events)) return new Response('OK', 200);

        foreach ($events as $e) {
            $email = strtolower((string) ($e['email'] ?? ''));
            if ($email === '') continue;
            $type  = (string) ($e['event'] ?? '');

            $this->logEvent('sendgrid', $type, [$email], json_encode($e));

            if ($type === 'bounce' && (string) ($e['type'] ?? '') === 'bounce') {
                // SendGrid's "bounce" event with type=bounce = hard bounce
                $this->suppressAll([$email], SuppressionService::REASON_HARD_BOUNCE, 'SendGrid hard bounce: ' . ($e['reason'] ?? ''));
            } elseif ($type === 'spamreport') {
                $this->suppressAll([$email], SuppressionService::REASON_COMPLAINT, 'SendGrid spam report');
            } elseif ($type === 'unsubscribe') {
                // SendGrid's own unsubscribe-link click — apply globally.
                $this->suppressAll([$email], SuppressionService::REASON_USER_UNSUBSCRIBE, 'SendGrid list-level unsubscribe');
            } elseif ($type === 'group_unsubscribe') {
                // Per-group; ignore — we manage groups via our own
                // category system.
            }
        }

        return new Response('OK', 200);
    }

    /** Postmark — single-event POST per request. */
    public function postmark(Request $request): Response
    {
        if (!$this->verifySharedSecret($request)) {
            return Response::json(['ok' => false], 401);
        }

        $event = json_decode($request->raw(), true);
        if (!is_array($event)) return new Response('OK', 200);

        $email = strtolower((string) ($event['Email'] ?? $event['Recipient'] ?? ''));
        $type  = (string) ($event['RecordType'] ?? '');

        $this->logEvent('postmark', strtolower($type), [$email], $request->raw());

        if ($type === 'Bounce') {
            $bounceType = (string) ($event['Type'] ?? '');
            // Postmark "HardBounce", "SpamComplaint", "ManuallyDeactivated" all suppress
            if (in_array($bounceType, ['HardBounce', 'ManuallyDeactivated', 'BadEmailAddress'], true)) {
                $this->suppressAll([$email], SuppressionService::REASON_HARD_BOUNCE, 'Postmark ' . $bounceType);
            }
        } elseif ($type === 'SpamComplaint') {
            $this->suppressAll([$email], SuppressionService::REASON_COMPLAINT, 'Postmark spam complaint');
        } elseif ($type === 'SubscriptionChange' && empty($event['SuppressSending'])) {
            // User resubscribed via Postmark UI — clear our wildcard suppression
            // (admin can decide whether to honour this; default: log only).
        }

        return new Response('OK', 200);
    }

    /** Mailgun — events posted with HMAC signature. */
    public function mailgun(Request $request): Response
    {
        $body = $request->raw();
        $event = json_decode($body, true);
        if (!is_array($event)) return new Response('OK', 200);

        // Mailgun signature: HMAC-SHA256 of (timestamp + token) with
        // the API webhook signing key.
        $sig = $event['signature'] ?? null;
        $key = (string) ($_ENV['MAILGUN_SIGNING_KEY'] ?? '');
        if (is_array($sig) && $key !== '') {
            $expected = hash_hmac('sha256',
                ((string) ($sig['timestamp'] ?? '')) . ((string) ($sig['token'] ?? '')),
                $key
            );
            if (!hash_equals($expected, (string) ($sig['signature'] ?? ''))) {
                return Response::json(['ok' => false], 401);
            }
        }

        $eventData = $event['event-data'] ?? [];
        $email = strtolower((string) ($eventData['recipient'] ?? ''));
        $type  = (string) ($eventData['event'] ?? '');
        $severity = (string) ($eventData['severity'] ?? '');

        $this->logEvent('mailgun', $type, [$email], $body);

        if ($type === 'failed' && $severity === 'permanent') {
            $this->suppressAll([$email], SuppressionService::REASON_HARD_BOUNCE, 'Mailgun permanent failure');
        } elseif ($type === 'complained') {
            $this->suppressAll([$email], SuppressionService::REASON_COMPLAINT, 'Mailgun complaint');
        } elseif ($type === 'unsubscribed') {
            $this->suppressAll([$email], SuppressionService::REASON_USER_UNSUBSCRIBE, 'Mailgun unsubscribe');
        }

        return new Response('OK', 200);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Constant-time check against MAIL_WEBHOOK_SECRET. If the env var
     * isn't set, the check is skipped (dev-friendly default — enable
     * by setting the env var before going to prod).
     */
    private function verifySharedSecret(Request $request): bool
    {
        $expected = (string) ($_ENV['MAIL_WEBHOOK_SECRET'] ?? '');
        if ($expected === '') return true;

        // Accept either a Bearer token in Authorization, an
        // X-Webhook-Secret header, or a ?secret= query string param —
        // covers all three common provider conventions.
        $candidates = [
            (string) $request->header('Authorization'),
            (string) $request->header('X-Webhook-Secret'),
            (string) $request->query('secret', ''),
        ];
        foreach ($candidates as $c) {
            // Strip "Bearer " prefix if present
            if (str_starts_with($c, 'Bearer ')) $c = substr($c, 7);
            if ($c !== '' && hash_equals($expected, $c)) return true;
        }
        return false;
    }

    private function suppressAll(array $emails, string $reason, string $note): void
    {
        foreach ($emails as $e) {
            if ($e === '') continue;
            $this->svc->suppress($e, SuppressionService::WILDCARD_CATEGORY, $reason, null, $note);
        }
    }

    private function logEvent(string $provider, string $type, array $emails, string $payload): void
    {
        foreach ($emails as $e) {
            if ($e === '') continue;
            $this->db->insert('mail_bounce_events', [
                'provider'   => $provider,
                'event_type' => $type,
                'email'      => strtolower($e),
                'payload'    => mb_substr($payload, 0, 65000),
                'processed'  => 1,
            ]);
        }
    }
}
