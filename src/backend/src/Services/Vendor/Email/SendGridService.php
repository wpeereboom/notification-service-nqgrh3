<?php

declare(strict_types=1);

namespace App\Services\Vendor\Email;

use App\Contracts\VendorInterface;
use App\Exceptions\VendorException;
use App\Utils\CircuitBreaker;
use Psr\Log\LoggerInterface;
use SendGrid\Mail\Mail;
use SendGrid;
use Predis\Client as Redis;

/**
 * SendGrid email delivery service implementation with enhanced reliability features.
 * Provides email delivery through SendGrid's API v3 with circuit breaker pattern,
 * batch processing, and comprehensive monitoring.
 *
 * @package App\Services\Vendor\Email
 * @version 1.0.0
 */
class SendGridService implements VendorInterface
{
    private const VENDOR_NAME = 'sendgrid';
    private const VENDOR_TYPE = 'email';
    private const API_VERSION = 'v3';
    private const BATCH_SIZE = 100;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 1000; // milliseconds

    private SendGrid $client;
    private LoggerInterface $logger;
    private CircuitBreaker $circuitBreaker;
    private Redis $redis;
    private array $metrics = [
        'sent_count' => 0,
        'error_count' => 0,
        'latency_ms' => [],
        'batch_sizes' => [],
    ];

    /**
     * Creates new SendGrid service instance with monitoring capabilities.
     *
     * @param string $apiKey SendGrid API key
     * @param LoggerInterface $logger PSR-3 logger instance
     * @param Redis $redis Redis client for circuit breaker
     * @param array $config Optional configuration overrides
     * 
     * @throws VendorException When API key validation fails
     */
    public function __construct(
        string $apiKey,
        LoggerInterface $logger,
        Redis $redis,
        array $config = []
    ) {
        $this->client = new SendGrid($apiKey);
        $this->logger = $logger;
        $this->redis = $redis;
        
        // Initialize circuit breaker with tenant-aware configuration
        $this->circuitBreaker = new CircuitBreaker(
            $redis,
            $logger,
            self::VENDOR_NAME,
            self::VENDOR_TYPE,
            $config['tenant_id'] ?? 'default'
        );

        // Validate API credentials on initialization
        $this->validateApiCredentials();
    }

