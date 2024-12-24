<?php

declare(strict_types=1);

namespace App\Services\Vendor;

use App\Contracts\VendorInterface;
use App\Exceptions\VendorException;
use App\Utils\CircuitBreaker;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;

/**
 * Core service that manages vendor interactions with distributed state management.
 * Implements sophisticated failover logic and health monitoring across notification channels.
 *
 * @package App\Services\Vendor
 * @version 1.0.0
 */
class VendorService
{
    /**
     * Redis key prefixes for rate limiting and metrics
     */
    private const RATE_LIMIT_KEY = 'rate_limit:{tenant}:{channel}';
    private const METRICS_KEY = 'metrics:{tenant}:{channel}:{vendor}';

    /**
     * @var VendorFactory Vendor factory instance
     */
    private VendorFactory $factory;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var Redis Redis client for distributed state
     */
    private Redis $redis;

    /**
     * @var array<string, CircuitBreaker> Circuit breakers by vendor
     */
    private array $circuitBreakers = [];

    /**
     * @var array Vendor metrics collection
     */
    private array $metrics = [];

    /**
     * Creates new vendor service instance with distributed state management.
     *
     * @param VendorFactory $factory Vendor factory instance
     * @param LoggerInterface $logger Logger for comprehensive tracking
     * @param Redis $redis Redis client for distributed state
     */
    public function __construct(
        VendorFactory $factory,
        LoggerInterface $logger,
        Redis $redis
    ) {
        $this->factory = $factory;
        $this->logger = $logger;
        $this->redis = $redis;
    }

    /**
     * Sends notification through appropriate vendor with failover support.
     *
     * @param array $payload Notification payload
     * @param string $channel Notification channel
     * @param string $tenantId Tenant identifier
     * @return array Delivery status and tracking information
     * @throws VendorException When all delivery attempts fail
     */
    public function send(array $payload, string $channel, string $tenantId): array
    {
        // Check rate limits
        $this->checkRateLimit($tenantId, $channel);

        try {
            // Get healthy vendor for channel
            $vendor = $this->factory->getHealthyVendor($channel, $tenantId);
            $vendorName = $vendor->getVendorName();

            // Initialize circuit breaker if needed
            if (!isset($this->circuitBreakers[$vendorName])) {
                $this->circuitBreakers[$vendorName] = new CircuitBreaker(
                    $this->redis,
                    $this->logger,
                    $vendorName,
                    $channel,
                    $tenantId
                );
            }

            // Check circuit breaker
            if (!$this->circuitBreakers[$vendorName]->isAvailable()) {
                return $this->handleFailover($payload, $channel, $vendor, $tenantId);
            }

            // Attempt delivery
            $response = $vendor->send($payload);

            // Record success
            $this->circuitBreakers[$vendorName]->recordSuccess();
            $this->updateMetrics($vendorName, $channel, $tenantId, true);

            return $this->enrichResponse($response, $vendorName, $channel);

        } catch (VendorException $e) {
            $this->logger->error('Vendor delivery failed', [
                'vendor' => $e->getVendorName(),
                'channel' => $channel,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);

            return $this->handleFailover($payload, $channel, $vendor, $tenantId);
        }
    }

    /**
     * Retrieves notification status with enhanced tracking.
     *
     * @param string $messageId Message identifier
     * @param string $vendorName Vendor name
     * @param string $tenantId Tenant identifier
     * @return array Detailed status information
     * @throws VendorException When status check fails
     */
    public function getStatus(string $messageId, string $vendorName, string $tenantId): array
    {
        try {
            $vendor = $this->factory->create($vendorName, $tenantId);
            $status = $vendor->getStatus($messageId);

            // Enrich with tracking data
            $tracking = $this->redis->hgetall(
                "tracking:{$tenantId}:{$messageId}"
            );

            return array_merge($status, ['tracking' => $tracking]);

        } catch (VendorException $e) {
            $this->logger->error('Status check failed', [
                'message_id' => $messageId,
                'vendor' => $vendorName,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);
            throw $e;
        }
    }

    /**
     * Performs comprehensive vendor health check.
     *
     * @param string $vendorName Vendor identifier
     * @return array Detailed health status
     */
    public function checkVendorHealth(string $vendorName): array
    {
        try {
            $vendor = $this->factory->create($vendorName, 'system');
            $health = $vendor->checkHealth();

            // Get circuit breaker state if exists
            $circuitState = isset($this->circuitBreakers[$vendorName]) 
                ? $this->circuitBreakers[$vendorName]->getState()
                : ['state' => 'unknown'];

            // Get vendor metrics
            $metrics = $this->getVendorMetrics($vendorName);

            return array_merge($health, [
                'circuit_breaker' => $circuitState,
                'metrics' => $metrics
            ]);

        } catch (VendorException $e) {
            $this->logger->error('Health check failed', [
                'vendor' => $vendorName,
                'error' => $e->getMessage()
            ]);

            return [
                'isHealthy' => false,
                'error' => $e->getMessage(),
                'circuit_breaker' => $circuitState ?? ['state' => 'unknown'],
                'metrics' => $metrics ?? []
            ];
        }
    }

    /**
     * Handles vendor failover with exponential backoff.
     *
     * @param array $payload Notification payload
     * @param string $channel Notification channel
     * @param VendorInterface $failedVendor Failed vendor instance
     * @param string $tenantId Tenant identifier
     * @return array Delivery status from backup vendor
     * @throws VendorException When all failover attempts fail
     */
    private function handleFailover(
        array $payload,
        string $channel,
        VendorInterface $failedVendor,
        string $tenantId
    ): array {
        $attempts = 0;
        $maxAttempts = (int)RETRY_ATTEMPTS;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                // Get next healthy vendor
                $backupVendor = $this->factory->getHealthyVendor($channel, $tenantId);
                
                // Skip if same as failed vendor
                if ($backupVendor->getVendorName() === $failedVendor->getVendorName()) {
                    continue;
                }

                // Calculate backoff delay
                $delay = (int)RETRY_DELAY_MS * pow(2, $attempts);
                usleep($delay * 1000);

                // Attempt delivery through backup vendor
                $response = $backupVendor->send($payload);

                // Record success
                $this->circuitBreakers[$backupVendor->getVendorName()]->recordSuccess();
                $this->updateMetrics($backupVendor->getVendorName(), $channel, $tenantId, true);

                return $this->enrichResponse($response, $backupVendor->getVendorName(), $channel);

            } catch (VendorException $e) {
                $lastException = $e;
                $attempts++;

                $this->logger->warning('Failover attempt failed', [
                    'attempt' => $attempts,
                    'vendor' => $e->getVendorName(),
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                    'tenant_id' => $tenantId
                ]);
            }
        }

        // All failover attempts exhausted
        throw new VendorException(
            'All failover attempts exhausted',
            VendorException::VENDOR_FAILOVER_EXHAUSTED,
            $lastException,
            [
                'vendor_name' => $failedVendor->getVendorName(),
                'channel' => $channel,
                'failover_attempts' => $attempts,
                'tenant_id' => $tenantId
            ]
        );
    }

