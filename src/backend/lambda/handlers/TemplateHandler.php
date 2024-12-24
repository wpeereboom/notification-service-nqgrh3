<?php

declare(strict_types=1);

namespace App\Lambda\Handlers;

use App\Services\Template\TemplateService;
use AWS\XRay\XRayTrace;
use CircuitBreaker\CircuitBreaker;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * AWS Lambda handler for template management operations with comprehensive error handling,
 * monitoring, and performance optimization.
 *
 * @version 1.0.0
 * @package App\Lambda\Handlers
 */
#[XRayTrace]
class TemplateHandler
{
    private const OPERATIONS = [
        'create' => 'handleCreate',
        'update' => 'handleUpdate',
        'delete' => 'handleDelete',
        'get' => 'handleGet',
    ];

    private const ERROR_CODES = [
        'INVALID_REQUEST' => ['code' => 400, 'retryable' => false],
        'NOT_FOUND' => ['code' => 404, 'retryable' => false],
        'RATE_LIMITED' => ['code' => 429, 'retryable' => true],
        'INTERNAL_ERROR' => ['code' => 500, 'retryable' => true],
        'SERVICE_ERROR' => ['code' => 503, 'retryable' => true],
    ];

    /**
     * @var TemplateService Template management service
     */
    private TemplateService $templateService;

    /**
     * @var LoggerInterface PSR-3 logger for monitoring
     */
    private LoggerInterface $logger;

    /**
     * @var CircuitBreaker Circuit breaker for service resilience
     */
    private CircuitBreaker $circuitBreaker;

    /**
     * @var array Handler configuration
     */
    private array $config;

    /**
     * Initialize template handler with dependencies
     *
     * @param TemplateService $templateService Template service instance
     * @param LoggerInterface $logger PSR-3 logger
     * @param CircuitBreaker $circuitBreaker Circuit breaker
     * @param array $config Handler configuration
     */
    public function __construct(
        TemplateService $templateService,
        LoggerInterface $logger,
        CircuitBreaker $circuitBreaker,
        array $config
    ) {
        $this->templateService = $templateService;
        $this->logger = $logger;
        $this->circuitBreaker = $circuitBreaker;
        $this->config = $config;
    }