    /**
     * @inheritDoc
     */
    public function send(array $payload): array
    {
        $startTime = microtime(true);

        try {
            // Check circuit breaker before proceeding
            if (!$this->circuitBreaker->isAvailable()) {
                throw new VendorException(
                    'SendGrid service is currently unavailable (circuit breaker open)',
                    VendorException::VENDOR_CIRCUIT_OPEN,
                    null,
                    [
                        'vendor_name' => self::VENDOR_NAME,
                        'channel' => self::VENDOR_TYPE,
                        'circuit_breaker_open' => true
                    ]
                );
            }

            // Validate payload
            $this->validatePayload($payload);

            // Process single or batch send
            if (isset($payload['batch']) && is_array($payload['batch'])) {
                $result = $this->processBatch($payload['batch']);
            } else {
                $result = $this->processSingle($payload);
            }

            // Record success in circuit breaker
            $this->circuitBreaker->recordSuccess();

            // Update metrics
            $this->updateMetrics($startTime, count($payload['batch'] ?? [1]));

            return $result;

        } catch (VendorException $e) {
            // Let VendorException propagate up
            throw $e;
        } catch (\Exception $e) {
            // Record failure in circuit breaker
            $this->circuitBreaker->recordFailure();

            $this->metrics['error_count']++;

            throw new VendorException(
                'SendGrid delivery failed: ' . $e->getMessage(),
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'error_type' => get_class($e)
                ]
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getStatus(string $messageId): array
    {
        try {
            $response = $this->client->client->messages()->_($messageId)->get();
            
            if ($response->statusCode() !== 200) {
                throw new VendorException(
                    'Failed to retrieve message status',
                    VendorException::VENDOR_UNAVAILABLE
                );
            }

            $status = json_decode($response->body(), true);

            return [
                'currentState' => $this->mapSendGridStatus($status['status'] ?? 'unknown'),
                'timestamps' => [
                    'sent' => $status['sent_at'] ?? null,
                    'delivered' => $status['delivered_at'] ?? null,
                    'failed' => $status['failed_at'] ?? null,
                ],
                'attempts' => $status['attempts'] ?? 1,
                'vendorMetadata' => $status
            ];

        } catch (\Exception $e) {
            throw new VendorException(
                'Failed to retrieve SendGrid message status: ' . $e->getMessage(),
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'message_id' => $messageId
                ]
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function checkHealth(): array
    {
        $startTime = microtime(true);

        try {
            // Perform API test call
            $response = $this->client->client->_('version')->get();
            $isHealthy = $response->statusCode() === 200;

            $latency = (microtime(true) - $startTime) * 1000;

            // Get circuit breaker state
            $circuitState = $this->circuitBreaker->getState();

            return [
                'isHealthy' => $isHealthy,
                'latency' => $latency,
                'timestamp' => date('c'),
                'diagnostics' => [
                    'circuit_breaker' => $circuitState,
                    'api_version' => self::API_VERSION,
                    'metrics' => $this->metrics
                ],
                'lastError' => null
            ];

        } catch (\Exception $e) {
            return [
                'isHealthy' => false,
                'latency' => (microtime(true) - $startTime) * 1000,
                'timestamp' => date('c'),
                'diagnostics' => [
                    'circuit_breaker' => $this->circuitBreaker->getState(),
                    'api_version' => self::API_VERSION,
                    'metrics' => $this->metrics
                ],
                'lastError' => $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getVendorType(): string
    {
        return self::VENDOR_TYPE;
    }

    /**
     * Processes a batch of email messages.
     *
     * @param array $batch Array of email payloads
     * @return array Batch processing results
     */
    private function processBatch(array $batch): array
    {
        $results = [];
        $chunks = array_chunk($batch, self::BATCH_SIZE);

        foreach ($chunks as $chunk) {
            $mail = new Mail();
            
            foreach ($chunk as $message) {
                $this->addMessageToMail($mail, $message);
            }

            $response = $this->sendWithRetry($mail);
            $results[] = $this->processResponse($response);
        }

        return [
            'status' => 'sent',
            'timestamp' => date('c'),
            'batch_results' => $results
        ];
    }

    /**
     * Processes a single email message.
     *
     * @param array $payload Single email payload
     * @return array Processing result
     */
    private function processSingle(array $payload): array
    {
        $mail = new Mail();
        $this->addMessageToMail($mail, $payload);
        
        $response = $this->sendWithRetry($mail);
        
        return $this->processResponse($response);
    }

    /**
     * Sends email with retry logic.
     *
     * @param Mail $mail SendGrid mail object
     * @return object SendGrid response
     * @throws VendorException When all retries fail
     */
    private function sendWithRetry(Mail $mail): object
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $response = $this->client->send($mail);
                
                if ($response->statusCode() === 429) {
                    // Handle rate limiting
                    usleep(self::RETRY_DELAY * 1000 * ($attempts + 1));
                    $attempts++;
                    continue;
                }

                return $response;

            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;
                
                if ($attempts < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY * 1000 * $attempts);
                }
            }
        }

        throw new VendorException(
            'SendGrid delivery failed after ' . self::MAX_RETRIES . ' attempts',
            VendorException::VENDOR_UNAVAILABLE,
            $lastException,
            [
                'vendor_name' => self::VENDOR_NAME,
                'channel' => self::VENDOR_TYPE,
                'retry_attempts' => $attempts
            ]
        );
    }

    /**
     * Validates SendGrid API credentials.
     *
     * @throws VendorException When credentials are invalid
     */
    private function validateApiCredentials(): void
    {
        try {
            $response = $this->client->client->_('version')->get();
            
            if ($response->statusCode() !== 200) {
                throw new VendorException(
                    'SendGrid API key validation failed',
                    VendorException::VENDOR_AUTH_ERROR,
                    null,
                    [
                        'vendor_name' => self::VENDOR_NAME,
                        'channel' => self::VENDOR_TYPE
                    ]
                );
            }
        } catch (\Exception $e) {
            throw new VendorException(
                'SendGrid API key validation failed: ' . $e->getMessage(),
                VendorException::VENDOR_AUTH_ERROR,
                $e,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE
                ]
            );
        }
    }

    /**
     * Updates service metrics.
     *
     * @param float $startTime Processing start time
     * @param int $batchSize Size of processed batch
     */
    private function updateMetrics(float $startTime, int $batchSize): void
    {
        $latency = (microtime(true) - $startTime) * 1000;
        
        $this->metrics['sent_count'] += $batchSize;
        $this->metrics['latency_ms'][] = $latency;
        $this->metrics['batch_sizes'][] = $batchSize;
        
        // Keep only last 100 measurements
        if (count($this->metrics['latency_ms']) > 100) {
            array_shift($this->metrics['latency_ms']);
            array_shift($this->metrics['batch_sizes']);
        }
    }

    /**
     * Maps SendGrid status to standardized status.
     *
     * @param string $sendGridStatus SendGrid specific status
     * @return string Standardized status
     */
    private function mapSendGridStatus(string $sendGridStatus): string
    {
        $statusMap = [
            'delivered' => 'delivered',
            'processed' => 'pending',
            'dropped' => 'failed',
            'bounced' => 'failed',
            'deferred' => 'pending',
        ];

        return $statusMap[$sendGridStatus] ?? 'unknown';
    }

    /**
     * Adds a message to SendGrid mail object.
     *
     * @param Mail $mail SendGrid mail object
     * @param array $message Message payload
     */
    private function addMessageToMail(Mail $mail, array $message): void
    {
        $mail->setFrom($message['from']['email'], $message['from']['name'] ?? null);
        $mail->setSubject($message['subject']);
        $mail->addTo($message['to']['email'], $message['to']['name'] ?? null);
        
        if (isset($message['content']['html'])) {
            $mail->addContent('text/html', $message['content']['html']);
        }
        
        if (isset($message['content']['text'])) {
            $mail->addContent('text/plain', $message['content']['text']);
        }

        if (isset($message['template_id'])) {
            $mail->setTemplateId($message['template_id']);
        }

        if (isset($message['custom_args'])) {
            foreach ($message['custom_args'] as $key => $value) {
                $mail->addCustomArg($key, $value);
            }
        }
    }

    /**
     * Processes SendGrid API response.
     *
     * @param object $response SendGrid response object
     * @return array Standardized response
     */
    private function processResponse(object $response): array
    {
        $body = json_decode($response->body(), true) ?? [];

        return [
            'messageId' => $body['message_id'] ?? null,
            'status' => $response->statusCode() === 202 ? 'sent' : 'failed',
            'timestamp' => date('c'),
            'vendorResponse' => [
                'statusCode' => $response->statusCode(),
                'headers' => $response->headers(),
                'body' => $body
            ]
        ];
    }

    /**
     * Validates email payload structure.
     *
     * @param array $payload Email payload
     * @throws VendorException When payload is invalid
     */
    private function validatePayload(array $payload): void
    {
        $required = ['from', 'to', 'subject'];
        
        foreach ($required as $field) {
            if (!isset($payload[$field])) {
                throw new VendorException(
                    "Missing required field: {$field}",
                    VendorException::VENDOR_INVALID_REQUEST,
                    null,
                    [
                        'vendor_name' => self::VENDOR_NAME,
                        'channel' => self::VENDOR_TYPE,
                        'validation_error' => "Missing {$field}"
                    ]
                );
            }
        }

        if (!isset($payload['content']) && !isset($payload['template_id'])) {
            throw new VendorException(
                'Either content or template_id must be provided',
                VendorException::VENDOR_INVALID_REQUEST,
                null,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'validation_error' => 'Missing content'
                ]
            );
        }
    }
}