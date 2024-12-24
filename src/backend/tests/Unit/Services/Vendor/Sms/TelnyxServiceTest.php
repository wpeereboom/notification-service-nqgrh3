<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vendor\Sms;

use App\Services\Vendor\Sms\TelnyxService;
use App\Utils\CircuitBreaker;
use App\Exceptions\VendorException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Telnyx\Client as TelnyxClient;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Comprehensive test suite for TelnyxService SMS delivery functionality.
 * Tests circuit breaker pattern, failover mechanisms, and performance metrics.
 *
 * @package Tests\Unit\Services\Vendor\Sms
 * @version 1.0.0
 * @covers \App\Services\Vendor\Sms\TelnyxService
 */
final class TelnyxServiceTest extends TestCase
{
    private const TEST_PHONE = '+1234567890';
    private const TEST_MESSAGE = 'Test message';
    private const TEST_MESSAGE_ID = 'msg_123456789';
    
    private TelnyxService $telnyxService;
    private MockObject $telnyxClient;
    private MockObject $logger;
    private MockObject $circuitBreaker;
    private MockObject $redis;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        // Create mock objects
        $this->telnyxClient = $this->createMock(TelnyxClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->redis = $this->createMock(Redis::class);
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);

        // Initialize service with mocks
        $this->telnyxService = new TelnyxService(
            $this->telnyxClient,
            $this->logger,
            $this->redis
        );

