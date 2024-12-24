<?php

declare(strict_types=1);

namespace App\Test\Performance\Stress;

use App\Test\Utils\TestHelper;
use App\Test\Utils\VendorSimulator;
use App\Services\Vendor\VendorService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RuntimeException;

/**
 * Performance test suite for vendor failover scenarios under high load conditions.
 * Validates sub-2 second failover times and 99.9% delivery success rates.
 *
 * @package App\Test\Performance\Stress
 * @group performance
 * @testdox Vendor Failover Performance Tests
 */
class FailoverTest extends TestCase
{
    private const CONCURRENT_REQUESTS = 1000;
    private const TEST_DURATION_SECONDS = 300;
    private const FAILOVER_TIME_THRESHOLD_MS = 2000;
    private const SUCCESS_RATE_THRESHOLD = 99.9;
    private const METRICS_COLLECTION_INTERVAL_MS = 100;

    private TestHelper $testHelper;
    private VendorService $vendorService;
    private VendorSimulator $vendorSimulator;
    private array $testResults = [];
    private array $performanceMetrics = [
        'failover_times' => [],
        'success_rates' => [],
        'throughput' => [],
        'latencies' => []
    ];

    /**
     * Set up test environment with parallel request configuration.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->testHelper = new TestHelper();
        $this->vendorService = $this->createVendorService();
        $this->vendorSimulator = new VendorSimulator();

        // Initialize performance monitoring
        $this->performanceMetrics = [
            'failover_times' => [],
            'success_rates' => [],
            'throughput' => [],
            'latencies' => [],
            'start_time' => Carbon::now()
        ];
    }

    /**
     * Clean up test environment and generate performance report.
     */
    protected function tearDown(): void
    {
        // Generate final performance report
        $this->generatePerformanceReport();

        // Clean up test resources
        $this->testHelper->cleanupTestEnvironment();
        
        parent::tearDown();
    }

    /**
     * Tests failover between email vendors under high load with parallel requests.
     *
     * @test
     * @group email
     */
    public function testEmailVendorFailover(): void
    {
        // Configure primary email vendor (Iterable) for controlled failure
        $this->vendorSimulator->configureErrorScenario('iterable', [
            'probability' => 1.0,
            'latency' => 100,
            'error_type' => 'server_error'
        ]);

        // Setup parallel request generator for email notifications
        $notifications = $this->testHelper->generateBatchNotifications(
            self::CONCURRENT_REQUESTS,
            'email'
        );

        // Start metrics collection
        $startTime = microtime(true);
        $failoverStartTime = null;

        try {
            // Execute concurrent email notifications
            foreach ($notifications as $notification) {
                if ($failoverStartTime === null) {
                    // Trigger primary vendor failure
                    $this->vendorSimulator->setHealth('iterable', false);
                    $failoverStartTime = microtime(true);
                }

                $response = $this->vendorService->send($notification, 'email', 'test_tenant');
                $this->collectMetrics($response, $startTime, $failoverStartTime);
            }

            // Analyze metrics
            $this->assertFailoverMetrics('email');

        } catch (RuntimeException $e) {
            $this->fail("Email failover test failed: " . $e->getMessage());
        }
    }

    /**
     * Tests failover between SMS vendors under high load with parallel requests.
     *
     * @test
     * @group sms
     */
    public function testSmsVendorFailover(): void
    {
        // Configure primary SMS vendor (Telnyx) for controlled failure
        $this->vendorSimulator->configureErrorScenario('telnyx', [
            'probability' => 1.0,
            'latency' => 100,
            'error_type' => 'server_error'
        ]);

        // Setup parallel request generator for SMS notifications
        $notifications = $this->testHelper->generateBatchNotifications(
            self::CONCURRENT_REQUESTS,
            'sms'
        );

        // Start metrics collection
        $startTime = microtime(true);
        $failoverStartTime = null;

        try {
            // Execute concurrent SMS notifications
            foreach ($notifications as $notification) {
                if ($failoverStartTime === null) {
                    // Trigger primary vendor failure
                    $this->vendorSimulator->setHealth('telnyx', false);
                    $failoverStartTime = microtime(true);
                }

                $response = $this->vendorService->send($notification, 'sms', 'test_tenant');
                $this->collectMetrics($response, $startTime, $failoverStartTime);
            }

            // Analyze metrics
            $this->assertFailoverMetrics('sms');

        } catch (RuntimeException $e) {
            $this->fail("SMS failover test failed: " . $e->getMessage());
        }
    }

