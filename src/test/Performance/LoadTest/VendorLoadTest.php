<?php

declare(strict_types=1);

namespace App\Test\Performance\LoadTest;

use App\Test\Utils\TestHelper;
use App\Test\Utils\VendorSimulator;
use App\Services\Vendor\VendorService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Performance load test suite for testing vendor service throughput, latency,
 * and failover capabilities under high load conditions.
 *
 * Tests requirements:
 * - 100,000+ messages per minute throughput
 * - 99.9% delivery success rate
 * - Vendor failover under 2 seconds
 *
 * @package App\Test\Performance\LoadTest
 * @version 1.0.0
 */
class VendorLoadTest extends TestCase
{
    private const LOAD_TEST_DURATION_SECONDS = 60;
    private const TARGET_RPS = 1667; // 100k per minute
    private const CONCURRENT_USERS = 100;
    private const BATCH_SIZE = 1000;
    private const MAX_RETRY_ATTEMPTS = 3;

    private VendorService $vendorService;
    private VendorSimulator $vendorSimulator;
    private Stopwatch $stopwatch;
    private array $performanceMetrics = [
        'throughput' => [],
        'latencies' => [],
        'success_rate' => 0,
        'failover_times' => [],
    ];

    /**
     * Set up test environment and initialize performance monitoring.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize test environment
        TestHelper::setupTestEnvironment([
            'performance_mode' => true,
            'monitoring_enabled' => true,
            'batch_size' => self::BATCH_SIZE,
        ]);

        // Configure vendor simulator
        $this->vendorSimulator = new VendorSimulator();

        // Initialize performance monitoring
        $this->stopwatch = new Stopwatch(true);
    }

    /**
     * Clean up test environment and collect final metrics.
     */
    protected function tearDown(): void
    {
        // Store final metrics
        $this->logPerformanceMetrics();

        // Clean up test environment
        TestHelper::cleanupTestEnvironment();

        parent::tearDown();
    }

    /**
     * Tests vendor service throughput under high load with detailed metrics.
     * Verifies 100,000+ messages per minute processing capability.
     */
    public function testVendorThroughput(): void
    {
        // Generate test notifications
        $notifications = TestHelper::generateBatchNotifications(
            self::BATCH_SIZE * self::CONCURRENT_USERS,
            'email'
        );

        $this->stopwatch->start('throughput_test');

        // Process notifications with concurrent load
        $processedCount = 0;
        $successCount = 0;
        $startTime = microtime(true);

        foreach (array_chunk($notifications, self::BATCH_SIZE) as $batch) {
            // Simulate concurrent processing
            $responses = $this->vendorSimulator->simulateConcurrentLoad(
                $batch,
                self::CONCURRENT_USERS,
                ['channel' => 'email']
            );

            foreach ($responses as $response) {
                $processedCount++;
                if ($response['status'] === 'sent') {
                    $successCount++;
                }
                $this->performanceMetrics['latencies'][] = $response['metadata']['latency'];
            }

            // Calculate current throughput
            $elapsedTime = microtime(true) - $startTime;
            $currentThroughput = $processedCount / ($elapsedTime / 60);
            $this->performanceMetrics['throughput'][] = $currentThroughput;

            // Verify we maintain required throughput
            $this->assertGreaterThanOrEqual(
                self::TARGET_RPS * 60,
                $currentThroughput,
                'Failed to maintain required throughput of 100,000 messages per minute'
            );
        }

        $event = $this->stopwatch->stop('throughput_test');

        // Calculate final success rate
        $successRate = $successCount / $processedCount;
        $this->performanceMetrics['success_rate'] = $successRate;

        // Assert success rate meets requirement
        $this->assertGreaterThanOrEqual(
            0.999,
            $successRate,
            'Failed to maintain 99.9% success rate under load'
        );

        // Assert memory usage is within bounds
        $this->assertLessThan(
            256 * 1024 * 1024, // 256MB
            $event->getMemory(),
            'Memory usage exceeded limits during load test'
        );
    }

