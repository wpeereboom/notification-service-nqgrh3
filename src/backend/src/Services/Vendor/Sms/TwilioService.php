<?php

declare(strict_types=1);

namespace App\Services\Vendor\Sms;

use App\Contracts\VendorInterface;
use App\Exceptions\VendorException;
use App\Utils\CircuitBreaker;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;
use Twilio\Rest\Client; // ^7.0
use Twilio\Exceptions\TwilioException;
use Twilio\Exceptions\RestException;

/**
 * Enhanced Twilio SMS service implementation with circuit breaker pattern,
 * response caching, and comprehensive monitoring for high-throughput delivery.
 *
 * @package App\Services\Vendor\Sms
 * @version 1.0.0
 */
class TwilioService implements VendorInterface
{
    private const VENDOR_NAME = 'twilio';
    private const VENDOR_TYPE = 'sms';
    private const MAX_RETRIES = 3;
    private const CACHE_TTL = 300; // 5 minutes
    private const HEALTH_CHECK_INTERVAL = 30; // seconds

    private Client $twilioClient;
    private LoggerInterface $logger;
    private CircuitBreaker $circuitBreaker;
    private Redis $cache;
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;
    private ?int $lastHealthCheck = null;

    /**
     * Initializes Twilio service with enhanced monitoring and caching.
     *
     * @param LoggerInterface $logger Logger instance
     * @param Redis $redis Cache client
     * @param string $accountSid Twilio account SID
     * @param string $authToken Twilio auth token
     * @param string $fromNumber Sender phone number
     */
    public function __construct(
        LoggerInterface $logger,
        Redis $redis,
        string $accountSid,
        string $authToken,
        string $fromNumber
    ) {
        $this->logger = $logger;
        $this->cache = $redis;
        $this->accountSid = $accountSid;
        $this->authToken = $authToken;
        $this->fromNumber = $fromNumber;

        $this->twilioClient = new Client($accountSid, $authToken);
        $this->circuitBreaker = new CircuitBreaker(
            $redis,
            $logger,
            self::VENDOR_NAME,
            self::VENDOR_TYPE,
            'default'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function send(array $payload): array
    {
        if (!$this->validatePayload($payload)) {
            throw new VendorException(
                'Invalid SMS payload',
                VendorException::VENDOR_INVALID_REQUEST,
                null,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'validation_errors' => $this->getValidationErrors($payload)
                ]
            );
        }

        if (!$this->circuitBreaker->isAvailable()) {
            throw new VendorException(
                'Twilio service is currently unavailable',
                VendorException::VENDOR_CIRCUIT_OPEN,
                null,
                [
                    'vendor_name' => self::VENDOR_NAME,
                    'channel' => self::VENDOR_TYPE,
                    'circuit_breaker_open' => true
                ]
            );
        }

        $attempts = 0;
        $lastException = null;

        do {
            try {
                $message = $this->twilioClient->messages->create(
                    $payload['recipient'],
                    [
                        'from' => $this->fromNumber,
                        'body' => $payload['content']['body'],
                        'statusCallback' => $payload['options']['statusCallback'] ?? null,
                    ]
                );

                $this->circuitBreaker->recordSuccess();

                $response = [
                    'messageId' => $message->sid,
                    'status' => 'sent',
                    'timestamp' => date('c'),
                    'vendorResponse' => [
                        'sid' => $message->sid,
                        'status' => $message->status,
                        'price' => $message->price,
                        'priceUnit' => $message->priceUnit
                    ],
                    'metadata' => [
                        'attempts' => $attempts + 1,
                        'vendor' => self::VENDOR_NAME,
                        'channel' => self::VENDOR_TYPE
                    ]
                ];

                $this->cache->setex(
                    "sms:status:{$message->sid}",
                    self::CACHE_TTL,
                    json_encode($response)
                );

                $this->logger->info('SMS sent successfully via Twilio', $response);

                return $response;

            } catch (RestException $e) {
                $lastException = $e;
                $attempts++;

                $this->logger->warning('Twilio SMS delivery attempt failed', [
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'recipient' => $payload['recipient']
                ]);

                if ($this->isRetryableError($e)) {
                    usleep(min(100000 * $attempts, 1000000)); // Exponential backoff
                    continue;
                }

                $this->circuitBreaker->recordFailure();
                break;
            }
        } while ($attempts < self::MAX_RETRIES);

        throw new VendorException(
            'Failed to send SMS via Twilio after ' . $attempts . ' attempts',
            VendorException::VENDOR_UNAVAILABLE,
            $lastException,
            [
                'vendor_name' => self::VENDOR_NAME,
                'channel' => self::VENDOR_TYPE,
                'attempts' => $attempts,
                'last_error' => $lastException ? $lastException->getMessage() : null
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(string $messageId): array
    {
        $cachedStatus = $this->cache->get("sms:status:{$messageId}");
        if ($cachedStatus) {
            return json_decode($cachedStatus, true);
        }

        try {
            $message = $this->twilioClient->messages($messageId)->fetch();

            $status = [
                'currentState' => $this->normalizeStatus($message->status),
                'timestamps' => [
                    'sent' => $message->dateSent?->format('c'),
                    'updated' => $message->dateUpdated->format('c')
                ],
                'attempts' => 1,
                'vendorMetadata' => [
                    'sid' => $message->sid,
                    'status' => $message->status,
                    'errorCode' => $message->errorCode,
                    'errorMessage' => $message->errorMessage,
                    'price' => $message->price,
                    'priceUnit' => $message->priceUnit
                ]
            ];

            $this->cache->setex(
                "sms:status:{$messageId}",
                self::CACHE_TTL,
                json_encode($status)
            );

            return $status;

        } catch (TwilioException $e) {
            throw new VendorException(
                'Failed to retrieve SMS status',
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
     * {@inheritdoc}
     */
    public function checkHealth(): array
    {
        $currentTime = time();
        
        // Return cached health status if within interval
        if ($this->lastHealthCheck && ($currentTime - $this->lastHealthCheck) < self::HEALTH_CHECK_INTERVAL) {
            return [
                'isHealthy' => $this->circuitBreaker->isAvailable(),
                'latency' => 0,
                'timestamp' => date('c', $this->lastHealthCheck),
                'cached' => true
            ];
        }

        $startTime = microtime(true);
        $isHealthy = true;
        $diagnostics = [];
        $lastError = null;

        try {
            // Verify credentials and API access
            $this->twilioClient->api->v2010->account->fetch();
            
            $circuitState = $this->circuitBreaker->getState();
            $diagnostics = [
                'circuit_state' => $circuitState['state'],
                'failure_count' => $circuitState['failure_count'],
                'api_accessible' => true
            ];

        } catch (TwilioException $e) {
            $isHealthy = false;
            $lastError = $e->getMessage();
            $this->circuitBreaker->recordFailure();
            
            $diagnostics['api_accessible'] = false;
            $diagnostics['error_code'] = $e->getCode();
        }

        $endTime = microtime(true);
        $latency = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $this->lastHealthCheck = $currentTime;

        return [
            'isHealthy' => $isHealthy && $this->circuitBreaker->isAvailable(),
            'latency' => round($latency, 2),
            'timestamp' => date('c'),
            'diagnostics' => $diagnostics,
            'lastError' => $lastError
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function getVendorType(): string
    {
        return self::VENDOR_TYPE;
    }

    /**
     * Validates SMS payload structure and content.
     *
     * @param array $payload The payload to validate
     * @return bool True if payload is valid
     */
    private function validatePayload(array $payload): bool
    {
        return isset($payload['recipient'])
            && isset($payload['content']['body'])
            && $this->isValidPhoneNumber($payload['recipient'])
            && strlen($payload['content']['body']) <= 1600;
    }

    /**
     * Gets detailed validation errors for a payload.
     *
     * @param array $payload The payload to validate
     * @return array List of validation errors
     */
    private function getValidationErrors(array $payload): array
    {
        $errors = [];

        if (!isset($payload['recipient'])) {
            $errors[] = 'Recipient phone number is required';
        } elseif (!$this->isValidPhoneNumber($payload['recipient'])) {
            $errors[] = 'Invalid phone number format';
        }

        if (!isset($payload['content']['body'])) {
            $errors[] = 'Message body is required';
        } elseif (strlen($payload['content']['body']) > 1600) {
            $errors[] = 'Message body exceeds maximum length of 1600 characters';
        }

        return $errors;
    }

    /**
     * Validates phone number format.
     *
     * @param string $phoneNumber Phone number to validate
     * @return bool True if phone number is valid
     */
    private function isValidPhoneNumber(string $phoneNumber): bool
    {
        return preg_match('/^\+[1-9]\d{1,14}$/', $phoneNumber) === 1;
    }

    /**
     * Determines if an error is retryable.
     *
     * @param RestException $e The exception to check
     * @return bool True if error is retryable
     */
    private function isRetryableError(RestException $e): bool
    {
        $retryCodes = [
            20429, // Rate limit exceeded
            20001, // Connection error
            20002, // Timeout error
            20003, // Network error
            50001, // Internal server error
            50002, // Service unavailable
        ];

        return in_array($e->getCode(), $retryCodes, true);
    }

    /**
     * Normalizes Twilio status to standard format.
     *
     * @param string $twilioStatus Raw Twilio status
     * @return string Normalized status
     */
    private function normalizeStatus(string $twilioStatus): string
    {
        $statusMap = [
            'queued' => 'pending',
            'sending' => 'pending',
            'sent' => 'delivered',
            'delivered' => 'delivered',
            'undelivered' => 'failed',
            'failed' => 'failed'
        ];

        return $statusMap[$twilioStatus] ?? 'unknown';
    }
}