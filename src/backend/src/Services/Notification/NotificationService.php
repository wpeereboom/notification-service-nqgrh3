<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Contracts\NotificationInterface;
use App\Services\Queue\SqsService;
use App\Services\Template\TemplateService;
use App\Services\Vendor\VendorService;
use App\Exceptions\VendorException;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Core service implementing high-throughput, multi-channel notification processing
 * with support for asynchronous delivery, vendor failover, and comprehensive tracking.
 *
 * @package App\Services\Notification
 * @version 1.0.0
 */
class NotificationService implements NotificationInterface
{
    /**
     * Batch processing size for high throughput
     */
    private const BATCH_SIZE = 100;

    /**
     * Maximum retry attempts for failed notifications
     */
    private const RETRY_ATTEMPTS = 3;

    /**
     * Base retry delay in milliseconds
     */
    private const RETRY_DELAY_MS = 1000;

    /**
     * Cache TTL for notification status
     */
    private const CACHE_TTL = 3600;

    /**
     * @var array<string, array> Metrics collection
     */
    private array $metrics = [
        'processed' => 0,
        'successful' => 0,
        'failed' => 0,
        'retried' => 0,
    ];

    /**
     * Initialize notification service with required dependencies
     */
    public function __construct(
        private SqsService $queueService,
        private TemplateService $templateService,
        private VendorService $vendorService,
        private LoggerInterface $logger,
        private Redis $redis
    ) {
        $this->initializeMetrics();
    }

