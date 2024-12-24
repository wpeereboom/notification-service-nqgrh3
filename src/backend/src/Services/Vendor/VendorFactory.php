<?php

declare(strict_types=1);

namespace App\Services\Vendor;

use App\Contracts\VendorInterface;
use App\Exceptions\VendorException;
use GuzzleHttp\Client;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Factory class for creating and managing vendor service instances with health monitoring and failover support.
 * Implements high-throughput vendor selection, health checks, and circuit breaker patterns.
 *
 * @package App\Services\Vendor
 * @version 1.0.0
 */
class VendorFactory
{
    /**
     * Cache key prefix for vendor health status
     */
    private const HEALTH_CACHE_PREFIX = 'vendor:health:';

    /**
     * Cache key prefix for circuit breaker status
     */
    private const CIRCUIT_BREAKER_PREFIX = 'vendor:circuit:';

    /**
     * Circuit breaker failure threshold
     */
    private const CIRCUIT_BREAKER_THRESHOLD = 5;

    /**
     * Circuit breaker reset timeout in seconds
     */
    private const CIRCUIT_BREAKER_TIMEOUT = 30;

    /**
     * @var array<string, array<string>> Mapping of channels to vendor types
     */
    private array $vendorTypes;

    /**
     * @var array<string, array<string, int>> Vendor priority configuration
     */
    private array $vendorPriorities;

    /**
     * @var array<string, array> Circuit breaker state tracking
     */
    private array $circuitBreakers = [];

    /**
     * Factory constructor with dependency injection.
     *
     * @param LoggerInterface $logger PSR-3 logger instance
     * @param Client $httpClient Configured Guzzle client
     * @param RedisClient $cache Redis client for health status caching
     * @param array $config Vendor configuration array
     */
    public function __construct(
        private LoggerInterface $logger,
        private Client $httpClient,
        private RedisClient $cache,
        private array $config
    ) {
        $this->vendorTypes = json_decode(VENDOR_TYPES, true, 512, JSON_THROW_ON_ERROR);
        $this->vendorPriorities = json_decode(VENDOR_PRIORITIES, true, 512, JSON_THROW_ON_ERROR);
        $this->initializeCircuitBreakers();
    }

    /**
     * Creates a new vendor service instance with configuration validation.
     *
     * @param string $vendorName Vendor identifier
     * @param string $tenantId Tenant identifier for configuration
     * @return VendorInterface Configured vendor service instance
     * @throws VendorException If vendor configuration is invalid
     */
    public function create(string $vendorName, string $tenantId): VendorInterface
    {
        $vendorName = strtolower(trim($vendorName));
        
        // Validate vendor exists in configuration
        if (!$this->isValidVendor($vendorName)) {
            throw new VendorException(
                "Invalid vendor: {$vendorName}",
                VendorException::VENDOR_INVALID_REQUEST,
                null,
                ['vendor_name' => $vendorName, 'channel' => $this->getVendorChannel($vendorName)]
            );
        }

        // Load vendor credentials
        $credentials = $this->loadVendorCredentials($vendorName, $tenantId);

        // Create vendor-specific configuration
        $config = [
            'credentials' => $credentials,
            'http_client' => $this->httpClient,
            'logger' => $this->logger,
            'timeout' => (int)VENDOR_TIMEOUT,
            'tenant_id' => $tenantId
        ];

        // Instantiate vendor class
        $vendorClass = $this->getVendorClass($vendorName);
        return new $vendorClass($config);
    }

