<?php
declare(strict_types=1);

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use Monolog\Logger;
use GuzzleHttp\Exception\GuzzleException;

// Runtime environment variables
define('LAMBDA_TASK_ROOT', getenv('LAMBDA_TASK_ROOT'));
define('LAMBDA_RUNTIME_API', getenv('AWS_LAMBDA_RUNTIME_API'));
define('LAMBDA_RUNTIME_DIR', getenv('LAMBDA_RUNTIME_DIR'));
define('_HANDLER', getenv('_HANDLER'));
define('LAMBDA_RUNTIME_LOAD_TIME', microtime(true));

/**
 * Enhanced Lambda Runtime implementation for high-performance PHP 8.2 functions
 * with comprehensive error handling and monitoring capabilities.
 * 
 * @version 1.0.0
 * @package Notification\Lambda
 */
class LambdaRuntime {
    private LoggerInterface $logger;
    private Client $httpClient;
    private string $handler;
    private array $metrics = [];
    private array $connectionPool = [];
    
    /**
     * Initialize Lambda runtime with optimized configuration
     * 
     * @param LoggerInterface $logger PSR-3 compliant logger
     * @param Client $httpClient Guzzle HTTP client v7.0
     * @throws RuntimeException If environment is not properly configured
     */
    public function __construct(LoggerInterface $logger, Client $httpClient) {
        $this->logger = $logger;
        $this->httpClient = $httpClient;
        
        if (!_HANDLER || !LAMBDA_RUNTIME_API) {
            throw new RuntimeException('Required environment variables not set');
        }
        
        $this->handler = _HANDLER;
        $this->initializeRuntime();
    }

    /**
     * Main runtime loop with error handling and performance monitoring
     * 
     * @throws RuntimeException For unrecoverable runtime errors
     */
    public function loop(): void {
        $this->logger->info('Starting Lambda runtime loop', [
            'handler' => $this->handler,
            'load_time' => LAMBDA_RUNTIME_LOAD_TIME
        ]);

        while (true) {
            $startTime = microtime(true);
            try {
                // Get next invocation
                $invocation = $this->getNextInvocation($this->httpClient);
                $requestId = $invocation['requestId'] ?? null;
                
                if (!$requestId) {
                    throw new RuntimeException('Invalid invocation: missing requestId');
                }

                // Process handler
                $response = $this->processHandler(
                    $this->handler,
                    $invocation['event'] ?? [],
                    $invocation['context'] ?? []
                );

                // Send response
                $this->sendResponse($this->httpClient, $requestId, $response);
                
                // Update metrics
                $this->metrics['invocations'][] = [
                    'requestId' => $requestId,
                    'duration' => microtime(true) - $startTime,
                    'status' => 'success'
                ];

            } catch (Throwable $error) {
                $this->handleError($this->httpClient, $requestId ?? 'unknown', $error);
            }
        }
    }

    /**
     * Initialize runtime environment with optimized settings
     */
    private function initializeRuntime(): void {
        // Optimize PHP settings
        error_reporting(E_ALL);
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '900');
        
        // Register error handlers
        set_error_handler(function($severity, $message, $file, $line) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        
        // Configure connection pooling
        $this->httpClient->getConfig('handler')->setDefaultOption('connect_timeout', 1.0);
        $this->httpClient->getConfig('handler')->setDefaultOption('keep_alive', true);
        
        $this->logger->info('Runtime initialized', [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'load_time' => microtime(true) - LAMBDA_RUNTIME_LOAD_TIME
        ]);
    }

