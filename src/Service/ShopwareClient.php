<?php

declare(strict_types=1);

namespace JunuDunning\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use JunuDunning\Config\ShopConfig;
use JunuDunning\Exception\ApiException;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;

/**
 * Shopware API client with token management and retry logic.
 */
final class ShopwareClient
{
    private string $token;
    private float $tokenExpiresAt;

    public function __construct(
        private readonly Client $httpClient,
        private readonly ShopConfig $config,
        private readonly Logger $logger
    ) {
        $this->token = '';
        $this->tokenExpiresAt = 0.0;
    }

    /**
     * Fetch orders with "reminded" transaction state for the sales channel.
     *
     * @return array<array<string, mixed>>
     */
    public function fetchRemindedOrders(): array
    {
        $start = microtime(true);
        try {
            $response = $this->request('POST', '/api/search/order', [
                'associations' => [
                    'transactions' => ['associations' => ['stateMachineState' => []]],
                    'tags' => [],
                    'documents' => ['associations' => ['documentType' => []]],
                    'billingAddress' => [],
                    'orderCustomer' => [],
                    'salesChannel' => [],
                ],
                'filter' => [
                    [
                        'type' => 'equals',
                        'field' => 'transactions.stateMachineState.technicalName',
                        'value' => 'reminded',
                    ],
                    [
                        'type' => 'equals',
                        'field' => 'salesChannelId',
                        'value' => $this->config->salesChannelId,
                    ],
                    [
                        'type' => 'not',
                        'queries' => [
                            ['type' => 'equals', 'field' => 'tags.name', 'value' => 'Mahnlauf ignorieren'],
                        ],
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $this->logger->debug('Fetched orders', [
                'count' => count($data['data'] ?? []),
                'duration' => microtime(true) - $start,
                'sales_channel_id' => $this->config->salesChannelId,
            ]);

            return $data['data'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch orders', [
                'url' => $this->config->url,
                'sales_channel_id' => $this->config->salesChannelId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Download invoice PDF for a document.
     */
    public function downloadInvoice(string $documentId): string
    {
        $response = $this->request('GET', "/api/document/{$documentId}/download");
        return $response->getBody()->getContents();
    }

    /**
     * Update an order with tags and custom fields.
     *
     * @param array<string, mixed> $payload
     */
    public function updateOrder(string $orderId, array $payload): void
    {
        $this->request('PATCH', "/api/order/{$orderId}", $payload);
    }

    /**
     * Perform an API request with token management and retries.
     *
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $endpoint, array $body = []): ResponseInterface
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $headers = ['Authorization' => 'Bearer ' . $this->getToken()];
                $options = $method === 'GET' ? ['headers' => $headers] : ['headers' => $headers, 'json' => $body];

                return $this->httpClient->request($method, $this->config->url . $endpoint, $options);
            } catch (RequestException $e) {
                $attempt++;
                if ($e->getResponse()?->getStatusCode() === 401 || $this->token === '') {
                    $this->refreshToken();
                    continue;
                }
                if ($attempt >= $maxRetries) {
                    throw new ApiException("Failed to request {$endpoint}: {$e->getMessage()}", 0, $e);
                }
                $this->logger->warning('Retrying request', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'sales_channel_id' => $this->config->salesChannelId,
                    'error' => $e->getMessage(),
                ]);
                usleep(500000 * $attempt); // Exponential backoff
            }
        }

        throw new ApiException("Failed to request {$endpoint} after {$maxRetries} attempts");
    }

    /**
     * Get or refresh the access token.
     */
    private function getToken(): string
    {
        if ($this->token === '' || microtime(true) >= $this->tokenExpiresAt) {
            $this->refreshToken();
        }
        return $this->token;
    }

    /**
     * Refresh the access token.
     *
     * @throws ApiException
     */
    private function refreshToken(): void
    {
        try {
            $response = $this->httpClient->post($this->config->url . '/api/oauth/token', [
                'json' => [
                    'client_id' => $this->config->apiKey,
                    'client_secret' => $this->config->apiSecret,
                    'grant_type' => 'client_credentials',
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $this->token = $data['access_token'];
            $this->tokenExpiresAt = microtime(true) + ($data['expires_in'] - 60); // Buffer for safety
            $this->logger->debug('Refreshed token', [
                'url' => $this->config->url,
                'sales_channel_id' => $this->config->salesChannelId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to refresh token', [
                'url' => $this->config->url,
                'sales_channel_id' => $this->config->salesChannelId,
                'error' => $e->getMessage(),
            ]);
            throw new ApiException('Failed to authenticate: ' . $e->getMessage(), 0, $e);
        }
    }
}
?>