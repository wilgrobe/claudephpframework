<?php
// modules/email/Services/DnsAuthChecker.php
namespace Modules\Email\Services;

/**
 * SPF / DKIM / DMARC verifier for a sending domain.
 *
 * Stateless. Each `check*` call does a fresh DNS lookup and returns
 * a structured result the deliverability dashboard can render. PHP's
 * built-in `dns_get_record` handles the lookups (no curl, no
 * external tool). DNS isn't cached here — admins might be iterating
 * on records and want fresh reads — but real cron-driven checks
 * SHOULD memoise per run to stay polite to the resolver.
 *
 * Re-homed from the marketing module (Phase 10 v6.3) into the core
 * email module — deliverability is email infrastructure (sending-
 * domain DNS auth + aggregating the email-module-owned
 * `mail_bounce_events` table), not a marketing-specific concern.
 *
 * Result envelope:
 *   [
 *     'pass'    => bool,
 *     'records' => string[],   // raw TXT values found
 *     'detail'  => string,     // short human-readable diagnosis
 *     'hints'   => string[],   // bullet list of fixes
 *   ]
 *
 * DKIM is selector-specific — there's no "lookup all DKIM keys" DNS
 * query. Admins must know their provider's selector. Common defaults
 * documented in the form view ("default" / "google" / "s1" /
 * "selector1" / "smtpapi" / etc).
 */
class DnsAuthChecker
{
    /**
     * Check the SPF record at `<domain>` (TXT lookup, filter for v=spf1).
     */
    public function checkSpf(string $domain): array
    {
        $domain = $this->sanitizeDomain($domain);
        if ($domain === '') {
            return $this->fail('Invalid domain.');
        }

        $records = $this->lookupTxt($domain);
        $spfRecords = array_values(array_filter($records, fn($r) => stripos($r, 'v=spf1') === 0));

        if (count($spfRecords) === 0) {
            return [
                'pass'    => false,
                'records' => [],
                'detail'  => 'No SPF record found.',
                'hints'   => [
                    'Publish a TXT record at the apex: e.g. "v=spf1 include:_spf.google.com include:sendgrid.net ~all".',
                    'List every authorised sender (your ESP, transactional provider, marketing tool).',
                    'End with "~all" (soft-fail) or "-all" (hard-fail) — not "+all" (open).',
                ],
            ];
        }
        if (count($spfRecords) > 1) {
            return [
                'pass'    => false,
                'records' => $spfRecords,
                'detail'  => 'Multiple SPF records found — RFC 7208 forbids more than one. Mail will fail SPF auth.',
                'hints'   => [
                    'Merge into a single TXT record: combine all your "include:" mechanisms into one string.',
                    'Delete the duplicate(s) at your DNS provider.',
                ],
            ];
        }

        $record = $spfRecords[0];
        $hints = [];
        if (stripos($record, '+all') !== false) {
            $hints[] = '"+all" allows ANY sender — replace with "~all" (soft-fail) or "-all" (hard-fail).';
        }
        if (preg_match_all('/include:|a:|mx|ip4:|ip6:|exists:/i', $record) > 10) {
            $hints[] = 'SPF has a 10-DNS-lookup limit — consider flattening with a tool like SPFlattener.';
        }

        return [
            'pass'    => empty($hints),
            'records' => $spfRecords,
            'detail'  => empty($hints) ? 'SPF looks good.' : 'SPF found but with concerns.',
            'hints'   => $hints,
        ];
    }

    /**
     * Check the DMARC record at `_dmarc.<domain>`.
     */
    public function checkDmarc(string $domain): array
    {
        $domain = $this->sanitizeDomain($domain);
        if ($domain === '') return $this->fail('Invalid domain.');

        $records = $this->lookupTxt('_dmarc.' . $domain);
        $dmarc = array_values(array_filter($records, fn($r) => stripos($r, 'v=DMARC1') === 0));

        if (count($dmarc) === 0) {
            return [
                'pass'    => false,
                'records' => [],
                'detail'  => 'No DMARC record found.',
                'hints'   => [
                    'Publish a TXT at _dmarc.' . $domain . ': e.g. "v=DMARC1; p=quarantine; rua=mailto:dmarc@' . $domain . '".',
                    'Start with p=none to monitor without affecting delivery; ramp to quarantine then reject.',
                    'rua= sends aggregate reports — set to a mailbox you actually monitor.',
                ],
            ];
        }

        $record = $dmarc[0];
        $hints = [];
        $policyMatch = [];
        preg_match('/p=([a-z]+)/i', $record, $policyMatch);
        $policy = strtolower($policyMatch[1] ?? '');
        if ($policy === 'none') {
            $hints[] = 'p=none is monitor-only — once you have clean reports, ramp to p=quarantine then p=reject.';
        }
        if (stripos($record, 'rua=') === false) {
            $hints[] = 'No rua= aggregate-report mailbox — you won\'t see what\'s failing.';
        }

        return [
            'pass'    => empty($hints) && $policy !== '',
            'records' => $dmarc,
            'detail'  => 'DMARC policy: ' . ($policy ?: '(none)'),
            'hints'   => $hints,
        ];
    }

