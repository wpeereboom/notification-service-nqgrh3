<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vendor\Sms;

use App\Exceptions\VendorException;
use App\Services\Vendor\Sms\TwilioService;
use App\Utils\CircuitBreaker;
use Mockery;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;
use Twilio\Rest\Client as TwilioClient;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Exceptions\RestException;

/**
 * Comprehensive test suite for TwilioService class.
 * Tests SMS functionality, circuit breaker integration, and vendor health checks.
 *
 * @package Tests\Unit\Services\Vendor\Sms
 * @version 1.0.0
 * @covers \App\Services\Vendor\Sms\TwilioService
 */
class TwilioServiceTest extends TestCase
{
    private const TEST_ACCOUNT_SID = 'test_account_sid';
    private const TEST_AUTH_TOKEN = 'test_auth_token';
    private const TEST_FROM_NUMBER = '+1234567890';
    private const TEST_TO_NUMBER = '+9876543210';
    private const TEST_MESSAGE = 'Test SMS message';

    private TwilioService $twilioService;
    private Mockery\MockInterface $twilioClient;
    private Mockery\MockInterface $messageList;
    private Mockery\MockInterface $logger;
    private Mockery\MockInterface $redis;
    private Mockery\MockInterface $circuitBreaker;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->twilioClient = Mockery::mock(TwilioClient::class);
        $this->messageList = Mockery::mock(MessageList::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->redis = Mockery::mock(Redis::class);
        $this->circuitBreaker = Mockery::mock(CircuitBreaker::class);

        // Configure default mock behaviors
        $this->twilioClient->messages = $this->messageList;
        $this->logger->shouldReceive('info')->byDefault();
        $this->logger->shouldReceive('warning')->byDefault();
        $this->redis->shouldReceive('setex')->byDefault();
        $this->redis->shouldReceive('get')->byDefault();

        $this->twilioService = new TwilioService(
            $this->logger,
            $this->redis,
            self::TEST_ACCOUNT_SID,
            self::TEST_AUTH_TOKEN,
            self::TEST_FROM_NUMBER
        );

        // Use reflection to inject mocked dependencies
        $reflection = new \ReflectionClass($this->twilioService);
        
        $twilioClientProperty = $reflection->getProperty('twilioClient');
        $twilioClientProperty->setAccessible(true);
        $twilioClientProperty->setValue($this->twilioService, $this->twilioClient);

        $circuitBreakerProperty = $reflection->getProperty('circuitBreaker');
        $circuitBreakerProperty->setAccessible(true);
        $circuitBreakerProperty->setValue($this->twilioService, $this->circuitBreaker);
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
     * Test successful SMS sending with proper response handling.
     */
    public function testSendSmsSuccess(): void
    {
        // Configure circuit breaker to allow request
        $this->circuitBreaker->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->circuitBreaker->shouldReceive('recordSuccess')
            ->once();

        // Mock successful message creation
        $messageInstance = Mockery::mock(MessageInstance::class);
        $messageInstance->sid = 'TEST_SID_123';
        $messageInstance->status = 'sent';
        $messageInstance->price = '0.07';
        $messageInstance->priceUnit = 'USD';

        $this->messageList->shouldReceive('create')
            ->with(
                self::TEST_TO_NUMBER,
                [
                    'from' => self::TEST_FROM_NUMBER,
                    'body' => self::TEST_MESSAGE,
                    'statusCallback' => null,
                ]
            )
            ->once()
            ->andReturn($messageInstance);

        // Configure Redis caching expectations
        $this->redis->shouldReceive('setex')
            ->with(
                'sms:status:TEST_SID_123',
                300,
                Mockery::type('string')
            )
            ->once();

        $response = $this->twilioService->send([
            'recipient' => self::TEST_TO_NUMBER,
            'content' => ['body' => self::TEST_MESSAGE],
            'options' => []
        ]);

        $this->assertEquals('TEST_SID_123', $response['messageId']);
        $this->assertEquals('sent', $response['status']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertEquals('0.07', $response['vendorResponse']['price']);
        $this->assertEquals('USD', $response['vendorResponse']['priceUnit']);
    }

    /**
     * Test SMS sending with various failure scenarios.
     */
    public function testSendSmsFailure(): void
    {
        $this->circuitBreaker->shouldReceive('isAvailable')
            ->times(3)
            ->andReturn(true);

        $this->circuitBreaker->shouldReceive('recordFailure')
            ->once();

        // Mock rate limit exception
        $rateLimitException = new RestException(
            429,
            'Too Many Requests',
            20429,
            'https://api.twilio.com'
        );

        $this->messageList->shouldReceive('create')
            ->times(3)
            ->andThrow($rateLimitException);

        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_UNAVAILABLE);

        $this->twilioService->send([
            'recipient' => self::TEST_TO_NUMBER,
            'content' => ['body' => self::TEST_MESSAGE],
            'options' => []
        ]);
    }

    /**
     * Test behavior when circuit breaker is open.
     */
    public function testCircuitBreakerOpen(): void
    {
        $this->circuitBreaker->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        $this->messageList->shouldNotReceive('create');

        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_CIRCUIT_OPEN);

        $this->twilioService->send([
            'recipient' => self::TEST_TO_NUMBER,
            'content' => ['body' => self::TEST_MESSAGE],
            'options' => []
        ]);
    }

