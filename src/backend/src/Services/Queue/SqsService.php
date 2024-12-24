<?php

declare(strict_types=1);

namespace App\Services\Queue;

use Aws\Sqs\SqsClient;
use Psr\Log\LoggerInterface;
use Predis\Client as Redis;
use App\Contracts\NotificationInterface;
use App\Utils\CircuitBreaker;
use App\Exceptions\VendorException;

/**
 * Enterprise-grade AWS SQS queue service implementing high-throughput message processing
 * with support for batching, dead-letter queues, and circuit breaker pattern.
 *
 * @package App\Services\Queue
 * @version 1.0.0
 */
class SqsService
{
    /**
     * Maximum messages per batch as per AWS limits
     */
    private const MAX_BATCH_SIZE = 10;

    /**
     * Default batch wait time in seconds
     */
    private const BATCH_WAIT_TIME = 5;

    /**
     * Message visibility timeout in seconds
     */
    private const VISIBILITY_TIMEOUT = 30;

    /**
     * @var SqsClient AWS SQS client instance
     */
    private SqsClient $client;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var Redis Redis client for caching and rate limiting
     */
    private Redis $redis;

    /**
     * @var CircuitBreaker Circuit breaker for SQS operations
     */
    private CircuitBreaker $circuitBreaker;

    /**
     * @var array Queue configuration
     */
    private array $config;

    /**
     * @var array Message buffer for batch operations
     */
    private array $messageBuffer = [];

    /**
     * @var array Operation metrics
     */
    private array $metrics = [
        'messages_sent' => 0,
        'batch_operations' => 0,
        'errors' => 0,
        'last_batch_time' => null,
    ];

    /**
     * Initializes the SQS service with dependencies and configuration.
     *
     * @param SqsClient $client AWS SQS client
     * @param LoggerInterface $logger Logger instance
     * @param Redis $redis Redis client
     * @param array $config Queue configuration
     */
    public function __construct(
        SqsClient $client,
        LoggerInterface $logger,
        Redis $redis,
        array $config
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->redis = $redis;
        $this->config = $this->validateConfig($config);

        $this->circuitBreaker = new CircuitBreaker(
            $this->redis,
            $this->logger,
            'aws_sqs',
            'queue',
            $config['tenant_id'] ?? 'default'
        );
    }

    /**
     * Sends a single message to SQS queue with batching support.
     *
     * @param array $message Message data
     * @param array $options Message options
     * @return string Message ID
     * @throws VendorException If queue operation fails
     */
    public function sendMessage(array $message, array $options = []): string
    {
        if (!$this->circuitBreaker->isAvailable()) {
            throw new VendorException(
                'SQS queue is currently unavailable',
                VendorException::VENDOR_CIRCUIT_OPEN,
                null,
                [
                    'vendor_name' => 'aws_sqs',
                    'channel' => 'queue',
                    'circuit_breaker_open' => true
                ]
            );
        }

        $messageData = $this->formatMessage($message, $options);
        $this->messageBuffer[] = $messageData;

        if ($this->shouldFlushBuffer()) {
            return $this->flushMessageBuffer()[0] ?? '';
        }

        return $messageData['Id'];
    }

    /**
     * Sends multiple messages to SQS queue in optimized batches.
     *
     * @param array $messages Array of messages
     * @return array Array of message IDs
     * @throws VendorException If queue operation fails
     */
    public function sendBatch(array $messages): array
    {
        if (!$this->circuitBreaker->isAvailable()) {
            throw new VendorException(
                'SQS queue is currently unavailable',
                VendorException::VENDOR_CIRCUIT_OPEN,
                null,
                [
                    'vendor_name' => 'aws_sqs',
                    'channel' => 'queue',
                    'circuit_breaker_open' => true
                ]
            );
        }

        $messageIds = [];
        $batches = array_chunk($messages, self::MAX_BATCH_SIZE);

        foreach ($batches as $batch) {
            $this->messageBuffer = array_map(
                fn($message) => $this->formatMessage($message),
                $batch
            );
            $messageIds = array_merge($messageIds, $this->flushMessageBuffer());
        }

        return $messageIds;
    }

