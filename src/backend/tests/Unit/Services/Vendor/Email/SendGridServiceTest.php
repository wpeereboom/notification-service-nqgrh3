<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vendor\Email;

use App\Exceptions\VendorException;
use App\Services\Vendor\Email\SendGridService;
use App\Utils\CircuitBreaker;
use Mockery;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;
use SendGrid;
use SendGrid\Mail\Mail;
use SendGrid\Response;

/**
 * Comprehensive test suite for SendGrid email service implementation.
 * Tests high-throughput processing, failover scenarios, and reliability features.
 *
 * @package Tests\Unit\Services\Vendor\Email
 * @version 1.0.0
 * @covers \App\Services\Vendor\Email\SendGridService
 */
class SendGridServiceTest extends TestCase
{
    private const API_KEY = 'test_api_key';
    private const TENANT_ID = 'test_tenant';

    private SendGridService $service;
    private Mockery\MockInterface $sendGridMock;
    private Mockery\MockInterface $loggerMock;
    private Mockery\MockInterface $redisMock;
    private Mockery\MockInterface $circuitBreakerMock;
    private Mockery\MockInterface $responseMock;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize mocks
        $this->sendGridMock = Mockery::mock(SendGrid::class);
        $this->loggerMock = Mockery::mock(LoggerInterface::class);
        $this->redisMock = Mockery::mock(Redis::class);
        $this->circuitBreakerMock = Mockery::mock(CircuitBreaker::class);
        $this->responseMock = Mockery::mock(Response::class);

        // Configure default successful response
        $this->responseMock->shouldReceive('statusCode')->andReturn(202);
        $this->responseMock->shouldReceive('body')->andReturn(json_encode([
            'message_id' => 'test_message_id'
        ]));
        $this->responseMock->shouldReceive('headers')->andReturn([]);

        // Configure SendGrid client mock
        $this->sendGridMock->shouldReceive('client->_->get')
            ->andReturn($this->responseMock);
        $this->sendGridMock->shouldReceive('send')
            ->andReturn($this->responseMock);

        // Configure circuit breaker mock
        $this->circuitBreakerMock->shouldReceive('isAvailable')
            ->andReturn(true);
        $this->circuitBreakerMock->shouldReceive('recordSuccess');

        // Create service instance
        $this->service = new SendGridService(
            self::API_KEY,
            $this->loggerMock,
            $this->redisMock,
            ['tenant_id' => self::TENANT_ID]
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
     * Tests successful single email delivery.
     */
    public function testSuccessfulSingleEmailDelivery(): void
    {
        // Prepare test data
        $payload = [
            'to' => ['email' => 'recipient@test.com', 'name' => 'Test Recipient'],
            'from' => ['email' => 'sender@test.com', 'name' => 'Test Sender'],
            'subject' => 'Test Subject',
            'content' => [
                'text' => 'Test content',
                'html' => '<p>Test content</p>'
            ]
        ];

        // Execute test
        $result = $this->service->send($payload);

        // Verify results
        $this->assertEquals('sent', $result['status']);
        $this->assertArrayHasKey('messageId', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('vendorResponse', $result);
    }

    /**
     * @test
     * Tests high-throughput batch processing capability.
     */
    public function testBatchProcessing(): void
    {
        // Prepare large batch of messages
        $messages = [];
        for ($i = 0; $i < 1000; $i++) {
            $messages[] = [
                'to' => ['email' => "recipient{$i}@test.com"],
                'from' => ['email' => 'sender@test.com'],
                'subject' => "Test Subject {$i}",
                'content' => ['text' => "Test content {$i}"]
            ];
        }

        $payload = ['batch' => $messages];

        // Configure mock for batch processing
        $this->sendGridMock->shouldReceive('send')
            ->times(10) // Expect 10 batches of 100 messages
            ->andReturn($this->responseMock);

        // Record start time
        $startTime = microtime(true);

        // Execute batch processing
        $result = $this->service->send($payload);

        // Calculate processing time
        $processingTime = microtime(true) - $startTime;

        // Verify results
        $this->assertEquals('sent', $result['status']);
        $this->assertArrayHasKey('batch_results', $result);
        $this->assertCount(10, $result['batch_results']);

        // Verify processing time meets SLA (< 30 seconds for 1000 messages)
        $this->assertLessThan(30, $processingTime);
    }

    /**
     * @test
     * Tests vendor failover functionality and timing.
     */
    public function testVendorFailover(): void
    {
        // Configure circuit breaker for failover scenario
        $this->circuitBreakerMock->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);
        
        $this->circuitBreakerMock->shouldReceive('recordFailure')
            ->once();

        // Configure SendGrid client to fail
        $this->sendGridMock->shouldReceive('send')
            ->once()
            ->andThrow(new \Exception('Service unavailable'));

        // Prepare test message
        $payload = [
            'to' => ['email' => 'recipient@test.com'],
            'from' => ['email' => 'sender@test.com'],
            'subject' => 'Test Subject',
            'content' => ['text' => 'Test content']
        ];

        // Record start time
        $startTime = microtime(true);

        // Execute test and expect exception
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_UNAVAILABLE);

        try {
            $this->service->send($payload);
        } catch (VendorException $e) {
            // Verify failover time is within 2 seconds
            $failoverTime = microtime(true) - $startTime;
            $this->assertLessThan(2, $failoverTime);
            throw $e;
        }
    }

