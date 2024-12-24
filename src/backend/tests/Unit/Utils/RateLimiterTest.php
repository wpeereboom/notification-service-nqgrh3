<?php

declare(strict_types=1);

namespace NotificationService\Tests\Unit\Utils;

use NotificationService\Utils\RateLimiter;
use NotificationService\Services\Cache\RedisCacheService;
use NotificationService\Exceptions\NotificationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Comprehensive test suite for RateLimiter utility class.
 * Verifies rate limiting functionality for high-throughput notification processing.
 *
 * @package NotificationService\Tests\Unit\Utils
 * @version 1.0.0
 */
class RateLimiterTest extends TestCase
{
    private const TEST_CLIENT_ID = 'test_client_123';
    private const TEST_TYPE_NOTIFICATION = 'notification';
    private const TEST_TYPE_TEMPLATE = 'template';
    private const TEST_LIMIT_NOTIFICATION = 1000;
    private const TEST_LIMIT_TEMPLATE = 100;

    private RateLimiter $rateLimiter;
    private RedisCacheService|MockObject $cacheService;
    private LoggerInterface|MockObject $logger;

    /**
     * Set up test environment before each test case.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock logger
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create mock cache service
        $this->cacheService = $this->createMock(RedisCacheService::class);

        // Create RateLimiter instance with test configuration
        $this->rateLimiter = new RateLimiter(
            $this->cacheService,
            $this->logger,
            [
                'enabled' => true,
                'monitoring' => true
            ]
        );
    }

    /**
     * Test rate limiting when requests are within allowed limits.
     *
     * @return void
     */
    public function testCheckLimitWithinLimits(): void
    {
        // Set up test data
        $key = self::TEST_CLIENT_ID;
        $type = self::TEST_TYPE_NOTIFICATION;
        $redisKey = "rate_limit:{$type}:{$key}:" . floor(time() / 60);

        // Configure mock cache behavior
        $this->cacheService->expects($this->once())
            ->method('set')
            ->with($this->stringContains('lock:'), '1', 1)
            ->willReturn(true);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($redisKey)
            ->willReturn('5'); // Simulate 5 previous requests

        $this->cacheService->expects($this->once())
            ->method('increment')
            ->with($redisKey, 1)
            ->willReturn(6);

        // Execute test
        $result = $this->rateLimiter->checkLimit($key, $type);

        // Verify results
        $this->assertTrue($result);
        $this->assertEquals(994, $this->rateLimiter->getRemainingLimit($key, $type));
    }

    /**
     * Test rate limiting when requests exceed allowed limits.
     *
     * @return void
     */
    public function testCheckLimitExceedsLimit(): void
    {
        // Set up test data
        $key = self::TEST_CLIENT_ID;
        $type = self::TEST_TYPE_NOTIFICATION;
        $redisKey = "rate_limit:{$type}:{$key}:" . floor(time() / 60);

        // Configure mock cache behavior for exceeded limits
        $this->cacheService->expects($this->once())
            ->method('set')
            ->with($this->stringContains('lock:'), '1', 1)
            ->willReturn(true);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($redisKey)
            ->willReturn((string)(self::TEST_LIMIT_NOTIFICATION * 1.5)); // Exceed burst allowance

        // Expect exception for rate limit exceeded
        $this->expectException(NotificationException::class);
        $this->expectExceptionCode(NotificationException::RATE_LIMITED);

        // Execute test
        $this->rateLimiter->checkLimit($key, $type);
    }

    /**
     * Test accurate calculation of remaining rate limit.
     *
     * @return void
     */
    public function testGetRemainingLimit(): void
    {
        // Set up test data
        $key = self::TEST_CLIENT_ID;
        $type = self::TEST_TYPE_NOTIFICATION;
        $redisKey = "rate_limit:{$type}:{$key}:" . floor(time() / 60);

        // Configure mock cache behavior
        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($redisKey)
            ->willReturn('100'); // Simulate 100 requests used

        // Execute test
        $remaining = $this->rateLimiter->getRemainingLimit($key, $type);

        // Verify results
        $this->assertEquals(900, $remaining);
    }

    /**
     * Test rate limit expiration and reset behavior.
     *
     * @return void
     */
    public function testRateLimitExpiration(): void
    {
        // Set up test data
        $key = self::TEST_CLIENT_ID;
        $type = self::TEST_TYPE_TEMPLATE;
        $redisKey = "rate_limit:{$type}:{$key}:" . floor(time() / 3600); // Hour window for templates

        // Configure mock cache behavior for expired counter
        $this->cacheService->expects($this->once())
            ->method('set')
            ->with($this->stringContains('lock:'), '1', 1)
            ->willReturn(true);

        $this->cacheService->expects($this->once())
            ->method('get')
            ->with($redisKey)
            ->willReturn(null); // Simulate expired counter

        $this->cacheService->expects($this->once())
            ->method('increment')
            ->with($redisKey, 1)
            ->willReturn(1); // First request after expiration

        // Execute test
        $result = $this->rateLimiter->checkLimit($key, $type);

        // Verify results
        $this->assertTrue($result);
        $this->assertEquals(99, $this->rateLimiter->getRemainingLimit($key, $type));
    }

    /**
     * Test rate limiting for different request types.
     *
     * @return void
     */
    public function testDifferentRequestTypes(): void
    {
        // Set up test data
        $key = self::TEST_CLIENT_ID;

        // Configure mock cache behavior for notification type
        $this->cacheService->expects($this->exactly(2))
            ->method('set')
            ->with($this->stringContains('lock:'), '1', 1)
            ->willReturn(true);

        $this->cacheService->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls('500', '50');

        $this->cacheService->expects($this->exactly(2))
            ->method('increment')
            ->willReturnOnConsecutiveCalls(501, 51);

        // Test notification type
        $resultNotification = $this->rateLimiter->checkLimit($key, self::TEST_TYPE_NOTIFICATION);
        $this->assertTrue($resultNotification);
        $this->assertEquals(499, $this->rateLimiter->getRemainingLimit($key, self::TEST_TYPE_NOTIFICATION));

        // Test template type
        $resultTemplate = $this->rateLimiter->checkLimit($key, self::TEST_TYPE_TEMPLATE);
        $this->assertTrue($resultTemplate);
        $this->assertEquals(49, $this->rateLimiter->getRemainingLimit($key, self::TEST_TYPE_TEMPLATE));
    }

    /**
     * Clean up test environment after each test case.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}