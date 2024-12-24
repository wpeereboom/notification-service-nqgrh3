<?php

declare(strict_types=1);

namespace App\Services\Vendor\Email;

use App\Contracts\VendorInterface;
use App\Utils\CircuitBreaker;
use App\Exceptions\VendorException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheInterface;
use JsonException;

/**
 * Iterable email service implementation with high-throughput processing,
 * circuit breaker pattern, and comprehensive monitoring capabilities.
 *
 * @package App\Services\Vendor\Email
 * @version 1.0.0
 */
class IterableService implements VendorInterface
{
    private const VENDOR_NAME = 'iterable';
    private const VENDOR_TYPE = 'email';
    private const API_VERSION = 'v1';
    private const API_TIMEOUT = 5;
    private const HEALTH_CHECK_INTERVAL = 30;
    private const CACHE_TTL = 300;
    private const MAX_RETRIES = 3;

    private Client $httpClient;
    private LoggerInterface $logger;
    private CircuitBreaker $circuitBreaker;
    private CacheInterface $cache;
    private string $apiKey;
    private string $apiEndpoint;
    private array $metrics = [
        'requests' => 0,
        'successes' => 0,
        'failures' => 0,
        'latency' => [],
    ];

    /**
     * Creates new Iterable service instance with monitoring capabilities.
     *
     * @param Client $httpClient HTTP client for API requests
     * @param LoggerInterface $logger Logger instance
     * @param CircuitBreaker $circuitBreaker Circuit breaker for fault tolerance
     * @param CacheInterface $cache Cache handler
     * @param string $apiKey Iterable API key
     * @param string $apiEndpoint Iterable API endpoint
     */
    public function __construct(
        Client $httpClient,
        LoggerInterface $logger,
        CircuitBreaker $circuitBreaker,
        CacheInterface $cache,
        string $apiKey,
        string $apiEndpoint
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->circuitBreaker = $circuitBreaker;
        $this->cache = $cache;
        $this->apiKey = $apiKey;
        $this->apiEndpoint = rtrim($apiEndpoint, '/');

        $this->logger->info('Iterable service initialized', [
            'vendor' => self::VENDOR_NAME,
            'endpoint' => $this->apiEndpoint
        ]);
    }

