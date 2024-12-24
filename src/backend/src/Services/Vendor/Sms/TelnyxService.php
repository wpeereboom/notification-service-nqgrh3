<?php

declare(strict_types=1);

namespace App\Services\Vendor\Sms;

use App\Contracts\VendorInterface;
use App\Exceptions\VendorException;
use App\Utils\CircuitBreaker;
use Telnyx\Client as TelnyxClient; // ^2.0
use Predis\Client as Redis; // ^2.0
use Psr\Log\LoggerInterface; // ^3.0

/**
 * Telnyx SMS service implementation with high-throughput processing capabilities.
 * Provides primary SMS delivery with failover support and comprehensive monitoring.
 *
 * @package App\Services\Vendor\Sms
 * @version 1.0.0
 */
class TelnyxService implements VendorInterface
{
    private const VENDOR_NAME = 'telnyx';
    private const VENDOR_TYPE = 'sms';
    private const API_TIMEOUT = 5;
    private const MAX_BATCH_SIZE = 100;
    private const CACHE_TTL = 300;
    private const STATUS_CACHE_PREFIX = 'telnyx:status:';
    private const HEALTH_CHECK_NUMBER = '+1234567890';

    private TelnyxClient $client;
    private LoggerInterface $logger;
    private CircuitBreaker $circuitBreaker;
    private Redis $redis;
    private array $metrics = [
        'sent_count' => 0,
        'error_count' => 0,
        'latency_sum' => 0,
        'last_success_time' => null,
    ];

    /**
     * Creates new Telnyx service instance with enhanced configuration.
     *
     * @param TelnyxClient $client Configured Telnyx client
     * @param LoggerInterface $logger PSR-3 logger
     * @param Redis $redis Redis client for caching
     */
    public function __construct(
        TelnyxClient $client,
        LoggerInterface $logger,
        Redis $redis
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->redis = $redis;
        
        // Initialize circuit breaker with tenant-aware configuration
        $this->circuitBreaker = new CircuitBreaker(
            $redis,
            $logger,
            self::VENDOR_NAME,
            self::VENDOR_TYPE,
            'default'
        );

        // Configure Telnyx client timeout
        $this->client->setTimeout(self::API_TIMEOUT);
    }

