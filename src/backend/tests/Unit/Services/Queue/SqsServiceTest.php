<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Queue;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Aws\Sqs\SqsClient;
use Psr\Log\LoggerInterface;
use Predis\Client as Redis;
use App\Services\Queue\SqsService;
use App\Utils\CircuitBreaker;
use App\Exceptions\VendorException;
use App\Contracts\NotificationInterface;
use Aws\Result;

/**
 * Comprehensive test suite for SqsService verifying high-throughput message processing,
 * circuit breaker integration, and fault tolerance capabilities.
 *
 * @package Tests\Unit\Services\Queue
 * @version 1.0.0
 * @covers \App\Services\Queue\SqsService
 */
class SqsServiceTest extends TestCase
{
    /**
     * @var SqsService Service instance under test
     */
    private SqsService $sqsService;

    /**
     * @var MockObject&SqsClient SQS client mock
     */
    private MockObject $sqsClientMock;

    /**
     * @var MockObject&LoggerInterface Logger mock
     */
    private MockObject $loggerMock;

    /**
     * @var MockObject&Redis Redis mock
     */
    private MockObject $redisMock;

    /**
     * @var MockObject&CircuitBreaker Circuit breaker mock
     */
    private MockObject $circuitBreakerMock;

    /**
     * @var array Default test configuration
     */
    private array $testConfig = [
        'queue_url' => 'https://sqs.test.aws/test-queue',
        'tenant_id' => 'test_tenant'
    ];

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects
        $this->sqsClientMock = $this->createMock(SqsClient::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->redisMock = $this->createMock(Redis::class);
        $this->circuitBreakerMock = $this->createMock(CircuitBreaker::class);

        // Initialize service with mocks
        $this->sqsService = new SqsService(
            $this->sqsClientMock,
            $this->loggerMock,
            $this->redisMock,
            $this->testConfig
        );
    }

    /**
     * Test high-throughput batch message processing.
     */
    public function testHighThroughputBatchProcessing(): void
    {
        // Generate test batch of messages (simulating high throughput)
        $messages = array_map(
            fn($i) => [
                'body' => "test_message_{$i}",
                'attributes' => ['test_attr' => 'value']
            ],
            range(1, 100)
        );

        // Configure mock for batch send operation
        $this->sqsClientMock
            ->expects($this->exactly(10)) // 100 messages / 10 per batch
            ->method('sendMessageBatch')
            ->willReturn(new Result([
                'Successful' => array_map(
                    fn($i) => ['MessageId' => "msg_{$i}"],
                    range(1, 10)
                ),
                'Failed' => []
            ]));

        // Circuit breaker should be checked for each batch
        $this->circuitBreakerMock
            ->expects($this->exactly(10))
            ->method('isAvailable')
            ->willReturn(true);

        // Execute batch send
        $messageIds = $this->sqsService->sendBatch($messages);

        // Verify results
        $this->assertCount(100, $messageIds);
        $this->assertStringStartsWith('msg_', $messageIds[0]);
    }

    /**
     * Test circuit breaker integration and failure handling.
     */
    public function testCircuitBreakerFailureScenarios(): void
    {
        // Configure circuit breaker to be open
        $this->circuitBreakerMock
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        // Expect exception when trying to send message
        $this->expectException(VendorException::class);
        $this->expectExceptionCode(VendorException::VENDOR_CIRCUIT_OPEN);

        $this->sqsService->sendMessage([
            'body' => 'test_message',
            'attributes' => ['test_attr' => 'value']
        ]);
    }

    /**
     * Test message delivery tracking and status updates.
     */
    public function testMessageDeliveryTracking(): void
    {
        $messageId = 'test_msg_123';
        $message = [
            'body' => 'test_message',
            'attributes' => [
                'status' => NotificationInterface::STATUS_PENDING
            ]
        ];

        // Configure successful message send
        $this->sqsClientMock
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturn(new Result(['MessageId' => $messageId]));

        // Circuit breaker should allow operation
        $this->circuitBreakerMock
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        // Record successful delivery
        $this->circuitBreakerMock
            ->expects($this->once())
            ->method('recordSuccess');

        // Send message and verify tracking
        $resultId = $this->sqsService->sendMessage($message);
        $this->assertEquals($messageId, $resultId);
    }

