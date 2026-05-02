<?php
// core/Services/MailService.php
namespace Core\Services;

use Core\Contracts\MailDriver;
use Core\Database\Database;
use Core\Services\IntegrationConfig;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * MailService — sends email via configured SMTP or API integrations.
 *
 * All config now lives in .env. The driver selector (MAIL_DRIVER) plus the
 * driver-specific credentials are read via IntegrationConfig::config('email').
 * On local dev this defaults to smtp4dev (localhost:25, no auth); inspect
 * caught mail at http://localhost:5000.
 *
 * Supported drivers:
 *   - smtp (default) — PHPMailer over SMTP. Use against smtp4dev locally
 *                      or your real provider in prod.
 *   - log            — Append the full message (recipient, subject, HTML,
 *                      text) to storage/logs/mail.log instead of sending.
 *                      Useful when you don't want to install smtp4dev. The
 *                      file is auto-created on first send.
 *   - mail           — PHP's built-in mail() — production fallback only.
 *
 * Every send attempt is logged to message_log regardless of driver.
 */
class MailService implements MailDriver
{
    private Database $db;
    private array    $config;

    public function __construct()
    {
        $this->db     = Database::getInstance();
        $this->config = $this->loadConfig();
    }

    private function loadConfig(): array
    {
        $env = IntegrationConfig::config('email');

        return [
            'driver'            => $env['driver']           ?? 'smtp',
            'host'              => $env['host']             ?? 'localhost',
            'port'              => (int) ($env['port']      ?? 25),
            'encryption'        => $env['encryption']       ?? '',
            'username'          => $env['username']         ?? '',
            'password'          => $env['password']         ?? '',
            'from_address'      => $env['from_address']     ?? 'noreply@example.com',
            'from_name'         => $env['from_name']        ?? ($_ENV['APP_NAME'] ?? 'App'),
            'sendgrid_api_key'  => $env['api_key']          ?? '',
            'mailgun_api_key'   => $env['api_key']          ?? '',
            'mailgun_domain'    => $env['domain']           ?? '',
            'mailgun_endpoint'  => $env['endpoint']         ?? 'api.mailgun.net',
            'ses_region'        => $env['region']           ?? '',
            'ses_access_key'    => $env['access_key']       ?? '',
            'ses_secret_key'    => $env['secret_key']       ?? '',
            'allow_self_signed' => ($_ENV['APP_ENV'] ?? 'production') !== 'production',
        ];
    }

    private const RETRY_BACKOFF_MINUTES = [1, 5, 25];
    private const MAX_ATTEMPTS          = 3;

    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $logId = $this->db->insert('message_log', [
            'channel'      => 'email',
            'recipient'    => $to,
            'subject'      => $subject,
            'body'         => $htmlBody,
            'status'       => 'queued',
            'provider'     => $this->config['driver'] ?? 'smtp',
            'max_attempts' => self::MAX_ATTEMPTS,
        ]);

        return $this->attemptSend($logId, $to, $subject, $htmlBody, $textBody);
    }

    public function resend(int $logId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM message_log WHERE id = ? AND channel = 'email' LIMIT 1",
            [$logId]
        );
        if (!$row) return false;
        if ($row['status'] === 'sent') return true;

        return $this->attemptSend(
            (int)$row['id'],
            (string)$row['recipient'],
            (string)($row['subject'] ?? ''),
            (string)($row['body']    ?? ''),
            ''
        );
    }

    private function attemptSend(int $logId, string $to, string $subject, string $html, string $text): bool
    {
        try {
            $success = $this->doSend($to, $subject, $html, $text);
            if ($success) {
                $this->db->update('message_log',
                    [
                        'status'             => 'sent',
                        'sent_at'            => date('Y-m-d H:i:s'),
                        'last_attempted_at'  => date('Y-m-d H:i:s'),
                        'next_attempt_at'    => null,
                        'attempts'           => $this->currentAttempts($logId) + 1,
                        'error'              => null,
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

    private function doSend(string $to, string $subject, string $html, string $text): bool
    {
        $driver = strtolower($this->config['driver'] ?? 'smtp');

        if ($driver === 'smtp') {
            return $this->sendSmtp($to, $subject, $html, $text);
        }
        if ($driver === 'log') {
            return $this->sendToLog($to, $subject, $html, $text);
        }

        $headers  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=utf-8\r\n";
        $headers .= "From: " . $this->config['from_name'] . " <" . $this->config['from_address'] . ">\r\n";
        return mail($to, $subject, $html, $headers);
    }

    private function sendToLog(string $to, string $subject, string $html, string $text): bool
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create mail log directory: $dir");
        }

        $path = $dir . '/mail.log';
        $now  = date('Y-m-d H:i:s');
        $from = sprintf('%s <%s>', $this->config['from_name'], $this->config['from_address']);

        $entry = "================================================================\n"
               . "[$now] MAIL_DRIVER=log\n"
               . "From:    $from\n"
               . "To:      $to\n"
               . "Subject: $subject\n"
               . "----------------------------------------------------------------\n"
               . ($text !== '' ? "TEXT:\n$text\n----\n" : '')
               . "HTML:\n$html\n"
               . "================================================================\n\n";

        if (file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write to mail log: $path");
        }
        return true;
    }

    private function sendSmtp(string $to, string $subject, string $html, string $text): bool
    {
        if (!class_exists(PHPMailer::class)) {
            throw new \RuntimeException(
                'PHPMailer is not installed. Run: composer require phpmailer/phpmailer'
            );
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host    = $this->config['host'];
        $mail->Port    = (int)$this->config['port'];
        $mail->CharSet = 'UTF-8';

        if (!empty($this->config['username'])) {
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'] ?? '';
        } else {
            $mail->SMTPAuth = false;
        }

        $encryption = strtolower((string)($this->config['encryption'] ?? ''));
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure  = false;
            $mail->SMTPAutoTLS = false;
        }

        if (!empty($this->config['allow_self_signed'])) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $mail->setFrom($this->config['from_address'], $this->config['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $html;
        $mail->AltBody = $text !== '' ? $text : trim(strip_tags($html));

        return $mail->send();
    }

    public function sendTemplate(string $to, string $subject, string $template, array $vars = []): bool
    {
        ob_start();
        extract($vars, EXTR_SKIP);
        $templatePath = BASE_PATH . "/app/Views/emails/$template.php";
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        $html = ob_get_clean();
        return $this->send($to, $subject, $html);
    }
}
