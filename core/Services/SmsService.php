<?php
// core/Services/SmsService.php
namespace Core\Services;

use Core\Contracts\SmsDriver;
use Core\Database\Database;

class SmsService implements SmsDriver
{
    private Database $db;
    private array    $config;

    public function __construct()
    {
        $this->db = Database::getInstance();

        // All SMS config is sourced from .env via IntegrationConfig. The
        // driver selector (SMS_DRIVER) plus provider-specific credentials
        // (SMS_TWILIO_*, SMS_VONAGE_*) are returned in one flat map.
        $env    = IntegrationConfig::config('sms');
        $driver = strtolower((string) ($env['driver'] ?? 'auto'));

        // SMS_DRIVER=auto means "log in dev, real provider in production".
        // In production with auto + no real provider credentials, fall
        // through to 'none' so we never try to hit a real provider with
        // blank creds. Captured messages with provider='log' land in
        // Superadmin > Message Log for inspection during dev.
        $appEnv = $_ENV['APP_ENV'] ?? 'production';
        if ($driver === 'auto') {
            $driver = $appEnv !== 'production' ? 'log' : 'none';
        }

        // Refuse the capture driver in production. doSend() writes the
        // recipient phone number + message body to the PHP error log,
        // which can expose OTP codes, 2FA challenges, and other sensitive
        // content to anyone with log access. Misconfiguring SMS_DRIVER=log
        // in production should fail loud, not silently.
        if ($appEnv === 'production' && $driver === 'log') {
            throw new \RuntimeException(
                "SmsService: driver 'log' is refused in production because it " .
                "logs recipient numbers and message bodies. Set SMS_DRIVER to " .
                "'auto', 'none', or a real provider ('twilio', 'vonage') and restart."
            );
        }

        $this->config = array_merge($env, ['provider' => $driver]);
    }

    // Mirrors MailService's retry policy — see MailService::RETRY_BACKOFF_MINUTES.
    private const RETRY_BACKOFF_MINUTES = [1, 5, 25];
    private const MAX_ATTEMPTS          = 3;

    /**
     * Default CTIA / A2P-10DLC opt-out notice appended to every outbound
     * SMS. Carriers + TCR reviewers expect recurring messages to carry
     * inline opt-out language. Override the wording (e.g. to add HELP) or
     * disable it (empty value) via the SMS_OPT_OUT_NOTICE env var.
     */
    private const DEFAULT_OPT_OUT_NOTICE = 'Reply STOP to opt out';

    public function send(string $to, string $body): bool
    {
        // Append the opt-out notice once, here at the single send entry
        // point, so EVERY outbound SMS (2FA OTP today, anything else later)
        // carries it — and so message_log stores exactly what was sent.
        // resend() re-sends the stored body, so it never double-appends.
        $body = $this->appendOptOutNotice($body);

        $logId = $this->db->insert('message_log', [
            'channel'      => 'sms',
            'recipient'    => $to,
            'body'         => $body,
            'status'       => 'queued',
            'provider'     => $this->config['provider'] ?? 'none',
            'max_attempts' => self::MAX_ATTEMPTS,
        ]);

        return $this->attemptSend($logId, $to, $body);
    }

    /**
     * Re-dispatch a previously-logged SMS by ID. See MailService::resend().
     */
    public function resend(int $logId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM message_log WHERE id = ? AND channel = 'sms' LIMIT 1",
            [$logId]
        );
        if (!$row) return false;
        if ($row['status'] === 'sent') return true;

