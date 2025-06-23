<?php

declare(strict_types=1);

namespace Junu\Dunning\Service;

use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\Configuration;
use Brevo\Client\Model\SendSmtpEmail;
use GuzzleHttp\Client;
use Monolog\Logger;

/**
 * Handles email sending via Brevo API.
 */
class BrevoMailer
{
    private TransactionalEmailsApi $api;
    private Logger $log;
    private bool $dryRun;

    public function __construct(string $apiKey, Logger $log, bool $dryRun)
    {
        $config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $apiKey);
        $this->api = new TransactionalEmailsApi(new Client(), $config);
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
                $email->setAttachment([[
                    'content' => base64_encode(file_get_contents($attachmentPath)),
                    'name' => $attachmentName,
                ]]);
            }

            $this->api->sendTransacEmail($email);
            $this->log->info('Email sent successfully', ['to' => $toEmail, 'subject' => $subject]);
        } catch (\Exception $e) {
            $this->log->error('Failed to send email', [
                'to' => $toEmail,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}