    /**
     * Tests vendor failover performance under load with precise timing.
     * Verifies failover completes within 2 seconds.
     */
    public function testVendorFailover(): void
    {
        // Configure primary vendor for failure
        $this->vendorSimulator->simulateHealthCheck('iterable', 100, false);

        // Generate test batch
        $notifications = TestHelper::generateBatchNotifications(self::BATCH_SIZE, 'email');

        $this->stopwatch->start('failover_test');

        foreach ($notifications as $notification) {
            // Trigger vendor failure and measure failover time
            $startTime = microtime(true);

            $response = $this->vendorSimulator->simulateFailover(
                [
                    ['vendor' => 'iterable', 'shouldFail' => true],
                    ['vendor' => 'sendgrid', 'shouldFail' => false],
                ],
                $notification
            );

            $failoverTime = ($response['totalTime']);
            $this->performanceMetrics['failover_times'][] = $failoverTime;

            // Assert failover time meets requirement
            $this->assertLessThanOrEqual(
                2000, // 2 seconds in ms
                $failoverTime,
                'Vendor failover exceeded maximum time of 2 seconds'
            );

            // Verify successful delivery through backup vendor
            $this->assertTrue(
                $response['successful'],
                'Failover did not result in successful delivery'
            );
        }

        $this->stopwatch->stop('failover_test');
    }

    /**
     * Tests vendor service performance with concurrent user load.
     * Verifies system stability and performance under concurrent access.
     */
    public function testConcurrentVendorRequests(): void
    {
        $this->stopwatch->start('concurrent_test');

        // Generate unique test data for each concurrent user
        $userNotifications = [];
        for ($i = 0; $i < self::CONCURRENT_USERS; $i++) {
            $userNotifications[$i] = TestHelper::generateTestNotification('email', [
                'metadata' => ['user_id' => "test_user_{$i}"]
            ]);
        }

        // Execute concurrent requests
        $responses = $this->vendorSimulator->simulateConcurrentLoad(
            $userNotifications,
            self::CONCURRENT_USERS,
            [
                'channel' => 'email',
                'timeout' => 30,
                'retry_attempts' => self::MAX_RETRY_ATTEMPTS
            ]
        );

        // Analyze response times and success rates
        $successCount = 0;
        $totalLatency = 0;

        foreach ($responses as $response) {
            if ($response['status'] === 'sent') {
                $successCount++;
            }
            $totalLatency += $response['metadata']['latency'];
        }

        $avgLatency = $totalLatency / count($responses);
        $successRate = $successCount / count($responses);

        // Assert performance metrics
        $this->assertGreaterThanOrEqual(
            0.999,
            $successRate,
            'Concurrent requests failed to maintain required success rate'
        );

        $this->assertLessThanOrEqual(
            1000, // 1 second
            $avgLatency,
            'Average response time exceeded acceptable threshold'
        );

        $this->stopwatch->stop('concurrent_test');
    }

    /**
     * Logs detailed performance metrics for analysis.
     */
    private function logPerformanceMetrics(): void
    {
        $metrics = [
            'throughput' => [
                'average' => array_sum($this->performanceMetrics['throughput']) / count($this->performanceMetrics['throughput']),
                'peak' => max($this->performanceMetrics['throughput']),
                'minimum' => min($this->performanceMetrics['throughput'])
            ],
            'latency' => [
                'average' => array_sum($this->performanceMetrics['latencies']) / count($this->performanceMetrics['latencies']),
                'p95' => $this->calculatePercentile($this->performanceMetrics['latencies'], 95),
                'p99' => $this->calculatePercentile($this->performanceMetrics['latencies'], 99)
            ],
            'success_rate' => $this->performanceMetrics['success_rate'],
            'failover' => [
                'average' => array_sum($this->performanceMetrics['failover_times']) / count($this->performanceMetrics['failover_times']),
                'maximum' => max($this->performanceMetrics['failover_times'])
            ]
        ];

        // Log metrics for analysis
        error_log(sprintf(
            "Performance Test Results:\n%s",
            json_encode($metrics, JSON_PRETTY_PRINT)
        ));
    }

    /**
     * Calculates percentile value from array of measurements.
     *
     * @param array $measurements Array of measurement values
     * @param int $percentile Percentile to calculate (0-100)
     * @return float Calculated percentile value
     */
    private function calculatePercentile(array $measurements, int $percentile): float
    {
        sort($measurements);
        $index = ceil(count($measurements) * $percentile / 100) - 1;
        return $measurements[$index];
    }
}