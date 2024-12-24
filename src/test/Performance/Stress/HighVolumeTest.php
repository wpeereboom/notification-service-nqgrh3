<?php

declare(strict_types=1);

namespace App\Test\Performance\Stress;

use App\Test\Utils\TestHelper;
use App\Services\Notification\NotificationService;
use PHPUnit\Framework\TestCase;
use Carbon\Carbon;

/**
 * Performance test suite for validating high-volume notification processing capabilities.
 * Tests system requirements:
 * - 100,000+ messages per minute throughput
 * - 99.9% delivery success rate
 * - Processing latency under 30 seconds for 95th percentile
 *
 * @package App\Test\Performance\Stress
 * @version 1.0.0
 */
class HighVolumeTest extends TestCase
{
    /**
     * @var NotificationService Notification service instance
     */
    private NotificationService $notificationService;

    /**
     * @var TestHelper Test utilities instance
     */
    private TestHelper $testHelper;

    /**
     * @var array Performance metrics collection
     */
    private array $performanceMetrics = [
        'total_messages' => 0,
        'successful_deliveries' => 0,
        'failed_deliveries' => 0,
        'processing_times' => [],
        'throughput_per_minute' => [],
        'errors' => [],
    ];

    /**
     * @var array Channel-specific metrics
     */
    private array $channelMetrics = [
        'email' => ['sent' => 0, 'success' => 0, 'latency' => []],
        'sms' => ['sent' => 0, 'success' => 0, 'latency' => []],
        'push' => ['sent' => 0, 'success' => 0, 'latency' => []],
    ];

    /**
     * Set up test environment and dependencies
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize test helper
        $this->testHelper = new TestHelper();
        
        // Set up isolated test environment
        $this->testHelper->setupTestEnvironment();
        
        // Configure mock vendors for controlled testing
        $this->testHelper->setupMockVendors([
            'email' => ['Iterable', 'SendGrid', 'SES'],
            'sms' => ['Telnyx', 'Twilio'],
            'push' => ['SNS']
        ]);

        // Initialize notification service
        $this->notificationService = $this->testHelper->getNotificationService();
    }

    /**
     * Clean up test resources and persist metrics
     */
    protected function tearDown(): void
    {
        // Save performance metrics for analysis
        $this->testHelper->trackMetrics('high_volume_test', $this->performanceMetrics);
        
        // Clean up test data
        $this->testHelper->cleanupTestEnvironment();
        
        parent::tearDown();
    }

    /**
     * Tests system's ability to process high volume of notifications
     * meeting throughput and success rate requirements
     *
     * @test
     */
    public function testHighVolumeNotificationProcessing(): void
    {
        // Generate test notifications across channels
        $notifications = [];
        $targetCount = (int)TARGET_MESSAGES_PER_MINUTE;
        $batchSize = (int)BATCH_SIZE;

        // Distribute notifications across channels
        $channelDistribution = [
            'email' => 0.6, // 60% email
            'sms' => 0.3,   // 30% SMS
            'push' => 0.1   // 10% push
        ];

        foreach ($channelDistribution as $channel => $ratio) {
            $channelCount = (int)($targetCount * $ratio);
            for ($i = 0; $i < $channelCount; $i += $batchSize) {
                $batchCount = min($batchSize, $channelCount - $i);
                $notifications[] = $this->testHelper->generateBatchNotifications($batchCount, $channel);
            }
        }

        // Record start time
        $startTime = Carbon::now();
        $processedCount = 0;

        // Process notifications in parallel batches
        foreach ($notifications as $batch) {
            $batchStartTime = microtime(true);
            
            // Send notifications
            $messageIds = [];
            foreach ($batch as $notification) {
                try {
                    $messageId = $this->notificationService->send(
                        $notification['payload'],
                        $notification['channel'],
                        ['priority' => $notification['priority'] ?? 2]
                    );
                    $messageIds[] = $messageId;
                    $this->channelMetrics[$notification['channel']]['sent']++;
                } catch (\Exception $e) {
                    $this->performanceMetrics['errors'][] = [
                        'message' => $e->getMessage(),
                        'channel' => $notification['channel'],
                        'timestamp' => Carbon::now()->toIso8601String()
                    ];
                }
            }

            // Track batch processing time
            $batchTime = (microtime(true) - $batchStartTime) * 1000; // Convert to milliseconds
            $this->performanceMetrics['processing_times'][] = $batchTime;

            // Verify deliveries and collect metrics
            foreach ($messageIds as $messageId) {
                $this->testHelper->assertNotificationDelivered($messageId);
                $status = $this->notificationService->getStatus($messageId);
                
                if ($status['status'] === 'delivered') {
                    $this->performanceMetrics['successful_deliveries']++;
                    $this->channelMetrics[$status['channel']]['success']++;
                    $this->channelMetrics[$status['channel']]['latency'][] = $status['metrics']['processing_time'];
                }
            }

            $processedCount += count($messageIds);
            $this->performanceMetrics['total_messages'] = $processedCount;
        }

        // Calculate final metrics
        $duration = Carbon::now()->diffInMinutes($startTime);
        $throughput = $processedCount / max($duration, 1);
        $successRate = ($this->performanceMetrics['successful_deliveries'] / $processedCount) * 100;

        // Calculate 95th percentile latency
        sort($this->performanceMetrics['processing_times']);
        $p95Index = (int)ceil(0.95 * count($this->performanceMetrics['processing_times']));
        $p95Latency = $this->performanceMetrics['processing_times'][$p95Index - 1] / 1000; // Convert to seconds

        // Assert performance requirements
        $this->assertGreaterThanOrEqual(
            (int)TARGET_MESSAGES_PER_MINUTE,
            $throughput,
            "Throughput of {$throughput} messages/minute does not meet target of " . TARGET_MESSAGES_PER_MINUTE
        );

        $this->assertGreaterThanOrEqual(
            (float)TARGET_SUCCESS_RATE,
            $successRate,
            "Success rate of {$successRate}% does not meet target of " . TARGET_SUCCESS_RATE . "%"
        );

        $this->assertLessThanOrEqual(
            (int)TARGET_MAX_LATENCY_SECONDS,
            $p95Latency,
            "95th percentile latency of {$p95Latency}s exceeds target of " . TARGET_MAX_LATENCY_SECONDS . "s"
        );
    }

