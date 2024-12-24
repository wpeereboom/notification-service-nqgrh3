<?php

declare(strict_types=1);

namespace App\Test\Mocks;

use App\Contracts\NotificationInterface;
use SplObjectStorage;
use InvalidArgumentException;
use RuntimeException;
use DateTimeImmutable;

/**
 * Thread-safe mock implementation of NotificationInterface for testing notification functionality
 * with support for high-throughput scenarios and vendor failover testing.
 *
 * @package App\Test\Mocks
 * @version 1.0.0
 */
class NotificationMock implements NotificationInterface
{
    /** @var SplObjectStorage<object, array> Thread-safe storage for notifications */
    private SplObjectStorage $notifications;

    /** @var SplObjectStorage<object, array> Thread-safe storage for delivery attempts */
    private SplObjectStorage $deliveryAttempts;

    /** @var SplObjectStorage<object, array> Thread-safe storage for retry tracking */
    private SplObjectStorage $retryAttempts;

    /** @var array<string, array> Vendor status tracking for failover simulation */
    private array $vendorStatus = [
        self::CHANNEL_EMAIL => [
            'iterable' => ['status' => 'up', 'failureRate' => 0.01],
            'sendgrid' => ['status' => 'up', 'failureRate' => 0.02],
            'ses' => ['status' => 'up', 'failureRate' => 0.01],
        ],
        self::CHANNEL_SMS => [
            'telnyx' => ['status' => 'up', 'failureRate' => 0.01],
            'twilio' => ['status' => 'up', 'failureRate' => 0.02],
        ],
        self::CHANNEL_PUSH => [
            'sns' => ['status' => 'up', 'failureRate' => 0.01],
        ],
    ];

    /**
     * Initializes the notification mock with thread-safe storage.
     */
    public function __construct()
    {
        $this->notifications = new SplObjectStorage();
        $this->deliveryAttempts = new SplObjectStorage();
        $this->retryAttempts = new SplObjectStorage();
    }