    /**
     * @inheritDoc
     */
    public function send(array $payload): array
    {
        $startTime = microtime(true);
        $this->metrics['requests']++;

        try {
            if (!$this->circuitBreaker->isAvailable()) {
                throw new VendorException(
                    'Iterable service circuit breaker is open',
                    VendorException::VENDOR_CIRCUIT_OPEN,
                    null,
                    [
                        'vendor_name' => self::VENDOR_NAME,
                        'channel' => self::VENDOR_TYPE,
                        'circuit_breaker_open' => true
                    ]
                );
            }

            $this->validatePayload($payload);
            $iterablePayload = $this->transformPayload($payload);
            
            $response = $this->sendWithRetry($iterablePayload);
            $messageId = $this->extractMessageId($response);
            
            $this->circuitBreaker->recordSuccess();
            $this->metrics['successes']++;
            $this->metrics['latency'][] = microtime(true) - $startTime;

            $this->logger->info('Email sent successfully via Iterable', [
                'message_id' => $messageId,
                'recipient' => $payload['recipient'],
                'template_id' => $payload['template_id'] ?? null
            ]);

            return [
                'messageId' => $messageId,
                'status' => 'sent',
                'timestamp' => date('c'),
                'vendorResponse' => $response,
                'metadata' => [
                    'latency' => microtime(true) - $startTime,
                    'vendor' => self::VENDOR_NAME,
                    'attempt' => 1
                ]
            ];

        } catch (VendorException $e) {
            $this->handleError($e, $startTime);
            throw $e;
        } catch (\Exception $e) {
            $this->handleError($e, $startTime);
            throw new VendorException(
                'Failed to send email via Iterable',
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'error_message' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getStatus(string $messageId): array
    {
        $cacheKey = sprintf('iterable_status_%s', $messageId);
        $cachedStatus = $this->cache->get($cacheKey);

        if ($cachedStatus !== null) {
            return $cachedStatus;
        }

        try {
            $response = $this->httpClient->get(
                sprintf('%s/%s/messages/%s', $this->apiEndpoint, self::API_VERSION, $messageId),
                [
                    'headers' => $this->getHeaders(),
                    'timeout' => self::API_TIMEOUT
                ]
            );

            $status = $this->parseStatusResponse(
                json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)
            );

            $this->cache->set($cacheKey, $status, self::CACHE_TTL);

            return $status;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get message status from Iterable', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);

            throw new VendorException(
                'Failed to get message status from Iterable',
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'message_id' => $messageId
                ]
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function checkHealth(): array
    {
        $cacheKey = 'iterable_health_status';
        $cachedHealth = $this->cache->get($cacheKey);

        if ($cachedHealth !== null) {
            return $cachedHealth;
        }

        try {
            $startTime = microtime(true);
            $response = $this->httpClient->get(
                sprintf('%s/%s/health', $this->apiEndpoint, self::API_VERSION),
                [
                    'headers' => $this->getHeaders(),
                    'timeout' => self::API_TIMEOUT
                ]
            );

            $latency = microtime(true) - $startTime;
            $health = [
                'isHealthy' => $response->getStatusCode() === 200,
                'latency' => $latency,
                'timestamp' => date('c'),
                'diagnostics' => [
                    'api_version' => self::API_VERSION,
                    'success_rate' => $this->calculateSuccessRate(),
                    'average_latency' => $this->calculateAverageLatency(),
                    'circuit_breaker' => $this->circuitBreaker->getState()
                ],
                'lastError' => null
            ];

            $this->cache->set($cacheKey, $health, self::HEALTH_CHECK_INTERVAL);

            return $health;

        } catch (\Exception $e) {
            $health = [
                'isHealthy' => false,
                'latency' => null,
                'timestamp' => date('c'),
                'diagnostics' => [
                    'success_rate' => $this->calculateSuccessRate(),
                    'circuit_breaker' => $this->circuitBreaker->getState()
                ],
                'lastError' => $e->getMessage()
            ];

            $this->cache->set($cacheKey, $health, self::HEALTH_CHECK_INTERVAL);

            return $health;
        }
    }

    /**
     * @inheritDoc
     */
    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getVendorType(): string
    {
        return self::VENDOR_TYPE;
    }

    /**
     * Validates email payload structure and content.
     *
     * @param array $payload The payload to validate
     * @throws VendorException If payload is invalid
     */
    private function validatePayload(array $payload): void
    {
        $requiredFields = ['recipient', 'content'];
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                throw new VendorException(
                    sprintf('Missing required field: %s', $field),
                    VendorException::VENDOR_INVALID_REQUEST,
                    null,
                    [
                        'vendor_name' => self::VENDOR_NAME,
                        'channel' => self::VENDOR_TYPE,
                        'missing_field' => $field
                    ]
                );
            }
        }

        if (!filter_var($payload['recipient'], FILTER_VALIDATE_EMAIL)) {
            throw new VendorException(
                'Invalid email address',
                VendorException::VENDOR_INVALID_REQUEST,
                null,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'recipient' => $payload['recipient']
                ]
            );
        }
    }

    /**
     * Transforms internal payload format to Iterable API format.
     *
     * @param array $payload Internal payload
     * @return array Iterable API formatted payload
     */
    private function transformPayload(array $payload): array
    {
        return [
            'recipientEmail' => $payload['recipient'],
            'templateId' => $payload['template_id'] ?? null,
            'dataFields' => $payload['content'],
            'metadata' => $payload['metadata'] ?? [],
            'sendAt' => $payload['schedule_time'] ?? null
        ];
    }

    /**
     * Sends request to Iterable API with retry logic.
     *
     * @param array $payload The payload to send
     * @return array API response
     * @throws VendorException If all retries fail
     */
    private function sendWithRetry(array $payload): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $response = $this->httpClient->post(
                    sprintf('%s/%s/email/send', $this->apiEndpoint, self::API_VERSION),
                    [
                        'headers' => $this->getHeaders(),
                        'json' => $payload,
                        'timeout' => self::API_TIMEOUT
                    ]
                );

                return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            } catch (GuzzleException|JsonException $e) {
                $lastException = $e;
                $attempt++;
                
                if ($attempt < self::MAX_RETRIES) {
                    usleep(100000 * pow(2, $attempt)); // Exponential backoff
                }
            }
        }

        throw new VendorException(
            'Max retries exceeded for Iterable API request',
            VendorException::VENDOR_UNAVAILABLE,
            $lastException,
            [
                'vendor_name' => self::VENDOR_NAME,
                'channel' => self::VENDOR_TYPE,
                'attempts' => self::MAX_RETRIES
            ]
        );
    }

    /**
     * Extracts message ID from Iterable API response.
     *
     * @param array $response API response
     * @return string Message ID
     * @throws VendorException If message ID is missing
     */
    private function extractMessageId(array $response): string
    {
        if (!isset($response['messageId'])) {
            throw new VendorException(
                'Missing message ID in Iterable response',
                VendorException::VENDOR_INVALID_REQUEST,
                null,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'response' => $response
                ]
            );
        }

        return (string)$response['messageId'];
    }

    /**
     * Handles and logs error scenarios.
     *
     * @param \Throwable $error The error to handle
     * @param float $startTime Request start time
     */
    private function handleError(\Throwable $error, float $startTime): void
    {
        $this->metrics['failures']++;
        $this->metrics['latency'][] = microtime(true) - $startTime;

        if (!$error instanceof VendorException || $error->getCode() !== VendorException::VENDOR_CIRCUIT_OPEN) {
            $this->circuitBreaker->recordFailure();
        }

        $this->logger->error('Iterable API error', [
            'error' => $error->getMessage(),
            'code' => $error->getCode(),
            'vendor' => self::VENDOR_NAME,
            'latency' => microtime(true) - $startTime
        ]);
    }

    /**
     * Generates API request headers.
     *
     * @return array Headers array
     */
    private function getHeaders(): array
    {
        return [
            'Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'NotificationService/1.0'
        ];
    }

    /**
     * Calculates current success rate.
     *
     * @return float Success rate percentage
     */
    private function calculateSuccessRate(): float
    {
        if ($this->metrics['requests'] === 0) {
            return 100.0;
        }

        return ($this->metrics['successes'] / $this->metrics['requests']) * 100;
    }

    /**
     * Calculates average request latency.
     *
     * @return float|null Average latency in seconds
     */
    private function calculateAverageLatency(): ?float
    {
        if (empty($this->metrics['latency'])) {
            return null;
        }

        return array_sum($this->metrics['latency']) / count($this->metrics['latency']);
    }

    /**
     * Parses and standardizes status response from Iterable.
     *
     * @param array $response Raw API response
     * @return array Standardized status response
     */
    private function parseStatusResponse(array $response): array
    {
        return [
            'currentState' => $this->mapIterableStatus($response['status'] ?? 'unknown'),
            'timestamps' => [
                'sent' => $response['sentAt'] ?? null,
                'delivered' => $response['deliveredAt'] ?? null,
                'failed' => $response['failedAt'] ?? null
            ],
            'attempts' => $response['attempts'] ?? 1,
            'vendorMetadata' => $response
        ];
    }

    /**
     * Maps Iterable status to standardized status.
     *
     * @param string $status Iterable status
     * @return string Standardized status
     */
    private function mapIterableStatus(string $status): string
    {
        return match (strtolower($status)) {
            'sent', 'delivered' => 'delivered',
            'failed', 'bounced', 'complained' => 'failed',
            'queued', 'sending' => 'pending',
            default => 'unknown'
        };
    }
}