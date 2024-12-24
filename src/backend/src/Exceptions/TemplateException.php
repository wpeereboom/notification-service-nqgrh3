<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Custom exception class for handling template-related errors in the notification service.
 * Provides standardized error handling with comprehensive context support.
 *
 * @package App\Exceptions
 * @version 1.0.0
 */
class TemplateException extends Exception
{
    /**
     * Template not found error code
     */
    public const TEMPLATE_NOT_FOUND = 'TEMPLATE_NOT_FOUND';

    /**
     * Template invalid format/structure error code
     */
    public const TEMPLATE_INVALID = 'TEMPLATE_INVALID';

    /**
     * Template rendering error code
     */
    public const TEMPLATE_RENDER_ERROR = 'TEMPLATE_RENDER_ERROR';

    /**
     * Template validation error code
     */
    public const TEMPLATE_VALIDATION_ERROR = 'TEMPLATE_VALIDATION_ERROR';

    /**
     * Template creation error code
     */
    public const TEMPLATE_CREATE_ERROR = 'TEMPLATE_CREATE_ERROR';

    /**
     * Template update error code
     */
    public const TEMPLATE_UPDATE_ERROR = 'TEMPLATE_UPDATE_ERROR';

    /**
     * Template deletion error code
     */
    public const TEMPLATE_DELETE_ERROR = 'TEMPLATE_DELETE_ERROR';

    /**
     * @var string Standardized error code for the template exception
     */
    private string $errorCode;

    /**
     * @var array<string, mixed> Context data for debugging and logging
     */
    private array $context;

    /**
     * Initialize a new template exception with message, error code and context.
     *
     * @param string $message Error message describing the template error
     * @param string $errorCode Standardized error code from class constants
     * @param array<string, mixed> $context Additional context data for debugging
     * @param Throwable|null $previous Previous exception if this is a wrapped exception
     */
    public function __construct(
        string $message,
        string $errorCode,
        array $context = [],
        ?Throwable $previous = null
    ) {
        // Validate error code against defined constants
        if (!$this->isValidErrorCode($errorCode)) {
            throw new \InvalidArgumentException('Invalid template error code provided');
        }

        // Call parent constructor with message and default code 0
        parent::__construct($message, 0, $previous);

        $this->errorCode = $errorCode;
        $this->context = $this->sanitizeContext($context);
    }

    /**
     * Get the standardized error code for this template exception.
     *
     * @return string The template-specific error code
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get the sanitized context data for debugging and logging.
     *
     * @return array<string, mixed> Contextual information about the error
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Validate that the provided error code is defined in class constants.
     *
     * @param string $errorCode Error code to validate
     * @return bool True if error code is valid
     */
    private function isValidErrorCode(string $errorCode): bool
    {
        $reflection = new \ReflectionClass($this);
        $constants = $reflection->getConstants();
        
        return in_array($errorCode, $constants, true);
    }

    /**
     * Sanitize context data by removing or masking sensitive information.
     *
     * @param array<string, mixed> $context Raw context data
     * @return array<string, mixed> Sanitized context data
     */
    private function sanitizeContext(array $context): array
    {
        // Define sensitive keys that should be masked
        $sensitiveKeys = ['password', 'token', 'key', 'secret'];
        
        return array_map(function ($value) use ($sensitiveKeys) {
            if (is_array($value)) {
                return $this->sanitizeContext($value);
            }
            
            if (is_string($value) && $this->containsSensitiveKey($value, $sensitiveKeys)) {
                return '********';
            }
            
            return $value;
        }, $context);
    }

    /**
     * Check if a key contains sensitive information that should be masked.
     *
     * @param string $key Key to check
     * @param array<string> $sensitiveKeys List of sensitive key patterns
     * @return bool True if key contains sensitive information
     */
    private function containsSensitiveKey(string $key, array $sensitiveKeys): bool
    {
        $lowercaseKey = strtolower($key);
        foreach ($sensitiveKeys as $sensitiveKey) {
            if (str_contains($lowercaseKey, $sensitiveKey)) {
                return true;
            }
        }
        return false;
    }
}