    /**
     * Queues a notification for asynchronous processing with tenant isolation
     *
     * @param array $payload Notification payload
     * @param string $channel Notification channel
     * @param array $options Delivery options
     * @return string Notification tracking ID
     * @throws InvalidArgumentException
     */
    public function send(array $payload, string $channel, array $options = []): string
    {
        $this->validatePayload($payload, $channel);
        $notificationId = $this->generateNotificationId();

        try {
            // Process template if specified
            if (isset($payload['template_id'])) {
                $payload['content'] = $this->templateService->render(
                    $payload['template_id'],
                    $payload['context'] ?? []
                );
            }

            // Prepare message for queuing
            $message = [
                'id' => $notificationId,
                'payload' => $payload,
                'channel' => $channel,
                'options' => $options,
                'tenant_id' => $options['tenant_id'] ?? 'default',
                'timestamp' => time(),
                'status' => self::STATUS_QUEUED
            ];

            // Queue for processing
            $this->queueService->sendMessage($message);

            // Update metrics
            $this->metrics['processed']++;
            $this->updateMetrics($notificationId, self::STATUS_QUEUED);

            $this->logger->info('Notification queued successfully', [
                'notification_id' => $notificationId,
                'channel' => $channel
            ]);

            return $notificationId;

        } catch (\Exception $e) {
            $this->logger->error('Failed to queue notification', [
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to queue notification: ' . $e->getMessage());
        }
    }

    /**
     * Retrieves detailed notification status with vendor responses
     *
     * @param string $notificationId Notification ID
     * @return array Status details
     * @throws RuntimeException
     */
    public function getStatus(string $notificationId): array
    {
        try {
            // Check cache first
            $cached = $this->redis->get("notification:status:{$notificationId}");
            if ($cached !== null) {
                return json_decode($cached, true);
            }

            // Get status from vendor
            $status = $this->vendorService->getStatus(
                $notificationId,
                $this->getVendorFromCache($notificationId),
                $this->getTenantFromCache($notificationId)
            );

            // Enrich with delivery attempts
            $status['attempts'] = $this->getDeliveryAttempts($notificationId);
            
            // Cache status
            $this->redis->setex(
                "notification:status:{$notificationId}",
                self::CACHE_TTL,
                json_encode($status)
            );

            return $status;

        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve notification status', [
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to retrieve status: ' . $e->getMessage());
        }
    }

    /**
     * Retrieves all delivery attempts with vendor responses
     *
     * @param string $notificationId Notification ID
     * @return array List of delivery attempts
     * @throws RuntimeException
     */
    public function getDeliveryAttempts(string $notificationId): array
    {
        try {
            $attempts = $this->redis->lrange(
                "notification:attempts:{$notificationId}",
                0,
                -1
            );

            return array_map(
                fn($attempt) => json_decode($attempt, true),
                $attempts
            );

        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve delivery attempts', [
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to retrieve attempts: ' . $e->getMessage());
        }
    }

    /**
     * Retries failed notification with exponential backoff
     *
     * @param string $notificationId Notification ID
     * @param array $options Retry options
     * @return array Retry status
     * @throws RuntimeException
     */
    public function retry(string $notificationId, array $options = []): array
    {
        try {
            $status = $this->getStatus($notificationId);
            
            if ($status['status'] !== self::STATUS_FAILED) {
                throw new InvalidArgumentException('Only failed notifications can be retried');
            }

            $attempts = count($this->getDeliveryAttempts($notificationId));
            if ($attempts >= self::RETRY_ATTEMPTS) {
                throw new InvalidArgumentException('Maximum retry attempts exceeded');
            }

            // Calculate backoff delay
            $delay = self::RETRY_DELAY_MS * pow(2, $attempts);

            // Queue retry attempt
            $message = [
                'id' => $notificationId,
                'retry_count' => $attempts + 1,
                'options' => array_merge($options, ['delay' => $delay])
            ];

            $this->queueService->sendMessage($message);
            $this->metrics['retried']++;

            return [
                'retry_id' => $notificationId,
                'attempt' => $attempts + 1,
                'delay' => $delay,
                'status' => self::STATUS_RETRYING
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to retry notification', [
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to retry notification: ' . $e->getMessage());
        }
    }

    /**
     * Internal handler for processing queued notifications
     *
     * @param array $message Queue message
     * @return bool Processing success status
     */
    private function processNotification(array $message): bool
    {
        try {
            $notificationId = $message['id'];
            $channel = $message['channel'];
            $tenantId = $message['tenant_id'];

            // Update status to processing
            $this->updateStatus($notificationId, self::STATUS_PROCESSING);

            // Attempt delivery through vendor service
            $response = $this->vendorService->send(
                $message['payload'],
                $channel,
                $tenantId
            );

            // Record successful delivery
            $this->updateStatus($notificationId, self::STATUS_DELIVERED);
            $this->recordDeliveryAttempt($notificationId, $response);
            $this->metrics['successful']++;

            return true;

        } catch (VendorException $e) {
            $this->handleDeliveryFailure($message, $e);
            return false;
        }
    }

    /**
     * Handles failed delivery attempts with retry logic
     *
     * @param array $message Original message
     * @param VendorException $exception Failure exception
     */
    private function handleDeliveryFailure(array $message, VendorException $exception): void
    {
        $notificationId = $message['id'];
        $retryCount = $message['retry_count'] ?? 0;

        $this->recordDeliveryAttempt($notificationId, [
            'status' => self::STATUS_FAILED,
            'error' => $exception->getMessage(),
            'vendor' => $exception->getVendorName(),
            'timestamp' => time()
        ]);

        if ($retryCount < self::RETRY_ATTEMPTS) {
            // Queue for retry with backoff
            $message['retry_count'] = $retryCount + 1;
            $message['options']['delay'] = self::RETRY_DELAY_MS * pow(2, $retryCount);
            $this->queueService->sendMessage($message);
        } else {
            $this->updateStatus($notificationId, self::STATUS_FAILED);
            $this->metrics['failed']++;
        }
    }

    /**
     * Updates notification status with caching
     *
     * @param string $notificationId Notification ID
     * @param string $status New status
     */
    private function updateStatus(string $notificationId, string $status): void
    {
        $this->redis->setex(
            "notification:status:{$notificationId}",
            self::CACHE_TTL,
            json_encode(['status' => $status, 'updated_at' => time()])
        );
    }

    /**
     * Records delivery attempt details
     *
     * @param string $notificationId Notification ID
     * @param array $attempt Attempt details
     */
    private function recordDeliveryAttempt(string $notificationId, array $attempt): void
    {
        $this->redis->rpush(
            "notification:attempts:{$notificationId}",
            json_encode($attempt)
        );
        $this->redis->expire("notification:attempts:{$notificationId}", 86400);
    }

    /**
     * Initializes metrics collection
     */
    private function initializeMetrics(): void
    {
        $this->redis->hmset('notification:metrics', $this->metrics);
    }

    /**
     * Updates metrics for notification
     *
     * @param string $notificationId Notification ID
     * @param string $status Current status
     */
    private function updateMetrics(string $notificationId, string $status): void
    {
        $this->redis->hincrby('notification:metrics', $status, 1);
        $this->redis->expire('notification:metrics', 86400);
    }

    /**
     * Generates unique notification ID
     *
     * @return string Unique ID
     */
    private function generateNotificationId(): string
    {
        return uniqid('notif_', true);
    }

    /**
     * Validates notification payload
     *
     * @param array $payload Notification payload
     * @param string $channel Notification channel
     * @throws InvalidArgumentException
     */
    private function validatePayload(array $payload, string $channel): void
    {
        if (!isset($payload['recipient'])) {
            throw new InvalidArgumentException('Recipient is required');
        }

        if (!isset($payload['content']) && !isset($payload['template_id'])) {
            throw new InvalidArgumentException('Content or template ID is required');
        }

        if (!in_array($channel, [self::CHANNEL_EMAIL, self::CHANNEL_SMS, self::CHANNEL_PUSH])) {
            throw new InvalidArgumentException('Invalid notification channel');
        }
    }

    /**
     * Gets vendor name from cache
     *
     * @param string $notificationId Notification ID
     * @return string Vendor name
     */
    private function getVendorFromCache(string $notificationId): string
    {
        return $this->redis->get("notification:vendor:{$notificationId}") ?? '';
    }

    /**
     * Gets tenant ID from cache
     *
     * @param string $notificationId Notification ID
     * @return string Tenant ID
     */
    private function getTenantFromCache(string $notificationId): string
    {
        return $this->redis->get("notification:tenant:{$notificationId}") ?? 'default';
    }
}