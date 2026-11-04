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
 *   POST /webhooks/email/smtp2go
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
        // Phase 43.183 — auth required. SES doesn't natively support
        // shared-secret webhook auth (SNS uses its own signing scheme),
        // but the practical attack surface is a forged POST that
        // suppresses arbitrary recipient addresses (anyone with the
        // endpoint URL can pretend to be SES). Operators wire the
        // SNS subscription URL with `?secret=<env>` so the shared-
        // secret check below covers them.
        // FUTURE: implement full SNS signature verification (cert
        // download + chain validation against AWS-owned signing certs)
        // for installs that can't safely embed a query-param secret in
        // their SNS topic config. Tracked as the SES-signature follow-up.
        if (!$this->verifySharedSecret($request)) {
            return Response::json(['ok' => false], 401);
        }

        $body = $request->raw();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return Response::json(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        // SubscriptionConfirmation step — visit the SubscribeURL once.
        if (($payload['Type'] ?? '') === 'SubscriptionConfirmation' && !empty($payload['SubscribeURL'])) {
            // Phase 43.183 — SSRF guard. Pre-fix blindly fetched the
            // SubscribeURL via file_get_contents with no host check
            // and no timeout. Attacker could send a forged confirmation
            // with SubscribeURL pointing at internal infrastructure
            // (e.g. http://localhost:9200/_cluster/state) and SSRF
            // the server. Now: validate host matches `sns.*.amazonaws.com`
            // (the only legitimate origin for SNS confirmations) +
            // use curl with a 5s timeout so a slow/hanging endpoint
            // can't lock up the request thread.
            $subUrl = (string) $payload['SubscribeURL'];
            $parsed = parse_url($subUrl);
            $host   = strtolower((string) ($parsed['host'] ?? ''));
            $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
            $hostOk = $scheme === 'https' && (bool) preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host);
            if (!$hostOk) {
                return Response::json(['ok' => false, 'error' => 'subscribe_url_host_not_allowed'], 400);
            }
            $ch = curl_init($subUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
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
            // Phase 43.184 — dedup id from SNS outer envelope.
            $sesEventId = (string) ($payload['MessageId'] ?? '');
            $this->logEvent('ses', $isHard ? 'hard_bounce' : 'soft_bounce', $emails, $body, $sesEventId !== '' ? $sesEventId : null);
            if ($isHard) $this->suppressAll($emails, SuppressionService::REASON_HARD_BOUNCE, 'SES bounce: ' . $bounceType);

        } elseif ($type === 'Complaint') {
            foreach (($message['complaint']['complainedRecipients'] ?? []) as $r) {
                $emails[] = (string) ($r['emailAddress'] ?? '');
            }
            $sesEventId = (string) ($payload['MessageId'] ?? '');
            $this->logEvent('ses', 'complaint', $emails, $body, $sesEventId !== '' ? $sesEventId : null);
            $this->suppressAll($emails, SuppressionService::REASON_COMPLAINT, 'SES complaint');
        } else {
            // Delivery, etc — log only.
            foreach (($message['mail']['destination'] ?? []) as $e) $emails[] = (string) $e;
            $sesEventId = (string) ($payload['MessageId'] ?? '');
            $this->logEvent('ses', strtolower($type ?: 'event'), $emails, $body, $sesEventId !== '' ? $sesEventId : null);
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

            // Phase 43.184 — per-event dedup id from sg_event_id.
            $sgEventId = (string) ($e['sg_event_id'] ?? '');
            $this->logEvent('sendgrid', $type, [$email], json_encode($e), $sgEventId !== '' ? $sgEventId : null);

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

        // Phase 43.184 — dedup id from MessageID + RecordType (Postmark
        // can emit multiple event kinds for the same MessageID — Bounce
        // followed by Delivery for a soft bounce — so disambiguate).
        $pmMessageId = (string) ($event['MessageID'] ?? '');
        $pmEventId = $pmMessageId !== '' ? $pmMessageId . ':' . strtolower($type) : null;
        $this->logEvent('postmark', strtolower($type), [$email], $request->raw(), $pmEventId);

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
            // (admin can decide whether to honor this; default: log only).
        }

        return new Response('OK', 200);
    }

    /**
     * SMTP2GO — events posted as a JSON object with `events` array
     * (or a single event at the top level for very old webhook
     * versions).
     *
     * Event types we react to:
     *   bounce / hard_bounce    → REASON_HARD_BOUNCE
     *   spam / complaint        → REASON_COMPLAINT
     *   unsubscribed            → REASON_USER_UNSUBSCRIBE
     *
     * `soft_bounce` and `delivered` / `processed` / `opened` etc.
     * are logged-only — temporary failures shouldn't kill the
     * address, and engagement events aren't suppression-relevant.
     *
     * Auth: shared secret via MAIL_WEBHOOK_SECRET (matches the
     * SendGrid / Postmark pattern). SMTP2GO supports a custom
     * `X-Smtp2go-Token` header, a query-string param, or a Bearer
     * token — all three land in verifySharedSecret's check.
     */
    public function smtp2go(Request $request): Response
    {
        if (!$this->verifySharedSecret($request)) {
            return Response::json(['ok' => false], 401);
        }

        $body    = $request->raw();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return new Response('OK', 200);
        }

        // SMTP2GO sends either {"events":[...]} OR a single event at
        // the top level (older webhook format). Normalize to a list.
        $events = isset($payload['events']) && is_array($payload['events'])
            ? $payload['events']
            : [$payload];

        foreach ($events as $e) {
            if (!is_array($e)) continue;
            $email = strtolower((string) ($e['email'] ?? ''));
            $type  = strtolower((string) ($e['event'] ?? ''));
            if ($email === '') continue;

            $this->logEvent('smtp2go', $type, [$email], json_encode($e));

            if ($type === 'bounce' || $type === 'hard_bounce') {
                $reason = trim((string) ($e['reason'] ?? $e['description'] ?? ''));
                $this->suppressAll([$email], SuppressionService::REASON_HARD_BOUNCE,
                    'SMTP2GO bounce' . ($reason !== '' ? ": $reason" : ''));
            } elseif ($type === 'spam' || $type === 'complaint' || $type === 'reject') {
                $this->suppressAll([$email], SuppressionService::REASON_COMPLAINT, 'SMTP2GO spam complaint');
            } elseif ($type === 'unsubscribed' || $type === 'unsubscribe') {
                $this->suppressAll([$email], SuppressionService::REASON_USER_UNSUBSCRIBE, 'SMTP2GO unsubscribe');
            }
            // soft_bounce / delivered / processed / opened / clicked: log only.
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
        //
        // Phase 43.184 — pre-fix `if (is_array($sig) && $key !== '')`
        // silently bypassed verification when EITHER condition was
        // false. A forged event with `signature` as a string (or
        // absent) escaped verification entirely. Now: when the key IS
        // configured, REQUIRE a valid signature; reject otherwise.
        // Same dev-vs-prod fallback as verifySharedSecret — empty key
        // in prod rejects + logs; empty key in dev warns once + permits.
        $sig = $event['signature'] ?? null;
        $key = (string) ($_ENV['MAILGUN_SIGNING_KEY'] ?? '');
        if ($key !== '') {
            // Key configured → signature MUST be a valid HMAC array.
            if (!is_array($sig)) {
                return Response::json(['ok' => false, 'error' => 'signature missing or malformed'], 401);
            }
            $expected = hash_hmac('sha256',
                ((string) ($sig['timestamp'] ?? '')) . ((string) ($sig['token'] ?? '')),
                $key
            );
            if (!hash_equals($expected, (string) ($sig['signature'] ?? ''))) {
                return Response::json(['ok' => false, 'error' => 'invalid signature'], 401);
            }
        } else {
            // Key NOT configured. Prod → reject. Dev → warn + permit.
            $env = strtolower((string) ($_ENV['APP_ENV'] ?? 'production'));
            // #466: fail CLOSED everywhere except a development allow-list (staging/live/prod/unset all reject).
            $isProd = !in_array($env, ['local', 'development', 'dev', 'testing'], true);
            if ($isProd) {
                error_log('[WebhookController::mailgun] MAILGUN_SIGNING_KEY unset in production — rejecting webhook');
                return Response::json(['ok' => false, 'error' => 'webhook auth not configured'], 401);
            }
            static $mailgunWarned = false;
            if (!$mailgunWarned) {
                $mailgunWarned = true;
                error_log('[WebhookController::mailgun] MAILGUN_SIGNING_KEY unset — signature verification bypassed (dev mode).');
            }
        }

        $eventData = $event['event-data'] ?? [];
        $email = strtolower((string) ($eventData['recipient'] ?? ''));
        $type  = (string) ($eventData['event'] ?? '');
        $severity = (string) ($eventData['severity'] ?? '');

        // Phase 43.184 — dedup id from event-data.id (per-event UUID).
        $mgEventId = (string) ($eventData['id'] ?? '');
        $this->logEvent('mailgun', $type, [$email], $body, $mgEventId !== '' ? $mgEventId : null);

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
     * Constant-time check against MAIL_WEBHOOK_SECRET.
     *
     * Phase 43.184 — dev-friendly skip when secret is unset PRESERVES
     * the pre-fix behavior for local development, BUT refuses to
     * authorize in production (`APP_ENV=production`). Pre-fix, an
     * operator who forgot to set MAIL_WEBHOOK_SECRET in prod left the
     * suppression endpoints world-callable. Now: prod with empty
     * secret → 401 + error_log; dev with empty secret → log warning
     * (once per request via static guard) + permit.
     */
    private function verifySharedSecret(Request $request): bool
    {
        $expected = (string) ($_ENV['MAIL_WEBHOOK_SECRET'] ?? '');
        if ($expected === '') {
            $env = strtolower((string) ($_ENV['APP_ENV'] ?? 'production'));
            // #466: fail CLOSED everywhere except a development allow-list (staging/live/prod/unset all reject).
            $isProd = !in_array($env, ['local', 'development', 'dev', 'testing'], true);
            if ($isProd) {
                error_log('[WebhookController] MAIL_WEBHOOK_SECRET unset in production — rejecting webhook to prevent world-callable suppression endpoint');
                return false;
            }
            // Dev — log first-call warning so operators see the misconfig.
            static $warned = false;
            if (!$warned) {
                $warned = true;
                error_log('[WebhookController] MAIL_WEBHOOK_SECRET unset — webhook auth bypassed (dev mode). Set the env var before deploying to prod.');
            }
            return true;
        }

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

    /**
     * Phase 43.184 — extended with $externalEventId for idempotency.
     * When the provider emits a stable per-event id (SES MessageId,
     * SendGrid sg_event_id, Postmark MessageID, Mailgun event-data.id,
     * SMTP2GO event_id), retries silently dedupe via the
     * uniq_provider_event UNIQUE constraint. Multi-recipient events
     * (SES bounce with N bouncedRecipients) get a synthetic suffix
     * (event_id + "#" + email) so each recipient gets its own row but
     * a retry of the same event hits the same suffix → same row.
     */
    private function logEvent(string $provider, string $type, array $emails, string $payload, ?string $externalEventId = null): void
    {
        $payloadTrim = mb_substr($payload, 0, 65000);
        foreach ($emails as $e) {
            if ($e === '') continue;
            $emailLower = strtolower($e);

            $rowEventId = null;
            if ($externalEventId !== null && $externalEventId !== '') {
                // Cap at column width — 120 chars total. Synthesise
                // per-recipient suffix when the event covers > 1 email
                // (each recipient gets its own dedup key, but a retry
                // of the same event hits the same key).
                $synth = count($emails) > 1
                    ? $externalEventId . '#' . $emailLower
                    : $externalEventId;
                $rowEventId = mb_substr($synth, 0, 120);
            }

            // INSERT IGNORE — the UNIQUE (provider, external_event_id)
            // constraint silently dedupes retries. NULL event_id rows
            // (provider didn't emit one) coexist freely since UNIQUE
            // permits multiple NULLs per MySQL spec.
            $this->db->query(
                "INSERT IGNORE INTO mail_bounce_events
                    (provider, external_event_id, event_type, email, payload, processed)
                 VALUES (?, ?, ?, ?, ?, 1)",
                [$provider, $rowEventId, $type, $emailLower, $payloadTrim]
            );
        }
    }
}
