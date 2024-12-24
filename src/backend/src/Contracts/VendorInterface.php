<?php

declare(strict_types=1);

namespace App\Contracts;

use Psr\Log\LoggerInterface; // ^3.0

/**
 * Core interface defining the contract for notification vendor implementations.
 * Supports Email (Iterable, SendGrid, SES), SMS (Telnyx, Twilio), and Push (AWS SNS) channels
 * with high-throughput processing and sophisticated health monitoring capabilities.
 *
 * Requirements:
 * - Support for processing 100,000+ messages per minute
 * - 99.9% delivery success rate
 * - Vendor failover within 2 seconds
 * - Health check intervals of 30 seconds
 * - Standardized response formats across all vendors
 *
 * @package App\Contracts
 */
interface VendorInterface
{
    /**
     * Sends a notification through the vendor's service with support for high-throughput processing.
     *
     * The payload array must contain all necessary data for the notification including:
     * - recipient: string (email address, phone number, or device token)
     * - content: array (message content with required format fields)
     * - metadata: array (optional tracking and processing metadata)
     * - options: array (optional vendor-specific delivery options)
     *
     * @param array<string, mixed> $payload The notification payload to be sent
     * 
     * @return array<string, mixed> Standardized delivery response containing:
     *         - messageId: string (unique identifier for tracking)
     *         - status: string (sent|failed|queued)
     *         - timestamp: string (ISO 8601 format)
     *         - vendorResponse: array (raw vendor response data)
     *         - metadata: array (additional processing details)
     *
     * @throws \App\Exceptions\VendorException When vendor service is unavailable
     * @throws \App\Exceptions\ValidationException When payload validation fails
     * @throws \App\Exceptions\RateLimitException When rate limits are exceeded
     */
    public function send(array $payload): array;

    /**
     * Retrieves the delivery status of a notification with caching support.
     *
     * @param string $messageId The unique identifier of the message to check
     * 
     * @return array<string, mixed> Detailed status information including:
     *         - currentState: string (delivered|failed|pending)
     *         - timestamps: array (sent, delivered, failed)
     *         - attempts: int (number of delivery attempts)
     *         - vendorMetadata: array (vendor-specific status details)
     *
     * @throws \App\Exceptions\MessageNotFoundException When message ID is not found
     * @throws \App\Exceptions\VendorException When vendor service is unavailable
     */
    public function getStatus(string $messageId): array;

    /**
     * Performs comprehensive health check of vendor service.
     * Includes API availability, authentication, and performance metrics.
     *
     * @return array<string, mixed> Detailed health status including:
     *         - isHealthy: bool (overall health status)
     *         - latency: float (response time in milliseconds)
     *         - timestamp: string (ISO 8601 format)
     *         - diagnostics: array (detailed health metrics)
     *         - lastError: string|null (last error message if any)
     *
     * @throws \App\Exceptions\VendorException When health check fails critically
     */
    public function checkHealth(): array;

    /**
     * Returns the standardized unique identifier for this vendor.
     * Used for logging, metrics, and vendor selection/failover.
     *
     * @return string Normalized vendor identifier (e.g., 'iterable', 'sendgrid', 'twilio')
     */
    public function getVendorName(): string;

    /**
     * Returns the standardized channel type this vendor supports.
     * Used for channel-specific routing and processing logic.
     *
     * @return string Normalized channel type ('email', 'sms', 'push')
     */
    public function getVendorType(): string;
}