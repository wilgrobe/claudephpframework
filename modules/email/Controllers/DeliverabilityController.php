<?php
// modules/email/Controllers/DeliverabilityController.php
namespace Modules\Email\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Services\SettingsService;
use Core\Session;
use Modules\Email\Services\DnsAuthChecker;

/**
 * Deliverability dashboard.
 *
 * Two surfaces:
 *  1. SPF / DKIM / DMARC verifier — on-demand DNS lookup against an
 *     admin-supplied domain + DKIM selector. Last-checked domain +
 *     selector cached in site settings so re-visits don't re-prompt.
 *  2. Bounce/complaint-rate aggregate — last-N-day rollup of
 *     mail_bounce_events grouped by provider, with industry-baseline
 *     comparisons (good <2% bounce / good <0.1% complaint).
 *
 * Re-homed from the marketing module (Phase 10 v6.3) into the core
 * email module — deliverability is email infrastructure (sending-
 * domain DNS auth + the email-owned `mail_bounce_events` table), not
 * a marketing-specific concern. Distinct from Phase 28's webhook
 * dashboard which shows individual events; this is aggregate health.
 */
class DeliverabilityController
{
    private Auth $auth;
    private SettingsService $settings;
    private DnsAuthChecker $checker;

    public function __construct()
    {
        $this->auth     = Auth::getInstance();
        $this->settings = new SettingsService();
        $this->checker  = new DnsAuthChecker();
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage()) return $this->denied();

        $defaultDomain = $this->extractDomainFromAppUrl();
        $lastDomain   = (string) $this->settings->get('email_deliverability_last_domain',   $defaultDomain, 'site');
        $lastSelector = (string) $this->settings->get('email_deliverability_last_selector', 'default',     'site');

        $checks   = null;
        $domain   = trim((string) $request->query('domain', ''));
        $selector = trim((string) $request->query('selector', ''));
        if ($domain !== '' && $selector !== '') {
            $checks = [
                'spf'   => $this->checker->checkSpf($domain),
                'dmarc' => $this->checker->checkDmarc($domain),
                'dkim'  => $this->checker->checkDkim($domain, $selector),
            ];
            // Memoise the last-tried values so the form pre-fills next time.
            $this->settings->set('email_deliverability_last_domain',   $domain,   'site', null, 'string');
            $this->settings->set('email_deliverability_last_selector', $selector, 'site', null, 'string');
            $lastDomain   = $domain;
            $lastSelector = $selector;
        }

        $bounce30 = $this->checker->aggregateBounceEvents(30);
        $bounce7  = $this->checker->aggregateBounceEvents(7);

        return Response::view('email::admin.deliverability', [
            'checks'       => $checks,
            'lastDomain'   => $lastDomain,
            'lastSelector' => $lastSelector,
            'bounce30'     => $bounce30,
            'bounce7'      => $bounce7,
            'user'         => $this->auth->user(),
        ]);
    }

    /**
     * Pull the domain part of APP_URL as a sensible default for the
     * verifier form. Falls back to empty string when APP_URL is not
     * set or is localhost.
     */
    private function extractDomainFromAppUrl(): string
    {
        $url = (string) ($_ENV['APP_URL'] ?? '');
        if ($url === '') return '';
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return '';
        }
        // Strip leading "www." so the verifier checks the apex by default.
        return preg_replace('/^www\./', '', $host) ?? '';
    }

    private function canManage(): bool
    {
        return $this->auth->check() && $this->auth->isSuperAdmin();
    }

    private function denied(): Response
    {
        Session::flash('error', 'Only superadmins can view deliverability data.');
        return Response::redirect('/admin/email-suppressions');
    }
}
