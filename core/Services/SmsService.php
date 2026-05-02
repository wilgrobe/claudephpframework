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

    public function send(string $to, string $body): bool
    {
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
}
