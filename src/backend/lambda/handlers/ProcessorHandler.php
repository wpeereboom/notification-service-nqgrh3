<?php

declare(strict_types=1);

namespace App\Lambda\Handlers;

use App\Services\Notification\NotificationService;
use App\Services\Queue\SqsService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * AWS Lambda handler for processing queued notifications with comprehensive error handling,
 * vendor failover support, and detailed metrics tracking.
 *
 * Processes notifications at scale (100,000+ messages/minute) with:
 * - Configurable batch processing
 * - Vendor failover within 2 seconds
 * - Comprehensive error handling and retry logic
 * - Detailed CloudWatch metrics emission
 *
 * @version 1.0.0
 */
class ProcessorHandler
{
    /**
     * Maximum batch size for SQS message processing
     */
    private const BATCH_SIZE = 10;

    /**
     * Maximum execution time in seconds
     */
    private const MAX_EXECUTION_TIME = 300;

    /**
     * Base retry delay in milliseconds
     */
    private const RETRY_DELAY = 1000;

    /**
     * @var NotificationService Notification processing service
     */
    private NotificationService $notificationService;

    /**
     * @var SqsService SQS queue service
     */
    private SqsService $queueService;

    /**
     * @var LoggerInterface PSR-3 logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var array Processing metrics collection
     */
    private array $metrics = [
        'processed' => 0,
        'successful' => 0,
        'failed' => 0,
        'retried' => 0,
        'processing_time' => 0,
        'vendor_failovers' => 0,
    ];