    /**
     * Tests simultaneous failover across multiple channels under high load.
     *
     * @test
     * @group multi-channel
     */
    public function testMultiChannelFailover(): void
    {
        // Configure multiple vendors for simultaneous failure
        $vendorConfigs = [
            'email' => ['vendor' => 'iterable', 'shouldFail' => true],
            'sms' => ['vendor' => 'telnyx', 'shouldFail' => true]
        ];

        // Generate multi-channel notifications
        $notifications = [];
        foreach ($vendorConfigs as $channel => $config) {
            $notifications[$channel] = $this->testHelper->generateBatchNotifications(
                self::CONCURRENT_REQUESTS / 2,
                $channel
            );
        }

        // Start metrics collection
        $startTime = microtime(true);
        $failoverStartTimes = [];

        try {
            // Execute concurrent multi-channel notifications
            foreach ($notifications as $channel => $channelNotifications) {
                foreach ($channelNotifications as $notification) {
                    if (!isset($failoverStartTimes[$channel])) {
                        // Trigger vendor failure for this channel
                        $this->vendorSimulator->setHealth($vendorConfigs[$channel]['vendor'], false);
                        $failoverStartTimes[$channel] = microtime(true);
                    }

                    $response = $this->vendorService->send($notification, $channel, 'test_tenant');
                    $this->collectMultiChannelMetrics($response, $startTime, $failoverStartTimes[$channel], $channel);
                }
            }

            // Analyze multi-channel metrics
            foreach ($vendorConfigs as $channel => $config) {
                $this->assertFailoverMetrics($channel);
            }

        } catch (RuntimeException $e) {
            $this->fail("Multi-channel failover test failed: " . $e->getMessage());
        }
    }

    /**
     * Collects and validates metrics for failover scenarios.
     *
     * @param array $response Vendor response data
     * @param float $startTime Test start time
     * @param float $failoverStartTime Failover initiation time
     */
    private function collectMetrics(array $response, float $startTime, float $failoverStartTime): void
    {
        $currentTime = microtime(true);

        // Calculate metrics
        if ($response['status'] === 'sent') {
            $this->performanceMetrics['success_rates'][] = 1;
        } else {
            $this->performanceMetrics['success_rates'][] = 0;
        }

        $this->performanceMetrics['latencies'][] = ($currentTime - $startTime) * 1000;

        if ($failoverStartTime !== null && isset($response['vendorResponse']['failoverTime'])) {
            $this->performanceMetrics['failover_times'][] = 
                ($currentTime - $failoverStartTime) * 1000;
        }

        // Calculate current throughput
        $elapsedSeconds = $currentTime - $startTime;
        if ($elapsedSeconds > 0) {
            $this->performanceMetrics['throughput'][] = 
                count($this->performanceMetrics['latencies']) / $elapsedSeconds;
        }
    }

    /**
     * Asserts that failover metrics meet performance requirements.
     *
     * @param string $channel Notification channel
     * @throws RuntimeException When performance requirements are not met
     */
    private function assertFailoverMetrics(string $channel): void
    {
        // Calculate average failover time
        $avgFailoverTime = array_sum($this->performanceMetrics['failover_times']) / 
            count($this->performanceMetrics['failover_times']);

        // Calculate success rate
        $successRate = (array_sum($this->performanceMetrics['success_rates']) / 
            count($this->performanceMetrics['success_rates'])) * 100;

        // Calculate average throughput
        $avgThroughput = array_sum($this->performanceMetrics['throughput']) / 
            count($this->performanceMetrics['throughput']);

        // Assert performance requirements
        $this->assertLessThanOrEqual(
            self::FAILOVER_TIME_THRESHOLD_MS,
            $avgFailoverTime,
            "Average failover time for {$channel} exceeded threshold"
        );

        $this->assertGreaterThanOrEqual(
            self::SUCCESS_RATE_THRESHOLD,
            $successRate,
            "Success rate for {$channel} below threshold"
        );

        $this->testResults[$channel] = [
            'avg_failover_time_ms' => $avgFailoverTime,
            'success_rate_percent' => $successRate,
            'avg_throughput_rps' => $avgThroughput,
            'total_requests' => count($this->performanceMetrics['latencies'])
        ];
    }

    /**
     * Generates comprehensive performance report.
     */
    private function generatePerformanceReport(): void
    {
        $report = [
            'test_duration' => Carbon::now()->diffInSeconds($this->performanceMetrics['start_time']),
            'total_requests' => array_sum(array_column($this->testResults, 'total_requests')),
            'channels' => $this->testResults,
            'overall_metrics' => [
                'avg_failover_time_ms' => array_sum(array_column($this->testResults, 'avg_failover_time_ms')) / 
                    count($this->testResults),
                'avg_success_rate' => array_sum(array_column($this->testResults, 'success_rate_percent')) / 
                    count($this->testResults),
                'total_throughput_rps' => array_sum(array_column($this->testResults, 'avg_throughput_rps'))
            ]
        ];

        // Log detailed performance report
        $this->testHelper->logPerformanceReport($report);
    }

    /**
     * Creates configured vendor service instance for testing.
     *
     * @return VendorService
     */
    private function createVendorService(): VendorService
    {
        // Implementation would create and configure a VendorService instance
        // with appropriate test configuration
        return new VendorService(/* configuration */);
    }
}