    /**
     * @test
     * Tests circuit breaker integration.
     */
    public function testCircuitBreakerIntegration(): void
    {
        // Configure circuit breaker to be open
        $this->circuitBreakerMock->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        // Prepare test message
        $payload = [
            'to' => ['email' => 'recipient@test.com'],
            'from' => ['email' => 'sender@test.com'],
            'subject' => 'Test Subject',
            'content' => ['text' => 'Test content']
        ];

        // Execute test and expect circuit breaker exception
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_CIRCUIT_OPEN);

        $this->service->send($payload);
    }

    /**
     * @test
     * Tests health check functionality.
     */
    public function testHealthCheck(): void
    {
        // Configure circuit breaker state
        $this->circuitBreakerMock->shouldReceive('getState')
            ->andReturn(['state' => 'closed']);

        // Execute health check
        $health = $this->service->checkHealth();

        // Verify health check response
        $this->assertArrayHasKey('isHealthy', $health);
        $this->assertArrayHasKey('latency', $health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertArrayHasKey('diagnostics', $health);
        $this->assertTrue($health['isHealthy']);
    }

    /**
     * @test
     * Tests message status retrieval.
     */
    public function testGetMessageStatus(): void
    {
        // Configure mock response for status check
        $statusResponse = Mockery::mock(Response::class);
        $statusResponse->shouldReceive('statusCode')->andReturn(200);
        $statusResponse->shouldReceive('body')->andReturn(json_encode([
            'message_id' => 'test_message_id',
            'status' => 'delivered',
            'delivered_at' => '2023-10-01T12:00:00Z'
        ]));

        $this->sendGridMock->shouldReceive('client->messages->_->get')
            ->once()
            ->andReturn($statusResponse);

        // Execute status check
        $status = $this->service->getStatus('test_message_id');

        // Verify status response
        $this->assertArrayHasKey('currentState', $status);
        $this->assertArrayHasKey('timestamps', $status);
        $this->assertArrayHasKey('attempts', $status);
        $this->assertEquals('delivered', $status['currentState']);
    }

    /**
     * @test
     * Tests rate limiting handling.
     */
    public function testRateLimitHandling(): void
    {
        // Configure rate limit response
        $rateLimitResponse = Mockery::mock(Response::class);
        $rateLimitResponse->shouldReceive('statusCode')->andReturn(429);
        $rateLimitResponse->shouldReceive('body')->andReturn('{}');

        $this->sendGridMock->shouldReceive('send')
            ->times(3)
            ->andReturn($rateLimitResponse);

        // Prepare test message
        $payload = [
            'to' => ['email' => 'recipient@test.com'],
            'from' => ['email' => 'sender@test.com'],
            'subject' => 'Test Subject',
            'content' => ['text' => 'Test content']
        ];

        // Execute test and expect rate limit exception
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_UNAVAILABLE);

        $this->service->send($payload);
    }
}