<?php
// modules/audit-log-viewer/Controllers/WebhookDashboardController.php
namespace Modules\AuditLogViewer\Controllers;

use Core\Auth\Auth;
use Core\Database\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Phase 28 — webhook delivery dashboard.
 *
 *   GET  /admin/webhooks                           — unified delivery list
 *   GET  /admin/webhooks/{source}/{id}             — detail w/ payload + replay
 *   POST /admin/webhooks/{source}/{id}/replay      — re-process event
 *
 * Two data sources are merged:
 *   - mail_bounce_events  (provider, event_type, email, payload, received_at)
 *     5 email providers (SES / SendGrid / Postmark / Mailgun / SMTP2GO)
 *     Full payload retained (LONGTEXT capped at 65 KB). NOT replayable —
 *     bounce events are append-only and already triggered suppression.
 *   - subscription_events (gateway, event_type, event_id, payload_json,
 *                          occurred_at)
 *     Stripe subscription webhook events. UNIQUE on (gateway, event_id)
 *     guarantees idempotency, so replay is safe — re-runs
 *     SubscriptionService::applyWebhookEvent against the persisted JSON.
 *
 * Marketing tracking (opens/clicks) writes to email_campaign_recipients
 * but doesn't persist raw payloads, so it's surfaced in the marketing
 * broadcast show view rather than here.
 *
 * Gate: same as the audit-log viewer + activity timeline (audit.view OR
 * admin.access). Read-only except for the replay POST, which is also
 * admin-gated.
 */