    /**
     * Checks rate limits for tenant and channel.
     *
     * @param string $tenantId Tenant identifier
     * @param string $channel Notification channel
     * @throws VendorException When rate limit exceeded
     */
    private function checkRateLimit(string $tenantId, string $channel): void
    {
        $key = str_replace(
            ['{tenant}', '{channel}'],
            [$tenantId, $channel],
            self::RATE_LIMIT_KEY
        );

        $current = $this->redis->incr($key);
        if ($current === 1) {
            $this->redis->expire($key, (int)RATE_LIMIT_WINDOW);
        }

        if ($current > (int)RATE_LIMIT_MAX_REQUESTS) {
            throw new VendorException(
                'Rate limit exceeded',
                VendorException::VENDOR_RATE_LIMITED,
                null,
                [
                    'tenant_id' => $tenantId,
                    'channel' => $channel,
                    'limit' => RATE_LIMIT_MAX_REQUESTS,
                    'window' => RATE_LIMIT_WINDOW
                ]
            );
        }
    }

    /**
     * Updates vendor metrics in Redis.
     *
     * @param string $vendorName Vendor identifier
     * @param string $channel Notification channel
     * @param string $tenantId Tenant identifier
     * @param bool $success Whether operation succeeded
     */
    private function updateMetrics(
        string $vendorName,
        string $channel,
        string $tenantId,
        bool $success
    ): void {
        $key = str_replace(
            ['{tenant}', '{channel}', '{vendor}'],
            [$tenantId, $channel, $vendorName],
            self::METRICS_KEY
        );

        $this->redis->multi();
        $this->redis->hincrby($key, $success ? 'successes' : 'failures', 1);
        $this->redis->hset($key, 'last_update', (string)time());
        $this->redis->expire($key, 86400); // 24 hours
        $this->redis->exec();
    }

    /**
     * Gets vendor metrics from Redis.
     *
     * @param string $vendorName Vendor identifier
     * @return array Vendor metrics
     */
    private function getVendorMetrics(string $vendorName): array
    {
        $metrics = [];
        $pattern = str_replace(
            '{vendor}',
            $vendorName,
            self::METRICS_KEY
        );

        foreach ($this->redis->keys($pattern) as $key) {
            $data = $this->redis->hgetall($key);
            $metrics[] = [
                'tenant_id' => explode(':', $key)[1],
                'channel' => explode(':', $key)[2],
                'successes' => (int)($data['successes'] ?? 0),
                'failures' => (int)($data['failures'] ?? 0),
                'last_update' => (int)($data['last_update'] ?? 0)
            ];
        }

        return $metrics;
    }

    /**
     * Enriches vendor response with additional context.
     *
     * @param array $response Original vendor response
     * @param string $vendorName Vendor identifier
     * @param string $channel Notification channel
     * @return array Enriched response
     */
    private function enrichResponse(array $response, string $vendorName, string $channel): array
    {
        return array_merge($response, [
            'vendor' => $vendorName,
            'channel' => $channel,
            'timestamp' => time(),
            'version' => '1.0.0'
        ]);
    }
}