    /**
     * Gets highest priority healthy vendor with caching and circuit breaker implementation.
     *
     * @param string $channel Notification channel type
     * @param string $tenantId Tenant identifier
     * @return VendorInterface Healthy vendor service instance
     * @throws VendorException If no healthy vendors are available
     */
    public function getHealthyVendor(string $channel, string $tenantId): VendorInterface
    {
        $channel = strtolower(trim($channel));

        // Validate channel type
        if (!isset($this->vendorTypes[$channel])) {
            throw new VendorException(
                "Invalid channel: {$channel}",
                VendorException::VENDOR_INVALID_REQUEST,
                null,
                ['channel' => $channel]
            );
        }

        // Check cache for known healthy vendor
        $cachedVendor = $this->cache->get(self::HEALTH_CACHE_PREFIX . $channel);
        if ($cachedVendor) {
            try {
                $vendor = $this->create($cachedVendor, $tenantId);
                if ($this->isVendorHealthy($vendor)) {
                    return $vendor;
                }
            } catch (VendorException $e) {
                $this->logger->warning('Cached vendor unavailable', [
                    'vendor' => $cachedVendor,
                    'channel' => $channel,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Get prioritized vendor list for channel
        $vendors = $this->getPrioritizedVendors($channel);
        
        foreach ($vendors as $vendorName) {
            // Skip if circuit breaker is open
            if ($this->isCircuitBreakerOpen($vendorName)) {
                continue;
            }

            try {
                $vendor = $this->create($vendorName, $tenantId);
                
                // Perform health check with timeout
                if ($this->isVendorHealthy($vendor)) {
                    // Update health cache
                    $this->cache->setex(
                        self::HEALTH_CACHE_PREFIX . $channel,
                        (int)HEALTH_CHECK_INTERVAL,
                        $vendorName
                    );
                    return $vendor;
                }
            } catch (VendorException $e) {
                $this->handleVendorFailure($vendorName, $e);
            }
        }

        // No healthy vendors available
        throw new VendorException(
            "No healthy vendors available for channel: {$channel}",
            VendorException::VENDOR_FAILOVER_EXHAUSTED,
            null,
            ['channel' => $channel]
        );
    }

    /**
     * Initializes circuit breakers for all vendors.
     */
    private function initializeCircuitBreakers(): void
    {
        foreach ($this->vendorTypes as $vendors) {
            foreach ($vendors as $vendor) {
                $this->circuitBreakers[$vendor] = [
                    'failures' => 0,
                    'last_failure' => 0,
                    'state' => 'closed'
                ];
            }
        }
    }

    /**
     * Checks if a vendor name is valid in the configuration.
     *
     * @param string $vendorName Vendor identifier
     * @return bool True if vendor is valid
     */
    private function isValidVendor(string $vendorName): bool
    {
        foreach ($this->vendorTypes as $vendors) {
            if (in_array($vendorName, $vendors, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets the channel type for a vendor.
     *
     * @param string $vendorName Vendor identifier
     * @return string Channel type
     */
    private function getVendorChannel(string $vendorName): string
    {
        foreach ($this->vendorTypes as $channel => $vendors) {
            if (in_array($vendorName, $vendors, true)) {
                return $channel;
            }
        }
        return 'unknown';
    }

    /**
     * Loads encrypted vendor credentials from configuration.
     *
     * @param string $vendorName Vendor identifier
     * @param string $tenantId Tenant identifier
     * @return array Decrypted vendor credentials
     * @throws VendorException If credentials are invalid
     */
    private function loadVendorCredentials(string $vendorName, string $tenantId): array
    {
        $credentials = $this->config['vendors'][$vendorName][$tenantId] ?? null;
        
        if (!$credentials) {
            throw new VendorException(
                "Invalid vendor credentials",
                VendorException::VENDOR_AUTH_ERROR,
                null,
                ['vendor_name' => $vendorName, 'tenant_id' => $tenantId]
            );
        }

        return $credentials;
    }

    /**
     * Gets the fully qualified class name for a vendor.
     *
     * @param string $vendorName Vendor identifier
     * @return string Vendor class name
     */
    private function getVendorClass(string $vendorName): string
    {
        return sprintf(
            'App\\Services\\Vendor\\%sVendor',
            str_replace(' ', '', ucwords(str_replace('_', ' ', $vendorName)))
        );
    }

    /**
     * Checks if a vendor is currently healthy.
     *
     * @param VendorInterface $vendor Vendor instance
     * @return bool True if vendor is healthy
     */
    private function isVendorHealthy(VendorInterface $vendor): bool
    {
        try {
            $health = $vendor->checkHealth();
            return $health['isHealthy'] ?? false;
        } catch (VendorException $e) {
            return false;
        }
    }

    /**
     * Gets prioritized list of vendors for a channel.
     *
     * @param string $channel Channel type
     * @return array<string> Ordered vendor list
     */
    private function getPrioritizedVendors(string $channel): array
    {
        $vendors = $this->vendorTypes[$channel];
        $priorities = $this->vendorPriorities[$channel];
        
        usort($vendors, function ($a, $b) use ($priorities) {
            return ($priorities[$a] ?? 999) <=> ($priorities[$b] ?? 999);
        });
        
        return $vendors;
    }

    /**
     * Checks if circuit breaker is open for a vendor.
     *
     * @param string $vendorName Vendor identifier
     * @return bool True if circuit breaker is open
     */
    private function isCircuitBreakerOpen(string $vendorName): bool
    {
        $breaker = $this->circuitBreakers[$vendorName];
        
        if ($breaker['state'] === 'open') {
            // Check if timeout has elapsed
            if (time() - $breaker['last_failure'] > self::CIRCUIT_BREAKER_TIMEOUT) {
                $this->circuitBreakers[$vendorName]['state'] = 'half-open';
                return false;
            }
            return true;
        }
        
        return false;
    }

    /**
     * Handles vendor failure by updating circuit breaker state.
     *
     * @param string $vendorName Vendor identifier
     * @param VendorException $exception Vendor exception
     */
    private function handleVendorFailure(string $vendorName, VendorException $exception): void
    {
        $breaker = &$this->circuitBreakers[$vendorName];
        $breaker['failures']++;
        $breaker['last_failure'] = time();

        if ($breaker['failures'] >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $breaker['state'] = 'open';
            $this->logger->error('Circuit breaker opened for vendor', [
                'vendor' => $vendorName,
                'failures' => $breaker['failures'],
                'error' => $exception->getMessage()
            ]);
        }
    }
}