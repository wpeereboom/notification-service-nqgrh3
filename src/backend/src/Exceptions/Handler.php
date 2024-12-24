<?php

declare(strict_types=1);

namespace Backend\Exceptions;

use Backend\Exceptions\NotificationException;
use App\Exceptions\TemplateException;
use App\Exceptions\VendorException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Global exception handler for the Notification Service.
 * Provides centralized error handling, logging, and standardized error responses
 * with enhanced monitoring and vendor failover support.
 *
 * @version 1.0.0
 * @package Backend\Exceptions
 */
class Handler
{
    /**
     * Error code to HTTP status code mapping
     */
    private const ERROR_HTTP_MAP = [
        NotificationException::INVALID_PAYLOAD => self::HTTP_BAD_REQUEST,
        NotificationException::RATE_LIMITED => self::HTTP_RATE_LIMITED,
        NotificationException::VENDOR_UNAVAILABLE => self::HTTP_SERVICE_UNAVAILABLE,
        TemplateException::TEMPLATE_NOT_FOUND => self::HTTP_NOT_FOUND,
        TemplateException::TEMPLATE_INVALID => self::HTTP_BAD_REQUEST,
        VendorException::VENDOR_UNAVAILABLE => self::HTTP_SERVICE_UNAVAILABLE,
        VendorException::VENDOR_RATE_LIMITED => self::HTTP_RATE_LIMITED,
        VendorException::VENDOR_AUTH_ERROR => self::HTTP_UNAUTHORIZED,
    ];

    /**
     * HTTP status codes
     */
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_NOT_FOUND = 404;
    private const HTTP_RATE_LIMITED = 429;
    private const HTTP_SERVER_ERROR = 500;
    private const HTTP_SERVICE_UNAVAILABLE = 503;

    /**
     * @var LoggerInterface PSR-3 logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var array<string, mixed> Monitoring thresholds configuration
     */
    private array $monitoringThresholds;

    /**
     * @var array<string, mixed> Vendor failover configuration
     */
    private array $vendorFailoverConfig;

