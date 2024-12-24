<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\VendorInterface;
use App\Exceptions\VendorException;
use App\Services\Vendor\VendorFactory;
use App\Services\Vendor\VendorService;
use App\Utils\CircuitBreaker;
use GuzzleHttp\Client;
use Mockery;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;

/**
 * Feature test suite for vendor service functionality including vendor selection,
 * failover logic, health checks, and circuit breaker patterns.
 *
 * @package Tests\Feature
 * @version 1.0.0
 */
class VendorTest extends TestCase
{
    private const TEST_TENANT_ID = 'test_tenant_123';
    private const TEST_CHANNEL = 'email';

    private VendorService $vendorService;
    private VendorFactory $mockFactory;
    private LoggerInterface $mockLogger;
    private Redis $mockRedis;
    private array $circuitBreakerConfig;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize mocks
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockRedis = Mockery::mock(Redis::class);
        $this->mockFactory = Mockery::mock(VendorFactory::class);

        // Configure circuit breaker settings
        $this->circuitBreakerConfig = [
            'failure_threshold' => 5,
            'reset_timeout' => 30,
            'half_open_timeout' => 15
        ];

        // Create vendor service instance
        $this->vendorService = new VendorService(
            $this->mockFactory,
            $this->mockLogger,
            $this->mockRedis
        );
    }

    /**
     * Test successful message delivery through primary vendor.
     */
    public function testVendorSendSuccess(): void
    {
        // Create test payload
        $payload = [
            'recipient' => 'test@example.com',
            'template_id' => 'welcome_email',
            'context' => ['name' => 'Test User']
        ];

        // Mock Redis state checks
        $this->mockRedis->shouldReceive('get')
            ->with('rate_limit:' . self::TEST_TENANT_ID . ':' . self::TEST_CHANNEL)
            ->andReturn('0');

        $this->mockRedis->shouldReceive('incr')
            ->once()
            ->andReturn(1);

        $this->mockRedis->shouldReceive('expire')
            ->once();

        // Mock primary vendor (Iterable)
        $mockVendor = Mockery::mock(VendorInterface::class);
        $mockVendor->shouldReceive('getVendorName')
            ->andReturn('iterable');
        $mockVendor->shouldReceive('checkHealth')
            ->once()
            ->andReturn(['isHealthy' => true]);
        $mockVendor->shouldReceive('send')
            ->once()
            ->with($payload)
            ->andReturn([
                'messageId' => 'msg_123',
                'status' => 'sent',
                'vendorResponse' => ['delivery_id' => 'del_456']
            ]);

        // Mock factory to return healthy vendor
        $this->mockFactory->shouldReceive('getHealthyVendor')
            ->with(self::TEST_CHANNEL, self::TEST_TENANT_ID)
            ->once()
            ->andReturn($mockVendor);

        // Mock circuit breaker state
        $this->mockRedis->shouldReceive('hgetall')
            ->with('circuit_breaker:' . self::TEST_TENANT_ID . ':' . self::TEST_CHANNEL . ':iterable')
            ->andReturn([
                'state' => 'closed',
                'failure_count' => '0'
            ]);

        // Execute test
        $result = $this->vendorService->send($payload, self::TEST_CHANNEL, self::TEST_TENANT_ID);

        // Verify response
        $this->assertArrayHasKey('messageId', $result);
        $this->assertEquals('sent', $result['status']);
        $this->assertEquals('iterable', $result['vendor']);
    }

    /**
     * Test vendor failover when primary vendor fails.
     */
    public function testVendorFailoverOnError(): void
    {
        // Create test payload
        $payload = [
            'recipient' => 'test@example.com',
            'template_id' => 'welcome_email',
            'context' => ['name' => 'Test User']
        ];

        // Mock Redis rate limit checks
        $this->mockRedis->shouldReceive('get')
            ->with('rate_limit:' . self::TEST_TENANT_ID . ':' . self::TEST_CHANNEL)
            ->andReturn('0');
        $this->mockRedis->shouldReceive('incr')->andReturn(1);
        $this->mockRedis->shouldReceive('expire');

        // Mock failed primary vendor (Iterable)
        $mockPrimaryVendor = Mockery::mock(VendorInterface::class);
        $mockPrimaryVendor->shouldReceive('getVendorName')
            ->andReturn('iterable');
        $mockPrimaryVendor->shouldReceive('send')
            ->once()
            ->andThrow(new VendorException(
                'Service unavailable',
                VendorException::VENDOR_UNAVAILABLE,
                null,
                ['vendor_name' => 'iterable', 'channel' => self::TEST_CHANNEL]
            ));

        // Mock successful secondary vendor (SendGrid)
        $mockSecondaryVendor = Mockery::mock(VendorInterface::class);
        $mockSecondaryVendor->shouldReceive('getVendorName')
            ->andReturn('sendgrid');
        $mockSecondaryVendor->shouldReceive('checkHealth')
            ->once()
            ->andReturn(['isHealthy' => true]);
        $mockSecondaryVendor->shouldReceive('send')
            ->once()
            ->with($payload)
            ->andReturn([
                'messageId' => 'msg_789',
                'status' => 'sent',
                'vendorResponse' => ['delivery_id' => 'del_012']
            ]);

        // Mock factory for failover sequence
        $this->mockFactory->shouldReceive('getHealthyVendor')
            ->with(self::TEST_CHANNEL, self::TEST_TENANT_ID)
            ->twice()
            ->andReturn($mockPrimaryVendor, $mockSecondaryVendor);

        // Mock circuit breaker states
        $this->mockRedis->shouldReceive('hgetall')
            ->with(Mockery::pattern('/circuit_breaker:.*:(iterable|sendgrid)/'))
            ->andReturn([
                'state' => 'closed',
                'failure_count' => '0'
            ]);

        // Mock logger for failure tracking
        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with('Vendor delivery failed', Mockery::type('array'));

        // Execute test with timing assertion
        $startTime = microtime(true);
        $result = $this->vendorService->send($payload, self::TEST_CHANNEL, self::TEST_TENANT_ID);
        $endTime = microtime(true);

        // Verify failover timing (should be < 2s)
        $this->assertLessThan(2.0, $endTime - $startTime);

        // Verify response from secondary vendor
        $this->assertArrayHasKey('messageId', $result);
        $this->assertEquals('sent', $result['status']);
        $this->assertEquals('sendgrid', $result['vendor']);
    }

    /**
     * Test circuit breaker behavior during vendor failures.
     */
    public function testCircuitBreakerIntegration(): void
    {
        // Create test payload
        $payload = [
            'recipient' => 'test@example.com',
            'template_id' => 'welcome_email',
            'context' => ['name' => 'Test User']
        ];

        // Mock Redis rate limit checks
        $this->mockRedis->shouldReceive('get')
            ->with('rate_limit:' . self::TEST_TENANT_ID . ':' . self::TEST_CHANNEL)
            ->andReturn('0');
        $this->mockRedis->shouldReceive('incr')->andReturn(1);
        $this->mockRedis->shouldReceive('expire');

        // Mock vendor with consecutive failures
        $mockVendor = Mockery::mock(VendorInterface::class);
        $mockVendor->shouldReceive('getVendorName')
            ->andReturn('iterable');
        $mockVendor->shouldReceive('send')
            ->times(5)
            ->andThrow(new VendorException(
                'Service unavailable',
                VendorException::VENDOR_UNAVAILABLE,
                null,
                ['vendor_name' => 'iterable', 'channel' => self::TEST_CHANNEL]
            ));

        // Mock factory to return same vendor
        $this->mockFactory->shouldReceive('getHealthyVendor')
            ->with(self::TEST_CHANNEL, self::TEST_TENANT_ID)
            ->andReturn($mockVendor);

        // Mock circuit breaker state progression
        $this->mockRedis->shouldReceive('hgetall')
            ->with('circuit_breaker:' . self::TEST_TENANT_ID . ':' . self::TEST_CHANNEL . ':iterable')
            ->andReturn(
                ['state' => 'closed', 'failure_count' => '0'],
                ['state' => 'closed', 'failure_count' => '1'],
                ['state' => 'closed', 'failure_count' => '2'],
                ['state' => 'closed', 'failure_count' => '3'],
                ['state' => 'closed', 'failure_count' => '4'],
                ['state' => 'open', 'failure_count' => '5']
            );

        // Mock Redis multi-exec for state updates
        $this->mockRedis->shouldReceive('multi')->andReturnSelf();
        $this->mockRedis->shouldReceive('hincrby')->andReturnSelf();
        $this->mockRedis->shouldReceive('hset')->andReturnSelf();
        $this->mockRedis->shouldReceive('exec');

        // Execute test for circuit breaker transition
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->vendorService->send($payload, self::TEST_CHANNEL, self::TEST_TENANT_ID);
            } catch (VendorException $e) {
                $this->assertEquals(
                    $i === 4 ? VendorException::VENDOR_CIRCUIT_OPEN : VendorException::VENDOR_UNAVAILABLE,
                    $e->getCode()
                );
            }
        }

        // Verify circuit breaker is open
        $state = $this->vendorService->checkVendorHealth('iterable');
        $this->assertEquals('open', $state['circuit_breaker']['state']);
    }

    /**
     * Clean up after each test.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}