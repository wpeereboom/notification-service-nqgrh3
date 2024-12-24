<?php

declare(strict_types=1);

namespace App\Test\Mocks;

use App\Contracts\VendorInterface;
use Ramsey\Uuid\Uuid; // ^4.7
use SplFixedArray; // PHP built-in
use Symfony\Component\Lock\LockFactory; // ^6.3
use Symfony\Component\Lock\Store\InMemoryStore; // ^6.3

/**
 * Thread-safe mock implementation of VendorInterface for testing scenarios.
 * Supports high-throughput testing, failure simulation, and detailed delivery tracking.
 *
 * Features:
 * - Configurable success rates and latency
 * - Thread-safe message tracking
 * - Detailed metrics collection
 * - Error scenario simulation
 * - Memory-efficient message storage
 */
class VendorMock implements VendorInterface
{
    private const MOCK_VENDOR_NAME = 'mock_vendor';
    private const MOCK_VENDOR_TYPE = 'email';
    private const MOCK_SUCCESS_RATE = 0.99;
    private const MOCK_DEFAULT_LATENCY = 0.1;
    private const MOCK_ERROR_CODES = [
        'rate_limit' => 429,
        'server_error' => 500,
        'auth_error' => 401
    ];

    private bool $isHealthy = true;
    private SplFixedArray $sentMessages;
    private array $messageStatuses = [];
    private float $successRate;
    private float $latency;
    private array $messageTimestamps = [];
    private array $errorSimulation = [];
    private LockFactory $lockFactory;

