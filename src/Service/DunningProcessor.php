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
        private readonly Logger $logger
    ) {
    }

    /**
     * Process all orders for a shop.
     */
    public function processShop(ShopwareClient $client, ShopConfig $config): void
    {
        $orders = $client->fetchRemindedOrders();
        $this->logger->info('Processing orders', [
            'url' => $config->url,
            'order_count' => count($orders),
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
                $this->mailer->sendNoInvoiceEmail($config, $orderNumber, $this->logger);
                $this->logger->info('No invoice found, skipping', ['orderNumber' => $orderNumber]);
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
                $this->logger->info('Order is paid or partially paid, skipping', ['orderNumber' => $orderNumber]);
                return;
            }

            // Determine dunning stage
            $tags = array_column($order['tags'] ?? [], 'name');
            $customFields = $order['customFields'] ?? [];
            $now = time();
            $dueSeconds = $config->dueDays * 24 * 60 * 60;

            if (!in_array(self::TAG_ZE, $tags, true)) {
                $this->sendDunningEmail($client, $config, $order, $invoice, 'ze');
                $client->updateOrder($orderId, [
                    'tags' => [['name' => self::TAG_ZE]],
                    'customFields' => array_merge($customFields, ['junu_dunning_ze_sent_at' => $now]),
                ]);
                $this->logger->info('Sent ZE email', ['orderNumber' => $orderNumber]);
            } elseif (
                in_array(self::TAG_ZE, $tags, true)
                && !in_array(self::TAG_MAHNUNG1, $tags, true)
                && ($customFields['junu_dunning_ze_sent_at'] ?? 0) + $dueSeconds <= $now
            ) {
                $this->sendDunningEmail($client, $config, $order, $invoice, 'mahnung1');
                $client->updateOrder($orderId, [
                    'tags' => [['name' => self::TAG_MAHNUNG1]],
                    'customFields' => array_merge($customFields, ['junu_dunning_mahnung1_sent_at' => $now]),
                ]);
                $this->logger->info('Sent Mahnung 1 email', ['orderNumber' => $orderNumber]);
            } elseif (
                in_array(self::TAG_MAHNUNG1, $tags, true)
                && !in_array(self::TAG_MAHNUNG2, $tags, true)
                && ($customFields['junu_dunning_mahnung1_sent_at'] ?? 0) + $dueSeconds <= $now
            ) {
                $this->sendDunningEmail($client, $config, $order, $invoice, 'mahnung2');
                $client->updateOrder($orderId, [
                    'tags' => [['name' => self::TAG_MAHNUNG2]],
                    'customFields' => array_merge($customFields, ['junu_dunning_mahnung2_sent_at' => $now]),
                ]);
                $this->logger->info('Sent Mahnung 2 email', ['orderNumber' => $orderNumber]);
            } else {
                $this->logger->debug('No action needed', ['orderNumber' => $orderNumber]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to process order', [
                'orderNumber' => $orderNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a dunning email with invoice attachment.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $invoice
     */
    private function sendDunningEmail(
        ShopwareClient $client,
        ShopConfig $config,
        array $order,
        array $invoice,
        string $stage
    ): void {
        try {
            $pdfContent = $client->downloadInvoice($invoice['id']);
            $this->mailer->sendDunningEmail($config, $order, $invoice, $stage, $pdfContent, $this->logger);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send dunning email', [
                'orderNumber' => $order['orderNumber'],
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
?>