    /**
     * {@inheritdoc}
     */
    public function send(array $payload): array
    {
        $startTime = microtime(true);

        try {
            // Check circuit breaker before proceeding
            if (!$this->circuitBreaker->isAvailable()) {
                throw new VendorException(
                    'Telnyx service is currently unavailable',
                    VendorException::VENDOR_CIRCUIT_OPEN,
                    null,
                    [
                        'vendor_name' => self::VENDOR_NAME,
                        'channel' => self::VENDOR_TYPE,
                        'circuit_breaker_open' => true
                    ]
                );
            }

            // Validate payload
            $this->validatePayload($payload);

            // Transform payload for Telnyx API
            $telnyxPayload = $this->transformPayload($payload);

            // Send message through Telnyx
            $response = $this->client->messages->create($telnyxPayload);

            // Record success in circuit breaker
            $this->circuitBreaker->recordSuccess();

            // Update metrics
            $this->updateMetrics($startTime);

            // Cache successful response
            $this->cacheResponse($response->id, [
                'status' => $response->status,
                'timestamp' => time(),
                'messageId' => $response->id
            ]);

            return [
                'messageId' => $response->id,
                'status' => 'sent',
                'timestamp' => date('c'),
                'vendorResponse' => [
                    'status' => $response->status,
                    'to' => $response->to,
                    'provider' => self::VENDOR_NAME
                ],
                'metadata' => [
                    'latency' => (microtime(true) - $startTime) * 1000,
                    'attempts' => 1
                ]
            ];

        } catch (\Throwable $e) {
            // Record failure in circuit breaker
            $this->circuitBreaker->recordFailure();

            // Update error metrics
            $this->metrics['error_count']++;

            $context = [
                'vendor_name' => self::VENDOR_NAME,
                'channel' => self::VENDOR_TYPE,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'circuit_breaker_open' => $this->circuitBreaker->getState()['state'] === 'open'
            ];

            $this->logger->error('Telnyx SMS delivery failed', $context);

            throw new VendorException(
                'Failed to send SMS through Telnyx: ' . $e->getMessage(),
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                $context
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(string $messageId): array
    {
        // Check cache first
        $cached = $this->redis->get(self::STATUS_CACHE_PREFIX . $messageId);
        if ($cached) {
            return json_decode($cached, true);
        }

        try {
            $response = $this->client->messages->retrieve($messageId);

            $status = [
                'currentState' => $this->mapTelnyxStatus($response->status),
                'timestamps' => [
                    'sent' => $response->sent_at,
                    'delivered' => $response->completed_at,
                    'failed' => $response->errors ? $response->errors[0]->occurred_at : null
                ],
                'attempts' => 1,
                'vendorMetadata' => [
                    'provider' => self::VENDOR_NAME,
                    'rawStatus' => $response->status,
                    'errorCode' => $response->errors[0]->code ?? null,
                    'errorMessage' => $response->errors[0]->detail ?? null
                ]
            ];

            // Cache the status
            $this->cacheResponse($messageId, $status);

            return $status;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve Telnyx message status', [
                'messageId' => $messageId,
                'error' => $e->getMessage()
            ]);

            throw new VendorException(
                'Failed to retrieve message status: ' . $e->getMessage(),
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
     * {@inheritdoc}
     */
    public function checkHealth(): array
    {
        $startTime = microtime(true);

        try {
            // Check circuit breaker state
            $circuitState = $this->circuitBreaker->getState();

            // Verify API credentials with test message
            $response = $this->client->messages->create([
                'to' => self::HEALTH_CHECK_NUMBER,
                'text' => 'Health check',
                'type' => 'test'
            ]);

            $latency = (microtime(true) - $startTime) * 1000;

            return [
                'isHealthy' => true,
                'latency' => $latency,
                'timestamp' => date('c'),
                'diagnostics' => [
                    'circuitBreaker' => $circuitState,
                    'apiStatus' => 'available',
                    'metrics' => $this->metrics,
                    'quotaRemaining' => $response->quota_remaining ?? null
                ],
                'lastError' => null
            ];

        } catch (\Throwable $e) {
            return [
                'isHealthy' => false,
                'latency' => (microtime(true) - $startTime) * 1000,
                'timestamp' => date('c'),
                'diagnostics' => [
                    'circuitBreaker' => $circuitState ?? null,
                    'apiStatus' => 'unavailable',
                    'metrics' => $this->metrics
                ],
                'lastError' => $e->getMessage()
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function getVendorType(): string
    {
        return self::VENDOR_TYPE;
    }

    /**
     * Validates the SMS payload structure and content.
     *
     * @param array $payload The payload to validate
     * @throws \InvalidArgumentException If payload is invalid
     */
    private function validatePayload(array $payload): void
    {
        if (empty($payload['recipient']) || !is_string($payload['recipient'])) {
            throw new \InvalidArgumentException('Invalid recipient phone number');
        }

        if (empty($payload['content']) || !is_string($payload['content'])) {
            throw new \InvalidArgumentException('Invalid message content');
        }

        // Validate phone number format
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $payload['recipient'])) {
            throw new \InvalidArgumentException('Invalid phone number format');
        }
    }

    /**
     * Transforms the standard payload into Telnyx-specific format.
     *
     * @param array $payload Standard payload
     * @return array Telnyx-specific payload
     */
    private function transformPayload(array $payload): array
    {
        return [
            'to' => $payload['recipient'],
            'text' => $payload['content'],
            'messaging_profile_id' => $payload['messaging_profile_id'] ?? null,
            'webhook_url' => $payload['webhook_url'] ?? null,
            'webhook_failover_url' => $payload['webhook_failover_url'] ?? null,
            'media_urls' => $payload['media_urls'] ?? null,
        ];
    }

    /**
     * Maps Telnyx status to standardized status.
     *
     * @param string $telnyxStatus Raw Telnyx status
     * @return string Standardized status
     */
    private function mapTelnyxStatus(string $telnyxStatus): string
    {
        return match ($telnyxStatus) {
            'delivered' => 'delivered',
            'failed', 'error' => 'failed',
            'queued', 'sending' => 'pending',
            default => 'unknown'
        };
    }

    /**
     * Caches response data with TTL.
     *
     * @param string $messageId Message identifier
     * @param array $data Response data
     */
    private function cacheResponse(string $messageId, array $data): void
    {
        $this->redis->setex(
            self::STATUS_CACHE_PREFIX . $messageId,
            self::CACHE_TTL,
            json_encode($data)
        );
    }

    /**
     * Updates service metrics after successful operation.
     *
     * @param float $startTime Operation start time
     */
    private function updateMetrics(float $startTime): void
    {
        $latency = (microtime(true) - $startTime) * 1000;
        $this->metrics['sent_count']++;
        $this->metrics['latency_sum'] += $latency;
        $this->metrics['last_success_time'] = time();
    }
}