        // Use reflection to inject mock circuit breaker
        $reflection = new \ReflectionClass($this->telnyxService);
        $property = $reflection->getProperty('circuitBreaker');
        $property->setAccessible(true);
        $property->setValue($this->telnyxService, $this->circuitBreaker);
    }

    /**
     * Test successful SMS sending with performance metrics.
     */
    public function testSendSmsSuccess(): void
    {
        // Prepare test data
        $payload = [
            'recipient' => self::TEST_PHONE,
            'content' => self::TEST_MESSAGE
        ];

        // Create mock response
        $response = new stdClass();
        $response->id = self::TEST_MESSAGE_ID;
        $response->status = 'sent';
        $response->to = self::TEST_PHONE;

        // Configure mocks
        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->telnyxClient->messages = $this->createMock(stdClass::class);
        $this->telnyxClient->messages->expects($this->once())
            ->method('create')
            ->willReturn($response);

        $this->circuitBreaker->expects($this->once())
            ->method('recordSuccess');

        $this->redis->expects($this->once())
            ->method('setex')
            ->with(
                $this->stringContains('telnyx:status:'),
                $this->anything(),
                $this->anything()
            );

        // Execute test
        $startTime = microtime(true);
        $result = $this->telnyxService->send($payload);
        $processingTime = (microtime(true) - $startTime) * 1000;

        // Verify response structure
        $this->assertArrayHasKey('messageId', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('vendorResponse', $result);
        $this->assertArrayHasKey('metadata', $result);

        // Verify message ID and status
        $this->assertEquals(self::TEST_MESSAGE_ID, $result['messageId']);
        $this->assertEquals('sent', $result['status']);

        // Verify performance requirements
        $this->assertLessThan(2000, $processingTime, 'Processing time exceeded 2 seconds');
    }

    /**
     * Test circuit breaker state transitions and failover behavior.
     */
    public function testCircuitBreakerFailover(): void
    {
        // Configure initial circuit breaker state
        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        // Simulate API failure
        $this->telnyxClient->messages = $this->createMock(stdClass::class);
        $this->telnyxClient->messages->expects($this->once())
            ->method('create')
            ->willThrowException(new \Exception('API Error'));

        // Expect circuit breaker to record failure
        $this->circuitBreaker->expects($this->once())
            ->method('recordFailure');

        // Expect error logging
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Telnyx SMS delivery failed'),
                $this->arrayHasKey('error_message')
            );

        // Execute test
        $startTime = microtime(true);
        try {
            $this->telnyxService->send([
                'recipient' => self::TEST_PHONE,
                'content' => self::TEST_MESSAGE
            ]);
            $this->fail('Expected VendorException was not thrown');
        } catch (VendorException $e) {
            // Verify failover time
            $failoverTime = (microtime(true) - $startTime) * 1000;
            $this->assertLessThan(2000, $failoverTime, 'Failover time exceeded 2 seconds');

            // Verify exception details
            $this->assertEquals(VendorException::VENDOR_UNAVAILABLE, $e->getCode());
            $this->assertEquals('telnyx', $e->getVendorName());
            $this->assertEquals('sms', $e->getChannel());
        }
    }

    /**
     * Test health check functionality with monitoring intervals.
     */
    public function testHealthCheck(): void
    {
        // Configure circuit breaker state
        $circuitState = [
            'state' => 'closed',
            'failure_count' => 0,
            'last_failure_time' => null
        ];

        $this->circuitBreaker->expects($this->once())
            ->method('getState')
            ->willReturn($circuitState);

        // Configure test message response
        $response = new stdClass();
        $response->id = self::TEST_MESSAGE_ID;
        $response->status = 'sent';

        $this->telnyxClient->messages = $this->createMock(stdClass::class);
        $this->telnyxClient->messages->expects($this->once())
            ->method('create')
            ->willReturn($response);

        // Execute health check
        $result = $this->telnyxService->checkHealth();

        // Verify health check response
        $this->assertArrayHasKey('isHealthy', $result);
        $this->assertArrayHasKey('latency', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('diagnostics', $result);

        // Verify health check interval
        $this->assertLessThan(30000, $result['latency'], 'Health check interval exceeded 30 seconds');
        $this->assertTrue($result['isHealthy']);
    }

    /**
     * Test message status retrieval with caching.
     */
    public function testGetMessageStatus(): void
    {
        // Configure cache miss
        $this->redis->expects($this->once())
            ->method('get')
            ->willReturn(null);

        // Configure API response
        $response = new stdClass();
        $response->id = self::TEST_MESSAGE_ID;
        $response->status = 'delivered';
        $response->sent_at = '2023-10-01T10:00:00Z';
        $response->completed_at = '2023-10-01T10:00:01Z';
        $response->errors = null;

        $this->telnyxClient->messages = $this->createMock(stdClass::class);
        $this->telnyxClient->messages->expects($this->once())
            ->method('retrieve')
            ->with(self::TEST_MESSAGE_ID)
            ->willReturn($response);

        // Execute test
        $result = $this->telnyxService->getStatus(self::TEST_MESSAGE_ID);

        // Verify status response
        $this->assertArrayHasKey('currentState', $result);
        $this->assertArrayHasKey('timestamps', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('vendorMetadata', $result);

        // Verify status mapping
        $this->assertEquals('delivered', $result['currentState']);
    }

    /**
     * Test batch sending functionality.
     */
    public function testSendBatch(): void
    {
        // Prepare batch test data
        $messages = [
            [
                'recipient' => self::TEST_PHONE,
                'content' => self::TEST_MESSAGE
            ],
            [
                'recipient' => '+1987654321',
                'content' => 'Another test message'
            ]
        ];

        // Configure mock responses
        $responses = [];
        foreach ($messages as $index => $message) {
            $response = new stdClass();
            $response->id = self::TEST_MESSAGE_ID . "_$index";
            $response->status = 'sent';
            $response->to = $message['recipient'];
            $responses[] = $response;
        }

        // Configure mocks for batch processing
        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->telnyxClient->messages = $this->createMock(stdClass::class);
        $this->telnyxClient->messages->expects($this->exactly(count($messages)))
            ->method('create')
            ->willReturnOnConsecutiveCalls(...$responses);

        // Execute batch send
        $startTime = microtime(true);
        $results = [];
        
        foreach ($messages as $message) {
            $results[] = $this->telnyxService->send($message);
        }

        $batchTime = (microtime(true) - $startTime) * 1000;

        // Verify batch processing time
        $this->assertLessThan(2000 * count($messages), $batchTime, 'Batch processing time exceeded limit');

        // Verify all messages were sent
        $this->assertCount(count($messages), $results);
        foreach ($results as $index => $result) {
            $this->assertEquals('sent', $result['status']);
            $this->assertEquals($messages[$index]['recipient'], $result['vendorResponse']['to']);
        }
    }
}