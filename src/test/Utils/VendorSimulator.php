<?php

declare(strict_types=1);

namespace App\Test\Utils;

use App\Test\Mocks\VendorMock;
use PHPUnit\Framework\TestCase;
use JsonException;

/**
 * Static utility class for simulating vendor API behaviors in tests.
 * Provides configurable response patterns, latency, and failure scenarios
 * for comprehensive testing of the notification delivery system.
 *
 * @package App\Test\Utils
 */
final class VendorSimulator
{
    /**
     * Default simulated latency in milliseconds
     */
    private const DEFAULT_LATENCY_MS = 50;

    /**
     * Maximum allowed latency for failover testing (2 seconds)
     */
    private const MAX_LATENCY_MS = 2000;

    /**
     * Path to vendor response fixture file
     */
    private const VENDOR_RESPONSES_PATH = __DIR__ . '/../Fixtures/vendor_responses.json';

    /**
     * Supported vendor types for simulation
     */
    private const SUPPORTED_VENDORS = [
        'email' => ['iterable', 'sendgrid', 'ses'],
        'sms' => ['telnyx', 'twilio'],
        'push' => ['sns']
    ];

    /**
     * Private constructor to prevent instantiation
     */
    private function __construct()
    {
        throw new \RuntimeException('VendorSimulator is a static utility class and cannot be instantiated');
    }

    /**
     * Simulates a vendor API response with configurable latency and failure modes.
     *
     * @param string $vendorName Name of the vendor to simulate (e.g., 'iterable', 'sendgrid')
     * @param array<string, mixed> $payload The notification payload
     * @param int $latencyMs Simulated response latency in milliseconds
     * @param bool $shouldFail Whether the response should simulate a failure
     * 
     * @return array<string, mixed> Simulated vendor response
     * 
     * @throws \InvalidArgumentException If vendor name is invalid
     * @throws \RuntimeException If response templates cannot be loaded
     */
    public static function simulateVendorResponse(
        string $vendorName,
        array $payload,
        int $latencyMs = self::DEFAULT_LATENCY_MS,
        bool $shouldFail = false
    ): array {
        // Validate vendor name
        $vendorFound = false;
        foreach (self::SUPPORTED_VENDORS as $vendors) {
            if (in_array($vendorName, $vendors, true)) {
                $vendorFound = true;
                break;
            }
        }
        if (!$vendorFound) {
            throw new \InvalidArgumentException("Unsupported vendor: {$vendorName}");
        }

        // Validate and cap latency
        $latencyMs = min($latencyMs, self::MAX_LATENCY_MS);
        
        // Load response templates
        $responseTemplates = self::loadVendorResponses();
        
        // Simulate processing time
        usleep($latencyMs * 1000);

        // Generate message ID and timestamp
        $messageId = uniqid($vendorName . '_', true);
        $timestamp = (new \DateTimeImmutable())->format('c');

        if ($shouldFail) {
            return [
                'messageId' => $messageId,
                'status' => 'failed',
                'timestamp' => $timestamp,
                'vendorResponse' => [
                    'error' => $responseTemplates[$vendorName]['errors']['default'] ?? 'Simulated failure',
                    'code' => 500,
                    'details' => [
                        'vendor' => $vendorName,
                        'latency' => $latencyMs,
                        'payload' => $payload
                    ]
                ]
            ];
        }

        return [
            'messageId' => $messageId,
            'status' => 'sent',
            'timestamp' => $timestamp,
            'vendorResponse' => [
                'success' => true,
                'vendorMessageId' => $vendorName . '_' . $messageId,
                'details' => [
                    'vendor' => $vendorName,
                    'latency' => $latencyMs,
                    'payload' => $payload
                ]
            ]
        ];
    }