    /**
     * Tests system performance under sustained high load
     *
     * @test
     */
    public function testSustainedHighLoad(): void
    {
        $testDuration = (int)TEST_DURATION_MINUTES;
        $startTime = Carbon::now();
        $endTime = $startTime->copy()->addMinutes($testDuration);

        while (Carbon::now()->lt($endTime)) {
            // Generate continuous load
            $batchSize = (int)BATCH_SIZE;
            $notifications = [];

            // Mix of channels
            foreach (['email', 'sms', 'push'] as $channel) {
                $notifications = array_merge(
                    $notifications,
                    $this->testHelper->generateBatchNotifications($batchSize, $channel)
                );
            }

            // Process batch
            $batchStartTime = microtime(true);
            foreach ($notifications as $notification) {
                try {
                    $messageId = $this->notificationService->send(
                        $notification['payload'],
                        $notification['channel'],
                        ['priority' => $notification['priority'] ?? 2]
                    );

                    // Track delivery status
                    $status = $this->notificationService->getStatus($messageId);
                    if ($status['status'] === 'delivered') {
                        $this->performanceMetrics['successful_deliveries']++;
                    }

                    $processingTime = (microtime(true) - $batchStartTime) * 1000;
                    $this->performanceMetrics['processing_times'][] = $processingTime;

                } catch (\Exception $e) {
                    $this->performanceMetrics['errors'][] = [
                        'message' => $e->getMessage(),
                        'timestamp' => Carbon::now()->toIso8601String()
                    ];
                }
            }

            // Calculate current throughput
            $currentMinute = Carbon::now()->diffInMinutes($startTime);
            $this->performanceMetrics['throughput_per_minute'][$currentMinute] = count($notifications);

            // Short delay to prevent overwhelming the system
            usleep(100000); // 100ms delay
        }

        // Calculate sustained performance metrics
        $avgThroughput = array_sum($this->performanceMetrics['throughput_per_minute']) / count($this->performanceMetrics['throughput_per_minute']);
        
        // Assert sustained performance
        $this->assertGreaterThanOrEqual(
            (int)TARGET_MESSAGES_PER_MINUTE,
            $avgThroughput,
            "Sustained throughput of {$avgThroughput} messages/minute does not meet target"
        );
    }

    /**
     * Tests high volume processing across multiple channels
     *
     * @test
     */
    public function testMultiChannelHighVolume(): void
    {
        $channels = ['email', 'sms', 'push'];
        $batchesPerChannel = 10;
        $batchSize = (int)BATCH_SIZE;

        foreach ($channels as $channel) {
            $channelStartTime = Carbon::now();
            
            for ($i = 0; $i < $batchesPerChannel; $i++) {
                // Generate channel-specific batch
                $notifications = $this->testHelper->generateBatchNotifications($batchSize, $channel);
                
                // Process batch
                foreach ($notifications as $notification) {
                    try {
                        $messageId = $this->notificationService->send(
                            $notification['payload'],
                            $channel,
                            ['priority' => $notification['priority'] ?? 2]
                        );

                        $this->channelMetrics[$channel]['sent']++;
                        
                        // Verify delivery
                        $status = $this->notificationService->getStatus($messageId);
                        if ($status['status'] === 'delivered') {
                            $this->channelMetrics[$channel]['success']++;
                        }

                    } catch (\Exception $e) {
                        $this->performanceMetrics['errors'][] = [
                            'channel' => $channel,
                            'message' => $e->getMessage(),
                            'timestamp' => Carbon::now()->toIso8601String()
                        ];
                    }
                }
            }

            // Calculate channel-specific metrics
            $channelDuration = Carbon::now()->diffInMinutes($channelStartTime);
            $channelThroughput = $this->channelMetrics[$channel]['sent'] / max($channelDuration, 1);
            $channelSuccessRate = ($this->channelMetrics[$channel]['success'] / $this->channelMetrics[$channel]['sent']) * 100;

            // Assert channel performance
            $this->assertGreaterThanOrEqual(
                (float)TARGET_SUCCESS_RATE,
                $channelSuccessRate,
                "Channel {$channel} success rate of {$channelSuccessRate}% does not meet target"
            );
        }
    }
}