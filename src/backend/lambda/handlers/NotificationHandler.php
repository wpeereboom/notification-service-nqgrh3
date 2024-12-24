<?php

declare(strict_types=1);

namespace App\Lambda\Handlers;

use App\Services\Notification\NotificationService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AWS Lambda handler for processing notification requests with support for
 * high-throughput batch processing, multi-channel delivery, and comprehensive error handling.
 *
 * @package App\Lambda\Handlers
 * @version 1.0.0
 */
class NotificationHandler
{
    /**
     * Maximum batch size for parallel processing
     */
    private const MAX_BATCH_SIZE = 100;

    /**
     * Processing timeout in seconds (25s to allow for Lambda overhead)
     */
    private const PROCESSING_TIMEOUT = 25;

    /**
     * @var NotificationService Notification service instance
     */
    private NotificationService $notificationService;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var array Performance metrics collection
     */
    private array $metrics = [
        'processed' => 0,
        'successful' => 0,
        'failed' => 0,
        'processing_time' => 0,
    ];

    /**
     * Initialize notification handler with required dependencies
     *
     * @param NotificationService $notificationService Core notification service
     * @param LoggerInterface $logger PSR-3 logger instance
     */
    public function __construct(
        NotificationService $notificationService,
        LoggerInterface $logger
    ) {
        $this->notificationService = $notificationService;
        $this->logger = $logger;

        // Configure error handling
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Main Lambda handler for processing individual notification requests
     *
     * @param array $event Lambda event data
     * @param array $context Lambda context
     * @return array Response containing notification status and tracking data
     */
    public function handleRequest(array $event, array $context): array
    {
        $startTime = microtime(true);
        $requestId = $event['requestContext']['requestId'] ?? uniqid('req_', true);

        try {
            $this->logger->info('Processing notification request', [
                'request_id' => $requestId,
                'remaining_time' => $context['getRemainingTimeInMillis']() ?? 0
            ]);

            // Validate request payload
            $this->validatePayload($event['body'] ?? []);
            $payload = json_decode($event['body'], true);

            // Process notification with timeout control
            $result = $this->processWithTimeout(function () use ($payload) {
                return $this->notificationService->send(
                    $payload['notification'],
                    $payload['channel'],
                    $payload['options'] ?? []
                );
            });

            $this->metrics['processed']++;
            $this->metrics['successful']++;
            $this->metrics['processing_time'] += microtime(true) - $startTime;

            return [
                'statusCode' => 202,
                'body' => json_encode([
                    'notification_id' => $result,
                    'status' => 'accepted',
                    'request_id' => $requestId,
                    'processing_time' => round(microtime(true) - $startTime, 3)
                ])
            ];

        } catch (Throwable $e) {
            $this->metrics['failed']++;
            return $this->handleError($e, $requestId);
        }
    }

    /**
     * Processes batch notification requests with parallel execution
     *
     * @param array $event Lambda event containing batch records
     * @param array $context Lambda context
     * @return array Batch processing results
     */
    public function handleBatchRequest(array $event, array $context): array
    {
        $startTime = microtime(true);
        $batchId = uniqid('batch_', true);

        try {
            $records = $event['Records'] ?? [];
            
            if (count($records) > self::MAX_BATCH_SIZE) {
                throw new \InvalidArgumentException('Batch size exceeds maximum limit');
            }

            $this->logger->info('Processing notification batch', [
                'batch_id' => $batchId,
                'record_count' => count($records),
                'remaining_time' => $context['getRemainingTimeInMillis']() ?? 0
            ]);

            $results = [];
            foreach ($records as $record) {
                try {
                    $payload = json_decode($record['body'], true);
                    $results[] = $this->processWithTimeout(function () use ($payload) {
                        return $this->notificationService->send(
                            $payload['notification'],
                            $payload['channel'],
                            $payload['options'] ?? []
                        );
                    });
                    $this->metrics['successful']++;
                } catch (Throwable $e) {
                    $this->metrics['failed']++;
                    $results[] = [
                        'error' => $e->getMessage(),
                        'record_id' => $record['messageId'] ?? null
                    ];
                }
                $this->metrics['processed']++;
            }

            $this->metrics['processing_time'] += microtime(true) - $startTime;

            return [
                'statusCode' => 200,
                'body' => json_encode([
                    'batch_id' => $batchId,
                    'processed' => count($results),
                    'successful' => $this->metrics['successful'],
                    'failed' => $this->metrics['failed'],
                    'processing_time' => round(microtime(true) - $startTime, 3),
                    'results' => $results
                ])
            ];

        } catch (Throwable $e) {
            return $this->handleError($e, $batchId);
        }
    }

    /**
     * Retrieves detailed notification status with tracking information
     *
     * @param array $event Lambda event data
     * @return array Detailed notification status
     */
    public function handleStatusRequest(array $event): array
    {
        try {
            $notificationId = $event['pathParameters']['notification_id'] ?? null;
            
            if (!$notificationId) {
                throw new \InvalidArgumentException('Notification ID is required');
            }

            $status = $this->notificationService->getStatus($notificationId);
            $attempts = $this->notificationService->getDeliveryAttempts($notificationId);

            return [
                'statusCode' => 200,
                'body' => json_encode([
                    'notification_id' => $notificationId,
                    'status' => $status,
                    'delivery_attempts' => $attempts,
                    'timestamp' => time()
                ])
            ];

        } catch (Throwable $e) {
            return $this->handleError($e, $notificationId);
        }
    }

    /**
     * Handles retry requests for failed notifications
     *
     * @param array $event Lambda event data
     * @return array Retry operation status
     */
    public function handleRetryRequest(array $event): array
    {
        try {
            $notificationId = $event['pathParameters']['notification_id'] ?? null;
            
            if (!$notificationId) {
                throw new \InvalidArgumentException('Notification ID is required');
            }

            $options = json_decode($event['body'] ?? '{}', true);
            $result = $this->notificationService->retry($notificationId, $options);

            return [
                'statusCode' => 202,
                'body' => json_encode([
                    'notification_id' => $notificationId,
                    'retry_status' => $result,
                    'timestamp' => time()
                ])
            ];

        } catch (Throwable $e) {
            return $this->handleError($e, $notificationId);
        }
    }

    /**
     * Executes callback with timeout control
     *
     * @param callable $callback Function to execute
     * @return mixed Result of callback execution
     * @throws \RuntimeException When execution times out
     */
    private function processWithTimeout(callable $callback)
    {
        $timeout = self::PROCESSING_TIMEOUT;
        $startTime = time();

        // Set timeout handler
        $handler = function () use ($timeout) {
            throw new \RuntimeException("Processing timeout after {$timeout} seconds");
        };
        pcntl_signal(SIGALRM, $handler);
        pcntl_alarm($timeout);

        try {
            $result = $callback();
            pcntl_alarm(0); // Clear alarm
            return $result;
        } catch (Throwable $e) {
            pcntl_alarm(0); // Clear alarm
            throw $e;
        }
    }

    /**
     * Validates notification request payload
     *
     * @param array|string $payload Request payload
     * @throws \InvalidArgumentException If payload is invalid
     */
    private function validatePayload(array|string $payload): void
    {
        if (empty($payload)) {
            throw new \InvalidArgumentException('Request payload is required');
        }

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid JSON payload');
            }
        }

        $required = ['notification', 'channel'];
        foreach ($required as $field) {
            if (!isset($payload[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }

    /**
     * Handles errors and generates appropriate response
     *
     * @param Throwable $error Caught error or exception
     * @param string $requestId Request identifier
     * @return array Error response
     */
    private function handleError(Throwable $error, string $requestId): array
    {
        $this->logger->error('Notification processing failed', [
            'request_id' => $requestId,
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString()
        ]);

        $statusCode = match (get_class($error)) {
            \InvalidArgumentException::class => 400,
            \RuntimeException::class => 500,
            default => 500
        };

        return [
            'statusCode' => $statusCode,
            'body' => json_encode([
                'error' => $error->getMessage(),
                'request_id' => $requestId,
                'type' => get_class($error)
            ])
        ];
    }

    /**
     * Handles fatal errors and shutdown events
     */
    private function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->logger->critical('Fatal error occurred', [
                'error' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'metrics' => $this->metrics
            ]);
        }
    }
}