    /**
     * Simulates a vendor health check with configurable response time.
     *
     * @param string $vendorName Name of the vendor to simulate
     * @param int $latencyMs Simulated response latency in milliseconds
     * @param bool $isHealthy Whether the vendor should report as healthy
     * 
     * @return array<string, mixed> Health check response
     * 
     * @throws \InvalidArgumentException If vendor name is invalid
     */
    public static function simulateHealthCheck(
        string $vendorName,
        int $latencyMs = self::DEFAULT_LATENCY_MS,
        bool $isHealthy = true
    ): array {
        // Validate vendor name
        $vendorFound = false;
        foreach (self::SUPPORTED_VENDORS as $vendors) {
            if (in_array($vendorName, $vendors, true)) {
                $vendorFound = true;
                break;
            }
        }
        if (!$vendorFound) {
            throw new \InvalidArgumentException("Unsupported vendor: {$vendorName}");
        }

        // Simulate processing time
        usleep($latencyMs * 1000);

        return [
            'isHealthy' => $isHealthy,
            'latency' => $latencyMs,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'diagnostics' => [
                'successRate' => $isHealthy ? 0.99 : 0.5,
                'throughput' => $isHealthy ? 1000 : 100,
                'messageCount' => 10000,
                'errorRates' => [
                    'timeout' => $isHealthy ? 0.001 : 0.1,
                    'api_error' => $isHealthy ? 0.001 : 0.2
                ]
            ],
            'lastError' => $isHealthy ? null : 'Simulated unhealthy state'
        ];
    }

    /**
     * Simulates vendor failover scenario with multiple vendors.
     *
     * @param array<string, mixed> $vendorConfigs Array of vendor configurations
     * @param array<string, mixed> $payload The notification payload
     * 
     * @return array<string, mixed> Failover results including timing and status
     * 
     * @throws \RuntimeException If no vendors are available
     */
    public static function simulateFailover(array $vendorConfigs, array $payload): array
    {
        $results = [
            'attempts' => [],
            'totalTime' => 0,
            'successful' => false,
            'finalVendor' => null
        ];

        $startTime = microtime(true);
        
        foreach ($vendorConfigs as $config) {
            $vendorName = $config['vendor'] ?? '';
            $latencyMs = $config['latency'] ?? self::DEFAULT_LATENCY_MS;
            $shouldFail = $config['shouldFail'] ?? false;

            $attemptStart = microtime(true);
            
            try {
                $response = self::simulateVendorResponse(
                    $vendorName,
                    $payload,
                    $latencyMs,
                    $shouldFail
                );

                $attemptDuration = (microtime(true) - $attemptStart) * 1000;

                $results['attempts'][] = [
                    'vendor' => $vendorName,
                    'duration' => $attemptDuration,
                    'success' => !$shouldFail,
                    'response' => $response
                ];

                if (!$shouldFail) {
                    $results['successful'] = true;
                    $results['finalVendor'] = $vendorName;
                    break;
                }
            } catch (\Exception $e) {
                $attemptDuration = (microtime(true) - $attemptStart) * 1000;
                $results['attempts'][] = [
                    'vendor' => $vendorName,
                    'duration' => $attemptDuration,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        $results['totalTime'] = (microtime(true) - $startTime) * 1000;
        
        return $results;
    }

    /**
     * Loads vendor response fixtures from JSON file.
     *
     * @return array<string, mixed> Vendor response templates
     * 
     * @throws \RuntimeException If fixture file cannot be loaded
     */
    private static function loadVendorResponses(): array
    {
        try {
            if (!file_exists(self::VENDOR_RESPONSES_PATH)) {
                throw new \RuntimeException('Vendor response fixtures file not found');
            }

            $content = file_get_contents(self::VENDOR_RESPONSES_PATH);
            if ($content === false) {
                throw new \RuntimeException('Failed to read vendor response fixtures');
            }

            $responses = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($responses)) {
                throw new \RuntimeException('Invalid vendor response fixture format');
            }

            return $responses;
        } catch (JsonException $e) {
            throw new \RuntimeException(
                'Failed to parse vendor response fixtures: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}