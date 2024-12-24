<?php

declare(strict_types=1);

namespace App\Test\Utils;

use App\Services\Queue\SqsService;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Test utility class for managing and testing AWS SQS queues with support for 
 * high-throughput testing and performance monitoring.
 *
 * Supports testing of:
 * - 100,000+ messages per minute throughput
 * - Queue setup and configuration
 * - Batch message operations
 * - Performance metrics collection
 * - Delivery success rates
 *
 * @package App\Test\Utils
 * @version 1.0.0
 */
class QueueHelper
{
    /**
     * Test queue configuration constants
     */
    private const TEST_QUEUE_PREFIX = 'test_queue_';
    private const MAX_POLL_ATTEMPTS = 10;
    private const POLL_INTERVAL_SECONDS = 1;
    private const DEFAULT_BATCH_SIZE = 100;
    private const MAX_CONCURRENT_TESTS = 5;

    /**
     * Performance thresholds
     */
    private const MIN_SUCCESS_RATE = 0.999; // 99.9% success rate requirement
    private const MAX_LATENCY_MS = 30000; // 30 seconds max latency
    private const TARGET_THROUGHPUT = 100000; // messages per minute

    /**
     * @var SqsService SQS service instance
     */
    private SqsService $sqsService;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var array Active test queues tracking
     */
    private array $testQueues = [];

    /**
     * @var array Performance metrics collection
     */
    private array $performanceMetrics = [
        'message_counts' => [],
        'latencies' => [],
        'success_rates' => [],
        'throughput_rates' => [],
        'start_time' => null,
        'end_time' => null
    ];

    /**
     * @var array Default queue attributes
     */
    private array $queueAttributes = [
        'VisibilityTimeout' => 30,
        'MessageRetentionPeriod' => 3600, // 1 hour for test messages
        'ReceiveMessageWaitTimeSeconds' => 20
    ];

    /**
     * Initialize queue helper with required dependencies.
     *
     * @param SqsService $sqsService SQS service instance
     * @param LoggerInterface $logger Logger for operation tracking
     */
    public function __construct(
        SqsService $sqsService,
        LoggerInterface $logger
    ) {
        $this->sqsService = $sqsService;
        $this->logger = $logger;
        $this->performanceMetrics['start_time'] = microtime(true);
    }

    /**
     * Creates and configures a test queue with specified attributes.
     *
     * @param string $queueName Base name for the test queue
     * @param array $attributes Optional queue attributes
     * @return string Queue URL
     * @throws RuntimeException If queue creation fails
     */
    public function setupTestQueue(string $queueName, array $attributes = []): string
    {
        $uniqueQueueName = self::TEST_QUEUE_PREFIX . $queueName . '_' . uniqid();
        
        try {
            // Merge default and custom attributes
            $queueAttributes = array_merge($this->queueAttributes, $attributes);
            
            // Create queue using SQS service
            $queueUrl = $this->sqsService->createQueue([
                'QueueName' => $uniqueQueueName,
                'Attributes' => $queueAttributes
            ]);

            // Track test queue
            $this->testQueues[$queueUrl] = [
                'name' => $uniqueQueueName,
                'attributes' => $queueAttributes,
                'created_at' => microtime(true)
            ];

            $this->logger->info('Test queue created', [
                'queue_name' => $uniqueQueueName,
                'queue_url' => $queueUrl
            ]);

            return $queueUrl;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create test queue', [
                'queue_name' => $uniqueQueueName,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to create test queue: ' . $e->getMessage());
        }
    }

    /**
     * Sends a batch of test messages to specified queue with performance tracking.
     *
     * @param string $queueUrl Target queue URL
     * @param array $messages Array of messages to send
     * @param int $batchSize Size of each batch (default: 100)
     * @return array Batch send results with metrics
     * @throws InvalidArgumentException If queue not found
     * @throws RuntimeException If send operation fails
     */
    public function sendBatchMessages(
        string $queueUrl,
        array $messages,
        int $batchSize = self::DEFAULT_BATCH_SIZE
    ): array {
        if (!isset($this->testQueues[$queueUrl])) {
            throw new InvalidArgumentException('Invalid test queue URL');
        }

        $results = [
            'successful' => 0,
            'failed' => 0,
            'latency_ms' => 0,
            'throughput' => 0
        ];

        try {
            $startTime = microtime(true);
            $batches = array_chunk($messages, $batchSize);

            foreach ($batches as $batch) {
                $batchResult = $this->sqsService->sendMessageBatch([
                    'QueueUrl' => $queueUrl,
                    'Entries' => array_map(function ($message) {
                        return [
                            'Id' => uniqid('msg_'),
                            'MessageBody' => json_encode($message),
                            'MessageAttributes' => [
                                'timestamp' => [
                                    'DataType' => 'Number',
                                    'StringValue' => (string)time()
                                ]
                            ]
                        ];
                    }, $batch)
                ]);

                // Track results
                $results['successful'] += count($batchResult['Successful'] ?? []);
                $results['failed'] += count($batchResult['Failed'] ?? []);
            }

            // Calculate metrics
            $endTime = microtime(true);
            $duration = $endTime - $startTime;
            $results['latency_ms'] = $duration * 1000;
            $results['throughput'] = count($messages) / $duration;

            // Store metrics
            $this->trackPerformanceMetrics($queueUrl, $results);

            $this->logger->info('Batch messages sent', [
                'queue_url' => $queueUrl,
                'total_messages' => count($messages),
                'successful' => $results['successful'],
                'failed' => $results['failed'],
                'latency_ms' => $results['latency_ms']
            ]);

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send batch messages', [
                'queue_url' => $queueUrl,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to send batch messages: ' . $e->getMessage());
        }
    }

