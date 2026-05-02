<?php
// core/Contracts/MailDriver.php
namespace Core\Contracts;

/**
 * Send transactional email. Implementations wrap SMTP (PHPMailer),
 * SendGrid, Mailgun, or SES APIs.
 *
 * Intentionally minimal. Richer features (templates, attachments,
 * scheduled delivery) live on a MailMessage value object when introduced.
 *
 * The signature matches Core\Services\MailService::send() exactly so that
 * service can `implements MailDriver` without any behavior change.
 */
interface MailDriver
{
    /**
     * Send one message.
     *
     * @param string $to        Single recipient address (today). Array support
     *                          is a future extension that would be added with
     *                          a new MailDriver method rather than widening
     *                          this one.
     * @param string $subject   Subject line
     * @param string $htmlBody  HTML body
     * @param string $textBody  Optional plain-text fallback; empty string
     *                          means "derive from HTML" (driver's choice).
     * @return bool  true when accepted by the transport; false on
     *               definitive failure (drivers should log/throw on I/O errors)
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool;
}
