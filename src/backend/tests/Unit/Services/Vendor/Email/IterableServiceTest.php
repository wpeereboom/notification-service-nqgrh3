<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vendor\Email;

use App\Services\Vendor\Email\IterableService;
use App\Utils\CircuitBreaker;
use App\Exceptions\VendorException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheInterface;

/**
 * Unit test suite for IterableService email delivery implementation.
 * Tests all aspects of the service including successful delivery,
 * error handling, circuit breaker integration, and health monitoring.
 *
 * @package Tests\Unit\Services\Vendor\Email
 * @version 1.0.0
 * @covers \App\Services\Vendor\Email\IterableService
 */
class IterableServiceTest extends TestCase
{
    private const API_KEY = 'test_api_key';
    private const API_ENDPOINT = 'https://api.iterable.test/api';

    private IterableService $service;
    private MockHandler $mockHandler;
    private LoggerInterface $logger;
    private CircuitBreaker $circuitBreaker;
    private CacheInterface $cache;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->service = new IterableService(
            $client,
            $this->logger,
            $this->circuitBreaker,
            $this->cache,
            self::API_KEY,
            self::API_ENDPOINT
        );
    }

    /**
     * Tests successful email delivery through Iterable API.
     */
    public function testSuccessfulEmailDelivery(): void
    {
        // Configure mocks
        $messageId = 'msg_' . uniqid();
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'messageId' => $messageId,
                'status' => 'queued'
            ]))
        );

        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->circuitBreaker->expects($this->once())
            ->method('recordSuccess');

        // Test payload
        $payload = [
            'recipient' => 'test@example.com',
            'template_id' => 'template_123',
            'content' => [
                'subject' => 'Test Email',
                'body' => 'Hello World'
            ],
            'metadata' => ['campaign_id' => 'test_campaign']
        ];

        // Execute test
        $result = $this->service->send($payload);

        // Assertions
        $this->assertArrayHasKey('messageId', $result);
        $this->assertEquals($messageId, $result['messageId']);
        $this->assertEquals('sent', $result['status']);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('vendorResponse', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertEquals('iterable', $result['metadata']['vendor']);
    }

    /**
     * Tests handling of failed email delivery scenarios.
     */
    public function testFailedEmailDelivery(): void
    {
        // Configure mocks
        $this->mockHandler->append(
            new Response(400, [], json_encode([
                'code' => 'INVALID_EMAIL',
                'message' => 'Invalid email address'
            ]))
        );

        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->circuitBreaker->expects($this->once())
            ->method('recordFailure');

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Iterable API error',
                $this->callback(function ($context) {
                    return isset($context['error']) &&
                           isset($context['code']) &&
                           isset($context['vendor']) &&
                           $context['vendor'] === 'iterable';
                })
            );

        // Test payload
        $payload = [
            'recipient' => 'invalid-email',
            'content' => ['subject' => 'Test']
        ];

        // Execute test and assert exception
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_INVALID_REQUEST);
        
        $this->service->send($payload);
    }

    /**
     * Tests circuit breaker integration and service availability management.
     */
    public function testCircuitBreakerIntegration(): void
    {
        // Configure circuit breaker in open state
        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Iterable API error',
                $this->callback(function ($context) {
                    return isset($context['circuit_breaker_open']) &&
                           $context['circuit_breaker_open'] === true;
                })
            );

        // Test payload
        $payload = [
            'recipient' => 'test@example.com',
            'content' => ['subject' => 'Test']
        ];

        // Execute test and assert exception
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_CIRCUIT_OPEN);
        
        $this->service->send($payload);
    }

    /**
     * Tests health check functionality and metrics collection.
     */
    public function testHealthCheck(): void
    {
        // Configure mocks
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'status' => 'healthy',
                'version' => 'v1',
                'latency' => 45
            ]))
        );

        $this->cache->expects($this->once())
            ->method('get')
            ->with('iterable_health_status')
            ->willReturn(null);

        $this->cache->expects($this->once())
            ->method('set')
            ->with(
                'iterable_health_status',
                $this->callback(function ($health) {
                    return isset($health['isHealthy']) &&
                           isset($health['latency']) &&
                           isset($health['timestamp']) &&
                           isset($health['diagnostics']);
                }),
                30
            );

        // Execute test
        $health = $this->service->checkHealth();

        // Assertions
        $this->assertArrayHasKey('isHealthy', $health);
        $this->assertTrue($health['isHealthy']);
        $this->assertArrayHasKey('latency', $health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertArrayHasKey('diagnostics', $health);
        $this->assertArrayHasKey('lastError', $health);
        $this->assertNull($health['lastError']);
    }

    /**
     * Tests retrieval and validation of email delivery status.
     */
    public function testDeliveryStatusRetrieval(): void
    {
        // Configure mocks
        $messageId = 'msg_' . uniqid();
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'status' => 'delivered',
                'sentAt' => '2023-10-01T10:00:00Z',
                'deliveredAt' => '2023-10-01T10:00:01Z',
                'attempts' => 1
            ]))
        );

        $cacheKey = sprintf('iterable_status_%s', $messageId);
        $this->cache->expects($this->once())
            ->method('get')
            ->with($cacheKey)
            ->willReturn(null);

        // Execute test
        $status = $this->service->getStatus($messageId);

        // Assertions
        $this->assertArrayHasKey('currentState', $status);
        $this->assertEquals('delivered', $status['currentState']);
        $this->assertArrayHasKey('timestamps', $status);
        $this->assertArrayHasKey('sent', $status['timestamps']);
        $this->assertArrayHasKey('delivered', $status['timestamps']);
        $this->assertArrayHasKey('attempts', $status);
        $this->assertEquals(1, $status['attempts']);
        $this->assertArrayHasKey('vendorMetadata', $status);
    }

    /**
     * Tests handling of invalid payload validation.
     */
    public function testInvalidPayloadValidation(): void
    {
        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        // Test with missing required fields
        $payload = ['content' => ['subject' => 'Test']];

        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_INVALID_REQUEST);
        
        $this->service->send($payload);
    }
}