    /**
     * Initialize mock vendor with configurable test parameters.
     *
     * @param float $successRate Success rate (0.0 to 1.0), defaults to MOCK_SUCCESS_RATE
     * @param float $latency Simulated processing latency in seconds, defaults to MOCK_DEFAULT_LATENCY
     */
    public function __construct(
        float $successRate = self::MOCK_SUCCESS_RATE,
        float $latency = self::MOCK_DEFAULT_LATENCY
    ) {
        $this->successRate = $successRate;
        $this->latency = $latency;
        $this->sentMessages = new SplFixedArray(100000); // Efficient fixed-size array
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    /**
     * {@inheritDoc}
     */
    public function send(array $payload): array
    {
        $lock = $this->lockFactory->createLock('vendor_mock_send');
        $lock->acquire(true);

        try {
            if (!$this->isHealthy) {
                throw new \RuntimeException('Vendor is currently unhealthy');
            }

            // Simulate configured latency
            usleep((int)($this->latency * 1000000));

            $messageId = Uuid::uuid4()->toString();
            $timestamp = (new \DateTimeImmutable())->format('c');

            // Store message for throughput tracking
            $this->messageTimestamps[] = microtime(true);
            $this->sentMessages->push([
                'id' => $messageId,
                'payload' => $payload,
                'timestamp' => $timestamp
            ]);

            // Check for configured error scenarios
            if (!empty($this->errorSimulation)) {
                foreach ($this->errorSimulation as $error => $config) {
                    if (rand(1, 100) <= ($config['probability'] * 100)) {
                        $this->messageStatuses[$messageId] = [
                            'status' => 'failed',
                            'error' => $error,
                            'code' => self::MOCK_ERROR_CODES[$error] ?? 500,
                            'timestamp' => $timestamp
                        ];
                        return [
                            'messageId' => $messageId,
                            'status' => 'failed',
                            'timestamp' => $timestamp,
                            'vendorResponse' => [
                                'error' => $error,
                                'code' => self::MOCK_ERROR_CODES[$error] ?? 500
                            ],
                            'metadata' => [
                                'latency' => $this->latency,
                                'vendor' => self::MOCK_VENDOR_NAME
                            ]
                        ];
                    }
                }
            }

            // Simulate success/failure based on configured rate
            $isSuccess = (mt_rand() / mt_getrandmax()) <= $this->successRate;

            $status = $isSuccess ? 'sent' : 'failed';
            $this->messageStatuses[$messageId] = [
                'status' => $status,
                'timestamp' => $timestamp,
                'attempts' => 1
            ];

            return [
                'messageId' => $messageId,
                'status' => $status,
                'timestamp' => $timestamp,
                'vendorResponse' => [
                    'success' => $isSuccess,
                    'vendorMessageId' => 'mock_' . $messageId
                ],
                'metadata' => [
                    'latency' => $this->latency,
                    'vendor' => self::MOCK_VENDOR_NAME,
                    'throughput' => $this->calculateCurrentThroughput()
                ]
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus(string $messageId): array
    {
        if (!isset($this->messageStatuses[$messageId])) {
            throw new \RuntimeException('Message not found');
        }

        $status = $this->messageStatuses[$messageId];
        return [
            'currentState' => $status['status'],
            'timestamps' => [
                'sent' => $status['timestamp'],
                'delivered' => $status['status'] === 'sent' ? $status['timestamp'] : null,
                'failed' => $status['status'] === 'failed' ? $status['timestamp'] : null
            ],
            'attempts' => $status['attempts'] ?? 1,
            'vendorMetadata' => [
                'vendor' => self::MOCK_VENDOR_NAME,
                'error' => $status['error'] ?? null
            ]
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function checkHealth(): array
    {
        $metrics = $this->getMetrics();
        
        return [
            'isHealthy' => $this->isHealthy,
            'latency' => $this->latency,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'diagnostics' => [
                'successRate' => $metrics['successRate'],
                'throughput' => $metrics['currentThroughput'],
                'messageCount' => $metrics['totalMessages'],
                'errorRates' => $metrics['errorRates']
            ],
            'lastError' => $this->isHealthy ? null : 'Simulated vendor outage'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getVendorName(): string
    {
        return self::MOCK_VENDOR_NAME;
    }

    /**
     * {@inheritDoc}
     */
    public function getVendorType(): string
    {
        return self::MOCK_VENDOR_TYPE;
    }

    /**
     * Sets the mock vendor health status for failover testing.
     *
     * @param bool $status New health status
     * @param array<string, mixed> $errorDetails Optional error details
     */
    public function setHealth(bool $status, array $errorDetails = []): void
    {
        $lock = $this->lockFactory->createLock('vendor_mock_health');
        $lock->acquire(true);

        try {
            $this->isHealthy = $status;
            if (!$status && !empty($errorDetails)) {
                $this->errorSimulation = $errorDetails;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Configures specific error scenarios for testing.
     *
     * @param string $errorType Type of error to simulate (rate_limit|server_error|auth_error)
     * @param array<string, mixed> $configuration Error configuration including probability
     */
    public function configureErrorScenario(string $errorType, array $configuration): void
    {
        if (!isset(self::MOCK_ERROR_CODES[$errorType])) {
            throw new \InvalidArgumentException('Invalid error type');
        }

        $this->errorSimulation[$errorType] = $configuration;
    }

    /**
     * Retrieves detailed testing metrics.
     *
     * @return array<string, mixed> Comprehensive metrics data
     */
    public function getMetrics(): array
    {
        $now = microtime(true);
        $recentTimestamps = array_filter(
            $this->messageTimestamps,
            fn($timestamp) => ($now - $timestamp) <= 60
        );

        $totalMessages = count($this->messageStatuses);
        $successfulMessages = count(array_filter(
            $this->messageStatuses,
            fn($status) => $status['status'] === 'sent'
        ));

        $errorRates = [];
        foreach ($this->errorSimulation as $error => $config) {
            $errorCount = count(array_filter(
                $this->messageStatuses,
                fn($status) => ($status['error'] ?? '') === $error
            ));
            $errorRates[$error] = $totalMessages > 0 ? $errorCount / $totalMessages : 0;
        }

        return [
            'currentThroughput' => count($recentTimestamps),
            'totalMessages' => $totalMessages,
            'successRate' => $totalMessages > 0 ? $successfulMessages / $totalMessages : 1,
            'averageLatency' => $this->latency,
            'errorRates' => $errorRates,
            'isHealthy' => $this->isHealthy
        ];
    }

    /**
     * Calculates current message throughput.
     *
     * @return int Messages processed in the last minute
     */
    private function calculateCurrentThroughput(): int
    {
        $now = microtime(true);
        return count(array_filter(
            $this->messageTimestamps,
            fn($timestamp) => ($now - $timestamp) <= 60
        ));
    }
}