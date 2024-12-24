<?php

declare(strict_types=1);

namespace App\Test\Performance\LoadTest;

use App\Test\Utils\TestHelper;
use App\Services\Notification\NotificationService;
use PHPUnit\Framework\TestCase;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Performance load test suite for notification service focusing on high-throughput 
 * message processing and delivery across multiple channels.
 * 
 * Validates key performance requirements:
 * - 100,000+ messages per minute throughput
 * - 99.9% delivery success rate
 * - < 30 seconds processing latency (95th percentile)
 *
 * @package App\Test\Performance\LoadTest
 * @version 1.0.0
 */
class NotificationLoadTest extends TestCase
{
    /**
     * @var NotificationService Notification service instance
     */
    private NotificationService $notificationService;

    /**
     * @var TestHelper Test helper instance
     */
    private TestHelper $testHelper;

    /**
     * @var array Performance metrics collection
     */
    private array $metrics = [
        'total_sent' => 0,
        'successful' => 0,
        'failed' => 0,
        'processing_times' => [],
        'channel_metrics' => [
            'email' => ['sent' => 0, 'successful' => 0],
            'sms' => ['sent' => 0, 'successful' => 0],
            'push' => ['sent' => 0, 'successful' => 0]
        ]
    ];

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize test helper
        $this->testHelper = new TestHelper();
        $this->testHelper->setupTestEnvironment();

