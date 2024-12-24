<?php

declare(strict_types=1);

namespace NotificationService\Test\Utils;

use NotificationService\Services\Cache\RedisCacheService;
use Faker\Factory as FakerFactory;
use Faker\Generator as Faker;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

/**
 * Helper utility class providing comprehensive test support functions for Redis cache testing
 * Supports initialization, data seeding, verification, cleanup operations, and performance validation
 *
 * @version 1.0.0
 * @package NotificationService\Test\Utils
 */
class CacheHelper
{
    private RedisCacheService $cacheService;
    private LoggerInterface $logger;
    private Faker $faker;
    private array $testData = [];
    private array $performanceMetrics = [];
    private array $config;

    // Performance thresholds in milliseconds
    private const DEFAULT_PERFORMANCE_THRESHOLDS = [
        'get' => 50,
        'set' => 100,
        'bulk' => 200
    ];

    /**
     * Initialize the cache helper with required services and configuration
     *
     * @param RedisCacheService $cacheService Redis cache service instance
     * @param LoggerInterface $logger PSR-3 logger for test operations
     * @param array $config Configuration options for test operations
     * @throws InvalidArgumentException If invalid configuration provided
     */
    public function __construct(
        RedisCacheService $cacheService,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->cacheService = $cacheService;
        $this->logger = $logger;
        $this->faker = FakerFactory::create();
        $this->config = array_merge([
            'performance_thresholds' => self::DEFAULT_PERFORMANCE_THRESHOLDS,
            'locale' => 'en_US',
            'test_prefix' => 'test_cache_'
        ], $config);

        $this->validateConfig();
    }

    /**
     * Initializes cache for testing with clean state and connection validation
     *
     * @param bool $validateConnection Whether to validate Redis connection
     * @return bool Initialization success status
     */
    public function initializeTestCache(bool $validateConnection = true): bool
    {
        try {
            if ($validateConnection && !$this->cacheService->connect()) {
                $this->logger->error('Failed to establish Redis connection during initialization');
                return false;
            }

            $this->cleanupTestCache(false);
            $this->performanceMetrics = [];

            $this->logger->info('Test cache initialized successfully');
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Cache initialization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Seeds cache with configurable test data and performance tracking
     *
     * @param int $count Number of test entries to generate
     * @param int $ttl Time-to-live in seconds
     * @param string $dataType Type of test data to generate (string|array|object)
     * @return array Generated test data with performance metrics
     * @throws InvalidArgumentException If invalid parameters provided
     */
    public function seedTestData(int $count = 10, int $ttl = 3600, string $dataType = 'string'): array
    {
        if ($count <= 0 || $ttl <= 0) {
            throw new InvalidArgumentException('Count and TTL must be positive integers');
        }

        $startTime = microtime(true);
        $generatedData = [];

        try {
            for ($i = 0; $i < $count; $i++) {
                $key = $this->generateTestCacheKey();
                $value = $this->generateTestValue($dataType);
                
                if (!$this->cacheService->set($key, $value, $ttl)) {
                    throw new \RuntimeException("Failed to set cache key: {$key}");
                }

                $generatedData[$key] = $value;
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->performanceMetrics['seed'] = [
                'count' => $count,
                'duration_ms' => $duration,
                'avg_per_item_ms' => $duration / $count
            ];

            $this->testData = array_merge($this->testData, $generatedData);
            $this->logger->info('Test data seeded successfully', [
                'count' => $count,
                'duration_ms' => $duration
            ]);

            return $generatedData;
        } catch (\Exception $e) {
            $this->logger->error('Failed to seed test data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Generates unique and consistent test cache keys
     *
     * @param string $prefix Optional key prefix
     * @param string $suffix Optional key suffix
     * @return string Formatted cache key
     */
    public function generateTestCacheKey(
        string $prefix = '',
        string $suffix = ''
    ): string {
        $basePrefix = $prefix ?: $this->config['test_prefix'];
        $uniqueId = $this->faker->unique()->uuid;
        $key = "{$basePrefix}{$uniqueId}{$suffix}";

        // Ensure key meets Redis requirements
        if (strlen($key) > 1024) {
            throw new InvalidArgumentException('Generated key exceeds maximum length of 1024 bytes');
        }

        return $key;
    }

    /**
     * Verifies cached test data integrity and performance metrics
     *
     * @param array $expectedData Expected test data for verification
     * @param bool $checkPerformance Whether to validate performance metrics
     * @return array Verification results with detailed metrics
     */
    public function verifyTestData(
        array $expectedData,
        bool $checkPerformance = true
    ): array {
        $startTime = microtime(true);
        $results = [
            'success' => true,
            'matches' => 0,
            'mismatches' => 0,
            'missing' => 0,
            'performance' => []
        ];

        try {
            foreach ($expectedData as $key => $expectedValue) {
                $actualValue = $this->cacheService->get($key);
                
                if ($actualValue === null) {
                    $results['missing']++;
                    $results['success'] = false;
                } elseif ($actualValue === $expectedValue) {
                    $results['matches']++;
                } else {
                    $results['mismatches']++;
                    $results['success'] = false;
                }
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $results['performance'] = [
                'duration_ms' => $duration,
                'items_checked' => count($expectedData),
                'avg_check_ms' => $duration / count($expectedData)
            ];

            if ($checkPerformance) {
                $results['performance']['thresholds_met'] = 
                    $results['performance']['avg_check_ms'] <= 
                    $this->config['performance_thresholds']['get'];
            }

            $this->logger->info('Test data verification completed', $results);
            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Data verification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Performs thorough cleanup of test cache data with verification
     *
     * @param bool $verify Whether to verify cleanup completion
     * @return bool Cleanup success status
     */
    public function cleanupTestCache(bool $verify = true): bool
    {
        try {
            $keys = array_keys($this->testData);
            foreach ($keys as $key) {
                $this->cacheService->set($key, null, 0);
            }

            if ($verify) {
                foreach ($keys as $key) {
                    if ($this->cacheService->get($key) !== null) {
                        $this->logger->warning('Cache cleanup verification failed', [
                            'key' => $key
                        ]);
                        return false;
                    }
                }
            }

            $this->testData = [];
            $this->performanceMetrics = [];
            
            $this->logger->info('Test cache cleanup completed successfully');
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Cache cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Validates helper configuration
     *
     * @throws InvalidArgumentException If configuration is invalid
     */
    private function validateConfig(): void
    {
        if (!isset($this->config['performance_thresholds'])) {
            throw new InvalidArgumentException('Performance thresholds must be configured');
        }

        foreach (['get', 'set', 'bulk'] as $metric) {
            if (!isset($this->config['performance_thresholds'][$metric])) {
                throw new InvalidArgumentException("Performance threshold for {$metric} must be defined");
            }
        }
    }

    /**
     * Generates test values based on specified data type
     *
     * @param string $dataType Type of test data to generate
     * @return mixed Generated test value
     */
    private function generateTestValue(string $dataType): mixed
    {
        return match($dataType) {
            'string' => $this->faker->text(100),
            'array' => [
                'id' => $this->faker->uuid,
                'name' => $this->faker->name,
                'email' => $this->faker->email,
                'created_at' => $this->faker->iso8601
            ],
            'object' => (object)[
                'id' => $this->faker->uuid,
                'type' => $this->faker->word,
                'attributes' => [
                    'title' => $this->faker->sentence,
                    'description' => $this->faker->paragraph
                ]
            ],
            default => throw new InvalidArgumentException("Unsupported test data type: {$dataType}")
        };
    }
}