    /**
     * Test vendor failover scenarios and retry logic.
     */
    public function testVendorFailoverScenarios(): void
    {
        // Configure SQS client to fail first attempt
        $this->sqsClientMock
            ->expects($this->exactly(2))
            ->method('sendMessage')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \Exception('Network error')),
                new Result(['MessageId' => 'retry_msg_123'])
            );

        // Circuit breaker should allow retry
        $this->circuitBreakerMock
            ->expects($this->exactly(2))
            ->method('isAvailable')
            ->willReturn(true);

        // Record failure then success
        $this->circuitBreakerMock
            ->expects($this->once())
            ->method('recordFailure');
        $this->circuitBreakerMock
            ->expects($this->once())
            ->method('recordSuccess');

        // Attempt message send with retry
        $messageId = $this->sqsService->sendMessage([
            'body' => 'test_message',
            'attributes' => ['retry_enabled' => true]
        ]);

        $this->assertEquals('retry_msg_123', $messageId);
    }

    /**
     * Test batch message receiving functionality.
     */
    public function testBatchMessageReceiving(): void
    {
        $testMessages = [
            ['MessageId' => 'msg_1', 'Body' => 'test_1'],
            ['MessageId' => 'msg_2', 'Body' => 'test_2']
        ];

        // Configure successful message receipt
        $this->sqsClientMock
            ->expects($this->once())
            ->method('receiveMessage')
            ->with([
                'QueueUrl' => $this->testConfig['queue_url'],
                'MaxNumberOfMessages' => 10,
                'WaitTimeSeconds' => 20,
                'VisibilityTimeout' => 30,
                'AttributeNames' => ['All'],
                'MessageAttributeNames' => ['All']
            ])
            ->willReturn(new Result(['Messages' => $testMessages]));

        // Circuit breaker should allow operation
        $this->circuitBreakerMock
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        // Receive messages and verify
        $messages = $this->sqsService->receiveMessages();
        $this->assertCount(2, $messages);
        $this->assertEquals('test_1', $messages[0]['Body']);
    }

    /**
     * Test message deletion functionality.
     */
    public function testMessageDeletion(): void
    {
        $receiptHandle = 'test_receipt_123';

        // Configure successful message deletion
        $this->sqsClientMock
            ->expects($this->once())
            ->method('deleteMessage')
            ->with([
                'QueueUrl' => $this->testConfig['queue_url'],
                'ReceiptHandle' => $receiptHandle
            ])
            ->willReturn(new Result([]));

        // Circuit breaker should allow operation
        $this->circuitBreakerMock
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        // Delete message and verify
        $result = $this->sqsService->deleteMessage($receiptHandle);
        $this->assertTrue($result);
    }

    /**
     * Test invalid configuration handling.
     */
    public function testInvalidConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        
        new SqsService(
            $this->sqsClientMock,
            $this->loggerMock,
            $this->redisMock,
            [] // Empty config should trigger validation error
        );
    }

    /**
     * Test rate limiting and throughput controls.
     */
    public function testRateLimitingAndThroughput(): void
    {
        $messages = array_map(
            fn($i) => ['body' => "test_{$i}"],
            range(1, 1000) // Test with 1000 messages
        );

        // Configure mock for batch operations
        $this->sqsClientMock
            ->expects($this->exactly(100)) // 1000 messages / 10 per batch
            ->method('sendMessageBatch')
            ->willReturn(new Result([
                'Successful' => array_map(
                    fn($i) => ['MessageId' => "msg_{$i}"],
                    range(1, 10)
                ),
                'Failed' => []
            ]));

        // Circuit breaker checks
        $this->circuitBreakerMock
            ->expects($this->exactly(100))
            ->method('isAvailable')
            ->willReturn(true);

        // Send messages in batches
        $messageIds = $this->sqsService->sendBatch($messages);

        // Verify all messages were processed
        $this->assertCount(1000, $messageIds);
    }
}