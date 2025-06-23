<?php

declare(strict_types=1);

namespace JunuDunning\Config;

use JunuDunning\Exception\ConfigurationException;

/**
 * Represents a single sales channel's configuration.
 */
final class ShopConfig
{
    public readonly string $url;
    public readonly string $apiKey;
    public readonly string $apiSecret;
    public readonly string $salesChannelId;
    public readonly string $salesChannelDomain;
    public readonly string $brevoApiKey;
    public readonly string $noInvoiceEmail;
    public readonly string $zeTemplate;
    public readonly string $mahnung1Template;
    public readonly string $mahnung2Template;
    public readonly int $dueDays;

    public function __construct(
        string $url,
        string $apiKey,
        string $apiSecret,
        string $salesChannelId,
        string $salesChannelDomain,
        string $brevoApiKey,
        string $noInvoiceEmail,
        string $zeTemplate,
        string $mahnung1Template,
        string $mahnung2Template,
        int $dueDays
    ) {
        if (!preg_match('/^[0-9a-f]{32}$/i', $salesChannelId)) {
            throw new ConfigurationException("Invalid sales_channel_id: {$salesChannelId}");
        }

        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->salesChannelId = $salesChannelId;
        $this->salesChannelDomain = $salesChannelDomain;
        $this->brevoApiKey = $brevoApiKey;
        $this->noInvoiceEmail = $noInvoiceEmail;
        $this->zeTemplate = $zeTemplate;
        $this->mahnung1Template = $mahnung1Template;
        $this->mahnung2Template = $mahnung2Template;
        $this->dueDays = max(1, $dueDays);
    }

    /**
     * Load sales channel configurations from environment.
     *
     * @return self[]
     * @throws ConfigurationException
     */
    public static function fromEnv(): array
    {
        $raw = json_decode($_ENV['SHOPWARE_SYSTEMS'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        $shops = [];

        foreach ($raw as $index => $config) {
            if (
                !isset(
                    $config['url'],
                    $config['api_key'],
                    $config['api_secret'],
                    $config['sales_channel_id'],
                    $config['sales_channel_domain'],
                    $config['brevo_api_key'],
                    $config['no_invoice_email'],
                    $config['ze_template'],
                    $config['mahnung1_template'],
                    $config['mahnung2_template'],
                    $config['due_days']
                )
            ) {
                throw new ConfigurationException("Incomplete configuration at index {$index}");
            }

            $shops[] = new self(
                $config['url'],
                $config['api_key'],
                $config['api_secret'],
                $config['sales_channel_id'],
                $config['sales_channel_domain'],
                $config['brevo_api_key'],
                $config['no_invoice_email'],
                $config['ze_template'],
                $config['mahnung1_template'],
                $config['mahnung2_template'],
                (int) $config['due_days']
            );
        }

        if (empty($shops)) {
            throw new ConfigurationException('No configurations found in SHOPWARE_SYSTEMS');
        }

        return $shops;
    }
}
?>