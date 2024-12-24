<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vendor;

use App\Contracts\VendorInterface;
use App\Exceptions\VendorException;
use App\Services\Vendor\VendorFactory;
use GuzzleHttp\Client;
use Mockery;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Unit test suite for VendorFactory class.
 * Tests vendor instantiation, health checks, failover logic, and timing requirements.
 *
 * @covers \App\Services\Vendor\VendorFactory
 */
class VendorFactoryTest extends TestCase
{
    private VendorFactory $vendorFactory;
    private LoggerInterface $logger;
    private Client $httpClient;
    private RedisClient $cache;
    private array $config;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mock logger
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('error')->byDefault();
        $this->logger->shouldReceive('warning')->byDefault();
        $this->logger->shouldReceive('info')->byDefault();

        // Mock HTTP client
        $this->httpClient = Mockery::mock(Client::class);
        $this->httpClient->shouldReceive('request')->byDefault();

        // Mock Redis client
        $this->cache = Mockery::mock(RedisClient::class);
        $this->cache->shouldReceive('get')->byDefault()->andReturn(null);
        $this->cache->shouldReceive('setex')->byDefault();

        // Test configuration
        $this->config = [
            'vendors' => [
                'iterable' => ['test_tenant' => ['api_key' => 'test_key']],
                'sendgrid' => ['test_tenant' => ['api_key' => 'test_key']],
                'ses' => ['test_tenant' => ['key' => 'test_key', 'secret' => 'test_secret']],
                'telnyx' => ['test_tenant' => ['api_key' => 'test_key']],
                'twilio' => ['test_tenant' => ['sid' => 'test_sid', 'token' => 'test_token']],
                'sns' => ['test_tenant' => ['key' => 'test_key', 'secret' => 'test_secret']]
            ]
        ];

        // Define vendor types constant if not defined
        if (!defined('VENDOR_TYPES')) {
            define('VENDOR_TYPES', json_encode([
                'email' => ['iterable', 'sendgrid', 'ses'],
                'sms' => ['telnyx', 'twilio'],
                'push' => ['sns']
            ]));
        }

        // Define vendor priorities constant if not defined
        if (!defined('VENDOR_PRIORITIES')) {
            define('VENDOR_PRIORITIES', json_encode([
                'email' => ['iterable' => 1, 'sendgrid' => 2, 'ses' => 3],
                'sms' => ['telnyx' => 1, 'twilio' => 2],
                'push' => ['sns' => 1]
            ]));
        }

        // Define other required constants
        if (!defined('VENDOR_TIMEOUT')) {
            define('VENDOR_TIMEOUT', '5');
        }
        if (!defined('HEALTH_CHECK_INTERVAL')) {
            define('HEALTH_CHECK_INTERVAL', '30');
        }

