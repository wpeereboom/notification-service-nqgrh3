<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\CircuitBreaker;
use App\Exceptions\VendorException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;

/**
 * Comprehensive test suite for CircuitBreaker implementation.
 * Tests all states, transitions, and edge cases for vendor fault tolerance.
 *
 * @package Tests\Unit\Utils
 * @version 1.0.0
 * @covers \App\Utils\CircuitBreaker
 */
class CircuitBreakerTest extends TestCase
{
    private const VENDOR_NAME = 'test-vendor';
    private const CHANNEL = 'email';
    private const TENANT_ID = 'test-tenant';
    private const REDIS_KEY = 'circuit_breaker:test-tenant:email:test-vendor';

    private MockObject&Redis $redisMock;
    private MockObject&LoggerInterface $loggerMock;
    private CircuitBreaker $circuitBreaker;

    /**
     * Set up test environment before each test case.
     */
    protected function setUp(): void
    {
        // Create Redis mock with multi/exec transaction support
        $this->redisMock = $this->createMock(Redis::class);
        $this->redisMock->method('multi')->willReturn($this->redisMock);
        $this->redisMock->method('exec')->willReturn([true]);

        // Create Logger mock
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        // Initialize CircuitBreaker instance
        $this->circuitBreaker = new CircuitBreaker(
            $this->redisMock,
            $this->loggerMock,
            self::VENDOR_NAME,
            self::CHANNEL,
            self::TENANT_ID
        );
    }

    /**
     * @test
     * Verify circuit breaker initializes in closed state.
     */
    public function testInitialStateIsClosed(): void
    {
        // Setup Redis mock to return initial state
        $this->redisMock->method('hgetall')
            ->with(self::REDIS_KEY)
            ->willReturn([
                'state' => 'closed',
                'failure_count' => '0',
                'last_failure_time' => null,
                'last_success_time' => null
            ]);

        // Assert initial state
        $this->assertTrue($this->circuitBreaker->isAvailable());
        $state = $this->circuitBreaker->getState();
        $this->assertEquals('closed', $state['state']);
        $this->assertEquals(0, $state['failure_count']);
        $this->assertNull($state['last_failure_time']);
    }

    /**
     * @test
     * Verify circuit opens after threshold failures.
     */
    public function testCircuitOpensAfterThresholdFailures(): void
    {
        // Setup Redis mock for failure tracking
        $this->redisMock->method('hgetall')
            ->willReturnOnConsecutiveCalls(
                // First 4 failures
                ['state' => 'closed', 'failure_count' => '0'],
                ['state' => 'closed', 'failure_count' => '1'],
                ['state' => 'closed', 'failure_count' => '2'],
                ['state' => 'closed', 'failure_count' => '3'],
                ['state' => 'closed', 'failure_count' => '4'],
                // Fifth failure triggers open state
                ['state' => 'open', 'failure_count' => '5']
            );

        // Record failures until threshold
        for ($i = 0; $i < 4; $i++) {
            $this->circuitBreaker->recordFailure();
        }

        // Expect exception on threshold breach
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_CIRCUIT_OPEN);
        
        $this->circuitBreaker->recordFailure();

        // Verify circuit is open
        $this->assertFalse($this->circuitBreaker->isAvailable());
    }

    /**
     * @test
     * Verify half-open state behavior and retry timeout.
     */
    public function testHalfOpenStateAndRetryTimeout(): void
    {
        $currentTime = time();
        
        // Setup Redis mock for state transition
        $this->redisMock->method('hgetall')
            ->willReturnOnConsecutiveCalls(
                // Initial open state
                [
                    'state' => 'open',
                    'failure_count' => '5',
                    'last_failure_time' => (string)($currentTime - 31) // Just past timeout
                ],
                // Transitioned to half-open
                [
                    'state' => 'half_open',
                    'failure_count' => '5',
                    'last_failure_time' => (string)($currentTime - 31)
                ]
            );

        // Verify circuit allows single test request
        $this->assertTrue($this->circuitBreaker->isAvailable());

        // Verify successful request closes circuit
        $this->redisMock->expects($this->once())
            ->method('hset')
            ->with(
                self::REDIS_KEY,
                'state',
                'closed'
            );

        $this->circuitBreaker->recordSuccess();
    }

    /**
     * @test
     * Verify exponential backoff in retry attempts.
     */
    public function testExponentialBackoffInHalfOpen(): void
    {
        $currentTime = time();
        $baseTimeout = 30; // RESET_TIMEOUT_SECONDS constant

        // Test increasing timeouts for consecutive failures
        $failureCounts = [5, 6, 7, 8];
        $expectedTimeouts = [
            $baseTimeout,
            $baseTimeout * 2,
            $baseTimeout * 4,
            $baseTimeout * 8
        ];

        foreach ($failureCounts as $index => $failureCount) {
            $this->redisMock->method('hgetall')
                ->willReturn([
                    'state' => 'open',
                    'failure_count' => (string)$failureCount,
                    'last_failure_time' => (string)($currentTime - $expectedTimeouts[$index] + 1)
                ]);

            // Circuit should remain closed until timeout expires
            $this->assertFalse($this->circuitBreaker->isAvailable());
        }
    }

    /**
     * @test
     * Verify atomic state transitions in distributed environment.
     */
    public function testAtomicStateTransitions(): void
    {
        // Setup Redis mock for atomic operations
        $this->redisMock->expects($this->once())
            ->method('multi');
        
        $this->redisMock->expects($this->exactly(3))
            ->method('hset')
            ->withConsecutive(
                [self::REDIS_KEY, 'failure_count', 0],
                [self::REDIS_KEY, 'last_success_time', $this->anything()],
                [self::REDIS_KEY, 'state', 'closed']
            );

        $this->redisMock->expects($this->once())
            ->method('exec');

        // Simulate successful state transition
        $this->circuitBreaker->recordSuccess();
    }

    /**
     * @test
     * Verify proper reset functionality.
     */
    public function testCircuitReset(): void
    {
        // Expect Redis calls for reset
        $this->redisMock->expects($this->exactly(3))
            ->method('hset')
            ->withConsecutive(
                [self::REDIS_KEY, 'state', 'closed'],
                [self::REDIS_KEY, 'failure_count', 0],
                [self::REDIS_KEY, 'last_failure_time', null]
            );

        // Expect log message for reset
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Circuit breaker manually reset',
                $this->callback(function ($context) {
                    return $context['vendor'] === self::VENDOR_NAME
                        && $context['channel'] === self::CHANNEL
                        && $context['tenant_id'] === self::TENANT_ID;
                })
            );

        $this->circuitBreaker->reset();
    }

    /**
     * @test
     * Verify proper state logging.
     */
    public function testStateTransitionLogging(): void
    {
        // Setup Redis mock for failure threshold
        $this->redisMock->method('hgetall')
            ->willReturn([
                'state' => 'closed',
                'failure_count' => '4',
                'last_failure_time' => null
            ]);

        // Expect state transition log
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Circuit breaker state changed from closed to open',
                $this->callback(function ($context) {
                    return $context['vendor'] === self::VENDOR_NAME
                        && $context['from_state'] === 'closed'
                        && $context['to_state'] === 'open';
                })
            );

        // Trigger state change
        try {
            $this->circuitBreaker->recordFailure();
        } catch (VendorException $e) {
            // Expected exception
        }
    }
}