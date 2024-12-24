<?php

declare(strict_types=1);

namespace App\Utils;

use App\Exceptions\VendorException;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;

/**
 * Circuit Breaker implementation for vendor fault tolerance with multi-tenant support.
 * Provides distributed state management and enhanced monitoring capabilities.
 *
 * @package App\Utils
 * @version 1.0.0
 */
class CircuitBreaker
{
    /**
     * Circuit breaker states
     */
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    /**
     * Configuration constants
     */
    private const FAILURE_THRESHOLD = 5;
    private const RESET_TIMEOUT_SECONDS = 30;
    private const HALF_OPEN_TIMEOUT_SECONDS = 15;
    private const EXPONENTIAL_BACKOFF_BASE = 2;
    private const REDIS_KEY_PREFIX = 'circuit_breaker:{tenant}:{channel}:{vendor}';

    /**
     * @var Redis Redis client instance
     */
    private Redis $redis;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var string Vendor name
     */
    private string $vendorName;

    /**
     * @var string Notification channel
     */
    private string $channel;

    /**
     * @var string Tenant identifier
     */
    private string $tenantId;

    /**
     * @var array Metrics collection
     */
    private array $metrics = [
        'failures' => 0,
        'successes' => 0,
        'last_failure_time' => null,
        'last_success_time' => null,
        'state_changes' => [],
    ];

    /**
     * Creates new circuit breaker instance with multi-tenant support.
     *
     * @param Redis $redis Redis client for distributed state
     * @param LoggerInterface $logger Logger for state changes
     * @param string $vendorName Vendor identifier
     * @param string $channel Notification channel
     * @param string $tenantId Tenant identifier
     */
    public function __construct(
        Redis $redis,
        LoggerInterface $logger,
        string $vendorName,
        string $channel,
        string $tenantId
    ) {
        $this->redis = $redis;
        $this->logger = $logger;
        $this->vendorName = $this->sanitizeVendorName($vendorName);
        $this->channel = $this->validateChannel($channel);
        $this->tenantId = $this->sanitizeTenantId($tenantId);

        $this->initializeState();
    }

    /**
     * Checks if the vendor is available for requests.
     *
     * @return bool True if circuit is closed or half-open and ready for test request
     */
    public function isAvailable(): bool
    {
        $state = $this->getStateFromRedis();
        
        if ($state['state'] === self::STATE_CLOSED) {
            return true;
        }

        if ($state['state'] === self::STATE_OPEN) {
            $resetTimeout = $this->calculateResetTimeout($state['failure_count']);
            
            if ((time() - $state['last_failure_time']) >= $resetTimeout) {
                $this->transitionToHalfOpen();
                return true;
            }
            
            return false;
        }

        if ($state['state'] === self::STATE_HALF_OPEN) {
            return $this->canAttemptHalfOpenRequest();
        }

        return false;
    }

    /**
     * Records a successful vendor operation.
     *
     * @return void
     */
    public function recordSuccess(): void
    {
        $state = $this->getStateFromRedis();
        
        $this->redis->multi();
        $this->redis->hset($this->getRedisKey(), 'failure_count', 0);
        $this->redis->hset($this->getRedisKey(), 'last_success_time', time());

        if ($state['state'] === self::STATE_HALF_OPEN) {
            $this->redis->hset($this->getRedisKey(), 'state', self::STATE_CLOSED);
            $this->logStateChange(self::STATE_HALF_OPEN, self::STATE_CLOSED);
        }

        $this->redis->exec();

        $this->metrics['successes']++;
        $this->metrics['last_success_time'] = time();
    }

    /**
     * Records a failed vendor operation.
     *
     * @throws VendorException When circuit transitions to open state
     * @return void
     */
    public function recordFailure(): void
    {
        $state = $this->getStateFromRedis();
        $currentTime = time();

        $this->redis->multi();
        $this->redis->hincrby($this->getRedisKey(), 'failure_count', 1);
        $this->redis->hset($this->getRedisKey(), 'last_failure_time', $currentTime);

        $newFailureCount = $state['failure_count'] + 1;

        if ($newFailureCount >= self::FAILURE_THRESHOLD || $state['state'] === self::STATE_HALF_OPEN) {
            $this->redis->hset($this->getRedisKey(), 'state', self::STATE_OPEN);
            $this->logStateChange($state['state'], self::STATE_OPEN);
        }

        $this->redis->exec();

        $this->metrics['failures']++;
        $this->metrics['last_failure_time'] = $currentTime;

        if ($newFailureCount >= self::FAILURE_THRESHOLD || $state['state'] === self::STATE_HALF_OPEN) {
            throw new VendorException(
                "Circuit breaker opened for vendor {$this->vendorName}",
                VendorException::VENDOR_CIRCUIT_OPEN,
                null,
                [
                    'vendor_name' => $this->vendorName,
                    'channel' => $this->channel,
                    'circuit_breaker_open' => true,
                    'failure_count' => $newFailureCount,
                    'tenant_id' => $this->tenantId
                ]
            );
        }
    }

