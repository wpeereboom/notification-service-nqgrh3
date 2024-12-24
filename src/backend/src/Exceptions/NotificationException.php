<?php

declare(strict_types=1);

namespace Backend\Exceptions;

use Exception;
use Throwable;

/**
 * Custom exception class for handling notification-specific errors with context tracking.
 * 
 * This class extends the base PHP Exception to provide standardized error handling for
 * the notification service, including error codes and contextual debugging information.
 * 
 * @version 1.0
 * @package Backend\Exceptions
 */
final class NotificationException extends Exception
{
    /**
     * Standard error codes for notification-related failures
     */
    public const INVALID_PAYLOAD = 'NOTIFICATION_INVALID_PAYLOAD';
    public const TEMPLATE_NOT_FOUND = 'NOTIFICATION_TEMPLATE_NOT_FOUND';
    public const RATE_LIMITED = 'NOTIFICATION_RATE_LIMITED';
    public const VENDOR_UNAVAILABLE = 'NOTIFICATION_VENDOR_UNAVAILABLE';
    public const DELIVERY_FAILED = 'NOTIFICATION_DELIVERY_FAILED';
    public const MAX_RETRIES_EXCEEDED = 'NOTIFICATION_MAX_RETRIES_EXCEEDED';

    /**
     * @var string Standardized error code for the notification exception
     */
    private string $errorCode;

    /**
     * @var array<string, mixed> Contextual data for debugging the error
     */
    private array $errorContext;

    /**
     * Initialize a new notification exception with message, code and context.
     *
     * @param string $message Human-readable error message
     * @param string $errorCode Standardized error code from class constants
     * @param array<string, mixed> $context Additional context data for debugging
     * @param int $code Optional numeric error code (default: 0)
     * @param Throwable|null $previous Optional previous exception for chaining
     * 
     * @throws \InvalidArgumentException If an invalid error code is provided
     */
    public function __construct(
        string $message,
        string $errorCode,
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        // Validate the error code against defined constants
        if (!$this->isValidErrorCode($errorCode)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid notification error code provided: %s', $errorCode)
            );
        }

        // Initialize parent Exception
        parent::__construct($message, $code, $previous);

        // Set error code
        $this->errorCode = $errorCode;

        // Filter sensitive data and store context
        $this->errorContext = $this->filterSensitiveData($context);
    }

    /**
     * Get the standardized error code for this notification exception.
     *
     * @return string The error code identifier
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get the filtered context data for debugging this error.
     *
     * @return array<string, mixed> The filtered context array
     */
    public function getErrorContext(): array
    {
        return $this->errorContext;
    }

    /**
     * Validate that the provided error code is defined in class constants.
     *
     * @param string $errorCode The error code to validate
     * @return bool True if the error code is valid
     */
    private function isValidErrorCode(string $errorCode): bool
    {
        $reflection = new \ReflectionClass($this);
        $constants = $reflection->getConstants();
        
        return in_array($errorCode, $constants, true);
    }

    /**
     * Filter sensitive information from the context array.
     *
     * @param array<string, mixed> $context The raw context array
     * @return array<string, mixed> The filtered context array
     */
    private function filterSensitiveData(array $context): array
    {
        // List of keys containing sensitive data that should be redacted
        $sensitiveKeys = [
            'password',
            'token',
            'api_key',
            'secret',
            'credential',
            'auth',
            'phone',
            'email'
        ];

        $filtered = [];
        
        foreach ($context as $key => $value) {
            // Check if the key contains any sensitive terms
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (stripos($key, $sensitiveKey) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            // Redact sensitive values, pass through non-sensitive ones
            $filtered[$key] = $isSensitive ? '[REDACTED]' : $value;
        }

        return $filtered;
    }
}