    /**
     * Check a DKIM record at `<selector>._domainkey.<domain>`. Selector
     * is provider-specific (e.g. "default", "google", "s1", "selector1",
     * "k1"). Returns "no record" when the selector lookup is empty.
     */
    public function checkDkim(string $domain, string $selector): array
    {
        $domain   = $this->sanitizeDomain($domain);
        $selector = preg_replace('/[^a-zA-Z0-9._-]/', '', trim($selector));
        if ($domain === '' || $selector === '') {
            return $this->fail('Invalid domain or selector.');
        }

        $records = $this->lookupTxt($selector . '._domainkey.' . $domain);
        $dkim = array_values(array_filter($records, fn($r) => stripos($r, 'v=DKIM1') !== false || stripos($r, 'k=rsa') !== false || stripos($r, 'p=') !== false));

        if (count($dkim) === 0) {
            return [
                'pass'    => false,
                'records' => [],
                'detail'  => 'No DKIM record at ' . $selector . '._domainkey.' . $domain,
                'hints'   => [
                    'Make sure the selector matches what your ESP advertises (often "default", "google", "s1", or a per-account selector).',
                    'Check the ESP\'s sending-domain settings page for the exact CNAME or TXT record to publish.',
                    'DNS propagation can take up to 24h; retry later if you just published.',
                ],
            ];
        }

        $record = $dkim[0];
        $hints = [];
        if (stripos($record, 'p=') !== false && preg_match('/p=([A-Za-z0-9+\/=]*)/', $record, $m)) {
            $publicKey = $m[1];
            if (strlen($publicKey) < 100) {
                $hints[] = 'DKIM public key looks short — most providers use 1024-bit (170 chars) or 2048-bit (380 chars) keys.';
            }
        }
        if (stripos($record, 'p=;') !== false) {
            $hints[] = 'DKIM record has empty public key (p=;) — this revokes the key. Publish the real key.';
        }

        return [
            'pass'    => empty($hints),
            'records' => $dkim,
            'detail'  => empty($hints) ? 'DKIM record found at the selector.' : 'DKIM found but with concerns.',
            'hints'   => $hints,
        ];
    }

    /**
     * Aggregate counts from `mail_bounce_events` for a window. Used by
     * the deliverability dashboard's bounce/complaint-rate panel.
     *
     * Returns:
     *   [
     *     'window_days' => int,
     *     'totals'      => ['delivered' => N, 'bounced' => N, 'complaint' => N, 'unsubscribe' => N, 'other' => N],
     *     'by_provider' => [provider => totals[]],
     *   ]
     *
     * Categorisation maps raw event_type strings to coarse buckets via
     * keyword match — providers vary in capitalisation + naming
     * ('Bounce' / 'bounce' / 'hard_bounce' all bucket together).
     */
    public function aggregateBounceEvents(int $windowDays = 30): array
    {
        $db = \Core\Database\Database::getInstance();
        $cutoff = (new \DateTimeImmutable())->modify("-{$windowDays} days")->format('Y-m-d H:i:s');

        $rows = $db->fetchAll(
            "SELECT provider, event_type, COUNT(*) AS n
               FROM mail_bounce_events
              WHERE received_at >= ?
           GROUP BY provider, event_type
           ORDER BY provider ASC, n DESC",
            [$cutoff]
        );

        $emptyTotals = ['delivered' => 0, 'bounced' => 0, 'complaint' => 0, 'unsubscribe' => 0, 'other' => 0];
        $totals = $emptyTotals;
        $byProvider = [];

        foreach ($rows as $r) {
            $bucket = $this->bucketize((string) $r['event_type']);
            $n = (int) $r['n'];
            $prov = (string) $r['provider'];

            if (!isset($byProvider[$prov])) $byProvider[$prov] = $emptyTotals;
            $byProvider[$prov][$bucket] += $n;
            $totals[$bucket] += $n;
        }

        return [
            'window_days' => $windowDays,
            'totals'      => $totals,
            'by_provider' => $byProvider,
        ];
    }

    /**
     * Map raw event_type strings to coarse buckets. Each provider
     * names events differently; the bucket vocabulary is what the
     * dashboard renders.
     */
    private function bucketize(string $type): string
    {
        $t = strtolower($type);
        if (str_contains($t, 'bounce')) return 'bounced';
        if (str_contains($t, 'complaint') || str_contains($t, 'spam') || str_contains($t, 'reject')) return 'complaint';
        if (str_contains($t, 'unsubscribe')) return 'unsubscribe';
        if (str_contains($t, 'deliver') || str_contains($t, 'sent')) return 'delivered';
        return 'other';
    }

    private function lookupTxt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);
        if (!is_array($records) || empty($records)) return [];
        $txt = [];
        foreach ($records as $r) {
            // Some resolvers return TXT chunks split into 'entries' (an
            // array). Concatenate when present, otherwise use the single
            // 'txt' field.
            if (!empty($r['entries']) && is_array($r['entries'])) {
                $txt[] = implode('', $r['entries']);
            } elseif (!empty($r['txt'])) {
                $txt[] = (string) $r['txt'];
            }
        }
        return $txt;
    }

    private function sanitizeDomain(string $raw): string
    {
        $d = strtolower(trim($raw));
        $d = preg_replace('/^https?:\/\//', '', (string) $d);
        $d = trim((string) $d, '/');
        // Allow only IDNA-safe ASCII domain chars; punycode upstream if needed.
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)) return '';
        return $d;
    }

    private function fail(string $detail): array
    {
        return ['pass' => false, 'records' => [], 'detail' => $detail, 'hints' => []];
    }
}
