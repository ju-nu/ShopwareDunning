<?php

declare(strict_types=1);

namespace Junu\Dunning\Service;

use DateTime;
use Junu\Dunning\Config\ShopConfig;
use Monolog\Logger;

/**
 * Processes orders and manages dunning logic for multiple sales channels.
 */
class DunningProcessor
{
    private ShopConfig $config;
    private Logger $log;
    private bool $dryRun;
    private bool $shouldShutdown = false;

    public function __construct(ShopConfig $config, Logger $log, bool $dryRun)
    {
        $this->config = $config;
        $this->log = $log;
        $this->dryRun = $dryRun;
    }

    public function process(): void
    {
        foreach ($this->config->getShops() as $shop) {
            if ($this->shouldShutdown) {
                $this->log->info('Shutdown requested, stopping processing');
                break;
            }

            $this->processSalesChannel($shop);
            usleep(100000); // 100ms delay between sales channels
        }
    }

    public function shutdown(): void
    {
        $this->shouldShutdown = true;
    }

    private function processSalesChannel(array $shop): void
    {
        $client = new ShopwareClient(
            $shop['url'],
            $shop['api_key'],
            $shop['api_secret'],
            '', // salesChannelId will be resolved below
            $this->log
        );

        try {
            $salesChannelId = $client->getSalesChannelIdByName($shop['sales_channel_name']);
        } catch (\Exception $e) {
            $this->log->error('Failed to resolve sales channel ID', [
                'sales_channel_name' => $shop['sales_channel_name'],
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // Reinitialize client with resolved salesChannelId
        $client = new ShopwareClient(
            $shop['url'],
            $shop['api_key'],
            $shop['api_secret'],
            $salesChannelId,
            $this->log
        );

        $mailer = new BrevoMailer($shop['brevo_api_key'], $this->log, $this->dryRun);

        $orders = $client->searchOrders();
        $this->log->debug('Orders fetched', ['sales_channel_id' => $salesChannelId, 'count' => count($orders['data'])]);
        foreach ($orders['data'] as $order) {
            if ($this->shouldShutdown) {
                break;
            }

            $this->processOrder($order, $client, $mailer, $shop, $salesChannelId);
            usleep(50000); // 50ms delay between orders
        }
    }

    private function processOrder(array $order, ShopwareClient $client, BrevoMailer $mailer, array $shop, string $salesChannelId): void
    {
        $context = [
            'sales_channel_id' => $salesChannelId,
            'order_number' => $order['orderNumber'],
        ];

        // Check transaction state
        $transactionState = $order['transactions'][0]['stateMachineState']['technicalName'] ?? '';
        if (in_array($transactionState, ['paid', 'partially_paid'], true)) {
            $this->log->info('Skipping paid order', $context);
            return;
        }

        // Check for invoice
        $invoice = null;
        foreach ($order['documents'] as $doc) {
            if ($doc['documentType']['technicalName'] === 'invoice') {
                $invoice = $doc;
                break;
            }
        }

        if (!$invoice) {
            $this->handleNoInvoice($order, $mailer, $shop, $context);
            return;
        }

        // Determine dunning stage
        $tags = array_column($order['tags'], 'name');
        $customFields = $order['customFields'] ?? [];
        $stage = $this->determineDunningStage($tags, $customFields, $shop['due_days']);

        if ($stage === null) {
            $this->log->info('No further dunning action required', $context);
            return;
        }

        $this->sendDunningEmail($order, $invoice, $stage, $client, $mailer, $shop, $context);
    }

    private function handleNoInvoice(array $order, BrevoMailer $mailer, array $shop, array $context): void
    {
        $subject = "Missing Invoice for Order {$order['orderNumber']}";
        $content = "Order {$order['orderNumber']} has no invoice document.";
        
        if ($this->dryRun) {
            $this->log->info('[DRY-RUN] Simulated no-invoice email', array_merge($context, [
                'to' => $shop['no_invoice_email'],
                'subject' => $subject,
            ]));
            return;
        }

        try {
            $mailer->sendEmail(
                $shop['no_invoice_email'],
                "no-reply@{$shop['sales_channel_domain']}",
                $subject,
                $content
            );
        } catch (\Exception $e) {
            $this->log->error('Failed to send no-invoice email', array_merge($context, ['error' => $e->getMessage()]));
        }
    }

    private function determineDunningStage(array $tags, array $customFields, int $dueDays): ?string
    {
        $now = time();
        $dueSeconds = $dueDays * 86400;

        if (!in_array('Mahnwesen: Zahlungserinnerung', $tags, true)) {
            return 'Zahlungserinnerung';
        }

        $zeSentAt = $customFields['junu_dunning_1_sent_at'] ?? 0;
        if (in_array('Mahnwesen: Zahlungserinnerung', $tags, true) && 
            !in_array('Mahnwesen: Mahnung 1', $tags, true) && 
            $zeSentAt && ($now - $zeSentAt) >= $dueSeconds) {
            return 'Mahnung 1';
        }

        $ma1SentAt = $customFields['junu_dunning_2_sent_at'] ?? 0;
        if (in_array('Mahnwesen: Mahnung 1', $tags, true) && 
            !in_array('Mahnwesen: Mahnung 2', $tags, true) && 
            $ma1SentAt && ($now - $ma1SentAt) >= $dueSeconds) {
            return 'Mahnung 2';
        }

        return null;
    }

    private function sendDunningEmail(
        array $order,
        array $invoice,
        string $stage,
        ShopwareClient $client,
        BrevoMailer $mailer,
        array $shop,
        array $context
    ): void {
        $templateMap = [
            'Zahlungserinnerung' => $shop['ze_template'],
            'Mahnung 1' => $shop['mahnung1_template'],
            'Mahnung 2' => $shop['mahnung2_template'],
        ];
        $tagMap = [
            'Zahlungserinnerung' => 'Mahnwesen: Zahlungserinnerung',
            'Mahnung 1' => 'Mahnwesen: Mahnung 1',
            'Mahnung 2' => 'Mahnwesen: Mahnung 2',
        ];
        $fieldMap = [
            'Zahlungserinnerung' => 'junu_dunning_1_sent_at',
            'Mahnung 1' => 'junu_dunning_2_sent_at',
            'Mahnung 2' => 'junu_dunning_3_sent_at',
        ];

        $templateFile = __DIR__ . '/../../templates/' . $templateMap[$stage];
        if (!file_exists($templateFile)) {
            $this->log->error('Template not found', array_merge($context, ['template' => $templateFile]));
            return;
        }

        $html = file_get_contents($templateFile);
        $replacements = $this->prepareEmailReplacements($order, $invoice, $shop);
        $html = str_replace(array_keys($replacements), array_values($replacements), $html);

        // Download invoice for attachment or dry-run storage
        $invoiceContent = $client->downloadInvoice($invoice['id']);
        $invoicePath = null;
        if ($this->dryRun) {
            $dir = __DIR__ . "/../../logs/dry-run/{$context['sales_channel_id']}";
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $invoicePath = "$dir/{$order['orderNumber']}_{$invoice['id']}.pdf";
            file_put_contents($invoicePath, $invoiceContent);
        } else {
            $invoicePath = sys_get_temp_dir() . "/{$order['orderNumber']}_{$invoice['id']}.pdf";
            file_put_contents($invoicePath, $invoiceContent);
        }

        $email = $order['billingAddress']['email'] ?? $shop['no_invoice_email'];
        $subject = match ($stage) {
            'Zahlungserinnerung' => "Zahlungserinnerung für Bestellung {$order['orderNumber']}",
            'Mahnung 1' => "Erste Mahnung für Bestellung {$order['orderNumber']}",
            'Mahnung 2' => "Zweite Mahnung für Bestellung {$order['orderNumber']}",
        };

        try {
            $mailer->sendEmail(
                $email,
                "no-reply@{$shop['sales_channel_domain']}",
                $subject,
                $html,
                $invoicePath,
                "Rechnung_{$order['orderNumber']}.pdf"
            );

            if (!$this->dryRun) {
                $client->addTag($order['id'], $tagMap[$stage]);
                $client->updateCustomFields($order['id'], [
                    $fieldMap[$stage] => time(),
                ]);
            }

            $this->log->info("Processed dunning stage: $stage", $context);
        } catch (\Exception $e) {
            $this->log->error("Failed to process dunning stage: $stage", array_merge($context, ['error' => $e->getMessage()]));
        } finally {
            if ($invoicePath && !$this->dryRun && file_exists($invoicePath)) {
                unlink($invoicePath);
            }
        }
    }

    private function prepareEmailReplacements(array $order, array $invoice, array $shop): array
    {
        $orderDate = (new DateTime($order['orderDateTime']))->format('d. F Y');
        $dueDate = (new DateTime())->modify("+{$shop['due_days']} days")->format('d. F Y');
        $amount = number_format($order['amountTotal'], 2, ',', '.') . ' EUR';

        return [
            '##FIRSTNAME##' => $order['billingAddress']['firstName'] ?? 'N/A',
            '##LASTNAME##' => $order['billingAddress']['lastName'] ?? 'N/A',
            '##ORDERID##' => $order['orderNumber'],
            '##ORDERDATE##' => $orderDate,
            '##ORDERAMOUNT##' => $amount,
            '##INVOICENUM##' => $invoice['id'] ?? 'N/A',
            '##DUEDATE##' => $dueDate,
            '##DUEDAYS##' => $shop['due_days'],
            '##SALESCHANNEL##' => $shop['sales_channel_domain'],
            '##CUSTOMERCOMMENT##' => $order['customerComment'] ?? 'No comment provided',
        ];
    }
}