        // Initialize notification service
        $this->notificationService = $this->getNotificationService();
    }

    /**
     * Clean up test environment after each test
     */
    protected function tearDown(): void
    {
        $this->testHelper->cleanupTestEnvironment();
        parent::tearDown();
    }

    /**
     * Tests system's ability to handle high volume of notifications
     * Requirement: 100,000+ messages per minute throughput
     *
     * @group performance
     * @group high-throughput
     */
    public function testHighThroughputNotificationProcessing(): void
    {
        // Generate test notifications
        $notifications = [];
        $targetCount = 100000;
        $channels = ['email', 'sms', 'push'];

        for ($i = 0; $i < $targetCount; $i++) {
            $channel = $channels[$i % count($channels)];
            $notifications[] = $this->testHelper->generateTestNotification($channel);
        }

        // Record start time
        $startTime = Carbon::now();

        // Process notifications in batches
        $batches = array_chunk($notifications, LOAD_TEST_BATCH_SIZE);
        $notificationIds = [];

        foreach ($batches as $batch) {
            $batchStartTime = microtime(true);

            // Send notifications in parallel
            foreach ($batch as $notification) {
                try {
                    $notificationId = $this->notificationService->send(
                        $notification,
                        $notification['channel'],
                        ['priority' => 'high']
                    );
                    $notificationIds[] = $notificationId;
                    $this->metrics['total_sent']++;
                    $this->metrics['channel_metrics'][$notification['channel']]['sent']++;
                } catch (\Exception $e) {
                    $this->metrics['failed']++;
                }
            }

            // Calculate and enforce batch rate
            $batchTime = microtime(true) - $batchStartTime;
            $targetBatchTime = count($batch) / LOAD_TEST_TARGET_RPS;
            if ($batchTime < $targetBatchTime) {
                usleep((int)(($targetBatchTime - $batchTime) * 1000000));
            }
        }

        // Calculate total duration
        $duration = Carbon::now()->diffInMinutes($startTime);
        $messagesPerMinute = $this->metrics['total_sent'] / max($duration, 1);

        // Verify delivery success
        foreach ($notificationIds as $id) {
            try {
                $this->testHelper->assertNotificationDelivered($id);
                $this->metrics['successful']++;
            } catch (\Exception $e) {
                $this->metrics['failed']++;
            }
        }

        // Calculate success rate
        $successRate = $this->metrics['successful'] / $this->metrics['total_sent'];

        // Log performance metrics
        $this->logPerformanceMetrics('high-throughput', [
            'total_sent' => $this->metrics['total_sent'],
            'messages_per_minute' => $messagesPerMinute,
            'success_rate' => $successRate,
            'duration_minutes' => $duration,
            'channel_metrics' => $this->metrics['channel_metrics']
        ]);

        // Assert performance requirements
        $this->assertGreaterThanOrEqual(100000, $messagesPerMinute, 
            'Failed to meet 100,000 messages per minute throughput requirement');
        $this->assertGreaterThanOrEqual(0.999, $successRate,
            'Failed to meet 99.9% delivery success rate requirement');
    }

    /**
     * Tests notification processing latency under load
     * Requirement: < 30 seconds for 95th percentile
     *
     * @group performance
     * @group latency
     */
    public function testProcessingLatencyUnderLoad(): void
    {
        $testDuration = LOAD_TEST_DURATION_SECONDS;
        $startTime = Carbon::now();
        $processingTimes = [];

        while (Carbon::now()->diffInSeconds($startTime) < $testDuration) {
            // Generate mixed channel notifications
            $notifications = [
                $this->testHelper->generateTestNotification('email'),
                $this->testHelper->generateTestNotification('sms'),
                $this->testHelper->generateTestNotification('push')
            ];

            foreach ($notifications as $notification) {
                $sendStart = microtime(true);
                
                try {
                    $notificationId = $this->notificationService->send(
                        $notification,
                        $notification['channel'],
                        ['track_processing_time' => true]
                    );

                    // Wait for delivery completion
                    $status = null;
                    $maxWaitTime = 30; // seconds
                    $waitStart = time();

                    while (time() - $waitStart < $maxWaitTime) {
                        $status = $this->notificationService->getStatus($notificationId);
                        if (in_array($status['status'], ['delivered', 'failed'])) {
                            break;
                        }
                        usleep(100000); // 100ms
                    }

                    $processingTime = (microtime(true) - $sendStart) * 1000; // ms
                    $processingTimes[] = $processingTime;
                    $this->metrics['processing_times'][] = $processingTime;

                } catch (\Exception $e) {
                    $this->metrics['failed']++;
                }
            }
        }

        // Calculate 95th percentile latency
        sort($processingTimes);
        $p95Index = (int)ceil(0.95 * count($processingTimes));
        $p95Latency = $processingTimes[$p95Index - 1];

        // Log latency metrics
        $this->logPerformanceMetrics('latency', [
            'p95_latency_ms' => $p95Latency,
            'min_latency_ms' => min($processingTimes),
            'max_latency_ms' => max($processingTimes),
            'avg_latency_ms' => array_sum($processingTimes) / count($processingTimes),
            'sample_size' => count($processingTimes)
        ]);

        // Assert latency requirement
        $this->assertLessThanOrEqual(30000, $p95Latency,
            '95th percentile latency exceeds 30 second requirement');
    }

    /**
     * Tests concurrent delivery across multiple channels
     * Requirements: 
     * - 99.9% delivery rate per channel
     * - Vendor failover < 2 seconds
     *
     * @group performance
     * @group multi-channel
     */
    public function testMultiChannelDeliveryUnderLoad(): void
    {
        $channelMetrics = [
            'email' => ['sent' => 0, 'successful' => 0, 'failover_times' => []],
            'sms' => ['sent' => 0, 'successful' => 0, 'failover_times' => []],
            'push' => ['sent' => 0, 'successful' => 0, 'failover_times' => []]
        ];

        // Test duration
        $testDuration = LOAD_TEST_DURATION_SECONDS;
        $startTime = Carbon::now();

        while (Carbon::now()->diffInSeconds($startTime) < $testDuration) {
            foreach (array_keys($channelMetrics) as $channel) {
                $notification = $this->testHelper->generateTestNotification($channel);
                
                try {
                    $sendStart = microtime(true);
                    $notificationId = $this->notificationService->send(
                        $notification,
                        $channel,
                        ['track_vendor_failover' => true]
                    );
                    
                    $channelMetrics[$channel]['sent']++;

                    // Monitor for vendor failover
                    $status = $this->notificationService->getStatus($notificationId);
                    if (!empty($status['vendor_failover_time'])) {
                        $channelMetrics[$channel]['failover_times'][] = 
                            $status['vendor_failover_time'];
                    }

                    // Verify delivery
                    $this->testHelper->assertNotificationDelivered($notificationId);
                    $channelMetrics[$channel]['successful']++;

                } catch (\Exception $e) {
                    // Log failure but continue testing
                    $this->metrics['failed']++;
                }
            }
        }

        // Calculate metrics per channel
        foreach ($channelMetrics as $channel => $metrics) {
            $successRate = $metrics['successful'] / max($metrics['sent'], 1);
            
            // Calculate average failover time if any failovers occurred
            $avgFailoverTime = !empty($metrics['failover_times']) 
                ? array_sum($metrics['failover_times']) / count($metrics['failover_times'])
                : 0;

            // Log channel-specific metrics
            $this->logPerformanceMetrics("channel-{$channel}", [
                'total_sent' => $metrics['sent'],
                'successful' => $metrics['successful'],
                'success_rate' => $successRate,
                'avg_failover_time_ms' => $avgFailoverTime,
                'failover_count' => count($metrics['failover_times'])
            ]);

            // Assert channel-specific requirements
            $this->assertGreaterThanOrEqual(0.999, $successRate,
                "Channel {$channel} failed to meet 99.9% delivery rate requirement");
            
            if (!empty($metrics['failover_times'])) {
                $this->assertLessThanOrEqual(2000, max($metrics['failover_times']),
                    "Channel {$channel} exceeded 2 second vendor failover requirement");
            }
        }
    }

    /**
     * Gets configured notification service instance
     *
     * @return NotificationService
     */
    private function getNotificationService(): NotificationService
    {
        // Service would be initialized with proper dependencies in production
        // This is a simplified version for testing
        return new NotificationService(
            $this->createMock(\App\Services\Queue\SqsService::class),
            $this->createMock(\App\Services\Template\TemplateService::class),
            $this->createMock(\App\Services\Vendor\VendorService::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\Predis\Client::class)
        );
    }

    /**
     * Logs performance metrics for analysis
     *
     * @param string $testName Name of the test
     * @param array $metrics Metrics to log
     */
    private function logPerformanceMetrics(string $testName, array $metrics): void
    {
        $metrics['timestamp'] = Carbon::now()->toIso8601String();
        $metrics['test_name'] = $testName;
        
        // In production, metrics would be sent to monitoring system
        error_log(json_encode($metrics));
    }
}