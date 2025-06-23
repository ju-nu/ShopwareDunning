<?php

declare(strict_types=1);

namespace Junu\Dunning\Config;

use Junu\Dunning\Exception\ConfigurationException;

/**
 * Manages and validates sales channel configurations from a JSON file.
 */
class ShopConfig
{
    private array $shops = [];

    /**
     * @throws ConfigurationException
     */
    public function __construct(string $configPath)
    {
        if (!file_exists($configPath)) {
            throw new ConfigurationException("Configuration file not found: $configPath");
        }

        $json = file_get_contents($configPath);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ConfigurationException('Invalid JSON in configuration file: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new ConfigurationException('Configuration must be an array of shop configurations');
        }

        foreach ($data as $index => $shop) {
            $this->validateShopConfig($shop, $index);
            $this->shops[] = $shop;
        }
    }

    /**
     * @throws ConfigurationException
     */
    private function validateShopConfig(array $shop, int $index): void
    {
        $required = [
            'url',
            'api_key',
            'api_secret',
            'sales_channel_id',
            'sales_channel_domain',
            'brevo_api_key',
            'no_invoice_email',
            'ze_template',
            'mahnung1_template',
            'mahnung2_template',
            'due_days',
        ];

        foreach ($required as $key) {
            if (!isset($shop[$key]) || empty($shop[$key])) {
                throw new ConfigurationException("Missing or empty '$key' in shop configuration at index $index");
            }
        }

        if (!preg_match('/^[a-f0-9]{32}$/i', $shop['sales_channel_id'])) {
            throw new ConfigurationException("Invalid sales_channel_id in shop configuration at index $index");
        }

        if (!filter_var($shop['no_invoice_email'], FILTER_VALIDATE_EMAIL)) {
            throw new ConfigurationException("Invalid no_invoice_email in shop configuration at index $index");
        }

        if (!is_int($shop['due_days']) || $shop['due_days'] <= 0) {
            throw new ConfigurationException("Invalid due_days in shop configuration at index $index");
        }
    }

    public function getShops(): array
    {
        return $this->shops;
    }
}