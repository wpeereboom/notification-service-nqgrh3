<?php

declare(strict_types=1);

namespace App\Test\Performance\Stress;

use App\Test\Utils\TestHelper;
use App\Services\Notification\NotificationService;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Carbon\Carbon;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;

/**
 * Performance test suite for validating concurrent notification processing capabilities.
 * Tests system behavior under high load to ensure it meets throughput and latency requirements.
 *
 * Requirements tested:
 * - 100,000+ messages per minute throughput
 * - < 30 seconds processing latency (95th percentile)
 * - 99.95% system availability under load
 *
 * @package App\Test\Performance\Stress
 * @version 1.0.0
 */
class ConcurrencyTest extends TestCase
{
    /**
     * @var NotificationService Notification service instance
     */
    private NotificationService $notificationService;

    /**
     * @var Logger Performance metrics logger
     */
    private Logger $performanceLogger;

    /**
     * @var array Performance metrics collection
     */
    private array $metrics = [
        'throughput' => [],
        'latency' => [],
        'queue_depth' => [],
        'resource_usage' => [],
        'errors' => [],
    ];

    /**
     * @var array Resource utilization stats
     */
    private array $resourceStats = [
        'cpu' => [],
        'memory' => [],
        'connections' => [],
    ];

    /**
     * Set up test environment and initialize monitoring
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize performance logger
        $this->performanceLogger = new Logger('performance_tests');
        $handler = new StreamHandler(
            __DIR__ . '/../../../../logs/performance.log',
            Logger::INFO
        );
        $handler->setFormatter(new JsonFormatter());
        $this->performanceLogger->pushHandler($handler);

        // Set up test environment
        TestHelper::setupTestEnvironment();

        // Initialize notification service
        $this->notificationService = $this->initializeNotificationService();

        // Configure Swoole for concurrent testing
        Coroutine::set([
            'max_coroutine' => CONCURRENT_USERS * 2,
            'hook_flags' => SWOOLE_HOOK_ALL,
        ]);

        $this->performanceLogger->info('Test environment initialized', [
            'concurrent_users' => CONCURRENT_USERS,
            'test_duration' => TEST_DURATION_SECONDS,
            'requests_per_second' => REQUESTS_PER_SECOND,
        ]);
    }

    /**
     * Clean up test environment and save metrics
     */
    protected function tearDown(): void
    {
        // Generate performance report
        $this->generatePerformanceReport();

        // Clean up test environment
        TestHelper::cleanupTestEnvironment();

        // Save detailed metrics
        $this->saveMetrics();

        parent::tearDown();
    }

    /**
     * Tests system performance under concurrent notification load
     */
    public function testConcurrentNotificationProcessing(): void
    {
        $startTime = Carbon::now();
        $endTime = $startTime->copy()->addSeconds(TEST_DURATION_SECONDS);

        // Start resource monitoring
        go(function () use ($endTime) {
            while (Carbon::now()->lt($endTime)) {
                $this->monitorResourceUsage();
                Coroutine::sleep(1);
            }
        });

        // Generate test notifications
        $notifications = [];
        $channels = ['email', 'sms', 'push'];
        
        for ($i = 0; $i < REQUESTS_PER_SECOND * TEST_DURATION_SECONDS; $i++) {
            $channel = $channels[$i % count($channels)];
            $notifications[] = TestHelper::generateTestNotification($channel);
        }

        // Track concurrent processing
        $activeCoroutines = 0;
        $processedCount = 0;
        $batchSize = REQUESTS_PER_SECOND / 10; // Process in smaller batches

        while (Carbon::now()->lt($endTime) && $processedCount < count($notifications)) {
            $batchStart = microtime(true);

            // Launch concurrent requests
            for ($i = 0; $i < $batchSize && $processedCount < count($notifications); $i++) {
                if ($activeCoroutines >= CONCURRENT_USERS) {
                    Coroutine::sleep(0.01); // Prevent overload
                    continue;
                }

                go(function () use ($notifications, $processedCount, &$activeCoroutines) {
                    $activeCoroutines++;
                    try {
                        $startTime = microtime(true);
                        $notification = $notifications[$processedCount];
                        
                        // Send notification
                        $response = $this->notificationService->send(
                            $notification['payload'],
                            $notification['channel'],
                            ['priority' => random_int(1, 3)]
                        );

                        // Track metrics
                        $latency = (microtime(true) - $startTime) * 1000;
                        $this->recordMetrics($notification, $response, $latency);

                        // Verify delivery
                        TestHelper::assertNotificationDelivered($response['id']);
                        
                    } catch (\Exception $e) {
                        $this->recordError($e);
                    } finally {
                        $activeCoroutines--;
                    }
                });

                $processedCount++;
            }

            // Maintain request rate
            $batchDuration = microtime(true) - $batchStart;
            $targetDuration = $batchSize / REQUESTS_PER_SECOND;
            
            if ($batchDuration < $targetDuration) {
                usleep(($targetDuration - $batchDuration) * 1000000);
            }
        }

        // Assert performance requirements
        $this->assertPerformanceMetrics();
    }

