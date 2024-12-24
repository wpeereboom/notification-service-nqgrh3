<?php

declare(strict_types=1);

namespace NotificationService\Tests\Unit\Services\Cache;

use NotificationService\Services\Cache\RedisCacheService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Predis\Client;
use Predis\Connection\ConnectionException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Comprehensive test suite for RedisCacheService
 * 
 * @covers \NotificationService\Services\Cache\RedisCacheService
 * @group cache
 * @group unit
 */
class RedisCacheServiceTest extends TestCase
{
    private RedisCacheService $cacheService;
    private LoggerInterface|MockObject $logger;
    private Client|MockObject $redisClient;
    private array $config;

    protected function setUp(): void
    {
        // Mock logger
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Mock Redis client
        $this->redisClient = $this->createMock(Client::class);
        
        // Setup test configuration
        $this->config = [
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'connection' => 'cache',
                    'cluster' => true,
                    'prefix' => 'test_prefix',
                    'ttl' => 3600,
                    'endpoints' => [
                        'primary' => [
                            'host' => 'localhost',
                            'port' => 6379,
                            'password' => null,
                            'database' => 0,
                            'timeout' => 2.0,
                            'read_timeout' => 2.0,
                        ]
                    ],
                    'serialize' => [
                        'enable' => true,
                        'method' => 'php'
                    ],
                    'monitoring' => [
                        'enable_metrics' => true,
                        'slow_query_threshold' => 100
                    ]
                ]
            ]
        ];

        $this->cacheService = new RedisCacheService($this->config, $this->logger);
    }

    protected function tearDown(): void
    {
        unset($this->cacheService);
        unset($this->logger);
        unset($this->redisClient);
    }

    /**
     * @test
     * @group connection
     */
    public function testConnectSuccessful(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Successfully connected to Redis cluster');

        $result = $this->cacheService->connect();
        
        $this->assertTrue($result);
    }

    /**
     * @test
     * @group connection
     */
    public function testConnectWithRetry(): void
    {
        $exception = new ConnectionException($this->redisClient->getConnection());
        
        $this->logger->expects($this->exactly(2))
            ->method('warning')
            ->with($this->stringContains('Redis connection attempt'));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Successfully connected to Redis cluster');

        $result = $this->cacheService->connect();
        
        $this->assertTrue($result);
    }

    /**
     * @test
     * @group cache-operations
     */
    public function testSetAndGetValue(): void
    {
        $key = 'test_key';
        $value = ['data' => 'test_value'];
        $ttl = 3600;

        // Test set operation
        $setResult = $this->cacheService->set($key, $value, $ttl);
        $this->assertTrue($setResult);

        // Test get operation
        $retrievedValue = $this->cacheService->get($key);
        $this->assertEquals($value, $retrievedValue);
    }

    /**
     * @test
     * @group cache-operations
     */
    public function testGetNonExistentKey(): void
    {
        $result = $this->cacheService->get('non_existent_key');
        $this->assertNull($result);
    }

    /**
     * @test
     * @group cache-operations
     */
    public function testSetWithInvalidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must be a non-empty string');
        
        $this->cacheService->set('', 'test_value');
    }

    /**
     * @test
     * @group bulk-operations
     */
    public function testGetMultiple(): void
    {
        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ];

        // Set multiple values first
        $this->cacheService->setMultiple($items);

        // Test getMultiple
        $result = $this->cacheService->getMultiple(array_keys($items));
        
        $this->assertEquals($items, $result);
        $this->assertCount(3, $result);
    }

    /**
     * @test
     * @group bulk-operations
     */
    public function testSetMultipleWithCustomTtl(): void
    {
        $items = [
            'key1' => 'value1',
            'key2' => 'value2'
        ];
        $ttl = 1800; // 30 minutes

        $result = $this->cacheService->setMultiple($items, $ttl);
        
        $this->assertTrue($result);

        // Verify values were stored
        foreach ($items as $key => $value) {
            $this->assertEquals($value, $this->cacheService->get($key));
        }
    }

    /**
     * @test
     * @group error-handling
     */
    public function testConnectionFailureLogging(): void
    {
        $this->logger->expects($this->exactly(3))
            ->method('warning')
            ->with($this->stringContains('Redis connection attempt'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to connect to Redis after maximum retry attempts');

        // Force connection failure
        $this->redisClient->method('connect')
            ->willThrowException(new ConnectionException($this->redisClient->getConnection()));

        $result = $this->cacheService->connect();
        $this->assertFalse($result);
    }

    /**
     * @test
     * @group metrics
     */
    public function testOperationMetricsLogging(): void
    {
        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Cache operation metrics',
                $this->callback(function ($params) {
                    return isset($params['operation']) &&
                           isset($params['key']) &&
                           isset($params['duration_ms']) &&
                           isset($params['slow_query']);
                })
            );

        $this->cacheService->set('test_key', 'test_value');
    }

    /**
     * @test
     * @group serialization
     */
    public function testSerializationOfComplexData(): void
    {
        $key = 'complex_data';
        $value = [
            'array' => [1, 2, 3],
            'object' => new \stdClass(),
            'string' => 'test',
            'number' => 42
        ];

        $setResult = $this->cacheService->set($key, $value);
        $this->assertTrue($setResult);

        $retrievedValue = $this->cacheService->get($key);
        $this->assertEquals($value, $retrievedValue);
    }
}