        $this->vendorFactory = new VendorFactory(
            $this->logger,
            $this->httpClient,
            $this->cache,
            $this->config
        );
    }

    /**
     * Clean up after each test.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     * Tests vendor instance creation for each supported vendor type.
     */
    public function testCreateReturnsCorrectVendorInstance(): void
    {
        // Test Iterable vendor creation
        $iterableVendor = $this->vendorFactory->create('iterable', 'test_tenant');
        $this->assertInstanceOf(VendorInterface::class, $iterableVendor);
        $this->assertEquals('email', $iterableVendor->getVendorType());
        $this->assertEquals('iterable', $iterableVendor->getVendorName());

        // Test SendGrid vendor creation
        $sendgridVendor = $this->vendorFactory->create('sendgrid', 'test_tenant');
        $this->assertInstanceOf(VendorInterface::class, $sendgridVendor);
        $this->assertEquals('email', $sendgridVendor->getVendorType());

        // Test invalid vendor
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_INVALID_REQUEST);
        $this->vendorFactory->create('invalid_vendor', 'test_tenant');
    }

    /**
     * @test
     * Tests that getHealthyVendor returns highest priority healthy vendor.
     */
    public function testGetHealthyVendorReturnsHighestPriorityHealthyVendor(): void
    {
        // Mock vendor health checks
        $mockVendor = Mockery::mock(VendorInterface::class);
        $mockVendor->shouldReceive('checkHealth')
            ->andReturn(['isHealthy' => true]);
        $mockVendor->shouldReceive('getVendorType')
            ->andReturn('email');
        $mockVendor->shouldReceive('getVendorName')
            ->andReturn('iterable');

        // Test email channel priority
        $vendor = $this->vendorFactory->getHealthyVendor('email', 'test_tenant');
        $this->assertEquals('iterable', $vendor->getVendorName());

        // Verify cache was set
        $this->cache->shouldHaveReceived('setex')
            ->with('vendor:health:email', 30, 'iterable');
    }

    /**
     * @test
     * Tests vendor failover behavior and timing requirements.
     */
    public function testGetHealthyVendorFailsOverToNextVendor(): void
    {
        $startTime = microtime(true);

        // Mock primary vendor as unhealthy
        $this->cache->shouldReceive('get')
            ->with('vendor:health:email')
            ->andReturn(null);

        // Configure primary vendor to fail health check
        $mockIterableVendor = Mockery::mock(VendorInterface::class);
        $mockIterableVendor->shouldReceive('checkHealth')
            ->andThrow(new VendorException(
                'Service unavailable',
                VendorException::VENDOR_UNAVAILABLE,
                null,
                ['vendor_name' => 'iterable', 'channel' => 'email']
            ));

        // Configure secondary vendor as healthy
        $mockSendGridVendor = Mockery::mock(VendorInterface::class);
        $mockSendGridVendor->shouldReceive('checkHealth')
            ->andReturn(['isHealthy' => true]);
        $mockSendGridVendor->shouldReceive('getVendorName')
            ->andReturn('sendgrid');
        $mockSendGridVendor->shouldReceive('getVendorType')
            ->andReturn('email');

        // Get healthy vendor with failover
        $vendor = $this->vendorFactory->getHealthyVendor('email', 'test_tenant');

        // Verify failover timing requirement
        $failoverTime = microtime(true) - $startTime;
        $this->assertLessThan(2.0, $failoverTime, 'Failover took longer than 2 seconds');

        // Verify correct vendor was selected
        $this->assertEquals('sendgrid', $vendor->getVendorName());

        // Verify error was logged
        $this->logger->shouldHaveReceived('error')
            ->with(Mockery::pattern('/Circuit breaker opened for vendor/'), Mockery::any());
    }

    /**
     * @test
     * Tests circuit breaker behavior when all vendors are unhealthy.
     */
    public function testGetHealthyVendorThrowsExceptionWhenAllVendorsUnhealthy(): void
    {
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_FAILOVER_EXHAUSTED);

        // Configure all vendors to fail health checks
        $mockVendor = Mockery::mock(VendorInterface::class);
        $mockVendor->shouldReceive('checkHealth')
            ->andThrow(new VendorException(
                'Service unavailable',
                VendorException::VENDOR_UNAVAILABLE,
                null,
                ['vendor_name' => 'iterable', 'channel' => 'email']
            ));

        // Attempt to get healthy vendor
        $this->vendorFactory->getHealthyVendor('email', 'test_tenant');
    }

    /**
     * @test
     * Tests vendor health check caching behavior.
     */
    public function testGetHealthyVendorUsesCachedVendor(): void
    {
        // Configure cache hit
        $this->cache->shouldReceive('get')
            ->with('vendor:health:email')
            ->andReturn('iterable');

        // Configure vendor as healthy
        $mockVendor = Mockery::mock(VendorInterface::class);
        $mockVendor->shouldReceive('checkHealth')
            ->andReturn(['isHealthy' => true]);
        $mockVendor->shouldReceive('getVendorName')
            ->andReturn('iterable');
        $mockVendor->shouldReceive('getVendorType')
            ->andReturn('email');

        // Get healthy vendor
        $vendor = $this->vendorFactory->getHealthyVendor('email', 'test_tenant');

        // Verify cached vendor was used
        $this->assertEquals('iterable', $vendor->getVendorName());
    }
}