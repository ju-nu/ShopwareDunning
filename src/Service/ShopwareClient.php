<?php

declare(strict_types=1);

namespace Junu\Dunning\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Junu\Dunning\Exception\ApiException;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;

/**
 * Handles Shopware Admin API interactions with OAuth and retry logic.
 */
class ShopwareClient
{
    private Client $client;
    private Logger $log;
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private string $salesChannelId;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $apiSecret,
        string $salesChannelId,
        Logger $log
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->salesChannelId = $salesChannelId;
        $this->log = $log;
        $this->client = new Client(['base_uri' => $this->baseUrl]);
    }

    /**
     * @throws ApiException
     */
    public function searchOrders(): array
    {
        return $this->request('POST', '/api/search/order', [
            'filter' => [
                ['type' => 'equals', 'field' => 'salesChannelId', 'value' => $this->salesChannelId],
                ['type' => 'equals', 'field' => 'transactions.stateMachineState.technicalName', 'value' => 'reminded'],
                ['type' => 'equals', 'field' => 'customFields.junu_dunning_ignore', 'value' => false],
            ],
            'associations' => ['transactions', 'documents', 'tags', 'billingAddress'],
            'includes' => [
                'order' => ['id', 'orderNumber', 'amountTotal', 'orderDateTime', 'salesChannelId', 'customFields', 'transactions', 'documents', 'tags', 'billingAddress'],
                'transaction' => ['stateMachineState'],
                'document' => ['documentType', 'id'],
                'document_type' => ['technicalName'],
                'tag' => ['name'],
                'address' => ['firstName', 'lastName', 'email'],
            ],
        ]);
    }

    /**
     * @throws ApiException
     */
    public function addTag(string $orderId, string $tagName): void
    {
        $tagId = $this->getTagId($tagName);
        $this->request('POST', "/api/order/{$orderId}/tags", [
            'id' => $tagId,
        ]);
    }

    /**
     * @throws ApiException
     */
    public function updateCustomFields(string $orderId, array $customFields): void
    {
        $this->request('PATCH', "/api/order/{$orderId}", [
            'customFields' => $customFields,
        ]);
    }

    /**
     * @throws ApiException
     */
    public function downloadInvoice(string $documentId): string
    {
        $response = $this->request('GET', "/api/document/{$documentId}/download", [], true);
        return $response->getBody()->getContents();
    }

    /**
     * @throws ApiException
     */
    private function getTagId(string $tagName): string
    {
        $response = $this->request('POST', '/api/search/tag', [
            'filter' => [['type' => 'equals', 'field' => 'name', 'value' => $tagName]],
        ]);

        if (empty($response['data'])) {
            $response = $this->request('POST', '/api/tag', ['name' => $tagName]);
            return $response['data']['id'];
        }

        return $response['data'][0]['id'];
    }

    /**
     * @throws ApiException
     */
    private function request(string $method, string $uri, array $body = [], bool $raw = false): array|string
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $headers = [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ];
                $options = $raw ? ['headers' => $headers] : ['headers' => $headers, 'json' => $body];

                $this->log->debug('Sending API request', [
                    'method' => $method,
                    'uri' => $uri,
                    'headers' => $headers,
                    'body' => $raw ? 'raw' : json_encode($body),
                ]);

                $start = microtime(true);
                $response = $this->client->request($method, $uri, $options);
                $this->log->debug('API request successful', [
                    'method' => $method,
                    'uri' => $uri,
                    'time_ms' => (microtime(true) - $start) * 1000,
                ]);

                return $raw ? $response : json_decode($response->getBody()->getContents(), true);
            } catch (RequestException $e) {
                $attempts++;
                if ($e->getCode() === 401 && $attempts === 1) {
                    $this->accessToken = null;
                    continue;
                }
                if ($attempts >= $maxAttempts) {
                    $this->log->error('API request failed', [
                        'method' => $method,
                        'uri' => $uri,
                        'error' => $e->getMessage(),
                        'response' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response',
                    ]);
                    throw new ApiException("API request failed: {$e->getMessage()}");
                }
                usleep((2 ** $attempts) * 100000);
            }
        }

        throw new ApiException('Unexpected error in API request');
    }

    /**
     * @throws ApiException
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiresAt > time() + 60) {
            return $this->accessToken;
        }

        try {
            $response = $this->client->post('/api/oauth/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->apiKey,
                    'client_secret' => $this->apiSecret,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $this->accessToken = $data['access_token'];
            $this->tokenExpiresAt = time() + $data['expires_in'];
            return $this->accessToken;
        } catch (RequestException $e) {
            $this->log->error('Failed to obtain access token', ['error' => $e->getMessage()]);
            throw new ApiException('Failed to obtain access token: ' . $e->getMessage());
        }
    }
}