        return $this->attemptSend(
            (int)$row['id'],
            (string)$row['recipient'],
            (string)($row['body'] ?? '')
        );
    }

    private function attemptSend(int $logId, string $to, string $body): bool
    {
        try {
            $success = $this->doSend($to, $body);
            if ($success) {
                $this->db->update('message_log',
                    [
                        'status'            => 'sent',
                        'sent_at'           => date('Y-m-d H:i:s'),
                        'last_attempted_at' => date('Y-m-d H:i:s'),
                        'next_attempt_at'   => null,
                        'attempts'          => $this->currentAttempts($logId) + 1,
                        'error'             => null,
                    ],
                    'id = ?', [$logId]
                );
                return true;
            }
            $this->markFailed($logId, 'Transport returned false (no exception).');
            return false;
        } catch (\Throwable $e) {
            $this->markFailed($logId, $e->getMessage());
            return false;
        }
    }

    private function currentAttempts(int $logId): int
    {
        $row = $this->db->fetchOne("SELECT attempts FROM message_log WHERE id = ?", [$logId]);
        return (int)($row['attempts'] ?? 0);
    }

    private function markFailed(int $logId, string $errorMessage): void
    {
        $row = $this->db->fetchOne("SELECT attempts, max_attempts FROM message_log WHERE id = ?", [$logId]);
        $attempts = (int)($row['attempts'] ?? 0) + 1;
        $max      = (int)($row['max_attempts'] ?? self::MAX_ATTEMPTS);

        $delayMinutes = self::RETRY_BACKOFF_MINUTES[$attempts - 1] ?? null;
        $nextAt = ($attempts < $max && $delayMinutes !== null)
            ? date('Y-m-d H:i:s', time() + ($delayMinutes * 60))
            : null;

        $this->db->update('message_log',
            [
                'status'            => 'failed',
                'error'             => substr($errorMessage, 0, 65000),
                'attempts'          => $attempts,
                'last_attempted_at' => date('Y-m-d H:i:s'),
                'next_attempt_at'   => $nextAt,
            ],
            'id = ?', [$logId]
        );
    }

    /**
     * Append the STOP opt-out notice to an outbound SMS body for CTIA /
     * A2P-10DLC compliance. Configurable via SMS_OPT_OUT_NOTICE (set it
     * to an empty string to disable; e.g. set it to
     * "Reply STOP to opt out, HELP for help" to also surface HELP).
     *
     * Skipped when the body already contains "STOP" (case-insensitive)
     * so a caller that supplies its own opt-out language isn't doubled.
     * Providers (TextMagic, Twilio, etc.) also honor STOP/HELP at the
     * platform level regardless; this is the human-visible footer.
     */
    private function appendOptOutNotice(string $body): string
    {
        $notice = array_key_exists('SMS_OPT_OUT_NOTICE', $_ENV)
            ? trim((string) $_ENV['SMS_OPT_OUT_NOTICE'])
            : self::DEFAULT_OPT_OUT_NOTICE;

        if ($notice === '') return $body;                       // explicitly disabled
        if (stripos($body, 'STOP') !== false) return $body;     // already has opt-out text
        return rtrim($body) . ' ' . $notice;
    }

    private function doSend(string $to, string $body): bool
    {
        $provider = $this->config['provider'] ?? '';

        // Local-capture driver: do nothing at the network layer and report
        // success. The message row in message_log (inserted by send() before
        // this method runs) becomes the "inbox" — browse it in the admin UI
        // at /admin/superadmin/message-log?channel=sms.
        //
        // Also echoes to the PHP error log so you can `tail` storage/logs
        // during development without round-tripping through the UI.
        if ($provider === 'log' || $provider === 'capture') {
            error_log("[sms:log] to={$to} body=" . str_replace(["\r","\n"], ' ', substr($body, 0, 200)));
            return true;
        }

        if ($provider === 'twilio') {
            return $this->sendTwilio($to, $body);
        }

        if ($provider === 'aws_sns') {
            return $this->sendAwsSns($to, $body);
        }

        if ($provider === 'textmagic') {
            return $this->sendTextMagic($to, $body);
        }

        if ($provider === 'telnyx') {
            return $this->sendTelnyx($to, $body);
        }

        if ($provider === 'textspot') {
            return $this->sendTextSpot($to, $body);
        }

        return false;
    }

    /**
     * Send via AWS SNS. SNS requires AWS Signature v4 on every request,
     * which is about 80 lines of canonical-request + credential-scope
     * plumbing. Rather than reimplement that here, we delegate to the
     * AWS SDK for PHP when it's installed (common if you're already
     * using S3 storage, which ships with the SDK). Without the SDK,
     * this method logs + fails gracefully so apps see a clear reason.
     */
    private function sendAwsSns(string $to, string $body): bool
    {
        if (!class_exists(\Aws\Sns\SnsClient::class)) {
            error_log('[sms:aws_sns] AWS SDK for PHP is not installed. `composer require aws/aws-sdk-php` to enable SNS.');
            return false;
        }
        try {
            $client = new \Aws\Sns\SnsClient([
                'region'      => (string) ($this->config['region']     ?? 'us-east-1'),
                'version'     => 'latest',
                'credentials' => [
                    'key'    => (string) ($this->config['access_key'] ?? ''),
                    'secret' => (string) ($this->config['secret_key'] ?? ''),
                ],
            ]);

            $args = ['Message' => $body];
            // Prefer topic publish when configured (fan-out), otherwise
            // direct-to-phone publish.
            $topicArn = (string) ($this->config['topic_arn'] ?? '');
            if ($topicArn !== '') {
                $args['TopicArn'] = $topicArn;
            } else {
                $args['PhoneNumber'] = $to;
            }

            $res = $client->publish($args);
            return !empty($res['MessageId']);
        } catch (\Throwable $e) {
            error_log('[sms:aws_sns] ' . $e->getMessage());
            return false;
        }
    }

    private function sendTwilio(string $to, string $body): bool
    {
        $sid   = $this->config['account_sid'] ?? '';
        $token = $this->config['auth_token']  ?? '';
        $from  = $this->config['from_number'] ?? '';

        $url     = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
        $payload = http_build_query(['To' => $to, 'From' => $from, 'Body' => $body]);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                       . "Authorization: Basic " . base64_encode("$sid:$token") . "\r\n",
            'content' => $payload,
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) return false;
        $data = json_decode($result, true);
        return !empty($data['sid']);
    }

    /**
     * TextMagic v2 — POST /api/v2/messages with X-TM-Username +
     * X-TM-Key headers, form-encoded body. Recommended provider for
     * a host's free-trial flow (no credit card required for
     * trial signup) + best public affiliate program.
     */
    private function sendTextMagic(string $to, string $body): bool
    {
        $user = $this->config['username'] ?? '';
        $key  = $this->config['api_key']  ?? '';
        $from = $this->config['from']     ?? '';
        if ($user === '' || $key === '') return false;

        $url     = 'https://rest.textmagic.com/api/v2/messages';
        $payload = ['text' => $body, 'phones' => $to];
        if ($from !== '') $payload['from'] = $from;

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                       . "X-TM-Username: $user\r\n"
                       . "X-TM-Key: $key\r\n",
            'content' => http_build_query($payload),
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) return false;
        $data = json_decode($result, true);
        // TextMagic returns {id: ..., href: ...} on success; an
        // error body lacks `id`. The HTTP status from $http_response_header
        // is 201 on accepted send.
        return is_array($data) && !empty($data['id']);
    }

    /**
     * Telnyx v2 — POST /v2/messages with Bearer auth + JSON body.
     * Cheapest commercial CPaaS for US SMS ($0.004/SMS as of 2026).
     * Documented as an alternative for cost-sensitive operators;
     * no public affiliate program.
     */
    private function sendTelnyx(string $to, string $body): bool
    {
        $key  = $this->config['api_key'] ?? '';
        $from = $this->config['from']    ?? '';
        if ($key === '' || $from === '') return false;

        $url     = 'https://api.telnyx.com/v2/messages';
        $payload = json_encode(['from' => $from, 'to' => $to, 'text' => $body]);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n"
                       . "Authorization: Bearer $key\r\n",
            'content' => $payload,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) return false;
        $data = json_decode($result, true);
        // Telnyx returns {data: {id: ..., ...}} on success; errors
        // come back as {errors: [...]}.
        return is_array($data) && !empty($data['data']['id']);
    }

    /**
     * TextSpot — best-effort REST POST. Their public docs aren't
     * comprehensive; this implementation assumes a Bearer-auth +
     * JSON pattern (industry standard). If TextSpot's actual API
     * differs, override this method or fall back to a different
     * driver. Marked as "unverified" in docs/integration-sms.md.
     */
    private function sendTextSpot(string $to, string $body): bool
    {
        $key  = $this->config['api_key'] ?? '';
        $from = $this->config['from']    ?? '';
        if ($key === '' || $from === '') return false;

        $url     = 'https://textspot.io/api/v1/messages';
        $payload = json_encode(['from' => $from, 'to' => $to, 'message' => $body]);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n"
                       . "Authorization: Bearer $key\r\n",
            'content' => $payload,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) return false;
        $data = json_decode($result, true);
        // Generic success heuristic — accept any 2xx response with
        // either id / message_id / status='sent' / success=true.
        if (!is_array($data)) return false;
        return !empty($data['id'])
            || !empty($data['message_id'])
            || (isset($data['status']) && $data['status'] === 'sent')
            || !empty($data['success']);
    }
}
