<?php

declare(strict_types=1);

namespace NotificationService\Utils;

use NotificationService\Services\Cache\RedisCacheService;
use NotificationService\Exceptions\NotificationException;
use Psr\Log\LoggerInterface;

/**
 * Distributed rate limiter implementation using Redis for high-throughput notification processing.
 * Supports per-client limits, burst allowance, and atomic operations with monitoring.
 *
 * @version 1.0.0
 * @package NotificationService\Utils
 */
class RateLimiter
{
    // Time windows in seconds
    private const WINDOW_MINUTE = 60;
    private const WINDOW_HOUR = 3600;

    // Rate limit types
    private const TYPE_NOTIFICATION = 'notification';
    private const TYPE_TEMPLATE = 'template';
    private const TYPE_STATUS = 'status';

    // Configuration constants
    private const BURST_MULTIPLIER = 1.5;
    private const LOCK_TIMEOUT = 1;

    private RedisCacheService $cache;
    private LoggerInterface $logger;
    private array $config;
    private array $limits;
    private array $burstAllowance;

    /**
     * Initialize rate limiter with Redis cache and configuration
     *
     * @param RedisCacheService $cache Redis cache service instance
     * @param LoggerInterface $logger PSR-3 logger interface
     * @param array $config Rate limiter configuration
     * @throws \InvalidArgumentException If configuration is invalid
     */
    public function __construct(
        RedisCacheService $cache,
        LoggerInterface $logger,
        array $config
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->validateConfig($config);
        
        // Set up rate limits
        $this->limits = [
            self::TYPE_NOTIFICATION => 1000,  // 1000 per minute
            self::TYPE_STATUS => 2000,        // 2000 per minute
            self::TYPE_TEMPLATE => 100        // 100 per minute
        ];

        // Calculate burst allowances
        $this->burstAllowance = array_map(
            fn($limit) => (int)($limit * self::BURST_MULTIPLIER),
            $this->limits
        );

        $this->initializeMetrics();
    }

    /**
     * Check if operation is within rate limits
     *
     * @param string $key Client identifier (e.g., API key, IP)
     * @param string $type Operation type
     * @return bool True if operation is allowed
     * @throws NotificationException If rate limit exceeded
     */
    public function checkLimit(string $key, string $type): bool
    {
        $this->validateType($type);
        $redisKey = $this->generateKey($key, $type);
        
        try {
            // Acquire distributed lock
            $lockKey = "lock:{$redisKey}";
            if (!$this->cache->set($lockKey, '1', self::LOCK_TIMEOUT)) {
                throw new \RuntimeException('Failed to acquire rate limit lock');
            }

            // Get current counter
            $counter = (int)$this->cache->get($redisKey) ?? 0;
            $limit = $this->limits[$type];
            $window = $this->getWindow($type);

            // Check if within limits including burst allowance
            if ($counter >= $this->burstAllowance[$type]) {
                $this->logRateLimit($key, $type, $counter);
                throw new NotificationException(
                    "Rate limit exceeded for {$type}",
                    NotificationException::RATE_LIMITED,
                    [
                        'key' => $key,
                        'type' => $type,
                        'counter' => $counter,
                        'limit' => $limit,
                        'window' => $window
                    ]
                );
            }

            // Increment counter atomically
            $newCount = $this->increment($redisKey, $window);
            $this->recordMetrics($type, $newCount);

            return true;
        } finally {
            // Release lock
            $this->cache->delete($lockKey);
        }
    }

    /**
     * Get remaining limit for client
     *
     * @param string $key Client identifier
     * @param string $type Operation type
     * @return int Number of remaining requests allowed
     */
    public function getRemainingLimit(string $key, string $type): int
    {
        $this->validateType($type);
        $redisKey = $this->generateKey($key, $type);
        
        $counter = (int)$this->cache->get($redisKey) ?? 0;
        $remaining = max(0, $this->limits[$type] - $counter);

        $this->logger->debug('Rate limit check', [
            'key' => $key,
            'type' => $type,
            'counter' => $counter,
            'remaining' => $remaining
        ]);

        return $remaining;
    }

    /**
     * Increment rate limit counter atomically
     *
     * @param string $key Redis key
     * @param int $window Time window in seconds
     * @return int New counter value
     */
    private function increment(string $key, int $window): int
    {
        $value = $this->cache->increment($key, 1);
        $this->cache->expire($key, $window);
        return $value;
    }

    /**
     * Generate Redis key with namespace
     *
     * @param string $key Client identifier
     * @param string $type Operation type
     * @return string Namespaced Redis key
     */
    private function generateKey(string $key, string $type): string
    {
        return "rate_limit:{$type}:{$key}:" . floor(time() / $this->getWindow($type));
    }

    /**
     * Get time window for rate limit type
     *
     * @param string $type Operation type
     * @return int Window duration in seconds
     */
    private function getWindow(string $type): int
    {
        return match($type) {
            self::TYPE_TEMPLATE => self::WINDOW_HOUR,
            default => self::WINDOW_MINUTE
        };
    }

    /**
     * Validate rate limit type
     *
     * @param string $type Operation type to validate
     * @throws \InvalidArgumentException If type is invalid
     */
    private function validateType(string $type): void
    {
        if (!isset($this->limits[$type])) {
            throw new \InvalidArgumentException("Invalid rate limit type: {$type}");
        }
    }

    /**
     * Validate configuration
     *
     * @param array $config Configuration array
     * @throws \InvalidArgumentException If config is invalid
     */
    private function validateConfig(array $config): void
    {
        $required = ['enabled', 'monitoring'];
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new \InvalidArgumentException("Missing required config: {$key}");
            }
        }
    }

    /**
     * Initialize monitoring metrics
     */
    private function initializeMetrics(): void
    {
        foreach ($this->limits as $type => $limit) {
            $this->logger->info("Rate limit initialized", [
                'type' => $type,
                'limit' => $limit,
                'burst_allowance' => $this->burstAllowance[$type],
                'window' => $this->getWindow($type)
            ]);
        }
    }

    /**
     * Record rate limiting metrics
     *
     * @param string $type Operation type
     * @param int $counter Current counter value
     */
    private function recordMetrics(string $type, int $counter): void
    {
        $this->logger->debug('Rate limit metrics', [
            'type' => $type,
            'counter' => $counter,
            'limit' => $this->limits[$type],
            'utilization' => ($counter / $this->limits[$type]) * 100
        ]);
    }

    /**
     * Log rate limit violation
     *
     * @param string $key Client identifier
     * @param string $type Operation type
     * @param int $counter Current counter value
     */
    private function logRateLimit(string $key, string $type, int $counter): void
    {
        $this->logger->warning('Rate limit exceeded', [
            'key' => $key,
            'type' => $type,
            'counter' => $counter,
            'limit' => $this->limits[$type],
            'burst_allowance' => $this->burstAllowance[$type]
        ]);
    }
}