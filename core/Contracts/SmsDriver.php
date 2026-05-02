<?php
// core/Contracts/SmsDriver.php
namespace Core\Contracts;

/**
 * Send SMS messages. Drivers: Twilio, Vonage.
 *
 * Signature matches Core\Services\SmsService::send() so that service
 * can `implements SmsDriver` without behavior change.
 */
interface SmsDriver
{
    /**
     * Send a single SMS.
     *
     * @param string $to   E.164-formatted recipient number
     * @param string $body Plain-text body; drivers handle GSM-7 / UCS-2
     *                     segmentation for long or unicode messages
     * @return bool true when the provider accepted the send
     */
    public function send(string $to, string $body): bool;
}
