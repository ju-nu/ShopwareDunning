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

        $mailer = new BrevoMailer($shop['brevo_api_key'], $this->log, $this->dryRun);

        try {
            $page = 1;
            do {
                $orders = $client->searchOrders($page); // Annahme: searchOrders akzeptiert eine Seitenzahl
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
            } while (count($orders) === 50); // Annahme: Limit ist 50
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

        // Prüfe, ob Transaktionen und Dokumente vorhanden sind
        if (empty($order['transactions']) || empty($order['documents'])) {
            $this->log->warning('Order skipped: No transactions or documents', $context);
            return;
        }

        // Prüfe Transaktionsstatus
        $transaction = $order['transactions'][0];
        $transactionState = $transaction['stateMachineState']['technicalName'] ?? null;
        if (!$transactionState) {
            $this->log->warning('Transaction state missing', array_merge($context, [
                'transactionId' => $transaction['id'] ?? 'unknown',
                'stateId' => $transaction['stateMachineState']['id'] ?? 'null',
            ]));
            return;
        }

        if ($transactionState !== 'reminded') {
            $this->log->info('Order skipped: Transaction not in reminded state', array_merge($context, [
                'state' => $transactionState,
            ]));
            return;
        }

        // Prüfe Rechnung
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

        // Bestimme Mahnstufe
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

    private function determineDunningStage(array $tags, array $customFields, int $dueDays): ?string
    {
        $now = time();
        $dueSeconds = $dueDays * 86400;

        if (!in_array('Mahnwesen: Zahlungserinnerung', $tags, true)) {
            return 'Zahlungserinnerung';
        }

        $zeSentAt = $customFields['junu_dunning_1_sent_at'] ?? 0;
        if (
            in_array('Mahnwesen: Zahlungserinnerung', $tags, true) &&
            !in_array('Mahnwesen: Mahnung 1', $tags, true) &&
            $zeSentAt && ($now - $zeSentAt) >= $dueSeconds
        ) {
            return 'Mahnung 1';
        }

        $ma1SentAt = $customFields['junu_dunning_2_sent_at'] ?? 0;
        if (
            in_array('Mahnwesen: Mahnung 1', $tags, true) &&
            !in_array('Mahnwesen: Mahnung 2', $tags, true) &&
            $ma1SentAt && ($now - $ma1SentAt) >= $dueSeconds
        ) {
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

        try {
            $invoiceContent = $client->downloadInvoice($invoice['id'], $invoice['deepLinkCode'] ?? '');
            $invoicePath = null;
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
        $formatter = new IntlDateFormatter(
            'de_DE',
            IntlDateFormatter::FULL,
            IntlDateFormatter::NONE,
            'Europe/Berlin',
            IntlDateFormatter::GREGORIAN,
            'dd. MMMM yyyy'
        );

        // Datum formatieren
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
