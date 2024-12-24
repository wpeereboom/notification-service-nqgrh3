<?php

declare(strict_types=1);

namespace NotificationService\Test\Integration\Cache;

use NotificationService\Services\Cache\RedisCacheService;
use NotificationService\Test\Utils\CacheHelper;
use NotificationService\Test\Utils\TestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Integration test suite for Redis cache service implementation
 * Verifies caching functionality, performance, and reliability using AWS ElastiCache
 *
 * @package NotificationService\Test\Integration\Cache
 * @version 1.0.0
 */
class RedisIntegrationTest extends TestCase
{
    private RedisCacheService $cacheService;
    private CacheHelper $cacheHelper;
    private LoggerInterface $logger;
    private array $config;

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize logger for test monitoring
        $this->logger = new Logger('redis_integration_test');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

        // Load Redis configuration
        $this->config = require __DIR__ . '/../../../../backend/config/cache.php';

        // Initialize cache service
        $this->cacheService = new RedisCacheService(
            $this->config,
            $this->logger
        );

        // Initialize cache helper with test configuration
        $this->cacheHelper = new CacheHelper(
            $this->cacheService,
            $this->logger,
            [
                'performance_thresholds' => [
                    'get' => 50,  // 50ms threshold for get operations
                    'set' => 100, // 100ms threshold for set operations
                    'bulk' => 200  // 200ms threshold for bulk operations
                ]
            ]
        );

        // Clean test environment
        $this->cacheHelper->cleanupTestCache();
    }

    /**
     * Clean up test environment after each test
     */
    protected function tearDown(): void
    {
        $this->cacheHelper->cleanupTestCache();
        parent::tearDown();
    }

    /**
     * Test Redis cache connection with retry logic and cluster awareness
     */
    public function testCacheConnection(): void
    {
        // Test connection establishment
        $connected = $this->cacheService->connect();
        $this->assertTrue($connected, 'Failed to establish Redis connection');

        // Verify cluster configuration
        $clusterInfo = $this->cacheService->getClusterInfo();
        $this->assertNotEmpty($clusterInfo, 'Failed to retrieve cluster information');
        $this->assertArrayHasKey('cluster_enabled', $clusterInfo);
        $this->assertTrue($clusterInfo['cluster_enabled']);
    }

    /**
     * Test setting and retrieving single cache value with performance metrics
     */
    public function testSetAndGetSingleValue(): void
    {
        $key = $this->cacheHelper->generateTestCacheKey();
        $value = ['test_data' => 'value', 'timestamp' => time()];
        
        // Test set operation with timing
        $startTime = microtime(true);
        $setResult = $this->cacheService->set($key, $value);
        $setDuration = (microtime(true) - $startTime) * 1000;
        
        $this->assertTrue($setResult, 'Failed to set cache value');
        $this->assertLessThan(
            100, // 100ms threshold
            $setDuration,
            'Cache set operation exceeded performance threshold'
        );

        // Test get operation with timing
        $startTime = microtime(true);
        $retrievedValue = $this->cacheService->get($key);
        $getDuration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals($value, $retrievedValue, 'Retrieved value does not match set value');
        $this->assertLessThan(
            50, // 50ms threshold
            $getDuration,
            'Cache get operation exceeded performance threshold'
        );
    }

    /**
     * Test bulk set and get operations with concurrent access
     */
    public function testSetAndGetMultipleValues(): void
    {
        $testData = [];
        $keyCount = 1000; // Test with 1000 keys

        // Generate test data
        for ($i = 0; $i < $keyCount; $i++) {
            $key = $this->cacheHelper->generateTestCacheKey();
            $testData[$key] = [
                'id' => $i,
                'data' => "test_value_{$i}",
                'timestamp' => time()
            ];
        }

        // Test bulk set operation
        $startTime = microtime(true);
        $setResult = $this->cacheService->setMultiple($testData);
        $setBulkDuration = (microtime(true) - $startTime) * 1000;

        $this->assertTrue($setResult, 'Bulk set operation failed');
        $this->assertLessThan(
            200, // 200ms threshold for bulk operations
            $setBulkDuration,
            'Bulk set operation exceeded performance threshold'
        );

        // Test bulk get operation
        $startTime = microtime(true);
        $retrievedValues = $this->cacheService->getMultiple(array_keys($testData));
        $getBulkDuration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals(
            $testData,
            $retrievedValues,
            'Retrieved bulk values do not match set values'
        );
        $this->assertLessThan(
            200, // 200ms threshold for bulk operations
            $getBulkDuration,
            'Bulk get operation exceeded performance threshold'
        );
    }

    /**
     * Test cache TTL functionality with 1-hour expiration
     */
    public function testCacheExpiry(): void
    {
        $key = $this->cacheHelper->generateTestCacheKey();
        $value = 'test_value';
        $ttl = 3600; // 1 hour

        // Set value with TTL
        $this->assertTrue(
            $this->cacheService->set($key, $value, $ttl),
            'Failed to set cache value with TTL'
        );

        // Verify value exists
        $this->assertEquals(
            $value,
            $this->cacheService->get($key),
            'Failed to retrieve cached value'
        );

        // Verify TTL is set correctly
        $ttlRemaining = $this->cacheService->getTtl($key);
        $this->assertGreaterThan(0, $ttlRemaining, 'TTL not set correctly');
        $this->assertLessThanOrEqual($ttl, $ttlRemaining, 'TTL exceeds set value');
    }

    /**
     * Test LRU cache eviction with 512MB size limit
     */
    public function testCacheEviction(): void
    {
        // Get initial memory stats
        $initialStats = $this->cacheService->getMemoryStats();
        
        // Fill cache to near capacity
        $largeData = $this->cacheHelper->generateLargeDataSet(450); // Fill ~450MB
        
        foreach ($largeData as $key => $value) {
            $this->cacheService->set($key, $value);
        }

        // Verify memory usage
        $currentStats = $this->cacheService->getMemoryStats();
        $this->assertLessThan(
            512 * 1024 * 1024, // 512MB limit
            $currentStats['used_memory'],
            'Cache exceeded memory limit'
        );

        // Verify LRU eviction
        $evictionCount = $currentStats['evicted_keys'] - $initialStats['evicted_keys'];
        $this->assertGreaterThan(0, $evictionCount, 'LRU eviction not working');
    }

    /**
     * Test cache key deletion with verification
     */
    public function testDeleteCacheKey(): void
    {
        $key = $this->cacheHelper->generateTestCacheKey();
        $value = 'test_value';

        // Set and verify value
        $this->assertTrue($this->cacheService->set($key, $value));
        $this->assertEquals($value, $this->cacheService->get($key));

        // Delete and verify removal
        $this->assertTrue($this->cacheService->delete($key));
        $this->assertNull($this->cacheService->get($key));
    }

    /**
     * Test complete cache flush with cluster awareness
     */
    public function testFlushCache(): void
    {
        // Seed test data
        $testData = $this->cacheHelper->seedTestData(100);
        
        // Verify data exists
        foreach ($testData as $key => $value) {
            $this->assertEquals(
                $value,
                $this->cacheService->get($key),
                'Test data not seeded correctly'
            );
        }

        // Flush cache
        $this->assertTrue($this->cacheService->flush());

        // Verify all data is removed
        foreach ($testData as $key => $value) {
            $this->assertNull(
                $this->cacheService->get($key),
                'Cache flush did not remove all data'
            );
        }
    }
}