    /**
     * Receives messages from SQS queue with optimized polling.
     *
     * @param int $maxMessages Maximum messages to receive
     * @param int $waitTime Wait time in seconds
     * @return array Array of received messages
     * @throws VendorException If queue operation fails
     */
    public function receiveMessages(int $maxMessages = 10, int $waitTime = 20): array
    {
        if (!$this->circuitBreaker->isAvailable()) {
            throw new VendorException(
                'SQS queue is currently unavailable',
                VendorException::VENDOR_CIRCUIT_OPEN,
                null,
                [
                    'vendor_name' => 'aws_sqs',
                    'channel' => 'queue',
                    'circuit_breaker_open' => true
                ]
            );
        }

        try {
            $result = $this->client->receiveMessage([
                'QueueUrl' => $this->config['queue_url'],
                'MaxNumberOfMessages' => min($maxMessages, self::MAX_BATCH_SIZE),
                'WaitTimeSeconds' => $waitTime,
                'VisibilityTimeout' => self::VISIBILITY_TIMEOUT,
                'AttributeNames' => ['All'],
                'MessageAttributeNames' => ['All']
            ]);

            $this->circuitBreaker->recordSuccess();
            return $result->get('Messages') ?? [];

        } catch (\Exception $e) {
            $this->circuitBreaker->recordFailure();
            $this->logger->error('Failed to receive messages from SQS', [
                'error' => $e->getMessage(),
                'queue_url' => $this->config['queue_url']
            ]);
            throw new VendorException(
                'Failed to receive messages from SQS',
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                [
                    'vendor_name' => 'aws_sqs',
                    'channel' => 'queue',
                    'error_message' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Deletes a processed message from the queue.
     *
     * @param string $receiptHandle Message receipt handle
     * @return bool True if deletion successful
     * @throws VendorException If deletion fails
     */
    public function deleteMessage(string $receiptHandle): bool
    {
        if (!$this->circuitBreaker->isAvailable()) {
            throw new VendorException(
                'SQS queue is currently unavailable',
                VendorException::VENDOR_CIRCUIT_OPEN,
                null,
                [
                    'vendor_name' => 'aws_sqs',
                    'channel' => 'queue',
                    'circuit_breaker_open' => true
                ]
            );
        }

        try {
            $this->client->deleteMessage([
                'QueueUrl' => $this->config['queue_url'],
                'ReceiptHandle' => $receiptHandle
            ]);

            $this->circuitBreaker->recordSuccess();
            return true;

        } catch (\Exception $e) {
            $this->circuitBreaker->recordFailure();
            $this->logger->error('Failed to delete message from SQS', [
                'error' => $e->getMessage(),
                'receipt_handle' => $receiptHandle
            ]);
            throw new VendorException(
                'Failed to delete message from SQS',
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                [
                    'vendor_name' => 'aws_sqs',
                    'channel' => 'queue',
                    'error_message' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Flushes accumulated messages to SQS in optimized batches.
     *
     * @return array Array of sent message IDs
     * @throws VendorException If batch send fails
     */
    private function flushMessageBuffer(): array
    {
        if (empty($this->messageBuffer)) {
            return [];
        }

        try {
            $result = $this->client->sendMessageBatch([
                'QueueUrl' => $this->config['queue_url'],
                'Entries' => $this->messageBuffer
            ]);

            $this->metrics['batch_operations']++;
            $this->metrics['messages_sent'] += count($this->messageBuffer);
            $this->metrics['last_batch_time'] = time();

            $this->messageBuffer = [];
            $this->circuitBreaker->recordSuccess();

            return array_map(
                fn($success) => $success['MessageId'],
                $result->get('Successful') ?? []
            );

        } catch (\Exception $e) {
            $this->metrics['errors']++;
            $this->circuitBreaker->recordFailure();
            $this->logger->error('Failed to send message batch to SQS', [
                'error' => $e->getMessage(),
                'batch_size' => count($this->messageBuffer)
            ]);
            throw new VendorException(
                'Failed to send message batch to SQS',
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                [
                    'vendor_name' => 'aws_sqs',
                    'channel' => 'queue',
                    'error_message' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Formats a message for SQS with required attributes.
     *
     * @param array $message Message data
     * @param array $options Message options
     * @return array Formatted message
     */
    private function formatMessage(array $message, array $options = []): array
    {
        $messageId = uniqid('msg_', true);
        return [
            'Id' => $messageId,
            'MessageBody' => json_encode($message),
            'DelaySeconds' => $options['delay'] ?? 0,
            'MessageAttributes' => [
                'status' => [
                    'DataType' => 'String',
                    'StringValue' => NotificationInterface::STATUS_PENDING
                ],
                'timestamp' => [
                    'DataType' => 'Number',
                    'StringValue' => (string)time()
                ]
            ]
        ];
    }

    /**
     * Determines if message buffer should be flushed.
     *
     * @return bool True if buffer should be flushed
     */
    private function shouldFlushBuffer(): bool
    {
        return count($this->messageBuffer) >= self::MAX_BATCH_SIZE ||
            (count($this->messageBuffer) > 0 && 
             time() - ($this->metrics['last_batch_time'] ?? 0) >= self::BATCH_WAIT_TIME);
    }

    /**
     * Validates and normalizes queue configuration.
     *
     * @param array $config Raw configuration
     * @return array Validated configuration
     * @throws \InvalidArgumentException If configuration is invalid
     */
    private function validateConfig(array $config): array
    {
        if (empty($config['queue_url'])) {
            throw new \InvalidArgumentException('Queue URL is required in configuration');
        }

        return array_merge([
            'tenant_id' => 'default',
            'batch_size' => self::MAX_BATCH_SIZE,
            'wait_time' => self::BATCH_WAIT_TIME,
            'visibility_timeout' => self::VISIBILITY_TIMEOUT
        ], $config);
    }
}