<?php

declare(strict_types=1);

namespace Junu\Dunning\Service;

use DateTime;
use Junu\Dunning\Config\ShopConfig;
use Junu\Dunning\Exception\ApiException;
use Monolog\Logger;
use IntlDateFormatter;

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

        $salesChannelName = $shop['sales_channel_name'] ?? '';
        if (empty($salesChannelName)) {
            $this->log->error('Sales channel name is missing', ['shop' => $shop]);
            return;
        }

        try {
            $salesChannelId = $client->getSalesChannelIdByName($salesChannelName);
            $this->log->debug('Sales channel ID fetched', [
                'name' => $salesChannelName,
                'id' => $salesChannelId,
            ]);
        } catch (ApiException $e) {
            $this->log->error('Failed to resolve sales channel ID', [
                'sales_channel_name' => $salesChannelName,
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

        $mailer = new BrevoMailer($shop['brevo_api_key'], $this->log, $this->dryRun, $shop['sales_channel_name']);

        // Tag can be overridden per shop; defaults to "Mahnlauf"
        $dunningTag = $shop['dunning_tag'] ?? 'Mahnlauf';

        try {
            $page = 1;
            $limit = 50; // Must match the limit in ShopwareClient::searchOrdersByTag
            do {
                // Fetch orders carrying the dunning tag
                $orders = $client->searchOrdersByTag($dunningTag, $page);
                if (empty($orders)) {
                    $this->log->debug('No more orders to process', [
                        'sales_channel_id' => $salesChannelId,
                        'page' => $page,
                    ]);
                    break;
                }

                $this->log->debug('Orders fetched', [
                    'sales_channel_id' => $salesChannelId,
                    'count' => count($orders),
                    'page' => $page,
                ]);

                foreach ($orders as $order) {
                    if ($this->shouldShutdown) {
                        $this->log->info('Shutdown requested, stopping order processing', [
                            'sales_channel_id' => $salesChannelId,
                        ]);
                        break 2; // Exit both loops
                    }

                    $this->processOrder($order, $client, $mailer, $shop, $salesChannelId);
                    usleep(50000); // 50ms delay between orders
                }

                $page++;
            } while (count($orders) === $limit); // Continue if the page was full
        } catch (ApiException $e) {
            $this->log->error('Failed to fetch orders', [
                'sales_channel_id' => $salesChannelId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processOrder(array $order, ShopwareClient $client, BrevoMailer $mailer, array $shop, string $salesChannelId): void
    {
        $context = [
            'sales_channel_id' => $salesChannelId,
            'order_number' => $order['orderNumber'] ?? 'unknown',
            'order_id' => $order['id'] ?? 'unknown',
        ];

        // We no longer care about transaction state — rely on the "Mahnlauf" tag and require documents
        if (empty($order['documents'])) {
            $this->log->warning('Order skipped: No documents', $context);
            return;
        }

        // Find invoice
        $invoice = null;
        foreach ($order['documents'] as $doc) {
            if (($doc['documentType']['technicalName'] ?? null) === 'invoice') {
                $invoice = $doc;
                break;
            }
        }

        if (!$invoice) {
            $this->handleNoInvoice($order, $mailer, $shop, $context);
            return;
        }

        // Determine dunning stage (unchanged)
        $customFields = $order['customFields'] ?? [];
        $stage = $this->determineDunningStage($customFields, $shop['due_days']);

        if ($stage === null) {
            $this->log->info('No further dunning action required', $context);
            return;
        }

        $this->sendDunningEmail($order, $invoice, $stage, $client, $mailer, $shop, $context);
    }

    private function handleNoInvoice(array $order, BrevoMailer $mailer, array $shop, array $context): void
    {
        $subject = "Missing Invoice for Order {$context['order_number']}";
        $content = "Order {$context['order_number']} has no invoice document.";

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
                $shop['no_invoice_email'],
                $subject,
                $content
            );
            $this->log->info('Sent no-invoice email', array_merge($context, [
                'to' => $shop['no_invoice_email'],
            ]));
        } catch (\Exception $e) {
            $this->log->error('Failed to send no-invoice email', array_merge($context, ['error' => $e->getMessage()]));
        }
    }

    private function determineDunningStage(array $customFields, int $dueDays): ?string
    {
        $now = time();
        $dueSeconds = $dueDays * 86400;

        // Check if order is ignored
        if (isset($customFields['junu_dunning_ignore']) && $customFields['junu_dunning_ignore'] === true) {
            return null;
        }

        // No dunning email sent yet (Zahlungserinnerung)
        if (empty($customFields['junu_dunning_1_sent_at'])) {
            return 'Zahlungserinnerung';
        }

        // Zahlungserinnerung sent, check for Mahnung 1
        $zeSentAt = $customFields['junu_dunning_1_sent_at'] ?? 0;
        if ($zeSentAt && empty($customFields['junu_dunning_2_sent_at']) && ($now - $zeSentAt) >= $dueSeconds) {
            return 'Mahnung 1';
        }

        // Mahnung 1 sent, check for Mahnung 2
        $ma1SentAt = $customFields['junu_dunning_2_sent_at'] ?? 0;
        if ($ma1SentAt && empty($customFields['junu_dunning_3_sent_at']) && ($now - $ma1SentAt) >= $dueSeconds) {
            return 'Mahnung 2';
        }

        // All dunning stages sent or no action required
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

        $invoicePath = null;

        try {
            $invoiceContent = $client->downloadInvoice($invoice['id'], $invoice['deepLinkCode'] ?? '');
            if ($this->dryRun) {
                $dir = __DIR__ . "/../../logs/dry-run/{$context['sales_channel_id']}";
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $invoicePath = "$dir/{$context['order_number']}_{$invoice['id']}.pdf";
                file_put_contents($invoicePath, $invoiceContent);
            } else {
                $invoicePath = sys_get_temp_dir() . "/{$context['order_number']}_{$invoice['id']}.pdf";
                file_put_contents($invoicePath, $invoiceContent);
            }

            $email = $order['orderCustomer']['email'] ?? $shop['no_invoice_email'];
            $subject = match ($stage) {
                'Zahlungserinnerung' => "Zahlungserinnerung für Bestellung {$context['order_number']}",
                'Mahnung 1' => "Erste Mahnung für Bestellung {$context['order_number']}",
                'Mahnung 2' => "Letzte Mahnung / Inkassoübergabe für Bestellung {$context['order_number']}",
            };

            $mailer->sendEmail(
                $email,
                $shop['no_invoice_email'],
                $subject,
                $html,
                $invoicePath,
                "Rechnung_{$context['order_number']}.pdf"
            );

            if (!$this->dryRun) {
                $client->updateCustomFields($order['id'], [
                    $fieldMap[$stage] => time(),
                ]);
            }

            $this->log->info("Processed dunning stage: $stage", $context);
        } catch (\Exception $e) {
            $this->log->error("Failed to process dunning stage: $stage", array_merge($context, ['error' => $e->getMessage()]));
        } finally {
            if ($invoicePath && file_exists($invoicePath)) {
                try {
                    unlink($invoicePath);
                    $this->log->debug("Deleted temporary invoice file", array_merge($context, ['file' => $invoicePath]));
                } catch (\Exception $e) {
                    $this->log->error("Failed to delete temporary invoice file", array_merge($context, ['file' => $invoicePath, 'error' => $e->getMessage()]));
                }
            }
        }
    }

    private function prepareEmailReplacements(array $order, array $invoice, array $shop): array
    {
        $formatter = new IntlDateFormatter(
            'de_DE',
            IntlDateFormatter::FULL,
            IntlDateFormatter::NONE,
            'Europe/Berlin',
            IntlDateFormatter::GREGORIAN,
            'dd. MMMM yyyy'
        );

        $orderDate = $formatter->format(new DateTime($order['orderDateTime']));
        $dueDate = $formatter->format((new DateTime())->modify("+{$shop['due_days']} days"));
        $amount = number_format($order['amountTotal'], 2, ',', '.') . ' EUR';
        $customerComment = !empty($order['customerComment'])
            ? nl2br(str_replace('.', ',', $order['customerComment']))
            : 'Technischer Fehler – bitte wenden Sie sich an unseren Kundenservice. Wir bitten um Entschuldigung.';

        return [
            '##FIRSTNAME##' => $order['billingAddress']['firstName'] ?? 'N/A',
            '##LASTNAME##' => $order['billingAddress']['lastName'] ?? 'N/A',
            '##ORDERID##' => $order['orderNumber'] ?? 'N/A',
            '##ORDERDATE##' => $orderDate,
            '##ORDERAMOUNT##' => $amount,
            '##INVOICENUM##' => $invoice['documentNumber'] ?? 'N/A',
            '##DUEDATE##' => $dueDate,
            '##DUEDAYS##' => $shop['due_days'],
            '##SALESCHANNEL##' => $shop['sales_channel_domain'],
            '##CUSTOMERCOMMENT##' => $customerComment,
        ];
    }
}