    /**
     * Initialize exception handler with logger and configuration.
     *
     * @param LoggerInterface $logger PSR-3 compatible logger
     * @param array<string, mixed> $config Handler configuration
     */
    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->monitoringThresholds = $config['monitoring'] ?? [
            'error_rate_threshold' => 0.05, // 5% error rate threshold
            'consecutive_failures' => 3,
            'time_window' => 300, // 5 minutes
        ];
        $this->vendorFailoverConfig = $config['vendor_failover'] ?? [
            'max_attempts' => 3,
            'retry_delay' => 2, // seconds
            'circuit_breaker_timeout' => 30, // seconds
        ];
    }

    /**
     * Handle thrown exception and return standardized error response.
     *
     * @param Throwable $exception The thrown exception
     * @return array<string, mixed> Standardized error response
     */
    public function handle(Throwable $exception): array
    {
        // Extract request context
        $context = [
            'request_id' => $this->generateRequestId(),
            'correlation_id' => $this->getCorrelationId(),
            'timestamp' => time(),
        ];

        // Handle specific exception types
        if ($exception instanceof VendorException) {
            return $this->handleVendorException($exception, $context);
        }

        if ($exception instanceof NotificationException) {
            return $this->handleNotificationException($exception, $context);
        }

        if ($exception instanceof TemplateException) {
            return $this->handleTemplateException($exception, $context);
        }

        // Log the exception with context
        $this->logException($exception, $context);

        // Return generic error response for unhandled exceptions
        return $this->formatResponse($exception, $context);
    }

    /**
     * Handle vendor-specific exceptions with failover support.
     *
     * @param VendorException $exception Vendor exception
     * @param array<string, mixed> $context Error context
     * @return array<string, mixed> Error response
     */
    private function handleVendorException(VendorException $exception, array $context): array
    {
        $vendorContext = $exception->getVendorContext();
        $failoverStatus = [
            'attempts' => $exception->getFailoverAttempts(),
            'circuit_breaker_open' => $exception->isCircuitBreakerOpen(),
            'vendor' => $exception->getVendorName(),
            'channel' => $exception->getChannel(),
        ];

        // Log vendor exception with enhanced context
        $this->logger->error(
            'Vendor error occurred',
            array_merge($context, $vendorContext, ['failover_status' => $failoverStatus])
        );

        // Check if failover is possible
        if ($this->shouldAttemptFailover($exception)) {
            $context['failover_initiated'] = true;
            $context['next_vendor'] = $this->determineNextVendor($exception->getChannel());
        }

        return $this->formatResponse($exception, $context);
    }

    /**
     * Handle notification-specific exceptions.
     *
     * @param NotificationException $exception Notification exception
     * @param array<string, mixed> $context Error context
     * @return array<string, mixed> Error response
     */
    private function handleNotificationException(NotificationException $exception, array $context): array
    {
        $errorContext = $exception->getErrorContext();
        
        // Log notification exception with context
        $this->logger->error(
            'Notification error occurred',
            array_merge($context, ['error_context' => $errorContext])
        );

        return $this->formatResponse($exception, $context);
    }

    /**
     * Handle template-specific exceptions.
     *
     * @param TemplateException $exception Template exception
     * @param array<string, mixed> $context Error context
     * @return array<string, mixed> Error response
     */
    private function handleTemplateException(TemplateException $exception, array $context): array
    {
        $templateContext = $exception->getContext();
        
        // Log template exception with context
        $this->logger->error(
            'Template error occurred',
            array_merge($context, ['template_context' => $templateContext])
        );

        return $this->formatResponse($exception, $context);
    }

    /**
     * Format exception into standardized error response.
     *
     * @param Throwable $exception The exception to format
     * @param array<string, mixed> $context Additional context
     * @return array<string, mixed> Formatted error response
     */
    private function formatResponse(Throwable $exception, array $context): array
    {
        $statusCode = $this->determineHttpStatusCode($exception);
        $errorCode = $this->getErrorCode($exception);

        $response = [
            'status' => 'error',
            'code' => $errorCode,
            'message' => $this->sanitizeErrorMessage($exception->getMessage()),
            'request_id' => $context['request_id'],
            'correlation_id' => $context['correlation_id'],
            'timestamp' => $context['timestamp'],
        ];

        // Add failover information for vendor exceptions
        if ($exception instanceof VendorException) {
            $response['failover_status'] = [
                'attempted' => $exception->getFailoverAttempts() > 0,
                'circuit_breaker_open' => $exception->isCircuitBreakerOpen(),
            ];
        }

        // Add monitoring metadata
        $response['_metadata'] = [
            'status_code' => $statusCode,
            'error_type' => get_class($exception),
            'service' => 'notification-service',
        ];

        return $response;
    }

    /**
     * Log exception with appropriate context and monitoring integration.
     *
     * @param Throwable $exception The exception to log
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    private function logException(Throwable $exception, array $context): void
    {
        $logContext = array_merge($context, [
            'exception' => [
                'class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ],
        ]);

        // Determine log level based on exception type and severity
        $level = $this->determineLogLevel($exception);
        
        $this->logger->log($level, $exception->getMessage(), $logContext);
    }

    /**
     * Generate unique request ID for tracking.
     *
     * @return string Unique request identifier
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * Get correlation ID from current request context.
     *
     * @return string Correlation identifier
     */
    private function getCorrelationId(): string
    {
        // Implement correlation ID extraction from request headers or generate new
        return $_SERVER['HTTP_X_CORRELATION_ID'] ?? uniqid('corr_', true);
    }

    /**
     * Determine appropriate HTTP status code for exception.
     *
     * @param Throwable $exception The exception
     * @return int HTTP status code
     */
    private function determineHttpStatusCode(Throwable $exception): int
    {
        if ($exception instanceof VendorException) {
            return self::ERROR_HTTP_MAP[$exception->getCode()] ?? self::HTTP_SERVICE_UNAVAILABLE;
        }

        if ($exception instanceof NotificationException) {
            return self::ERROR_HTTP_MAP[$exception->getErrorCode()] ?? self::HTTP_BAD_REQUEST;
        }

        if ($exception instanceof TemplateException) {
            return self::ERROR_HTTP_MAP[$exception->getErrorCode()] ?? self::HTTP_BAD_REQUEST;
        }

        return self::HTTP_SERVER_ERROR;
    }

    /**
     * Get standardized error code from exception.
     *
     * @param Throwable $exception The exception
     * @return string Error code
     */
    private function getErrorCode(Throwable $exception): string
    {
        if ($exception instanceof NotificationException) {
            return $exception->getErrorCode();
        }

        if ($exception instanceof TemplateException) {
            return $exception->getErrorCode();
        }

        if ($exception instanceof VendorException) {
            return 'VENDOR_ERROR_' . $exception->getCode();
        }

        return 'INTERNAL_SERVER_ERROR';
    }

    /**
     * Determine if failover should be attempted for vendor exception.
     *
     * @param VendorException $exception The vendor exception
     * @return bool True if failover should be attempted
     */
    private function shouldAttemptFailover(VendorException $exception): bool
    {
        return !$exception->isCircuitBreakerOpen() 
            && $exception->getFailoverAttempts() < $this->vendorFailoverConfig['max_attempts'];
    }

    /**
     * Determine next vendor for failover attempt.
     *
     * @param string $channel The notification channel
     * @return string|null Next vendor name or null if none available
     */
    private function determineNextVendor(string $channel): ?string
    {
        // Implement vendor selection logic based on channel and availability
        $vendorMap = [
            'email' => ['sendgrid', 'ses', 'iterable'],
            'sms' => ['twilio', 'telnyx'],
            'push' => ['sns'],
        ];

        return $vendorMap[$channel][0] ?? null;
    }

    /**
     * Determine appropriate log level for exception.
     *
     * @param Throwable $exception The exception
     * @return string PSR-3 log level
     */
    private function determineLogLevel(Throwable $exception): string
    {
        if ($exception instanceof VendorException && $exception->isCircuitBreakerOpen()) {
            return 'critical';
        }

        if ($exception instanceof NotificationException 
            && $exception->getErrorCode() === NotificationException::DELIVERY_FAILED) {
            return 'error';
        }

        return 'warning';
    }

    /**
     * Sanitize error message for safe display.
     *
     * @param string $message Raw error message
     * @return string Sanitized message
     */
    private function sanitizeErrorMessage(string $message): string
    {
        return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
}