<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Custom exception class for handling vendor-related errors in the Notification Service.
 * Provides detailed context about vendor failures, circuit breaker states, and failover attempts.
 * 
 * @package App\Exceptions
 * @version 1.0.0
 */
class VendorException extends Exception
{
    /**
     * Error code when vendor service is unavailable
     */
    public const VENDOR_UNAVAILABLE = 1001;

    /**
     * Error code when vendor rate limit is exceeded
     */
    public const VENDOR_RATE_LIMITED = 1002;

    /**
     * Error code when vendor authentication fails
     */
    public const VENDOR_AUTH_ERROR = 1003;

    /**
     * Error code when vendor request is invalid
     */
    public const VENDOR_INVALID_REQUEST = 1004;

    /**
     * Error code when circuit breaker is open
     */
    public const VENDOR_CIRCUIT_OPEN = 1005;

    /**
     * Error code when all failover attempts are exhausted
     */
    public const VENDOR_FAILOVER_EXHAUSTED = 1006;

    /**
     * Name of the vendor where the error occurred
     */
    private string $vendorName;

    /**
     * Channel type (email, sms, push) where the error occurred
     */
    private string $channel;

    /**
     * Detailed context about the vendor error
     */
    private array $vendorContext;

    /**
     * Number of failover attempts made
     */
    private int $failoverAttempts;

    /**
     * Current state of the circuit breaker
     */
    private bool $circuitBreakerOpen;

    /**
     * Creates a new vendor exception instance with comprehensive error context.
     *
     * @param string $message Error message
     * @param int $code Error code from class constants
     * @param Throwable|null $previous Previous exception if any
     * @param array $context Additional context about the vendor error
     * @throws \InvalidArgumentException If required context is missing
     */
    public function __construct(
        string $message,
        int $code,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);

        // Validate required context fields
        if (!isset($context['vendor_name']) || !is_string($context['vendor_name'])) {
            throw new \InvalidArgumentException('Vendor name is required in context');
        }

        if (!isset($context['channel']) || !is_string($context['channel'])) {
            throw new \InvalidArgumentException('Channel is required in context');
        }

        // Set and sanitize vendor name
        $this->vendorName = $this->sanitizeVendorName($context['vendor_name']);
        
        // Set and validate channel
        $this->channel = $this->validateChannel($context['channel']);
        
        // Set failover attempts with default of 0
        $this->failoverAttempts = (int) ($context['failover_attempts'] ?? 0);
        
        // Set circuit breaker state with default of false
        $this->circuitBreakerOpen = (bool) ($context['circuit_breaker_open'] ?? false);
        
        // Store sanitized vendor context
        $this->vendorContext = $this->sanitizeContext($context);
    }

    /**
     * Gets the name of the vendor that caused the exception.
     *
     * @return string Sanitized vendor name
     */
    public function getVendorName(): string
    {
        return $this->vendorName;
    }

    /**
     * Gets the notification channel where the error occurred.
     *
     * @return string Channel identifier (email, sms, push)
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * Gets the detailed context about the vendor error.
     *
     * @return array Sanitized vendor error context
     */
    public function getVendorContext(): array
    {
        return $this->vendorContext;
    }

    /**
     * Gets the number of failover attempts made before this error.
     *
     * @return int Number of failover attempts
     */
    public function getFailoverAttempts(): int
    {
        return $this->failoverAttempts;
    }

    /**
     * Checks if the circuit breaker was open when the error occurred.
     *
     * @return bool True if circuit breaker was open
     */
    public function isCircuitBreakerOpen(): bool
    {
        return $this->circuitBreakerOpen;
    }

    /**
     * Sanitizes the vendor name to prevent XSS and other injection attacks.
     *
     * @param string $vendorName Raw vendor name
     * @return string Sanitized vendor name
     */
    private function sanitizeVendorName(string $vendorName): string
    {
        return htmlspecialchars(trim($vendorName), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validates the channel type is one of the supported values.
     *
     * @param string $channel Channel identifier
     * @return string Validated channel
     * @throws \InvalidArgumentException If channel is invalid
     */
    private function validateChannel(string $channel): string
    {
        $validChannels = ['email', 'sms', 'push'];
        $channel = strtolower(trim($channel));
        
        if (!in_array($channel, $validChannels, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid channel "%s". Must be one of: %s', 
                    $channel, 
                    implode(', ', $validChannels)
                )
            );
        }
        
        return $channel;
    }

    /**
     * Sanitizes the vendor context by removing sensitive information and validating structure.
     *
     * @param array $context Raw context array
     * @return array Sanitized context
     */
    private function sanitizeContext(array $context): array
    {
        // Remove sensitive fields
        unset(
            $context['api_key'],
            $context['auth_token'],
            $context['password'],
            $context['secret']
        );

        // Ensure required metadata
        $context['timestamp'] = $context['timestamp'] ?? time();
        $context['error_type'] = $context['error_type'] ?? 'unknown';

        // Sanitize any HTML in error messages
        if (isset($context['error_message'])) {
            $context['error_message'] = htmlspecialchars(
                $context['error_message'],
                ENT_QUOTES,
                'UTF-8'
            );
        }

        return $context;
    }
}