    /**
     * Get next Lambda invocation with connection reuse
     * 
     * @param Client $httpClient HTTP client instance
     * @return array Invocation details
     * @throws RuntimeException For invocation retrieval errors
     */
    private function getNextInvocation(Client $httpClient): array {
        try {
            $response = $httpClient->get(
                "http://" . LAMBDA_RUNTIME_API . "/2018-06-01/runtime/invocation/next",
                ['http_errors' => true]
            );
            
            $invocation = [
                'requestId' => $response->getHeader('Lambda-Runtime-Aws-Request-Id')[0] ?? null,
                'event' => json_decode($response->getBody()->getContents(), true),
                'context' => [
                    'functionName' => getenv('AWS_LAMBDA_FUNCTION_NAME'),
                    'functionVersion' => getenv('AWS_LAMBDA_FUNCTION_VERSION'),
                    'memoryLimitInMB' => getenv('AWS_LAMBDA_FUNCTION_MEMORY_SIZE'),
                    'logGroupName' => getenv('AWS_LAMBDA_LOG_GROUP_NAME'),
                    'logStreamName' => getenv('AWS_LAMBDA_LOG_STREAM_NAME'),
                    'awsRequestId' => $response->getHeader('Lambda-Runtime-Aws-Request-Id')[0] ?? null,
                    'invokedFunctionArn' => $response->getHeader('Lambda-Runtime-Invoked-Function-Arn')[0] ?? null,
                    'deadline' => $response->getHeader('Lambda-Runtime-Deadline-Ms')[0] ?? null,
                ]
            ];

            $this->logger->debug('Received invocation', ['requestId' => $invocation['requestId']]);
            return $invocation;

        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get next invocation: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Process Lambda handler with comprehensive error handling
     * 
     * @param string $handlerFunction Handler function name
     * @param array $event Event data
     * @param array $context Execution context
     * @return mixed Handler response
     * @throws RuntimeException For handler execution errors
     */
    private function processHandler(string $handlerFunction, array $event, array $context) {
        $handlerParts = explode('::', $handlerFunction);
        if (count($handlerParts) !== 2) {
            throw new RuntimeException("Invalid handler format: {$handlerFunction}");
        }

        [$class, $method] = $handlerParts;
        if (!class_exists($class)) {
            throw new RuntimeException("Handler class not found: {$class}");
        }

        $handler = new $class();
        if (!method_exists($handler, $method)) {
            throw new RuntimeException("Handler method not found: {$method}");
        }

        $startTime = microtime(true);
        try {
            $response = $handler->$method($event, $context);
            $this->logger->info('Handler executed successfully', [
                'duration' => microtime(true) - $startTime,
                'memory_used' => memory_get_peak_usage(true)
            ]);
            return $response;
        } catch (Throwable $e) {
            throw new RuntimeException('Handler execution failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Send invocation response with retry logic
     * 
     * @param Client $httpClient HTTP client instance
     * @param string $requestId Request ID
     * @param mixed $response Response data
     * @throws RuntimeException For response transmission errors
     */
    private function sendResponse(Client $httpClient, string $requestId, $response): void {
        $maxRetries = 3;
        $attempt = 0;
        
        while ($attempt < $maxRetries) {
            try {
                $httpClient->post(
                    "http://" . LAMBDA_RUNTIME_API . "/2018-06-01/runtime/invocation/{$requestId}/response",
                    [
                        'body' => json_encode($response, JSON_THROW_ON_ERROR),
                        'headers' => ['Content-Type' => 'application/json']
                    ]
                );
                return;
            } catch (Throwable $e) {
                $attempt++;
                if ($attempt === $maxRetries) {
                    throw new RuntimeException('Failed to send response after ' . $maxRetries . ' attempts', 0, $e);
                }
                usleep(100000 * $attempt); // Exponential backoff
            }
        }
    }

    /**
     * Handle runtime errors with comprehensive logging
     * 
     * @param Client $httpClient HTTP client instance
     * @param string $requestId Request ID
     * @param Throwable $error Error instance
     */
    private function handleError(Client $httpClient, string $requestId, Throwable $error): void {
        $errorResponse = [
            'errorMessage' => $error->getMessage(),
            'errorType' => get_class($error),
            'stackTrace' => array_map(function($trace) {
                return [
                    'file' => $trace['file'] ?? 'unknown',
                    'line' => $trace['line'] ?? 0,
                    'function' => $trace['function'] ?? 'unknown'
                ];
            }, $error->getTrace())
        ];

        $this->logger->error('Runtime error', [
            'requestId' => $requestId,
            'error' => $errorResponse
        ]);

        try {
            $httpClient->post(
                "http://" . LAMBDA_RUNTIME_API . "/2018-06-01/runtime/invocation/{$requestId}/error",
                [
                    'json' => $errorResponse,
                    'headers' => [
                        'Lambda-Runtime-Function-Error-Type' => get_class($error)
                    ]
                ]
            );
        } catch (Throwable $e) {
            $this->logger->critical('Failed to report error to Lambda Runtime API', [
                'requestId' => $requestId,
                'error' => $e->getMessage()
            ]);
        }

        // Update error metrics
        $this->metrics['errors'][] = [
            'requestId' => $requestId,
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'timestamp' => time()
        ];
    }
}