<?php

declare(strict_types=1);

namespace Junu\Dunning\Service;

use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\ApiException;
use Brevo\Client\Configuration;
use Brevo\Client\Model\SendSmtpEmail;
use GuzzleHttp\Client;
use Monolog\Logger;

/**
 * Handles email sending via Brevo API (getbrevo/brevo-php v2.0.8).
 */
class BrevoMailer
{
    private TransactionalEmailsApi $api;
    private Logger $log;
    private bool $dryRun;

    public function __construct(string $apiKey, Logger $log, bool $dryRun)
    {
        $config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $apiKey);
        $this->api = new TransactionalEmailsApi(new Client(['timeout' => 10.0]), $config);
        $this->log = $log;
        $this->dryRun = $dryRun;
    }

    public function sendEmail(
        string $toEmail,
        string $senderEmail,
        string $subject,
        string $htmlContent,
        ?string $attachmentPath = null,
        ?string $attachmentName = null
    ): void {
        if ($this->dryRun) {
            $this->log->info('[DRY-RUN] Simulated email sending', [
                'to' => $toEmail,
                'subject' => $subject,
                'content_length' => strlen($htmlContent),
                'attachment' => $attachmentPath ?? 'none',
            ]);
            return;
        }

        try {
            $email = new SendSmtpEmail([
                'to' => [['email' => $toEmail]],
                'sender' => ['email' => $senderEmail, 'name' => 'Dunning System'],
                'subject' => $subject,
                'htmlContent' => $htmlContent,
            ]);

            if ($attachmentPath && $attachmentName) {
                if (!file_exists($attachmentPath) || !is_readable($attachmentPath)) {
                    $this->log->error('Attachment file is missing or unreadable', [
                        'path' => $attachmentPath,
                    ]);
                    throw new \RuntimeException('Attachment file is missing or unreadable: ' . $attachmentPath);
                }

                $email->setAttachment([[
                    'content' => base64_encode(file_get_contents($attachmentPath)),
                    'name' => $attachmentName,
                ]]);
            }

            $this->api->sendTransacEmail($email);
            $this->log->info('Email sent successfully', [
                'to' => $toEmail,
                'subject' => $subject,
            ]);
        } catch (ApiException $e) {
            $this->log->error('Failed to send email via Brevo API', [
                'to' => $toEmail,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'response' => $e->getResponseBody() ?? 'No response',
            ]);
            throw new \RuntimeException('Failed to send email: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            $this->log->error('Unexpected error while sending email', [
                'to' => $toEmail,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Unexpected error while sending email: ' . $e->getMessage(), 0, $e);
        }
    }
}