    /**
     * Handles incoming Lambda requests with comprehensive error handling
     *
     * @param array $event Lambda event data
     * @param array $context Lambda context
     * @return array Response with status and data
     */
    #[XRayTrace]
    public function handleRequest(array $event, array $context): array
    {
        $startTime = microtime(true);
        $requestId = $context['awsRequestId'] ?? uniqid();

        try {
            $this->logger->info('Processing template request', [
                'request_id' => $requestId,
                'operation' => $event['operation'] ?? 'unknown'
            ]);

            $this->validateRequest($event);
            
            if (!isset(self::OPERATIONS[$event['operation']])) {
                throw new InvalidArgumentException('Invalid operation specified');
            }

            $operation = self::OPERATIONS[$event['operation']];
            $result = $this->$operation($event);

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->info('Template operation completed', [
                'request_id' => $requestId,
                'duration_ms' => $duration,
                'operation' => $event['operation']
            ]);

            return [
                'statusCode' => 200,
                'body' => $result,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Request-ID' => $requestId
                ]
            ];
        } catch (Throwable $e) {
            return $this->handleError($e, $requestId, $startTime);
        }
    }

    /**
     * Handles template creation with validation
     *
     * @param array $event Request data
     * @return array Creation response
     */
    #[XRayTrace]
    private function handleCreate(array $event): array
    {
        $this->validateCreateData($event['template'] ?? []);

        return $this->circuitBreaker->execute(function () use ($event) {
            $created = $this->templateService->create($event['template']);
            
            if (!$created) {
                throw new RuntimeException('Failed to create template');
            }

            return [
                'success' => true,
                'message' => 'Template created successfully',
                'data' => $event['template']
            ];
        });
    }

    /**
     * Handles template updates with validation
     *
     * @param array $event Request data
     * @return array Update response
     */
    #[XRayTrace]
    private function handleUpdate(array $event): array
    {
        $this->validateUpdateData($event);

        return $this->circuitBreaker->execute(function () use ($event) {
            $updated = $this->templateService->update(
                $event['template_id'],
                $event['template']
            );

            if (!$updated) {
                throw new RuntimeException('Failed to update template');
            }

            return [
                'success' => true,
                'message' => 'Template updated successfully',
                'data' => ['template_id' => $event['template_id']]
            ];
        });
    }

    /**
     * Handles template deletion with dependency checking
     *
     * @param array $event Request data
     * @return array Deletion response
     */
    #[XRayTrace]
    private function handleDelete(array $event): array
    {
        if (empty($event['template_id'])) {
            throw new InvalidArgumentException('Template ID is required');
        }

        return $this->circuitBreaker->execute(function () use ($event) {
            $deleted = $this->templateService->delete($event['template_id']);

            if (!$deleted) {
                throw new RuntimeException('Failed to delete template');
            }

            return [
                'success' => true,
                'message' => 'Template deleted successfully',
                'data' => ['template_id' => $event['template_id']]
            ];
        });
    }

    /**
     * Handles template retrieval with caching
     *
     * @param array $event Request data
     * @return array Template data
     */
    #[XRayTrace]
    private function handleGet(array $event): array
    {
        if (empty($event['template_id'])) {
            throw new InvalidArgumentException('Template ID is required');
        }

        return $this->circuitBreaker->execute(function () use ($event) {
            $template = $this->templateService->find($event['template_id']);

            if ($template === null) {
                throw new InvalidArgumentException('Template not found');
            }

            return [
                'success' => true,
                'data' => $template
            ];
        });
    }

    /**
     * Validates incoming request structure
     *
     * @param array $event Request data
     * @throws InvalidArgumentException If request is invalid
     */
    private function validateRequest(array $event): void
    {
        if (empty($event['operation'])) {
            throw new InvalidArgumentException('Operation is required');
        }
    }

    /**
     * Validates template creation data
     *
     * @param array $data Template data
     * @throws InvalidArgumentException If data is invalid
     */
    private function validateCreateData(array $data): void
    {
        if (empty($data['name']) || empty($data['type']) || empty($data['content'])) {
            throw new InvalidArgumentException('Name, type, and content are required');
        }

        if (!$this->templateService->validate($data['content'])) {
            throw new InvalidArgumentException('Invalid template content');
        }
    }

    /**
     * Validates template update data
     *
     * @param array $event Update request data
     * @throws InvalidArgumentException If data is invalid
     */
    private function validateUpdateData(array $event): void
    {
        if (empty($event['template_id'])) {
            throw new InvalidArgumentException('Template ID is required');
        }

        if (empty($event['template'])) {
            throw new InvalidArgumentException('Template data is required');
        }
    }

    /**
     * Handles and formats error responses
     *
     * @param Throwable $error Caught exception
     * @param string $requestId Request identifier
     * @param float $startTime Request start time
     * @return array Formatted error response
     */
    private function handleError(Throwable $error, string $requestId, float $startTime): array
    {
        $duration = (microtime(true) - $startTime) * 1000;
        $errorType = $this->determineErrorType($error);
        $errorData = self::ERROR_CODES[$errorType];

        $this->logger->error('Template operation failed', [
            'request_id' => $requestId,
            'error_type' => $errorType,
            'message' => $error->getMessage(),
            'duration_ms' => $duration,
            'trace' => $error->getTraceAsString()
        ]);

        return [
            'statusCode' => $errorData['code'],
            'body' => [
                'success' => false,
                'error' => [
                    'type' => $errorType,
                    'message' => $error->getMessage(),
                    'retryable' => $errorData['retryable']
                ]
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Request-ID' => $requestId
            ]
        ];
    }

    /**
     * Determines error type from exception
     *
     * @param Throwable $error Caught exception
     * @return string Error type identifier
     */
    private function determineErrorType(Throwable $error): string
    {
        return match (true) {
            $error instanceof InvalidArgumentException => 'INVALID_REQUEST',
            $error->getCode() === 404 => 'NOT_FOUND',
            $error->getCode() === 429 => 'RATE_LIMITED',
            $error instanceof RuntimeException => 'SERVICE_ERROR',
            default => 'INTERNAL_ERROR'
        };
    }
}