final class WebhookDashboardController
{
    private Auth     $auth;
    private Database $db;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->db   = Database::getInstance();
    }

    private function gate(): ?Response
    {
        if ($this->auth->guest()) return Response::redirect('/login');
        if (!$this->auth->can('audit.view') && !$this->auth->can('admin.access')) {
            return new Response('Forbidden', 403);
        }
        return null;
    }

    public function index(Request $request): Response
    {
        if ($g = $this->gate()) return $g;

        $source     = strtolower(trim((string) $request->query('source', 'all')));
        if (!in_array($source, ['all', 'email', 'stripe'], true)) $source = 'all';
        $provider   = trim((string) $request->query('provider')) ?: null;
        $eventType  = trim((string) $request->query('event_type')) ?: null;
        $dateFrom   = trim((string) $request->query('date_from')) ?: null;
        $dateTo     = trim((string) $request->query('date_to'))   ?: null;
        $q          = trim((string) $request->query('q'))         ?: null;
        $page       = max(1, (int) $request->query('page', 1));
        $perPage    = 30;

        $fetchEach = max($perPage * 5, 200);

        $items = [];
        if ($source === 'all' || $source === 'email') {
            foreach ($this->fetchEmailEvents($provider, $eventType, $dateFrom, $dateTo, $q, $fetchEach) as $r) {
                $items[] = $this->shapeEmail($r);
            }
        }
        if ($source === 'all' || $source === 'stripe') {
            foreach ($this->fetchStripeEvents($eventType, $dateFrom, $dateTo, $q, $fetchEach) as $r) {
                $items[] = $this->shapeStripe($r);
            }
        }

        // Newest first, stable tiebreak.
        usort($items, function ($a, $b) {
            $cmp = strcmp((string) $b['ts'], (string) $a['ts']);
            if ($cmp !== 0) return $cmp;
            return strcmp((string) $b['id'], (string) $a['id']);
        });

        $totalLoaded = count($items);
        $offset      = ($page - 1) * $perPage;
        $items       = array_slice($items, $offset, $perPage);

        // Provider tally for the sidebar filter chips. Done after the
        // shape pass so the chips reflect the loaded working set.
        $tally = ['email:ses' => 0, 'email:sendgrid' => 0, 'email:postmark' => 0,
                  'email:mailgun' => 0, 'email:smtp2go' => 0, 'stripe' => 0];
        // Sample from the underlying tables for an accurate count.
        try {
            foreach ($this->db->fetchAll(
                "SELECT provider, COUNT(*) AS n FROM mail_bounce_events GROUP BY provider", []
            ) as $r) {
                $tally['email:' . (string) $r['provider']] = (int) $r['n'];
            }
        } catch (\Throwable) {}
        try {
            $stripeTotal = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM subscription_events", [],
            );
            $tally['stripe'] = $stripeTotal;
        } catch (\Throwable) {}

        return Response::view('audit_log_viewer::admin.webhooks', [
            'items'        => $items,
            'source'       => $source,
            'filters'      => [
                'source'     => $source,
                'provider'   => $provider,
                'event_type' => $eventType,
                'date_from'  => $dateFrom,
                'date_to'    => $dateTo,
                'q'          => $q,
            ],
            'page'         => $page,
            'per_page'     => $perPage,
            'total_loaded' => $totalLoaded,
            'fetch_each'   => $fetchEach,
            'tally'        => $tally,
        ]);
    }

    public function show(Request $request): Response
    {
        if ($g = $this->gate()) return $g;

        $source = (string) $request->param(0);
        $id     = (int) $request->param(1);

        $detail = $this->fetchOne($source, $id);
        if (!$detail) return new Response('Not found', 404);

        return Response::view('audit_log_viewer::admin.webhook_show', [
            'source'  => $source,
            'detail'  => $detail,
            'csrf'    => csrf_token(),
        ]);
    }

    public function replay(Request $request): Response
    {
        if ($g = $this->gate()) return $g;

        $source = (string) $request->param(0);
        $id     = (int) $request->param(1);

        $detail = $this->fetchOne($source, $id);
        if (!$detail) return new Response('Not found', 404);

        if ($source !== 'stripe') {
            Session::flash('error', 'Replay is only supported for Stripe events. Email-provider bounce/complaint events are append-only — the suppression they triggered has already taken effect.');
            return Response::redirect("/admin/webhooks/$source/$id");
        }

        // Re-apply via SubscriptionService. The UNIQUE constraint on
        // (gateway, event_id) makes the insert side idempotent; the
        // service's per-event handlers (cancel/upgrade/etc.) are all
        // designed to be safe to re-run.
        try {
            $payload = is_array($detail['payload_decoded']) ? $detail['payload_decoded'] : [];
            if (empty($payload)) {
                throw new \RuntimeException('Stored payload is empty or unparseable.');
            }
            // applyWebhookEvent expects the full Stripe event object.
            if (class_exists(\Modules\Subscriptions\Services\SubscriptionService::class)) {
                (new \Modules\Subscriptions\Services\SubscriptionService())->applyWebhookEvent($payload);
            } else {
                throw new \RuntimeException('SubscriptionService not loaded — replay requires the subscriptions module to be in the runtime allowlist.');
            }
            Session::flash('success', 'Replayed Stripe event #' . $id . ' — idempotent on event_id, so a redelivery doesn\'t double-process.');
        } catch (\Throwable $e) {
            Session::flash('error', 'Replay failed: ' . $e->getMessage());
        }

        return Response::redirect("/admin/webhooks/$source/$id");
    }

    // ── Source-specific fetchers ───────────────────────────────────

    private function fetchEmailEvents(?string $provider, ?string $eventType, ?string $from, ?string $to, ?string $q, int $limit): array
    {
        try {
            $this->db->fetchOne("SELECT 1 FROM mail_bounce_events LIMIT 0", []);
        } catch (\Throwable) {
            return [];
        }
        $where = ['1=1'];
        $params = [];
        if ($provider !== null && str_starts_with($provider, 'email:')) {
            $where[] = 'provider = ?';
            $params[] = substr($provider, 6);
        }
        if ($eventType !== null) { $where[] = 'event_type = ?'; $params[] = $eventType; }
        if ($from !== null) { $where[] = 'received_at >= ?'; $params[] = $from; }
        if ($to   !== null) { $where[] = 'received_at <= ?'; $params[] = $to; }
        if ($q    !== null) {
            $like = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';
            $where[] = '(email LIKE ? OR event_type LIKE ?)';
            $params[] = $like; $params[] = $like;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $params[] = $limit;
        return $this->db->fetchAll(
            "SELECT id, provider, event_type, email, processed, received_at
               FROM mail_bounce_events $whereSql
           ORDER BY received_at DESC, id DESC LIMIT ?",
            $params,
        );
    }

    private function fetchStripeEvents(?string $eventType, ?string $from, ?string $to, ?string $q, int $limit): array
    {
        try {
            $this->db->fetchOne("SELECT 1 FROM subscription_events LIMIT 0", []);
        } catch (\Throwable) {
            return [];
        }
        $where = ['1=1'];
        $params = [];
        if ($eventType !== null) { $where[] = 'event_type = ?'; $params[] = $eventType; }
        if ($from !== null) { $where[] = 'occurred_at >= ?'; $params[] = $from; }
        if ($to   !== null) { $where[] = 'occurred_at <= ?'; $params[] = $to; }
        if ($q    !== null) {
            $like = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';
            $where[] = '(event_type LIKE ? OR event_id LIKE ?)';
            $params[] = $like; $params[] = $like;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $params[] = $limit;
        return $this->db->fetchAll(
            "SELECT id, gateway, event_type, event_id, subscription_id, occurred_at
               FROM subscription_events $whereSql
           ORDER BY occurred_at DESC, id DESC LIMIT ?",
            $params,
        );
    }

    private function fetchOne(string $source, int $id): ?array
    {
        if ($source === 'email') {
            try {
                $row = $this->db->fetchOne(
                    "SELECT * FROM mail_bounce_events WHERE id = ?", [$id],
                );
            } catch (\Throwable) {
                return null;
            }
            if (!$row) return null;
            $payload = (string) ($row['payload'] ?? '');
            $decoded = $payload !== '' ? json_decode($payload, true) : null;
            return [
                'kind'             => 'email',
                'id'               => (int) $row['id'],
                'ts'               => $row['received_at'],
                'provider'         => 'email:' . $row['provider'],
                'event_type'       => $row['event_type'],
                'subject_label'    => $row['email'] ?? '',
                'processed'        => (int) ($row['processed'] ?? 0) === 1,
                'payload_raw'      => $payload,
                'payload_decoded'  => is_array($decoded) ? $decoded : null,
                'replay_supported' => false,
                'replay_disabled_reason' => 'Bounce/complaint events are append-only — suppression already applied at receipt.',
            ];
        }
        if ($source === 'stripe') {
            try {
                $row = $this->db->fetchOne(
                    "SELECT * FROM subscription_events WHERE id = ?", [$id],
                );
            } catch (\Throwable) {
                return null;
            }
            if (!$row) return null;
            $payload = (string) ($row['payload_json'] ?? '');
            $decoded = $payload !== '' ? json_decode($payload, true) : null;
            return [
                'kind'             => 'stripe',
                'id'               => (int) $row['id'],
                'ts'               => $row['occurred_at'],
                'provider'         => 'stripe',
                'event_type'       => $row['event_type'],
                'subject_label'    => $row['event_id'] ?? '',
                'processed'        => true,
                'payload_raw'      => $payload,
                'payload_decoded'  => is_array($decoded) ? $decoded : null,
                'replay_supported' => true,
                'replay_disabled_reason' => null,
                'subscription_id'  => $row['subscription_id'] ?? null,
            ];
        }
        return null;
    }

    // ── Shapers ────────────────────────────────────────────────────

    private function shapeEmail(array $r): array
    {
        $icon = match ((string) $r['event_type']) {
            'hard_bounce', 'bounce' => '⤴',
            'complaint', 'spam'     => '🚫',
            'delivered'             => '✓',
            'opened', 'open'        => '👁',
            'clicked', 'click'      => '🔗',
            'unsubscribed', 'unsubscribe' => '🔕',
            default                 => '✉',
        };
        $color = match ((string) $r['event_type']) {
            'hard_bounce', 'bounce', 'complaint', 'spam' => '#dc2626',
            'delivered', 'opened', 'open'                => '#16a34a',
            default                                      => 'var(--color-gray-500)',
        };
        return [
            'kind'          => 'email',
            'source'        => 'email',
            'id'            => (int) $r['id'],
            'ts'            => $r['received_at'],
            'icon'          => $icon,
            'color'         => $color,
            'provider'      => 'email:' . $r['provider'],
            'provider_label'=> ucfirst((string) $r['provider']),
            'event_type'    => $r['event_type'],
            'subject_label' => $r['email'] ?? '',
            'processed'     => (int) ($r['processed'] ?? 0) === 1,
        ];
    }

    private function shapeStripe(array $r): array
    {
        $type = (string) $r['event_type'];
        $icon = match (true) {
            str_starts_with($type, 'invoice.')           => '🧾',
            str_starts_with($type, 'customer.subscription.') => '🔁',
            str_starts_with($type, 'payment_intent.')    => '💳',
            str_starts_with($type, 'charge.')            => '💰',
            str_starts_with($type, 'checkout.')          => '🛒',
            default                                       => '⚡',
        };
        return [
            'kind'           => 'stripe',
            'source'         => 'stripe',
            'id'             => (int) $r['id'],
            'ts'             => $r['occurred_at'],
            'icon'           => $icon,
            'color'          => '#0891b2',
            'provider'       => 'stripe',
            'provider_label' => 'Stripe',
            'event_type'     => $type,
            'subject_label'  => $r['event_id'] ?? '',
            'processed'      => true,
        ];
    }
}