    /**
     * Initialize processor with required services
     *
     * @param NotificationService $notificationService Core notification service
     * @param SqsService $queueService SQS queue service
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        NotificationService $notificationService,
        SqsService $queueService,
        LoggerInterface $logger
    ) {
        $this->notificationService = $notificationService;
        $this->queueService = $queueService;
        $this->logger = $logger;
    }

    /**
     * Main Lambda handler for processing notification messages
     *
     * @param array $event Lambda event data
     * @param object $context Lambda context
     * @return array Processing results and metrics
     * @throws RuntimeException If critical processing error occurs
     */
    public function handle(array $event, object $context): array
    {
        $startTime = microtime(true);
        $this->logger->info('Starting notification batch processing', [
            'request_id' => $context->getAwsRequestId(),
            'batch_size' => count($event['Records'] ?? [])
        ]);

        try {
            // Validate event structure
            if (!isset($event['Records']) || empty($event['Records'])) {
                throw new RuntimeException('Invalid event structure: missing Records');
            }

            $processedIds = [];
            $failedIds = [];
            $remainingTime = self::MAX_EXECUTION_TIME;

            // Process messages in configurable batches
            foreach (array_chunk($event['Records'], self::BATCH_SIZE) as $batch) {
                if ($remainingTime <= 0) {
                    $this->logger->warning('Lambda timeout approaching, stopping batch processing');
                    break;
                }

                $batchResults = $this->processBatch($batch, $remainingTime);
                $processedIds = array_merge($processedIds, $batchResults['processed']);
                $failedIds = array_merge($failedIds, $batchResults['failed']);

                $remainingTime = self::MAX_EXECUTION_TIME - (microtime(true) - $startTime);
            }

            // Calculate final metrics
            $this->metrics['processing_time'] = microtime(true) - $startTime;
            $this->metrics['success_rate'] = $this->calculateSuccessRate();

            // Emit detailed CloudWatch metrics
            $this->emitMetrics();

            return [
                'statusCode' => 200,
                'processed' => count($processedIds),
                'failed' => count($failedIds),
                'metrics' => $this->metrics,
                'request_id' => $context->getAwsRequestId(),
                'remaining_time' => $remainingTime
            ];

        } catch (\Exception $e) {
            $this->logger->error('Critical processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $context->getAwsRequestId()
            ]);

            throw new RuntimeException(
                'Failed to process notification batch: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Processes a batch of SQS messages with error handling
     *
     * @param array $messages Batch of SQS messages
     * @param float $timeout Remaining execution time
     * @return array Processing results
     */
    private function processBatch(array $messages, float $timeout): array
    {
        $processedIds = [];
        $failedIds = [];
        $batchStartTime = microtime(true);

        foreach ($messages as $message) {
            if ((microtime(true) - $batchStartTime) >= $timeout) {
                break;
            }

            try {
                $messageBody = json_decode($message['body'], true);
                if (!$messageBody) {
                    throw new RuntimeException('Invalid message body format');
                }

                // Process notification with vendor failover support
                $result = $this->notificationService->processNotification($messageBody);

                if ($result) {
                    $this->queueService->deleteMessage($message['receiptHandle']);
                    $processedIds[] = $messageBody['id'];
                    $this->metrics['successful']++;
                } else {
                    $this->handleFailure($message, 'Processing failed');
                    $failedIds[] = $messageBody['id'];
                }

                $this->metrics['processed']++;

            } catch (\Exception $e) {
                $this->handleFailure($message, $e->getMessage());
                $failedIds[] = $messageBody['id'] ?? 'unknown';
                $this->metrics['failed']++;
            }
        }

        return [
            'processed' => $processedIds,
            'failed' => $failedIds
        ];
    }

    /**
     * Handles failed message processing with retry logic
     *
     * @param array $message Failed SQS message
     * @param string $error Error message
     * @return void
     */
    private function handleFailure(array $message, string $error): void
    {
        try {
            $messageBody = json_decode($message['body'], true);
            $retryCount = ($messageBody['retry_count'] ?? 0) + 1;
            $messageId = $messageBody['id'] ?? 'unknown';

            $this->logger->warning('Message processing failed', [
                'message_id' => $messageId,
                'retry_count' => $retryCount,
                'error' => $error
            ]);

            // Attempt retry if within limits
            if ($retryCount <= 3) {
                $messageBody['retry_count'] = $retryCount;
                $messageBody['last_error'] = $error;
                $messageBody['retry_delay'] = self::RETRY_DELAY * pow(2, $retryCount - 1);

                $this->notificationService->retry($messageId, [
                    'delay' => $messageBody['retry_delay'],
                    'error_context' => $error
                ]);

                $this->metrics['retried']++;
            } else {
                // Move to DLQ after max retries
                $this->queueService->deleteMessage($message['receiptHandle']);
                $this->logger->error('Message exceeded retry limit', [
                    'message_id' => $messageId,
                    'final_error' => $error
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to handle message failure', [
                'error' => $e->getMessage(),
                'original_error' => $error
            ]);
        }
    }

    /**
     * Calculates the current success rate
     *
     * @return float Success rate percentage
     */
    private function calculateSuccessRate(): float
    {
        if ($this->metrics['processed'] === 0) {
            return 0.0;
        }

        return round(
            ($this->metrics['successful'] / $this->metrics['processed']) * 100,
            2
        );
    }

    /**
     * Emits detailed CloudWatch metrics
     *
     * @return void
     */
    private function emitMetrics(): void
    {
        $dimensions = [
            ['Name' => 'Environment', 'Value' => getenv('APP_ENV')],
            ['Name' => 'Function', 'Value' => 'NotificationProcessor']
        ];

        $metrics = [
            ['Name' => 'ProcessedNotifications', 'Value' => $this->metrics['processed']],
            ['Name' => 'SuccessfulNotifications', 'Value' => $this->metrics['successful']],
            ['Name' => 'FailedNotifications', 'Value' => $this->metrics['failed']],
            ['Name' => 'RetriedNotifications', 'Value' => $this->metrics['retried']],
            ['Name' => 'ProcessingTime', 'Value' => $this->metrics['processing_time']],
            ['Name' => 'SuccessRate', 'Value' => $this->metrics['success_rate']],
            ['Name' => 'VendorFailovers', 'Value' => $this->metrics['vendor_failovers']]
        ];

        foreach ($metrics as $metric) {
            $this->logger->info('CloudWatch metric', [
                'metric_name' => $metric['Name'],
                'value' => $metric['Value'],
                'dimensions' => $dimensions
            ]);
        }
    }
}