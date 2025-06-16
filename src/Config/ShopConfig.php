<?php

declare(strict_types=1);

namespace JunuDunning\Config;

use JunuDunning\Exception\ConfigurationException;

/**
 * Represents a single shop's configuration.
 */
final class ShopConfig
{
    public readonly string $url;
    public readonly string $apiKey;
    public readonly string $apiSecret;
    public readonly string $domain;
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
        string $domain,
        string $brevoApiKey,
        string $noInvoiceEmail,
        string $zeTemplate,
        string $mahnung1Template,
        string $mahnung2Template,
        int $dueDays
    ) {
        $this->url = rtrim($url, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->domain = $domain;
        $this->brevoApiKey = $brevoApiKey;
        $this->noInvoiceEmail = $noInvoiceEmail;
        $this->zeTemplate = $zeTemplate;
        $this->mahnung1Template = $mahnung1Template;
        $this->mahnung2Template = $mahnung2Template;
        $this->dueDays = max(1, $dueDays);
    }

    /**
     * Load shop configurations from environment.
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
                    $config['domain'],
                    $config['brevo_api_key'],
                    $config['no_invoice_email'],
                    $config['ze_template'],
                    $config['mahnung1_template'],
                    $config['mahnung2_template'],
                    $config['due_days']
                )
            ) {
                throw new ConfigurationException("Incomplete shop configuration at index {$index}");
            }

            $shops[] = new self(
                $config['url'],
                $config['api_key'],
                $config['api_secret'],
                $config['domain'],
                $config['brevo_api_key'],
                $config['no_invoice_email'],
                $config['ze_template'],
                $config['mahnung1_template'],
                $config['mahnung2_template'],
                (int) $config['due_days']
            );
        }

        if (empty($shops)) {
            throw new ConfigurationException('No shop configurations found in SHOPWARE_SYSTEMS');
        }

        return $shops;
    }
}
?>