    /**
     * Test message status retrieval functionality.
     */
    public function testGetStatus(): void
    {
        $messageId = 'TEST_SID_123';
        $messageInstance = Mockery::mock(MessageInstance::class);
        $messageInstance->status = 'delivered';
        $messageInstance->sid = $messageId;
        $messageInstance->dateSent = new \DateTime();
        $messageInstance->dateUpdated = new \DateTime();
        $messageInstance->errorCode = null;
        $messageInstance->errorMessage = null;
        $messageInstance->price = '0.07';
        $messageInstance->priceUnit = 'USD';

        // Test cache miss scenario
        $this->redis->shouldReceive('get')
            ->with("sms:status:{$messageId}")
            ->once()
            ->andReturn(null);

        $this->twilioClient->shouldReceive('messages')
            ->with($messageId)
            ->once()
            ->andReturn(Mockery::mock([
                'fetch' => $messageInstance
            ]));

        $status = $this->twilioService->getStatus($messageId);

        $this->assertEquals('delivered', $status['currentState']);
        $this->assertArrayHasKey('timestamps', $status);
        $this->assertEquals(1, $status['attempts']);
        $this->assertEquals($messageId, $status['vendorMetadata']['sid']);
    }

    /**
     * Test health check functionality.
     */
    public function testCheckHealth(): void
    {
        $account = Mockery::mock();
        $api = Mockery::mock(['v2010' => Mockery::mock(['account' => $account])]);
        $this->twilioClient->api = $api;

        $account->shouldReceive('fetch')
            ->once()
            ->andReturn(true);

        $this->circuitBreaker->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->circuitBreaker->shouldReceive('getState')
            ->once()
            ->andReturn([
                'state' => 'closed',
                'failure_count' => 0
            ]);

        $health = $this->twilioService->checkHealth();

        $this->assertTrue($health['isHealthy']);
        $this->assertArrayHasKey('latency', $health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertTrue($health['diagnostics']['api_accessible']);
    }

    /**
     * Test invalid payload validation.
     */
    public function testInvalidPayload(): void
    {
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_INVALID_REQUEST);

        $this->twilioService->send([
            'recipient' => 'invalid_phone',
            'content' => ['body' => self::TEST_MESSAGE]
        ]);
    }

    /**
     * Test vendor name and type getters.
     */
    public function testVendorIdentification(): void
    {
        $this->assertEquals('twilio', $this->twilioService->getVendorName());
        $this->assertEquals('sms', $this->twilioService->getVendorType());
    }
}