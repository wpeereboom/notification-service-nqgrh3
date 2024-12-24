<?php

declare(strict_types=1);

namespace App\Services\Vendor\Email;

use App\Contracts\VendorInterface;
use App\Exceptions\VendorException;
use App\Utils\CircuitBreaker;
use Aws\Ses\SesClient;
use Psr\Log\LoggerInterface;

/**
 * Amazon SES (Simple Email Service) implementation with enhanced batch processing,
 * comprehensive monitoring, and failover support.
 *
 * @package App\Services\Vendor\Email
 * @version 1.0.0
 */
class SesService implements VendorInterface
{
    private const VENDOR_NAME = 'ses';
    private const VENDOR_TYPE = 'email';
    private const BATCH_SIZE = 50;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 100;

    private SesClient $client;
    private LoggerInterface $logger;
    private CircuitBreaker $circuitBreaker;

    /**
     * Initializes SES service with enhanced configuration.
     *
     * @param SesClient $client AWS SES client instance
     * @param LoggerInterface $logger PSR-3 logger
     * @param CircuitBreaker $circuitBreaker Circuit breaker for fault tolerance
     */
    public function __construct(
        SesClient $client,
        LoggerInterface $logger,
        CircuitBreaker $circuitBreaker
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->circuitBreaker = $circuitBreaker;
    }

    /**
     * Sends an email notification through Amazon SES with batch processing support.
     *
     * @param array<string, mixed> $payload Email notification payload
     * @return array<string, mixed> Standardized delivery response
     * @throws VendorException When delivery fails or service is unavailable
     */
    public function send(array $payload): array
    {
        if (!$this->circuitBreaker->isAvailable()) {
            throw new VendorException(
                'SES service is currently unavailable',
                VendorException::VENDOR_CIRCUIT_OPEN,
                null,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'circuit_breaker_open' => true
                ]
            );
        }

        try {
            $this->validatePayload($payload);
            
            $emailParams = $this->transformPayload($payload);
            
            $response = $this->sendWithRetry($emailParams);
            
            $this->circuitBreaker->recordSuccess();
            
            $this->logger->info('Email sent successfully via SES', [
                'message_id' => $response['MessageId'],
                'vendor' => self::VENDOR_NAME,
                'recipient' => $payload['recipient']
            ]);

            return [
                'messageId' => $response['MessageId'],
                'status' => 'sent',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'vendorResponse' => $response,
                'metadata' => [
                    'vendor' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'requestId' => $response['@metadata']['requestId'] ?? null
                ]
            ];
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            
            throw new VendorException(
                'Failed to send email via SES: ' . $e->getMessage(),
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'error_type' => get_class($e),
                    'error_message' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Retrieves the delivery status of an email from SES.
     *
     * @param string $messageId SES message ID
     * @return array<string, mixed> Detailed status information
     * @throws VendorException When status check fails
     */
    public function getStatus(string $messageId): array
    {
        try {
            $result = $this->client->getMessageStatus([
                'MessageId' => $messageId
            ]);

            return [
                'currentState' => $this->mapSesStatus($result['Status']),
                'timestamps' => [
                    'sent' => $result['SendTimestamp'] ?? null,
                    'delivered' => $result['DeliveryTimestamp'] ?? null,
                    'failed' => $result['FailureTimestamp'] ?? null
                ],
                'attempts' => $result['RetryAttempts'] ?? 1,
                'vendorMetadata' => $result
            ];
        } catch (\Throwable $e) {
            throw new VendorException(
                'Failed to check message status: ' . $e->getMessage(),
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
     * Performs comprehensive health check of SES service.
     *
     * @return array<string, mixed> Detailed health status
     * @throws VendorException When health check fails critically
     */
    public function checkHealth(): array
    {
        try {
            $startTime = microtime(true);
            
            $quotaResponse = $this->client->getSendQuota();
            
            $latency = (microtime(true) - $startTime) * 1000;
            
            $state = $this->circuitBreaker->getState();
            
            return [
                'isHealthy' => true,
                'latency' => round($latency, 2),
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'diagnostics' => [
                    'quotaMax' => $quotaResponse['Max24HourSend'],
                    'quotaUsed' => $quotaResponse['SentLast24Hours'],
                    'sendRate' => $quotaResponse['MaxSendRate'],
                    'circuitBreakerState' => $state['state'],
                    'failureCount' => $state['failure_count']
                ],
                'lastError' => null
            ];
        } catch (\Throwable $e) {
            throw new VendorException(
                'SES health check failed: ' . $e->getMessage(),
                VendorException::VENDOR_UNAVAILABLE,
                $e,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE
                ]
            );
        }
    }

    /**
     * Returns the vendor name identifier.
     *
     * @return string Vendor name
     */
    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    /**
     * Returns the vendor channel type.
     *
     * @return string Channel type
     */
    public function getVendorType(): string
    {
        return self::VENDOR_TYPE;
    }

    /**
     * Validates email payload structure and content.
     *
     * @param array<string, mixed> $payload Email payload
     * @throws \InvalidArgumentException When payload is invalid
     */
    private function validatePayload(array $payload): void
    {
        if (empty($payload['recipient']) || !filter_var($payload['recipient'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid recipient email address');
        }

        if (empty($payload['content']['subject'])) {
            throw new \InvalidArgumentException('Email subject is required');
        }

        if (empty($payload['content']['html']) && empty($payload['content']['text'])) {
            throw new \InvalidArgumentException('Email must contain either HTML or text content');
        }
    }

    /**
     * Transforms notification payload to SES-specific format.
     *
     * @param array<string, mixed> $payload Original payload
     * @return array<string, mixed> SES-formatted payload
     */
    private function transformPayload(array $payload): array
    {
        $sesPayload = [
            'Source' => $payload['sender'] ?? getenv('SES_DEFAULT_SENDER'),
            'Destination' => [
                'ToAddresses' => [$payload['recipient']]
            ],
            'Message' => [
                'Subject' => [
                    'Data' => $payload['content']['subject'],
                    'Charset' => 'UTF-8'
                ],
                'Body' => []
            ]
        ];

        if (!empty($payload['content']['html'])) {
            $sesPayload['Message']['Body']['Html'] = [
                'Data' => $payload['content']['html'],
                'Charset' => 'UTF-8'
            ];
        }

        if (!empty($payload['content']['text'])) {
            $sesPayload['Message']['Body']['Text'] = [
                'Data' => $payload['content']['text'],
                'Charset' => 'UTF-8'
            ];
        }

        if (!empty($payload['options']['configuration_set'])) {
            $sesPayload['ConfigurationSetName'] = $payload['options']['configuration_set'];
        }

        return $sesPayload;
    }

    /**
     * Sends email with retry mechanism.
     *
     * @param array<string, mixed> $emailParams SES email parameters
     * @return array<string, mixed> SES response
     * @throws \RuntimeException When all retries fail
     */
    private function sendWithRetry(array $emailParams): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                return $this->client->sendEmail($emailParams)->toArray();
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;
                
                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                }
            }
        }

        throw new \RuntimeException(
            'Failed to send email after ' . self::MAX_RETRIES . ' attempts',
            0,
            $lastException
        );
    }

    /**
     * Maps SES-specific status to standardized status.
     *
     * @param string $sesStatus SES status
     * @return string Standardized status
     */
    private function mapSesStatus(string $sesStatus): string
    {
        return match (strtoupper($sesStatus)) {
            'SUCCESS' => 'delivered',
            'FAILED' => 'failed',
            'PENDING' => 'pending',
            default => 'unknown'
        };
    }
}