<?php

declare(strict_types=1);

namespace JunuDunning\Service;

use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\Configuration;
use Brevo\Client\Model\SendSmtpEmail;
use JunuDunning\Config\ShopConfig;
use Monolog\Logger;

/**
 * Sends emails via Brevo API.
 */
final class BrevoMailer
{
    /**
     * Send a no-invoice notification email.
     */
    public function sendNoInvoiceEmail(ShopConfig $config, string $orderNumber, Logger $logger): void
    {
        $email = new SendSmtpEmail([
            'sender' => ['name' => 'No Reply', 'email' => 'no-reply@' . $config->domain],
            'to' => [['email' => $config->noInvoiceEmail]],
            'subject' => 'Order without Invoice: ' . $orderNumber,
            'htmlContent' => '<p>Please check order ' . htmlspecialchars($orderNumber) . ' for its invoice or remove it from the dunning cycle.</p>',
        ]);

        $this->send($config, $email, $logger, ['orderNumber' => $orderNumber]);
    }

    /**
     * Send a dunning email with invoice attachment.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $invoice
     */
    public function sendDunningEmail(
        ShopConfig $config,
        array $order,
        array $invoice,
        string $stage,
        string $pdfContent,
        Logger $logger
    ): void {
        $orderNumber = $order['orderNumber'];
        $customerEmail = $order['orderCustomer']['email'] ?? null;

        if (!$customerEmail) {
            $logger->warning('No customer email found', ['orderNumber' => $orderNumber]);
            return;
        }

        $templateFile = __DIR__ . '/../../templates/' . $config->{"{$stage}Template"};
        if (!file_exists($templateFile)) {
            $logger->error('Email template not found', ['template' => $templateFile]);
            return;
        }
        $template = file_get_contents($templateFile);

        $formatter = new \IntlDateFormatter(
            'de_DE',
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE,
            'Europe/Berlin'
        );

        $orderDate = new \DateTime($order['orderDateTime']);
        $dueDate = (new \DateTime())->modify("+{$config->dueDays} days");

        $replacements = [
            '##FIRSTNAME##' => htmlspecialchars($order['billingAddress']['firstName'] ?? ''),
            '##LASTNAME##' => htmlspecialchars($order['billingAddress']['lastName'] ?? ''),
            '##ORDERID##' => htmlspecialchars($orderNumber),
            '##ORDERDATE##' => $formatter->format($orderDate) ?: 'N/A',
            '##ORDERAMOUNT##' => number_format($order['amountTotal'] ?? 0, 2, ',', '.') . ' EUR',
            '##INVOICENUM##' => htmlspecialchars($invoice['documentNumber'] ?? 'N/A'),
            '##DUEDATE##' => $formatter->format($dueDate) ?: 'N/A',
            '##DUEDAYS##' => $config->dueDays,
            '##SALESCHANNEL##' => htmlspecialchars($order['salesChannel']['name'] ?? 'Unknown'),
            '##CUSTOMERCOMMENT##' => htmlspecialchars($order['orderCustomer']['customerComment'] ?? 'No comment provided'),
        ];

        $content = str_replace(array_keys($replacements), array_values($replacements), $template);

        $email = new SendSmtpEmail([
            'sender' => ['name' => 'No Reply', 'email' => 'no-reply@' . $config->domain],
            'to' => [['email' => $customerEmail]],
            'subject' => match ($stage) {
                'ze' => 'Zahlungserinnerung für Bestellung ' . $orderNumber,
                'mahnung1' => 'Erste Mahnung für Bestellung ' . $orderNumber,
                'mahnung2' => 'Zweite Mahnung für Bestellung ' . $orderNumber,
                default => 'Mahnung für Bestellung ' . $orderNumber,
            },
            'htmlContent' => $content,
            'attachment' => [
                [
                    'content' => base64_encode($pdfContent),
                    'name' => 'rechnung_' . $orderNumber . '.pdf',
                ],
            ],
        ]);

        $this->send($config, $email, $logger, ['orderNumber' => $orderNumber, 'stage' => $stage]);
    }

    /**
     * Send an email via Brevo API.
     *
     * @param array<string, mixed> $context
     */
    private function send(ShopConfig $config, SendSmtpEmail $email, Logger $logger, array $context): void
    {
        $apiConfig = Configuration::getDefaultConfiguration()->setApiKey('api-key', $config->brevoApiKey);
        $api = new TransactionalEmailsApi(null, $apiConfig);

        try {
            $api->sendTransacEmail($email);
            $logger->info('Sent email', $context);
        } catch (\Exception $e) {
            $logger->error('Failed to send email', array_merge($context, ['error' => $e->getMessage()]));
        }
    }
}
?>