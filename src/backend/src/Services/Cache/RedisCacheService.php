<?php

declare(strict_types=1);

namespace NotificationService\Services\Cache;

use Predis\Client;
use Predis\Connection\ConnectionException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * High-performance Redis cache service implementation using AWS ElastiCache
 * with support for clustering, failover, and LRU eviction.
 *
 * @version 1.0.0
 * @package NotificationService\Services\Cache
 */
class RedisCacheService
{
    private Client $client;
    private LoggerInterface $logger;
    private array $config;
    private bool $connected = false;
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_BASE_DELAY_MS = 100;

    /**
     * Initialize Redis cache service with configuration and logging
     *
     * @param array $config Redis configuration from cache.php
     * @param LoggerInterface $logger PSR-3 logger for operational monitoring
     */
    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->validateConfig($config);
        $this->config = $config['stores']['redis'];
        $this->logger = $logger;

        $this->initializeClient();
    }

    /**
     * Validates the required configuration parameters
     *
     * @param array $config Configuration array to validate
     * @throws \InvalidArgumentException If required config is missing
     */
    private function validateConfig(array $config): void
    {
        if (!isset($config['stores']['redis'])) {
            throw new \InvalidArgumentException('Redis configuration is missing');
        }

        $required = ['driver', 'connection', 'cluster', 'prefix', 'ttl', 'endpoints'];
        foreach ($required as $key) {
            if (!isset($config['stores']['redis'][$key])) {
                throw new \InvalidArgumentException("Required Redis config key missing: {$key}");
            }
        }
    }

    /**
     * Initializes the Redis client with cluster configuration
     */
    private function initializeClient(): void
    {
        $endpoints = $this->config['endpoints'];
        $options = [
            'cluster' => $this->config['cluster'],
            'prefix' => $this->config['prefix'],
            'parameters' => [
                'password' => $endpoints['primary']['password'],
                'database' => $endpoints['primary']['database'],
                'timeout' => $endpoints['primary']['timeout'],
                'read_timeout' => $endpoints['primary']['read_timeout'],
            ],
            'connections' => [
                'tcp_keepalive' => true,
                'tcp_keepidle' => 60,
                'tcp_keepintvl' => 30,
                'tcp_keepcnt' => 3,
            ],
        ];

        // Configure connection pooling
        if (isset($this->config['options']['parameters'])) {
            $options['parameters'] = array_merge(
                $options['parameters'],
                $this->config['options']['parameters']
            );
        }

        $this->client = new Client([
            'scheme' => 'tcp',
            'host' => $endpoints['primary']['host'],
            'port' => $endpoints['primary']['port'],
        ], $options);
    }

    /**
     * Establishes connection to Redis cluster with retry logic
     *
     * @return bool Connection success status
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                $this->client->connect();
                $this->connected = true;
                $this->logger->info('Successfully connected to Redis cluster');
                return true;
            } catch (ConnectionException $e) {
                $delay = $this->calculateRetryDelay($attempt);
                $this->logger->warning(
                    "Redis connection attempt {$attempt} failed: {$e->getMessage()}. Retrying in {$delay}ms"
                );
                usleep($delay * 1000);
            }
        }

        $this->logger->error('Failed to connect to Redis after maximum retry attempts');
        return false;
    }

    /**
     * Calculates exponential backoff delay for retries
     *
     * @param int $attempt Current attempt number
     * @return int Delay in milliseconds
     */
    private function calculateRetryDelay(int $attempt): int
    {
        return self::RETRY_BASE_DELAY_MS * (2 ** ($attempt - 1));
    }

    /**
     * Retrieves cached value with monitoring
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     * @throws InvalidArgumentException If key is invalid
     */
    public function get(string $key)
    {
        $this->validateKey($key);
        $startTime = microtime(true);

        try {
            if (!$this->ensureConnection()) {
                return null;
            }

            $value = $this->client->get($key);
            $this->recordMetrics('get', $key, $startTime);

            return $value !== null ? $this->unserialize($value) : null;
        } catch (\Exception $e) {
            $this->handleError('get', $key, $e);
            return null;
        }
    }

    /**
     * Stores value in cache with TTL and compression
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds
     * @return bool Storage success status
     * @throws InvalidArgumentException If key is invalid
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $this->validateKey($key);
        $startTime = microtime(true);

        try {
            if (!$this->ensureConnection()) {
                return false;
            }

            $ttl = $ttl ?? $this->config['ttl'];
            $serialized = $this->serialize($value);

            $success = $this->client->setex($key, $ttl, $serialized);
            $this->recordMetrics('set', $key, $startTime);

            return (bool)$success;
        } catch (\Exception $e) {
            $this->handleError('set', $key, $e);
            return false;
        }
    }

    /**
     * Bulk retrieval of cached values
     *
     * @param array $keys Array of cache keys
     * @return array Array of key-value pairs
     * @throws InvalidArgumentException If any key is invalid
     */
    public function getMultiple(array $keys): array
    {
        array_map([$this, 'validateKey'], $keys);
        $startTime = microtime(true);

        try {
            if (!$this->ensureConnection()) {
                return [];
            }

            $pipeline = $this->client->pipeline();
            foreach ($keys as $key) {
                $pipeline->get($key);
            }
            
            $values = $pipeline->execute();
            $result = array_combine($keys, array_map([$this, 'unserialize'], $values));
            
            $this->recordMetrics('getMultiple', implode(',', $keys), $startTime);
            
            return $result;
        } catch (\Exception $e) {
            $this->handleError('getMultiple', implode(',', $keys), $e);
            return [];
        }
    }

    /**
     * Bulk storage of values with TTL
     *
     * @param array $values Key-value pairs to store
     * @param int|null $ttl Time to live in seconds
     * @return bool Bulk storage success status
     * @throws InvalidArgumentException If any key is invalid
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        array_map([$this, 'validateKey'], array_keys($values));
        $startTime = microtime(true);

        try {
            if (!$this->ensureConnection()) {
                return false;
            }

            $ttl = $ttl ?? $this->config['ttl'];
            $pipeline = $this->client->pipeline();
            
            foreach ($values as $key => $value) {
                $serialized = $this->serialize($value);
                $pipeline->setex($key, $ttl, $serialized);
            }

            $results = $pipeline->execute();
            $success = !in_array(false, $results, true);
            
            $this->recordMetrics('setMultiple', implode(',', array_keys($values)), $startTime);
            
            return $success;
        } catch (\Exception $e) {
            $this->handleError('setMultiple', implode(',', array_keys($values)), $e);
            return false;
        }
    }

    /**
     * Ensures valid connection state
     *
     * @return bool Connection status
     */
    private function ensureConnection(): bool
    {
        return $this->connected || $this->connect();
    }

    /**
     * Validates cache key format
     *
     * @param string $key Cache key to validate
     * @throws InvalidArgumentException If key is invalid
     */
    private function validateKey(string $key): void
    {
        if (empty($key) || !is_string($key)) {
            throw new \InvalidArgumentException('Cache key must be a non-empty string');
        }
    }

    /**
     * Serializes cache value
     *
     * @param mixed $value Value to serialize
     * @return string Serialized value
     */
    private function serialize($value): string
    {
        if ($this->config['serialize']['enable'] ?? false) {
            return $this->config['serialize']['method'] === 'igbinary'
                ? igbinary_serialize($value)
                : serialize($value);
        }
        return (string)$value;
    }

    /**
     * Unserializes cached value
     *
     * @param string|null $value Serialized value
     * @return mixed Unserialized value
     */
    private function unserialize(?string $value)
    {
        if ($value === null) {
            return null;
        }

        if ($this->config['serialize']['enable'] ?? false) {
            return $this->config['serialize']['method'] === 'igbinary'
                ? igbinary_unserialize($value)
                : unserialize($value);
        }
        return $value;
    }

    /**
     * Records cache operation metrics
     *
     * @param string $operation Operation name
     * @param string $key Cache key(s)
     * @param float $startTime Operation start time
     */
    private function recordMetrics(string $operation, string $key, float $startTime): void
    {
        if (!($this->config['monitoring']['enable_metrics'] ?? false)) {
            return;
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $this->logger->debug('Cache operation metrics', [
            'operation' => $operation,
            'key' => $key,
            'duration_ms' => $duration,
            'slow_query' => $duration > ($this->config['monitoring']['slow_query_threshold'] ?? 100),
        ]);
    }

    /**
     * Handles and logs cache operation errors
     *
     * @param string $operation Operation name
     * @param string $key Cache key(s)
     * @param \Exception $exception Caught exception
     */
    private function handleError(string $operation, string $key, \Exception $exception): void
    {
        $this->logger->error('Cache operation failed', [
            'operation' => $operation,
            'key' => $key,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}