    /**
     * Gets current circuit breaker state and metrics.
     *
     * @return array Current state and metrics
     */
    public function getState(): array
    {
        $state = $this->getStateFromRedis();
        
        return [
            'state' => $state['state'],
            'failure_count' => $state['failure_count'],
            'last_failure_time' => $state['last_failure_time'],
            'last_success_time' => $state['last_success_time'],
            'metrics' => $this->metrics,
            'vendor' => $this->vendorName,
            'channel' => $this->channel,
            'tenant_id' => $this->tenantId
        ];
    }

    /**
     * Forcefully resets circuit breaker state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->redis->multi();
        $this->redis->hset($this->getRedisKey(), 'state', self::STATE_CLOSED);
        $this->redis->hset($this->getRedisKey(), 'failure_count', 0);
        $this->redis->hset($this->getRedisKey(), 'last_failure_time', null);
        $this->redis->exec();

        $this->metrics = [
            'failures' => 0,
            'successes' => 0,
            'last_failure_time' => null,
            'last_success_time' => null,
            'state_changes' => [],
        ];

        $this->logger->info('Circuit breaker manually reset', [
            'vendor' => $this->vendorName,
            'channel' => $this->channel,
            'tenant_id' => $this->tenantId
        ]);
    }

    /**
     * Initializes circuit breaker state in Redis if not exists.
     *
     * @return void
     */
    private function initializeState(): void
    {
        $this->redis->hsetnx($this->getRedisKey(), 'state', self::STATE_CLOSED);
        $this->redis->hsetnx($this->getRedisKey(), 'failure_count', 0);
        $this->redis->hsetnx($this->getRedisKey(), 'last_failure_time', null);
        $this->redis->hsetnx($this->getRedisKey(), 'last_success_time', null);
    }

    /**
     * Gets current state from Redis with atomic operation.
     *
     * @return array Current state data
     */
    private function getStateFromRedis(): array
    {
        $state = $this->redis->hgetall($this->getRedisKey());
        
        return [
            'state' => $state['state'] ?? self::STATE_CLOSED,
            'failure_count' => (int)($state['failure_count'] ?? 0),
            'last_failure_time' => $state['last_failure_time'] ? (int)$state['last_failure_time'] : null,
            'last_success_time' => $state['last_success_time'] ? (int)$state['last_success_time'] : null,
        ];
    }

    /**
     * Transitions circuit to half-open state.
     *
     * @return void
     */
    private function transitionToHalfOpen(): void
    {
        $this->redis->hset($this->getRedisKey(), 'state', self::STATE_HALF_OPEN);
        $this->logStateChange(self::STATE_OPEN, self::STATE_HALF_OPEN);
    }

    /**
     * Checks if a test request can be attempted in half-open state.
     *
     * @return bool True if test request is allowed
     */
    private function canAttemptHalfOpenRequest(): bool
    {
        $state = $this->getStateFromRedis();
        return (time() - $state['last_failure_time']) >= self::HALF_OPEN_TIMEOUT_SECONDS;
    }

    /**
     * Calculates reset timeout with exponential backoff.
     *
     * @param int $failureCount Current failure count
     * @return int Timeout in seconds
     */
    private function calculateResetTimeout(int $failureCount): int
    {
        return self::RESET_TIMEOUT_SECONDS * pow(self::EXPONENTIAL_BACKOFF_BASE, min($failureCount - self::FAILURE_THRESHOLD, 3));
    }

    /**
     * Generates Redis key with tenant and vendor context.
     *
     * @return string Redis key
     */
    private function getRedisKey(): string
    {
        return str_replace(
            ['{tenant}', '{channel}', '{vendor}'],
            [$this->tenantId, $this->channel, $this->vendorName],
            self::REDIS_KEY_PREFIX
        );
    }

    /**
     * Logs state change with enhanced context.
     *
     * @param string $fromState Previous state
     * @param string $toState New state
     * @return void
     */
    private function logStateChange(string $fromState, string $toState): void
    {
        $context = [
            'vendor' => $this->vendorName,
            'channel' => $this->channel,
            'tenant_id' => $this->tenantId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'failure_count' => $this->getStateFromRedis()['failure_count'],
            'timestamp' => time()
        ];

        $this->logger->info(
            sprintf('Circuit breaker state changed from %s to %s', $fromState, $toState),
            $context
        );

        $this->metrics['state_changes'][] = $context;
    }

    /**
     * Sanitizes vendor name for Redis key safety.
     *
     * @param string $vendorName Raw vendor name
     * @return string Sanitized vendor name
     */
    private function sanitizeVendorName(string $vendorName): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $vendorName);
    }

    /**
     * Validates notification channel.
     *
     * @param string $channel Channel identifier
     * @return string Validated channel
     * @throws \InvalidArgumentException If channel is invalid
     */
    private function validateChannel(string $channel): string
    {
        $validChannels = ['email', 'sms', 'push'];
        $channel = strtolower(trim($channel));
        
        if (!in_array($channel, $validChannels, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid channel "%s". Must be one of: %s', 
                    $channel, 
                    implode(', ', $validChannels)
                )
            );
        }
        
        return $channel;
    }

    /**
     * Sanitizes tenant ID for Redis key safety.
     *
     * @param string $tenantId Raw tenant ID
     * @return string Sanitized tenant ID
     */
    private function sanitizeTenantId(string $tenantId): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantId);
    }
}