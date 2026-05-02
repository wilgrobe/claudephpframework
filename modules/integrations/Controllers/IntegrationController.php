<?php
// modules/integrations/Controllers/IntegrationController.php
namespace Modules\Integrations\Controllers;

use Core\Auth\Auth;
use Core\Request;
use Core\Response;
use Core\Services\IntegrationConfig;
use Core\Services\MailService;

/**
 * Read-only status dashboard for third-party integrations. Ported from
 * App\Controllers\Admin\IntegrationController — only namespace and view name
 * changed; probe logic is identical.
 *
 * All integration config lives in .env (see .env.example). This controller
 * never writes config — its job is to show which integrations are configured
 * correctly and run a live "Test" probe against each.
 */
class IntegrationController
{
    private Auth $auth;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return $this->denied();
        return Response::view('integrations::admin.index', [
            'integrations' => IntegrationConfig::describeAll(),
            'user'         => $this->auth->user(),
        ]);
    }

    public function test(Request $request): Response
    {
        if (!$this->auth->isSuperAdmin()) return Response::json(['error' => 'Forbidden'], 403);

        $type = (string) $request->post('type', '');
        if (!in_array($type, IntegrationConfig::types(), true)) {
            return Response::json(['error' => 'Unknown integration type.'], 400);
        }

        if (!IntegrationConfig::enabled($type)) {
            return Response::json([
                'success' => false,
                'message' => 'Not configured. Set the required env vars and try again.',
            ]);
        }

        $result = $this->runProbe($type);
        return Response::json([
            'success' => $result['ok'],
            'message' => $result['message'],
        ]);
    }

    /**
     * Per-type test probes. Each returns ['ok' => bool, 'message' => str].
     * Keep side effects small and observable — mail sends to the configured
     * from_address so it lands somewhere the admin can see.
     */
    private function runProbe(string $type): array
    {
        switch ($type) {
            case 'email':
                $cfg = IntegrationConfig::config('email');
                $to  = $cfg['from_address'] ?? '';
                if ($to === '') {
                    return ['ok' => false, 'message' => 'MAIL_FROM_ADDRESS not set — nowhere to send the test to.'];
                }
                $mail = new MailService();
                $ok   = $mail->send($to, 'Integration Test', '<p>Test email from framework. Safe to ignore.</p>');
                return ['ok' => $ok, 'message' => $ok ? "Test email sent to $to." : 'Send failed — check Superadmin > Message Log for the error.'];

            case 'sms':
                $cfg = IntegrationConfig::config('sms');
                $driver = $cfg['driver'] ?? 'none';
                return ['ok' => true, 'message' => "SMS driver active: $driver. Send a real message to verify end-to-end."];

            case 'storage':
                try {
                    new \Core\Services\FileUploadService();
                    return ['ok' => true, 'message' => 'Storage driver initialized successfully.'];
                } catch (\Throwable $e) {
                    return ['ok' => false, 'message' => 'Storage init failed: ' . $e->getMessage()];
                }

            case 'ai':
                return ['ok' => true, 'message' => 'No automated probe for AI yet — use the feature that depends on it.'];

            case 'broadcast':
                return ['ok' => true, 'message' => 'No automated probe for broadcasting yet.'];

            case 'oauth':
                return ['ok' => true, 'message' => 'OAuth providers must be tested by completing a real sign-in flow.'];

            case 'sentry':
                \Core\Services\SentryService::captureMessage(
                    'Sentry integration test from admin dashboard',
                    'info',
                    ['triggered_by' => $this->auth->user()['email'] ?? 'unknown']
                );
                return ['ok' => true, 'message' => 'Test message dispatched to Sentry. Check your Sentry project in ~30s.'];

            case 'captcha':
                $cfg = \Core\Services\IntegrationConfig::config('captcha');
                return ['ok' => true, 'message' =>
                    "Provider: {$cfg['driver']}. Site key configured: "
                    . (!empty($cfg['site_key']) ? 'yes' : 'no')
                    . ". End-to-end verification happens when a real user submits a form."];

            case 'cache':
                $cache = \Core\Services\CacheService::instance();
                $token = bin2hex(random_bytes(8));
                $cache->set('__probe', $token, 30);
                $got   = $cache->get('__probe');
                $cache->forget('__probe');
                $ok    = ($got === $token);
                return [
                    'ok'      => $ok,
                    'message' => $ok
                        ? 'Cache round-trip succeeded using the ' . $cache->driver() . ' driver.'
                        : 'Cache round-trip failed using the ' . $cache->driver() . ' driver.',
                ];

            case 'analytics':
                $snippet = \Core\Services\AnalyticsService::snippet();
                return ['ok' => $snippet !== '', 'message' => $snippet !== ''
                    ? 'Analytics snippet generated — it will render in the <head> of every page.'
                    : 'No analytics snippet rendered — check ANALYTICS_PROVIDER and provider-specific vars.'];

            case 'payments':
                $cfg = \Core\Services\IntegrationConfig::config('payments');
                $prov = $cfg['driver'] ?? 'none';
                if ($prov === 'stripe') {
                    $res = (new \Core\Services\StripeService())->call('GET', '/v1/balance');
                    return ['ok' => $res !== null, 'message' => $res !== null
                        ? 'Stripe credentials accepted (fetched /v1/balance successfully).'
                        : 'Stripe rejected the request. Check STRIPE_SECRET_KEY and the PHP error log.'];
                }
                if ($prov === 'braintree') {
                    $svc = new \Core\Services\BraintreeService();
                    $res = $svc->call('POST', '/client_token', '<client-token/>');
                    return ['ok' => $res !== null, 'message' => $res !== null
                        ? "Braintree credentials accepted in {$svc->environment()} environment."
                        : 'Braintree rejected the request. Check BRAINTREE_* env vars.'];
                }
                if ($prov === 'square') {
                    $svc = new \Core\Services\SquareService();
                    $res = $svc->call('GET', '/v2/locations');
                    return ['ok' => $res !== null, 'message' => $res !== null
                        ? "Square credentials accepted in {$svc->environment()} environment."
                        : 'Square rejected the request. Check SQUARE_ACCESS_TOKEN and SQUARE_LOCATION_ID.'];
                }
                return ['ok' => false, 'message' => 'No payments provider configured.'];

            case 'media_cdn':
                $cfg = \Core\Services\IntegrationConfig::config('media_cdn');
                $sample = 'https://example.com/sample.jpg';
                $out    = \Core\Services\MediaCdnService::rewrite($sample, ['w' => 800]);
                return ['ok' => $out !== $sample, 'message' => $out !== $sample
                    ? "Rewrite working. Sample: $out"
                    : 'CDN rewrite produced no change — check provider-specific vars.'];

            case 'search':
                $cfg = \Core\Services\IntegrationConfig::config('search');
                $prov = $cfg['driver'] ?? 'none';
                if ($prov === 'meilisearch') {
                    $host = rtrim((string)($cfg['host'] ?? ''), '/');
                    $raw  = @file_get_contents("$host/health", false, stream_context_create([
                        'http' => ['timeout' => 5.0, 'ignore_errors' => true]
                    ]));
                    return ['ok' => $raw !== false && str_contains((string)$raw, 'available'),
                            'message' => $raw !== false ? 'Meilisearch /health reachable.' : 'Could not reach Meilisearch host.'];
                }
                return ['ok' => true, 'message' => "Search provider: $prov. End-to-end test requires an indexed document."];

            case 'video':
                $video = new \Core\Services\VideoService();
                return ['ok' => $video->isEnabled(), 'message' => $video->isEnabled()
                    ? "Video provider active: {$video->provider()}. No automated probe — upload a test asset to verify."
                    : 'Video provider not configured.'];

            case 'cdn':
                $cdn = new \Core\Services\CdnService();
                if (!$cdn->isEnabled()) {
                    return ['ok' => false, 'message' => 'No CDN provider configured.'];
                }
                $cfg  = \Core\Services\IntegrationConfig::config('cdn');
                $prov = $cdn->provider();

                if ($prov === 'cloudflare') {
                    $zone  = (string) ($cfg['zone_id']   ?? '');
                    $token = (string) ($cfg['api_token'] ?? '');
                    $ctx = stream_context_create(['http' => [
                        'method'        => 'GET',
                        'header'        => "Authorization: Bearer $token\r\n",
                        'timeout'       => 6.0,
                        'ignore_errors' => true,
                    ]]);
                    $raw = @file_get_contents("https://api.cloudflare.com/client/v4/zones/$zone", false, $ctx);
                    $ok  = $raw !== false && !empty(json_decode($raw, true)['success'] ?? null);
                    return ['ok' => $ok, 'message' => $ok ? 'Cloudflare zone token verified.' : 'Cloudflare rejected the token.'];
                }
                if ($prov === 'cloudfront') {
                    $have = !empty($cfg['distribution_id']) && !empty($cfg['aws_access_key']);
                    return ['ok' => $have, 'message' => $have
                        ? 'CloudFront distribution id + credentials present. Install aws/aws-sdk-php for live probes.'
                        : 'CloudFront credentials incomplete.'];
                }
                if ($prov === 'fastly') {
                    $token = (string) ($cfg['api_token'] ?? '');
                    $ctx = stream_context_create(['http' => [
                        'method'        => 'GET',
                        'header'        => "Fastly-Key: $token\r\nAccept: application/json\r\n",
                        'timeout'       => 6.0,
                        'ignore_errors' => true,
                    ]]);
                    $raw = @file_get_contents('https://api.fastly.com/current_customer', false, $ctx);
                    $ok  = $raw !== false && str_contains((string)$raw, '"id"');
                    return ['ok' => $ok, 'message' => $ok ? 'Fastly API token verified.' : 'Fastly rejected the token.'];
                }
                if ($prov === 'bunny') {
                    $key = (string) ($cfg['api_key'] ?? '');
                    $ctx = stream_context_create(['http' => [
                        'method'        => 'GET',
                        'header'        => "AccessKey: $key\r\nAccept: application/json\r\n",
                        'timeout'       => 6.0,
                        'ignore_errors' => true,
                    ]]);
                    $raw = @file_get_contents('https://api.bunny.net/pullzone', false, $ctx);
                    $ok  = $raw !== false && json_decode($raw, true) !== null;
                    return ['ok' => $ok, 'message' => $ok ? 'Bunny API key verified.' : 'Bunny rejected the key.'];
                }
                return ['ok' => true, 'message' => "CDN provider: $prov."];
        }

        return ['ok' => true, 'message' => 'No automated test for this integration type.'];
    }

    private function denied(): Response
    {
        return Response::redirect('/admin')->withFlash('error', 'Superadmin access required.');
    }
}