    /**
     * Waits for and retrieves messages with performance tracking.
     *
     * @param string $queueUrl Queue to poll
     * @param int $expectedCount Expected message count
     * @param int $timeoutSeconds Maximum wait time
     * @return array Retrieved messages with performance data
     * @throws InvalidArgumentException If queue not found
     * @throws RuntimeException If receive operation fails
     */
    public function waitForMessagesWithMetrics(
        string $queueUrl,
        int $expectedCount,
        int $timeoutSeconds = 30
    ): array {
        if (!isset($this->testQueues[$queueUrl])) {
            throw new InvalidArgumentException('Invalid test queue URL');
        }

        $messages = [];
        $startTime = microtime(true);
        $attempts = 0;

        try {
            while (count($messages) < $expectedCount && $attempts < self::MAX_POLL_ATTEMPTS) {
                $result = $this->sqsService->receiveMessages([
                    'QueueUrl' => $queueUrl,
                    'MaxNumberOfMessages' => min(10, $expectedCount - count($messages)),
                    'WaitTimeSeconds' => self::POLL_INTERVAL_SECONDS
                ]);

                if (!empty($result['Messages'])) {
                    $messages = array_merge($messages, $result['Messages']);
                }

                $attempts++;
                
                if ((microtime(true) - $startTime) > $timeoutSeconds) {
                    break;
                }
            }

            $endTime = microtime(true);
            $metrics = [
                'messages_received' => count($messages),
                'expected_count' => $expectedCount,
                'attempts' => $attempts,
                'duration_ms' => ($endTime - $startTime) * 1000,
                'success_rate' => count($messages) / $expectedCount
            ];

            $this->trackPerformanceMetrics($queueUrl, $metrics);

            return [
                'messages' => $messages,
                'metrics' => $metrics
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to receive messages', [
                'queue_url' => $queueUrl,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to receive messages: ' . $e->getMessage());
        }
    }

    /**
     * Retrieves performance metrics for specified queue.
     *
     * @param string $queueUrl Queue URL
     * @return array Queue performance metrics
     * @throws InvalidArgumentException If queue not found
     */
    public function getQueueMetrics(string $queueUrl): array
    {
        if (!isset($this->testQueues[$queueUrl])) {
            throw new InvalidArgumentException('Invalid test queue URL');
        }

        return [
            'queue_info' => $this->testQueues[$queueUrl],
            'performance' => [
                'message_counts' => $this->performanceMetrics['message_counts'][$queueUrl] ?? [],
                'latencies' => $this->performanceMetrics['latencies'][$queueUrl] ?? [],
                'success_rates' => $this->performanceMetrics['success_rates'][$queueUrl] ?? [],
                'throughput_rates' => $this->performanceMetrics['throughput_rates'][$queueUrl] ?? [],
                'duration' => microtime(true) - $this->performanceMetrics['start_time']
            ]
        ];
    }

    /**
     * Tracks performance metrics for a queue operation.
     *
     * @param string $queueUrl Queue URL
     * @param array $metrics Operation metrics
     * @return void
     */
    private function trackPerformanceMetrics(string $queueUrl, array $metrics): void
    {
        if (!isset($this->performanceMetrics['message_counts'][$queueUrl])) {
            $this->performanceMetrics['message_counts'][$queueUrl] = [];
            $this->performanceMetrics['latencies'][$queueUrl] = [];
            $this->performanceMetrics['success_rates'][$queueUrl] = [];
            $this->performanceMetrics['throughput_rates'][$queueUrl] = [];
        }

        // Track message counts
        $this->performanceMetrics['message_counts'][$queueUrl][] = 
            $metrics['successful'] ?? $metrics['messages_received'] ?? 0;

        // Track latencies
        if (isset($metrics['latency_ms']) || isset($metrics['duration_ms'])) {
            $this->performanceMetrics['latencies'][$queueUrl][] = 
                $metrics['latency_ms'] ?? $metrics['duration_ms'];
        }

        // Track success rates
        if (isset($metrics['successful']) && isset($metrics['failed'])) {
            $total = $metrics['successful'] + $metrics['failed'];
            $this->performanceMetrics['success_rates'][$queueUrl][] = 
                $total > 0 ? $metrics['successful'] / $total : 0;
        }

        // Track throughput
        if (isset($metrics['throughput'])) {
            $this->performanceMetrics['throughput_rates'][$queueUrl][] = $metrics['throughput'];
        }
    }
}