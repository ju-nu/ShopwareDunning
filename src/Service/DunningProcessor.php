<?php

declare(strict_types=1);

namespace JunuDunning\Service;

use GuzzleHttp\Client;
use JunuDunning\Config\ShopConfig;
use Monolog\Logger;

/**
 * Processes orders for dunning.
 */
final class DunningProcessor
{
    private const TAG_ZE = 'Billing: ZE';
    private const TAG_MAHNUNG1 = 'Billing: Mahnung 1';
    private const TAG_MAHNUNG2 = 'Billing: Mahnung 2';

    public function __construct(
        private readonly Client $httpClient,
        private readonly BrevoMailer $mailer,
        private readonly Logger $logger,
        private readonly bool $isDryRun = false
    ) {
    }

    /**
     * Process all orders for a sales channel.
     */
    public function processShop(ShopwareClient $client, ShopConfig $config): void
    {
        $orders = $client->fetchRemindedOrders();
        $this->logger->info('Processing orders', [
            'url' => $config->url,
            'sales_channel_id' => $config->salesChannelId,
            'order_count' => count($orders),
            'dry_run' => $this->isDryRun,
        ]);

        foreach ($orders as $order) {
            $this->processOrder($client, $config, $order);
            usleep(50000); // 50ms delay to avoid API rate limits
        }
    }

    /**
     * Process a single order.
     *
     * @param array<string, mixed> $order
     */
    private function processOrder(ShopwareClient $client, ShopConfig $config, array $order): void
    {
        $orderId = $order['id'];
        $orderNumber = $order['orderNumber'];

        try {
            // Check for invoice
            $invoice = null;
            foreach ($order['documents'] ?? [] as $document) {
                if (($document['documentType']['technicalName'] ?? '') === 'invoice') {
                    $invoice = $document;
                    break;
                }
            }

            if ($invoice === null) {
                if ($this->isDryRun) {
                    $this->logger->info('[DRY-RUN] Would send no-invoice email', [
                        'orderNumber' => $orderNumber,
                        'sales_channel_id' => $config->salesChannelId,
                    ]);
                } else {
                    $this->mailer->sendNoInvoiceEmail($config, $orderNumber, $this->logger);
                }
                $this->logger->info('No invoice found, skipping', [
                    'orderNumber' => $orderNumber,
                    'sales_channel_id' => $config->salesChannelId,
                ]);
                return;
            }

            // Check payment state
            $isUnpaid = true;
            foreach ($order['transactions'] ?? [] as $transaction) {
                $state = $transaction['stateMachineState']['technicalName'] ?? '';
                if (in_array($state, ['paid', 'partially_paid'], true)) {
                    $isUnpaid = false;
                    break;
                }
            }

            if (!$isUnpaid) {
                $this->logger->info('Order is paid or partially paid, skipping', [
                    'orderNumber' => $orderNumber,
                    'sales_channel_id' => $config->salesChannelId,
                ]);
                return;
            }

            // Determine dunning stage
            $tags = array_column($order['tags'] ?? [], 'name');
            $customFields = $order['customFields'] ?? [];
            $now = time();
            $dueSeconds = $config->dueDays * 24 * 60 * 60;

            if (!in_array(self::TAG_ZE, $tags, true)) {
                $this->handleDunningStage($client, $config, $order, $invoice, 'ze', self::TAG_ZE, 'junu_dunning_ze_sent_at', $now);
            } elseif (
                in_array(self::TAG_ZE, $tags, true)
                && !in_array(self::TAG_MAHNUNG1, $tags, true)
                && ($customFields['junu_dunning_ze_sent_at'] ?? 0) + $dueSeconds <= $now
            ) {
                $this->handleDunningStage($client, $config, $order, $invoice, 'mahnung1', self::TAG_MAHNUNG1, 'junu_dunning_mahnung1_sent_at', $now);
            } elseif (
                in_array(self::TAG_MAHNUNG1, $tags, true)
                && !in_array(self::TAG_MAHNUNG2, $tags, true)
                && ($customFields['junu_dunning_mahnung1_sent_at'] ?? 0) + $dueSeconds <= $now
            ) {
                $this->handleDunningStage($client, $config, $order, $invoice, 'mahnung2', self::TAG_MAHNUNG2, 'junu_dunning_mahnung2_sent_at', $now);
            } else {
                $this->logger->debug('No action needed', [
                    'orderNumber' => $orderNumber,
                    'sales_channel_id' => $config->salesChannelId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to process order', [
                'orderNumber' => $orderNumber,
                'sales_channel_id' => $config->salesChannelId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a dunning stage (send email and update order).
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $invoice
     */
    private function handleDunningStage(
        ShopwareClient $client,
        ShopConfig $config,
        array $order,
        array $invoice,
        string $stage,
        string $tag,
        string $customField,
        int $timestamp
    ): void {
        $orderId = $order['id'];
        $orderNumber = $order['orderNumber'];

        // Download invoice
        try {
            $pdfContent = $client->downloadInvoice($invoice['id']);
            $this->saveDryRunInvoice($config, $orderNumber, $invoice['id'], $pdfContent);
        } catch (\Exception $e) {
            $this->logger->error('Failed to download invoice', [
                'orderNumber' => $orderNumber,
                'sales_channel_id' => $config->salesChannelId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($this->isDryRun) {
            $this->logger->info("[DRY-RUN] Would send {$stage} email", [
                'orderNumber' => $orderNumber,
                'sales_channel_id' => $config->salesChannelId,
            ]);
            $this->logger->info("[DRY-RUN] Would update order with tag: {$tag}, customField: {$customField}={$timestamp}", [
                'orderId' => $orderId,
                'sales_channel_id' => $config->salesChannelId,
            ]);
            $this->mailer->sendDryRunDunningEmail($config, $order, $invoice, $stage, $pdfContent, $this->logger);
        } else {
            $this->mailer->sendDunningEmail($config, $order, $invoice, $stage, $pdfContent, $this->logger);
            $client->updateOrder($orderId, [
                'tags' => [['name' => $tag]],
                'customFields' => array_merge($customFields, [$customField => $timestamp]),
            ]);
            $this->logger->info("Sent {$stage} email", [
                'orderNumber' => $orderNumber,
                'sales_channel_id' => $config->salesChannelId,
            ]);
        }
    }

    /**
     * Save invoice PDF to dry-run folder.
     */
    private function saveDryRunInvoice(ShopConfig $config, string $orderNumber, string $documentId, string $pdfContent): void
    {
        $dir = __DIR__ . '/../../dry-run/' . $config->salesChannelId;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = "{$dir}/{$orderNumber}_{$documentId}.pdf";
        file_put_contents($file, $pdfContent);
        $this->logger->info('Saved invoice to dry-run folder', [
            'file' => $file,
            'orderNumber' => $orderNumber,
            'sales_channel_id' => $config->salesChannelId,
        ]);
    }
}
?>