<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vendor\Email;

use App\Contracts\VendorInterface;
use App\Exceptions\VendorException;
use App\Services\Vendor\Email\SesService;
use App\Utils\CircuitBreaker;
use Aws\Result;
use Aws\Ses\SesClient;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Comprehensive test suite for Amazon SES email vendor implementation.
 * Tests email delivery, status tracking, health checks, and circuit breaker integration.
 *
 * @package Tests\Unit\Services\Vendor\Email
 * @version 1.0.0
 * @covers \App\Services\Vendor\Email\SesService
 */
final class SesServiceTest extends TestCase
{
    private SesService $service;
    private MockInterface $sesClient;
    private MockInterface $logger;
    private MockInterface $circuitBreaker;

    /**
     * Set up test dependencies before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sesClient = Mockery::mock(SesClient::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->circuitBreaker = Mockery::mock(CircuitBreaker::class);

        $this->service = new SesService(
            $this->sesClient,
            $this->logger,
            $this->circuitBreaker
        );
    }

    /**
     * Clean up test dependencies after each test.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     * Verifies SesService implements VendorInterface correctly.
     */
    public function testImplementsVendorInterface(): void
    {
        $this->assertInstanceOf(VendorInterface::class, $this->service);
    }

    /**
     * @test
     * Tests successful email sending with proper response handling.
     */
    public function testSendEmailSuccess(): void
    {
        $messageId = 'test-message-id-123';
        $payload = [
            'recipient' => 'test@example.com',
            'content' => [
                'subject' => 'Test Subject',
                'html' => '<p>Test content</p>',
                'text' => 'Test content'
            ]
        ];

        $this->circuitBreaker->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->sesClient->shouldReceive('sendEmail')
            ->once()
            ->andReturn(new Result([
                'MessageId' => $messageId,
                '@metadata' => ['requestId' => 'request-123']
            ]));

        $this->circuitBreaker->shouldReceive('recordSuccess')
            ->once();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Email sent successfully via SES', Mockery::subset([
                'message_id' => $messageId,
                'recipient' => $payload['recipient']
            ]));

        $response = $this->service->send($payload);

        $this->assertEquals('sent', $response['status']);
        $this->assertEquals($messageId, $response['messageId']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertEquals('ses', $response['metadata']['vendor']);
        $this->assertEquals('email', $response['metadata']['channel']);
    }

    /**
     * @test
     * Tests email sending when circuit breaker is open.
     */
    public function testSendEmailCircuitBreakerOpen(): void
    {
        $this->circuitBreaker->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_CIRCUIT_OPEN);

        $this->service->send([
            'recipient' => 'test@example.com',
            'content' => ['subject' => 'Test', 'text' => 'content']
        ]);
    }

    /**
     * @test
     * Tests email sending with invalid payload validation.
     */
    public function testSendEmailInvalidPayload(): void
    {
        $this->circuitBreaker->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->send([
            'recipient' => 'invalid-email',
            'content' => []
        ]);
    }

    /**
     * @test
     * Tests email sending with vendor failure and circuit breaker recording.
     */
    public function testSendEmailVendorFailure(): void
    {
        $this->circuitBreaker->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->sesClient->shouldReceive('sendEmail')
            ->once()
            ->andThrow(new \Exception('SES API Error'));

        $this->circuitBreaker->shouldReceive('recordFailure')
            ->once();

        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_UNAVAILABLE);

        $this->service->send([
            'recipient' => 'test@example.com',
            'content' => [
                'subject' => 'Test',
                'text' => 'content'
            ]
        ]);
    }

    /**
     * @test
     * Tests successful message status retrieval.
     */
    public function testGetStatusSuccess(): void
    {
        $messageId = 'test-message-id';
        $status = 'Success';

        $this->sesClient->shouldReceive('getMessageStatus')
            ->with(['MessageId' => $messageId])
            ->once()
            ->andReturn(new Result([
                'Status' => $status,
                'SendTimestamp' => time(),
                'DeliveryTimestamp' => time()
            ]));

        $response = $this->service->getStatus($messageId);

        $this->assertEquals('delivered', $response['currentState']);
        $this->assertArrayHasKey('timestamps', $response);
        $this->assertArrayHasKey('attempts', $response);
        $this->assertArrayHasKey('vendorMetadata', $response);
    }

    /**
     * @test
     * Tests health check with successful response.
     */
    public function testCheckHealthSuccess(): void
    {
        $quotaResponse = [
            'Max24HourSend' => 50000,
            'SentLast24Hours' => 1000,
            'MaxSendRate' => 14
        ];

        $this->sesClient->shouldReceive('getSendQuota')
            ->once()
            ->andReturn(new Result($quotaResponse));

        $this->circuitBreaker->shouldReceive('getState')
            ->once()
            ->andReturn([
                'state' => 'closed',
                'failure_count' => 0
            ]);

        $response = $this->service->checkHealth();

        $this->assertTrue($response['isHealthy']);
        $this->assertArrayHasKey('latency', $response);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertEquals($quotaResponse['Max24HourSend'], $response['diagnostics']['quotaMax']);
        $this->assertEquals('closed', $response['diagnostics']['circuitBreakerState']);
    }

    /**
     * @test
     * Tests health check with vendor failure.
     */
    public function testCheckHealthFailure(): void
    {
        $this->sesClient->shouldReceive('getSendQuota')
            ->once()
            ->andThrow(new \Exception('SES API Error'));

        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_UNAVAILABLE);

        $this->service->checkHealth();
    }

    /**
     * @test
     * Tests vendor name and type getters.
     */
    public function testVendorIdentifiers(): void
    {
        $this->assertEquals('ses', $this->service->getVendorName());
        $this->assertEquals('email', $this->service->getVendorType());
    }

    /**
     * @test
     * Tests email sending with retry mechanism.
     */
    public function testSendEmailWithRetry(): void
    {
        $messageId = 'retry-test-id';
        $payload = [
            'recipient' => 'test@example.com',
            'content' => [
                'subject' => 'Test Subject',
                'text' => 'Test content'
            ]
        ];

        $this->circuitBreaker->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        // First attempt fails
        $this->sesClient->shouldReceive('sendEmail')
            ->once()
            ->andThrow(new \Exception('Temporary failure'));

        // Second attempt succeeds
        $this->sesClient->shouldReceive('sendEmail')
            ->once()
            ->andReturn(new Result([
                'MessageId' => $messageId,
                '@metadata' => ['requestId' => 'retry-123']
            ]));

        $this->circuitBreaker->shouldReceive('recordSuccess')
            ->once();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Email sent successfully via SES', Mockery::any());

        $response = $this->service->send($payload);

        $this->assertEquals('sent', $response['status']);
        $this->assertEquals($messageId, $response['messageId']);
    }
}