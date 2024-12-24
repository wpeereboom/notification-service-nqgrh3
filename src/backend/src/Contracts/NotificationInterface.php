<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Core interface defining the contract for high-throughput notification processing
 * and delivery tracking across multiple channels with vendor failover support.
 *
 * This interface provides comprehensive methods for:
 * - Multi-channel notification delivery (Email, SMS, Push)
 * - Asynchronous message processing
 * - Delivery status tracking and retry mechanisms
 * - Vendor redundancy and failover strategies
 *
 * @package App\Contracts
 * @version 1.0.0
 */
interface NotificationInterface
{
    /**
     * Status constants for notification lifecycle tracking
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRYING = 'retrying';

    /**
     * Supported notification channels
     */
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_PUSH = 'push';

    /**
     * Priority levels for message processing
     */
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW = 'low';

    /**
     * Asynchronously sends a notification through the specified channel.
     *
     * Handles high-throughput message processing with support for:
     * - Multiple delivery channels (email, SMS, push)
     * - Vendor-specific configuration options
     * - Priority-based processing
     * - Template rendering
     *
     * @param array $payload {
     *     @type string $recipient Recipient address/identifier
     *     @type string $template_id Template identifier
     *     @type array  $context Template context data
     *     @type string $priority Message priority (default: normal)
     *     @type array  $metadata Additional tracking metadata
     * }
     * @param string $channel One of CHANNEL_* constants
     * @param array $options {
     *     Optional vendor-specific configuration
     *     
     *     @type array  $vendors Prioritized list of vendors to attempt
     *     @type int    $retry_attempts Max retry attempts (default: 3)
     *     @type int    $retry_delay Delay between retries in seconds
     *     @type bool   $track_opens Enable open tracking (email only)
     *     @type bool   $track_clicks Enable click tracking (email only)
     * }
     * @return string Unique notification ID for tracking
     * @throws \InvalidArgumentException If payload or channel is invalid
     */
    public function send(array $payload, string $channel, array $options = []): string;

    /**
     * Retrieves detailed current status of a notification.
     *
     * Provides comprehensive status information including:
     * - Current delivery state
     * - Processing timestamps
     * - Vendor attempt history
     * - Delivery metrics
     *
     * @param string $notificationId Unique notification identifier
     * @return array {
     *     @type string $status Current status (one of STATUS_* constants)
     *     @type string $channel Delivery channel used
     *     @type array  $timestamps {
     *         @type string $created Creation timestamp
     *         @type string $queued Queue entry timestamp
     *         @type string $processing Processing start timestamp
     *         @type string $completed Completion timestamp
     *     }
     *     @type array  $vendor Current/last vendor information
     *     @type array  $metrics Delivery performance metrics
     * }
     * @throws \InvalidArgumentException If notification ID is invalid
     * @throws \RuntimeException If status retrieval fails
     */
    public function getStatus(string $notificationId): array;

    /**
     * Retrieves comprehensive history of all delivery attempts.
     *
     * Provides detailed tracking of:
     * - All vendor delivery attempts
     * - Failure reasons and responses
     * - Timing data for each attempt
     * - Vendor failover progression
     *
     * @param string $notificationId Unique notification identifier
     * @return array {
     *     @type array[] $attempts List of delivery attempts {
     *         @type string $vendor Vendor identifier
     *         @type string $status Attempt status
     *         @type string $timestamp Attempt timestamp
     *         @type array  $response Raw vendor response
     *         @type string $error Error message if failed
     *     }
     * }
     * @throws \InvalidArgumentException If notification ID is invalid
     * @throws \RuntimeException If history retrieval fails
     */
    public function getDeliveryAttempts(string $notificationId): array;

    /**
     * Initiates a retry attempt for a failed notification.
     *
     * Supports:
     * - Optional vendor failover
     * - Custom retry configuration
     * - Attempt tracking
     *
     * @param string $notificationId Unique notification identifier
     * @param array $options {
     *     Optional retry configuration
     *     
     *     @type string $vendor Specific vendor to use
     *     @type bool   $force_vendor_failover Force using next vendor
     *     @type int    $retry_delay Custom retry delay
     *     @type array  $vendor_options Vendor-specific options
     * }
     * @return array {
     *     @type string $retry_id New tracking ID for retry attempt
     *     @type string $vendor Vendor selected for retry
     *     @type string $status Initial retry status
     * }
     * @throws \InvalidArgumentException If notification ID is invalid
     * @throws \RuntimeException If retry initiation fails
     */
    public function retry(string $notificationId, array $options = []): array;
}