    /**
     * Tests system behavior under queue saturation conditions
     */
    public function testQueueBackpressure(): void
    {
        $queueDepth = [];
        $processingTimes = [];
        $startTime = Carbon::now();

        // Generate high-volume batch
        $notifications = TestHelper::generateBatchNotifications(100000, 'email');

        // Monitor queue growth
        go(function () use (&$queueDepth) {
            for ($i = 0; $i < 60; $i++) {
                $queueDepth[] = $this->getQueueDepth();
                Coroutine::sleep(1);
            }
        });

        // Rapid queue insertion
        foreach (array_chunk($notifications, 1000) as $batch) {
            go(function () use ($batch, &$processingTimes) {
                foreach ($batch as $notification) {
                    $start = microtime(true);
                    $response = $this->notificationService->send(
                        $notification['payload'],
                        $notification['channel'],
                        ['priority' => 1]
                    );
                    $processingTimes[] = microtime(true) - $start;
                }
            });
        }

        // Assert queue behavior
        $this->assertQueueBehavior($queueDepth, $processingTimes);
    }

    /**
     * Tests and validates system resource usage patterns under sustained load
     */
    public function testResourceUtilization(): void
    {
        $duration = 300; // 5 minutes
        $interval = 1; // 1 second sampling
        $samples = $duration / $interval;

        for ($i = 0; $i < $samples; $i++) {
            // Generate consistent load
            go(function () {
                $notifications = TestHelper::generateBatchNotifications(
                    REQUESTS_PER_SECOND,
                    'email'
                );
                
                foreach ($notifications as $notification) {
                    $this->notificationService->send(
                        $notification['payload'],
                        $notification['channel']
                    );
                }
            });

            // Collect resource metrics
            $this->resourceStats['cpu'][] = $this->getCpuUsage();
            $this->resourceStats['memory'][] = $this->getMemoryUsage();
            $this->resourceStats['connections'][] = $this->getConnectionCount();

            Coroutine::sleep($interval);
        }

        // Assert resource utilization
        $this->assertResourceUtilization();
    }

    /**
     * Records performance metrics for a notification
     *
     * @param array $notification Original notification
     * @param array $response Service response
     * @param float $latency Processing latency in ms
     */
    private function recordMetrics(array $notification, array $response, float $latency): void
    {
        $this->metrics['latency'][] = $latency;
        $this->metrics['throughput'][] = Carbon::now()->timestamp;

        $this->performanceLogger->info('Notification processed', [
            'notification_id' => $response['id'],
            'channel' => $notification['channel'],
            'latency_ms' => $latency,
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Records error information
     *
     * @param \Exception $error Caught exception
     */
    private function recordError(\Exception $error): void
    {
        $this->metrics['errors'][] = [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        $this->performanceLogger->error('Processing error', [
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
        ]);
    }

    /**
     * Generates comprehensive performance report
     */
    private function generatePerformanceReport(): void
    {
        $latencies = $this->metrics['latency'];
        sort($latencies);
        
        $p95Index = (int) ceil(0.95 * count($latencies));
        $p95Latency = $latencies[$p95Index - 1] ?? 0;

        $throughput = count($this->metrics['throughput']) / (TEST_DURATION_SECONDS / 60);
        $errorRate = count($this->metrics['errors']) / count($this->metrics['throughput']);

        $report = [
            'summary' => [
                'total_requests' => count($this->metrics['throughput']),
                'throughput_per_minute' => $throughput,
                'p95_latency_ms' => $p95Latency,
                'error_rate' => $errorRate,
                'test_duration' => TEST_DURATION_SECONDS,
            ],
            'resource_usage' => [
                'cpu_max' => max($this->resourceStats['cpu']),
                'memory_max' => max($this->resourceStats['memory']),
                'connections_max' => max($this->resourceStats['connections']),
            ],
            'errors' => $this->metrics['errors'],
        ];

        $this->performanceLogger->info('Performance test completed', $report);
    }

    /**
     * Saves detailed metrics for analysis
     */
    private function saveMetrics(): void
    {
        file_put_contents(
            __DIR__ . '/../../../../logs/metrics.json',
            json_encode($this->metrics, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Asserts that performance metrics meet requirements
     */
    private function assertPerformanceMetrics(): void
    {
        $thresholds = json_decode(PERFORMANCE_THRESHOLDS, true);

        // Assert throughput
        $throughput = count($this->metrics['throughput']) / (TEST_DURATION_SECONDS / 60);
        $this->assertGreaterThanOrEqual(
            $thresholds['throughput'],
            $throughput,
            "Throughput of {$throughput} messages/minute below required {$thresholds['throughput']}"
        );

        // Assert latency
        $latencies = $this->metrics['latency'];
        sort($latencies);
        $p95Index = (int) ceil(0.95 * count($latencies));
        $p95Latency = $latencies[$p95Index - 1];
        
        $this->assertLessThanOrEqual(
            $thresholds['latency_p95'],
            $p95Latency / 1000,
            "95th percentile latency of {$p95Latency}ms exceeds {$thresholds['latency_p95']} seconds"
        );

        // Assert error rate
        $errorRate = count($this->metrics['errors']) / count($this->metrics['throughput']);
        $this->assertLessThanOrEqual(
            0.0005, // 99.95% success rate
            $errorRate,
            "Error rate of {$errorRate} exceeds maximum 0.05%"
        );
    }

    /**
     * Initializes notification service with test configuration
     */
    private function initializeNotificationService(): NotificationService
    {
        // Service initialization code would go here
        // This is a placeholder as the actual initialization would depend on your DI container
        return new NotificationService(
            /* dependencies would be injected here */
        );
    }
}