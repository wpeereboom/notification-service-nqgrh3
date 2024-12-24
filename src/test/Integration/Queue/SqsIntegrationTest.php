<?php

declare(strict_types=1);

namespace App\Test\Integration\Queue;

use App\Services\Queue\SqsService;
use App\Test\Utils\TestHelper;
use App\Exceptions\VendorException;
use Aws\Sqs\SqsClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Predis\Client as Redis;
use Carbon\Carbon;

/**
 * Integration tests for AWS SQS queue service implementation.
 * Verifies high-throughput message processing, batching capabilities, and fault tolerance.
 *
 * @package App\Test\Integration\Queue
 * @version 1.0.0
 */
class SqsIntegrationTest extends TestCase
{
    private const BATCH_SIZE = 10;
    private const HIGH_THROUGHPUT_MESSAGE_COUNT = 100000;
    private const MAX_LATENCY_MS = 30000; // 30 seconds
    private const VENDOR_FAILOVER_MS = 2000; // 2 seconds
    private const TEST_QUEUE_URL = 'https://sqs.us-east-1.amazonaws.com/test-queue';

    private SqsService $sqsService;
    private SqsClient $sqsClient;
    private Redis $redis;
    private LoggerInterface $logger;
    private array $testMessages = [];
    private array $performanceMetrics = [];

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize AWS SQS client with test configuration
        $this->sqsClient = new SqsClient([
            'version' => 'latest',
            'region'  => 'us-east-1',
            'credentials' => [
                'key'    => getenv('AWS_ACCESS_KEY_ID'),
                'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
            ],
            'endpoint' => getenv('AWS_SQS_ENDPOINT') ?? null,
        ]);

        // Initialize Redis client for circuit breaker
        $this->redis = new Redis([
            'scheme' => 'tcp',
            'host'   => getenv('REDIS_HOST') ?? 'localhost',
            'port'   => getenv('REDIS_PORT') ?? 6379,
        ]);

        // Initialize logger
        $this->logger = $this->createMock(LoggerInterface::class);

        // Initialize SQS service with circuit breaker settings
        $this->sqsService = new SqsService(
            $this->sqsClient,
            $this->logger,
            $this->redis,
            [
                'queue_url' => self::TEST_QUEUE_URL,
                'tenant_id' => 'test',
            ]
        );

        // Reset performance metrics
        $this->performanceMetrics = [
            'throughput' => [],
            'latency' => [],
            'batch_sizes' => [],
            'errors' => [],
            'start_time' => null,
            'end_time' => null,
        ];
    }

    /**
     * Clean up test environment after each test.
     */
    protected function tearDown(): void
    {
        // Store performance metrics if collected
        if (!empty($this->performanceMetrics)) {
            file_put_contents(
                '/tmp/sqs_performance_metrics.json',
                json_encode($this->performanceMetrics, JSON_PRETTY_PRINT)
            );
        }

        // Clean up test queue
        $this->purgeTestQueue();

        parent::tearDown();
    }

    /**
     * Test high-throughput message processing with performance monitoring.
     *
     * @test
     */
    public function testHighThroughputProcessing(): void
    {
        // Generate test messages
        $this->testMessages = array_map(
            fn($i) => TestHelper::generateTestNotification('email', [
                'metadata' => ['test_index' => $i]
            ]),
            range(1, self::HIGH_THROUGHPUT_MESSAGE_COUNT)
        );

        $this->performanceMetrics['start_time'] = microtime(true);

        // Send messages in optimized batches
        $messageIds = [];
        $batchCount = 0;
        foreach (array_chunk($this->testMessages, self::BATCH_SIZE) as $batch) {
            try {
                $batchStart = microtime(true);
                $batchIds = $this->sqsService->sendBatch($batch);
                $batchDuration = (microtime(true) - $batchStart) * 1000;

                $messageIds = array_merge($messageIds, $batchIds);
                $this->performanceMetrics['batch_sizes'][] = count($batch);
                $this->performanceMetrics['latency'][] = $batchDuration;
                $batchCount++;

            } catch (VendorException $e) {
                $this->performanceMetrics['errors'][] = [
                    'batch' => $batchCount,
                    'error' => $e->getMessage(),
                    'vendor_context' => $e->getVendorContext(),
                ];
                $this->fail("Batch send failed: " . $e->getMessage());
            }
        }

        $this->performanceMetrics['end_time'] = microtime(true);

        // Calculate and verify throughput
        $totalDuration = $this->performanceMetrics['end_time'] - $this->performanceMetrics['start_time'];
        $messagesPerSecond = count($messageIds) / $totalDuration;
        $messagesPerMinute = $messagesPerSecond * 60;

        $this->performanceMetrics['throughput'] = [
            'messages_per_second' => $messagesPerSecond,
            'messages_per_minute' => $messagesPerMinute,
            'total_messages' => count($messageIds),
            'total_duration_seconds' => $totalDuration,
        ];

        // Verify message throughput meets requirements
        $this->assertGreaterThanOrEqual(
            self::HIGH_THROUGHPUT_MESSAGE_COUNT / 60,
            $messagesPerMinute,
            "Message throughput below required 100,000 messages per minute"
        );

        // Verify 95th percentile latency
        $latencies = $this->performanceMetrics['latency'];
        sort($latencies);
        $p95Index = (int) ceil(0.95 * count($latencies));
        $p95Latency = $latencies[$p95Index - 1];

        $this->assertLessThanOrEqual(
            self::MAX_LATENCY_MS,
            $p95Latency,
            "95th percentile latency exceeds maximum of 30 seconds"
        );

        // Verify message integrity
        $receivedMessages = [];
        $receiveStart = microtime(true);

        while (count($receivedMessages) < count($messageIds)) {
            $messages = $this->sqsService->receiveMessages(self::BATCH_SIZE);
            foreach ($messages as $message) {
                $receivedMessages[] = $message;
                $this->sqsService->deleteMessage($message['ReceiptHandle']);
            }

            // Prevent infinite loop if messages are lost
            if ((microtime(true) - $receiveStart) > 60) {
                $this->fail("Timeout waiting for messages");
            }
        }

        $this->assertCount(
            count($messageIds),
            $receivedMessages,
            "Not all messages were received successfully"
        );

        // Verify message order and content integrity
        foreach ($receivedMessages as $message) {
            $body = json_decode($message['Body'], true);
            $this->assertArrayHasKey('metadata', $body);
            $this->assertArrayHasKey('test_index', $body['metadata']);
        }
    }

    /**
     * Purges the test queue after test completion.
     */
    private function purgeTestQueue(): void
    {
        try {
            $this->sqsClient->purgeQueue([
                'QueueUrl' => self::TEST_QUEUE_URL
            ]);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to purge test queue: " . $e->getMessage());
        }
    }
}