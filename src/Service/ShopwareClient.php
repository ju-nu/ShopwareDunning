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
     * Fetches sales channel ID by name.
     *
     * @throws ApiException
     */
    public function getSalesChannelIdByName(string $salesChannelName): string
    {
        $response = $this->request('POST', '/api/search/sales-channel', [
            'filter' => [['type' => 'equals', 'field' => 'name', 'value' => $salesChannelName]],
            'includes' => ['sales_channel' => ['id']],
        ]);

        if (!isset($response['data']) || !is_array($response['data']) || empty($response['data'])) {
            $this->log->warning('Sales channel not found or invalid response', [
                'name' => $salesChannelName,
                'response' => $response,
            ]);
            throw new ApiException("Sales channel '$salesChannelName' not found");
        }

        return $response['data'][0]['id'];
    }

    /**
     * Searches orders with 'reminded' transaction state and an invoice document.
     *
     * @throws ApiException
     */
    public function searchOrders(): array
    {
        $body = [
            'filter' => [
                ['type' => 'equals', 'field' => 'salesChannelId', 'value' => $this->salesChannelId],
                ['type' => 'equals', 'field' => 'transactions.stateMachineState.technicalName', 'value' => 'reminded'],
                ['type' => 'equals', 'field' => 'documents.documentType.technicalName', 'value' => 'invoice'],
            ],
            'associations' => [
                'transactions' => [
                    'associations' => ['stateMachineState' => []],
                    'sort' => [['field' => 'createdAt', 'order' => 'DESC']],
                    'limit' => 1,
                ],
                'documents' => [
                    'associations' => ['documentType' => []],
                ],
                'tags' => [],
                'billingAddress' => [],
                'orderCustomer' => [],
            ],
            'includes' => [
                'order' => [
                    'id',
                    'orderNumber',
                    'amountTotal',
                    'orderDateTime',
                    'salesChannelId',
                    'customFields',
                    'transactions',
                    'documents',
                    'tags',
                    'billingAddress',
                    'customerComment',
                    'orderCustomer',
                ],
                'order_transaction' => ['id', 'createdAt', 'stateMachineState'],
                'state_machine_state' => ['id', 'technicalName'],
                'document' => ['id', 'deepLinkCode', 'documentType', 'documentNumber'],
                'document_type' => ['id', 'technicalName'],
                'order_address' => ['firstName', 'lastName', 'email'],
                'order_customer' => ['email'],
            ],
            'limit' => 2,
        ];

        $this->log->debug('Search orders request body', ['body' => $body]);

        $response = $this->request('POST', '/api/search/order', $body);

        if (!isset($response['data']) || !is_array($response['data'])) {
            $this->log->warning('Invalid or empty response from API', [
                'response' => $response,
                'uri' => '/api/search/order',
            ]);
            return [];
        }

        $this->log->debug('Raw orders count', ['count' => count($response['data'])]);

        foreach ($response['data'] as $order) {
            $orderId = $order['id'] ?? 'unknown';

            $this->log->debug('Order found', [
                'orderId' => $orderId,
                'orderNumber' => $order['orderNumber'] ?? 'unknown',
            ]);

            foreach ($order['transactions'] as $transaction) {
                $this->log->debug('Transaction state', [
                    'orderId' => $orderId,
                    'transactionId' => $transaction['id'] ?? 'unknown',
                    'state' => $transaction['stateMachineState']['technicalName'] ?? 'null',
                    'stateId' => $transaction['stateMachineState']['id'] ?? 'null',
                ]);
            }

            foreach ($order['documents'] as $document) {
                $this->log->debug('Document found', [
                    'orderId' => $orderId,
                    'documentId' => $document['id'] ?? 'unknown',
                    'type' => $document['documentType']['technicalName'] ?? 'null',
                    'deepLinkCode' => $document['deepLinkCode'] ?? 'unknown',
                ]);
            }
        }

        return array_values($response['data']);
    }

    /**
     * Adds a tag to an order.
     *
     * @throws ApiException
     */
    public function addTag(string $orderId, string $tagName): void
    {
        $tagId = $this->getTagId($tagName);
        $response = $this->request('POST', "/api/order/{$orderId}/tags", [
            'id' => $tagId,
        ]);

        if (!isset($response['data'])) {
            $this->log->warning('Failed to add tag', [
                'orderId' => $orderId,
                'tagName' => $tagName,
                'response' => $response,
            ]);
        }
    }

    /**
     * Updates custom fields for an order.
     *
     * @throws ApiException
     */
    public function updateCustomFields(string $orderId, array $customFields): void
    {
        $response = $this->request('PATCH', "/api/order/{$orderId}", [
            'customFields' => $customFields,
        ]);

        if (!isset($response['data'])) {
            $this->log->warning('Failed to update custom fields', [
                'orderId' => $orderId,
                'customFields' => $customFields,
                'response' => $response,
            ]);
        }
    }

    /**
     * Downloads an invoice document as a PDF.
     *
     * @throws ApiException
     */
    public function downloadInvoice(string $documentId, string $deepLinkCode): string
    {
        $content = $this->request('GET', "/api/_action/document/{$documentId}/{$deepLinkCode}", [], true);

        if (empty($content)) {
            $this->log->warning('Empty document content', [
                'documentId' => $documentId,
                'deepLinkCode' => $deepLinkCode,
            ]);
            throw new ApiException('Failed to download document: Empty response');
        }

        return $content;
    }

    /**
     * Fetches or creates a tag ID by name.
     *
     * @throws ApiException
     */
    private function getTagId(string $tagName): string
    {
        $response = $this->request('POST', '/api/search/tag', [
            'filter' => [['type' => 'equals', 'field' => 'name', 'value' => $tagName]],
        ]);

        if (!isset($response['data']) || !is_array($response['data']) || empty($response['data'])) {
            $createResponse = $this->request('POST', '/api/tag', ['name' => $tagName]);
            if (!isset($createResponse['data']['id'])) {
                $this->log->error('Failed to create tag', [
                    'tagName' => $tagName,
                    'response' => $createResponse,
                ]);
                throw new ApiException("Failed to create tag '$tagName'");
            }
            return $createResponse['data']['id'];
        }

        return $response['data'][0]['id'];
    }

    /**
     * Makes an API request with retry logic.
     *
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
                $bodyContent = $response->getBody()->getContents();

                $this->log->debug('API request successful', [
                    'method' => $method,
                    'uri' => $uri,
                    'time_ms' => (microtime(true) - $start) * 1000,
                    'response' => $raw ? 'raw' : $bodyContent,
                ]);

                if ($raw) {
                    return $bodyContent; // Return the response body as a string
                }

                $decoded = json_decode($bodyContent, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    $this->log->error('Failed to decode JSON response', [
                        'method' => $method,
                        'uri' => $uri,
                        'error' => json_last_error_msg(),
                        'response' => $bodyContent,
                    ]);
                    return [];
                }

                return $decoded;
            } catch (RequestException $e) {
                $attempts++;
                $errorResponse = $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response';

                $this->log->error('API request attempt failed', [
                    'method' => $method,
                    'uri' => $uri,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                    'response' => $errorResponse,
                ]);

                if ($e->getCode() === 401 && $attempts === 1) {
                    $this->accessToken = null;
                    continue;
                }
                if ($attempts >= $maxAttempts) {
                    throw new ApiException("API request failed after $maxAttempts attempts: {$e->getMessage()}", 0, $e);
                }
                usleep((2 ** $attempts) * 100000);
            }
        }

        $this->log->error('Unexpected error in API request', [
            'method' => $method,
            'uri' => $uri,
        ]);
        throw new ApiException('Unexpected error in API request');
    }

    /**
     * Obtains an OAuth access token.
     *
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

            $bodyContent = $response->getBody()->getContents();
            $data = json_decode($bodyContent, true);

            if (!isset($data['access_token']) || !isset($data['expires_in'])) {
                $this->log->error('Invalid OAuth response', [
                    'response' => $bodyContent,
                ]);
                throw new ApiException('Invalid OAuth response');
            }

            $this->accessToken = $data['access_token'];
            $this->tokenExpiresAt = time() + $data['expires_in'];
            $this->log->debug('Access token obtained', [
                'expires_at' => $this->tokenExpiresAt,
            ]);

            return $this->accessToken;
        } catch (RequestException $e) {
            $this->log->error('Failed to obtain access token', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response',
            ]);
            throw new ApiException('Failed to obtain access token: ' . $e->getMessage());
        }
    }
}