    /**
     * {@inheritDoc}
     */
    public function send(array $payload, string $channel, array $options = []): string
    {
        $this->validatePayload($payload, $channel);
        
        $notificationId = $this->generateUuid();
        $timestamp = new DateTimeImmutable();
        
        $vendors = $options['vendors'] ?? $this->getDefaultVendors($channel);
        $selectedVendor = $this->selectVendor($channel, $vendors);
        
        $notification = [
            'id' => $notificationId,
            'channel' => $channel,
            'payload' => $payload,
            'status' => self::STATUS_PENDING,
            'vendor' => $selectedVendor,
            'timestamps' => [
                'created' => $timestamp->format('c'),
                'queued' => $timestamp->format('c'),
            ],
            'options' => $options,
        ];

        $this->notifications->attach((object)$notificationId, $notification);
        
        // Simulate async processing
        $this->processNotification($notificationId, $selectedVendor);
        
        return $notificationId;
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus(string $notificationId): array
    {
        $notification = $this->getNotification($notificationId);
        
        return [
            'status' => $notification['status'],
            'channel' => $notification['channel'],
            'timestamps' => $notification['timestamps'],
            'vendor' => [
                'name' => $notification['vendor'],
                'status' => $this->vendorStatus[$notification['channel']][$notification['vendor']]['status'],
            ],
            'metrics' => [
                'processingTime' => $this->calculateProcessingTime($notification),
                'retryCount' => $this->getRetryCount($notificationId),
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getDeliveryAttempts(string $notificationId): array
    {
        $attempts = [];
        foreach ($this->deliveryAttempts as $storage) {
            if ($storage['notificationId'] === $notificationId) {
                $attempts[] = [
                    'vendor' => $storage['vendor'],
                    'status' => $storage['status'],
                    'timestamp' => $storage['timestamp'],
                    'response' => $storage['response'],
                    'error' => $storage['error'] ?? null,
                ];
            }
        }

        usort($attempts, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        
        return ['attempts' => $attempts];
    }

    /**
     * {@inheritDoc}
     */
    public function retry(string $notificationId, array $options = []): array
    {
        $notification = $this->getNotification($notificationId);
        
        if ($notification['status'] !== self::STATUS_FAILED) {
            throw new RuntimeException('Only failed notifications can be retried');
        }

        $retryCount = $this->getRetryCount($notificationId);
        $maxRetries = $options['retry_attempts'] ?? 3;

        if ($retryCount >= $maxRetries) {
            throw new RuntimeException('Maximum retry attempts exceeded');
        }

        $vendor = $options['vendor'] ?? $this->selectVendor(
            $notification['channel'],
            $this->getDefaultVendors($notification['channel']),
            [$notification['vendor']]
        );

        $retryId = $this->generateUuid();
        $timestamp = new DateTimeImmutable();

        $retryAttempt = [
            'id' => $retryId,
            'originalId' => $notificationId,
            'vendor' => $vendor,
            'timestamp' => $timestamp->format('c'),
            'status' => self::STATUS_RETRYING,
        ];

        $this->retryAttempts->attach((object)$retryId, $retryAttempt);
        
        // Simulate retry processing
        $this->processNotification($notificationId, $vendor);

        return [
            'retry_id' => $retryId,
            'vendor' => $vendor,
            'status' => self::STATUS_RETRYING,
        ];
    }

    /**
     * Resets all mock storage for clean test state.
     */
    public function reset(): void
    {
        $this->notifications = new SplObjectStorage();
        $this->deliveryAttempts = new SplObjectStorage();
        $this->retryAttempts = new SplObjectStorage();
        
        // Reset vendor status
        foreach ($this->vendorStatus as $channel => $vendors) {
            foreach ($vendors as $vendor => $status) {
                $this->vendorStatus[$channel][$vendor]['status'] = 'up';
            }
        }
    }

    /**
     * Simulates vendor processing and updates notification status.
     */
    private function processNotification(string $notificationId, string $vendor): void
    {
        $notification = $this->getNotification($notificationId);
        $timestamp = new DateTimeImmutable();
        
        $notification['timestamps']['processing'] = $timestamp->format('c');
        $notification['status'] = self::STATUS_PROCESSING;
        
        // Simulate vendor response based on failure rate
        $isSuccessful = (mt_rand(1, 100) / 100) > $this->vendorStatus[$notification['channel']][$vendor]['failureRate'];
        
        $attemptData = [
            'notificationId' => $notificationId,
            'vendor' => $vendor,
            'timestamp' => $timestamp->format('c'),
            'status' => $isSuccessful ? self::STATUS_DELIVERED : self::STATUS_FAILED,
            'response' => [
                'vendor_response_id' => $this->generateUuid(),
                'timestamp' => $timestamp->format('c'),
            ],
        ];

        if (!$isSuccessful) {
            $attemptData['error'] = 'Simulated vendor failure';
        }

        $this->deliveryAttempts->attach((object)$notificationId, $attemptData);
        
        $notification['status'] = $isSuccessful ? self::STATUS_DELIVERED : self::STATUS_FAILED;
        $notification['timestamps']['completed'] = $timestamp->format('c');
        
        $this->notifications->attach((object)$notificationId, $notification);
    }

    /**
     * Validates notification payload and channel.
     *
     * @throws InvalidArgumentException
     */
    private function validatePayload(array $payload, string $channel): void
    {
        if (!isset($payload['recipient'])) {
            throw new InvalidArgumentException('Recipient is required');
        }

        if (!in_array($channel, [self::CHANNEL_EMAIL, self::CHANNEL_SMS, self::CHANNEL_PUSH])) {
            throw new InvalidArgumentException('Invalid channel specified');
        }
    }

    /**
     * Generates a UUID v4 for notification tracking.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Retrieves notification data by ID.
     *
     * @throws InvalidArgumentException
     */
    private function getNotification(string $notificationId): array
    {
        foreach ($this->notifications as $storage) {
            if ($storage['id'] === $notificationId) {
                return $storage;
            }
        }
        
        throw new InvalidArgumentException('Notification not found');
    }

    /**
     * Returns default vendors for specified channel.
     */
    private function getDefaultVendors(string $channel): array
    {
        return array_keys($this->vendorStatus[$channel]);
    }

    /**
     * Selects appropriate vendor for notification delivery.
     */
    private function selectVendor(string $channel, array $vendors, array $excludeVendors = []): string
    {
        foreach ($vendors as $vendor) {
            if (
                !in_array($vendor, $excludeVendors) &&
                $this->vendorStatus[$channel][$vendor]['status'] === 'up'
            ) {
                return $vendor;
            }
        }
        
        throw new RuntimeException('No available vendors for channel');
    }

    /**
     * Calculates processing time for a notification.
     */
    private function calculateProcessingTime(array $notification): ?float
    {
        if (!isset($notification['timestamps']['completed'])) {
            return null;
        }

        $start = new DateTimeImmutable($notification['timestamps']['created']);
        $end = new DateTimeImmutable($notification['timestamps']['completed']);
        
        return $end->getTimestamp() - $start->getTimestamp();
    }

    /**
     * Gets retry attempt count for a notification.
     */
    private function getRetryCount(string $notificationId): int
    {
        $count = 0;
        foreach ($this->retryAttempts as $storage) {
            if ($storage['originalId'] === $notificationId) {
                $count++;
            }
        }
        return $count;
    }
}