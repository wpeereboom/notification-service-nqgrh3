<?php

declare(strict_types=1);

namespace App\Test\E2E\Scenarios;

use App\Services\Notification\NotificationService;
use App\Test\Utils\TestHelper;
use Carbon\Carbon; // ^2.0
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test suite for validating multi-channel notification delivery scenarios.
 * Tests simultaneous delivery across Email, SMS, and Push channels with comprehensive
 * validation of delivery success rates, timing constraints, and failover behaviors.
 *
 * Requirements tested:
 * - Multi-channel delivery support
 * - 99.9% delivery success rate
 * - Message processing latency < 30 seconds for 95th percentile
 * - Vendor failover < 2 seconds
 *
 * @package App\Test\E2E\Scenarios
 * @version 1.0.0
 */
class MultiChannelScenarioTest extends TestCase
{
    private NotificationService $notificationService;
    private array $testTemplates = [];
    private array $testNotifications = [];
    private array $vendorConfigs = [];
    private array $deliveryMetrics = [
        'total' => 0,
        'successful' => 0,
        'failed' => 0,
        'processing_times' => [],
    ];

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize test environment
        TestHelper::setupTestEnvironment();

        // Create notification service instance
        $this->notificationService = app(NotificationService::class);

        // Initialize test templates for each channel
        $this->testTemplates = [
            'email' => TestHelper::generateTestTemplate('email', [
                'subject' => 'Test Email Notification',
                'body' => 'This is a test email notification with ID: {{notification_id}}'
            ]),
            'sms' => TestHelper::generateTestTemplate('sms', [
                'body' => 'Test SMS notification: {{notification_id}}'
            ]),
            'push' => TestHelper::generateTestTemplate('push', [
                'title' => 'Test Push Notification',
                'body' => 'Test push message: {{notification_id}}'
            ])
        ];

        // Configure vendor settings for testing
        $this->vendorConfigs = [
            'email' => ['Iterable', 'SendGrid', 'SES'],
            'sms' => ['Telnyx', 'Twilio'],
            'push' => ['SNS']
        ];
    }

    /**
     * Clean up test environment after each test
     */
    protected function tearDown(): void
    {
        // Clean up test notifications
        foreach ($this->testNotifications as $notification) {
            TestHelper::cleanupTestNotification($notification['id']);
        }

        // Reset vendor configurations
        $this->vendorConfigs = [];
        $this->deliveryMetrics = [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
            'processing_times' => [],
        ];

        TestHelper::cleanupTestEnvironment();
        parent::tearDown();
    }

    /**
     * Tests simultaneous delivery of notifications across multiple channels
     * validating delivery success rates and timing constraints.
     *
     * @test
     */
    public function testSimultaneousMultiChannelDelivery(): void
    {
        // Generate test notifications for each channel
        $notifications = [
            'email' => TestHelper::generateTestNotification('email', [
                'template_id' => $this->testTemplates['email']['id'],
                'recipient' => 'test@example.com'
            ]),
            'sms' => TestHelper::generateTestNotification('sms', [
                'template_id' => $this->testTemplates['sms']['id'],
                'recipient' => '+1234567890'
            ]),
            'push' => TestHelper::generateTestNotification('push', [
                'template_id' => $this->testTemplates['push']['id'],
                'device_token' => 'test_device_token_123'
            ])
        ];

        // Record start time for delivery timing
        $startTime = Carbon::now();

        // Send notifications simultaneously
        $notificationIds = [];
        foreach ($notifications as $channel => $notification) {
            $notificationIds[$channel] = $this->notificationService->send(
                $notification['payload'],
                $channel,
                ['priority' => 'high']
            );
            $this->deliveryMetrics['total']++;
        }

        // Wait for processing completion (with timeout)
        $timeout = Carbon::now()->addSeconds(30);
        $completed = [];

        while (count($completed) < count($notificationIds) && Carbon::now()->lt($timeout)) {
            foreach ($notificationIds as $channel => $id) {
                if (isset($completed[$channel])) {
                    continue;
                }

                $status = $this->notificationService->getStatus($id);
                if (in_array($status['status'], ['delivered', 'failed'])) {
                    $completed[$channel] = $status;
                    
                    // Record processing time
                    $processingTime = Carbon::parse($status['timestamps']['created'])
                        ->diffInMilliseconds(Carbon::parse($status['timestamps']['completed']));
                    $this->deliveryMetrics['processing_times'][] = $processingTime;

                    // Update success metrics
                    if ($status['status'] === 'delivered') {
                        $this->deliveryMetrics['successful']++;
                    } else {
                        $this->deliveryMetrics['failed']++;
                    }
                }
            }
            usleep(100000); // 100ms pause between checks
        }

        // Assert all notifications were processed
        $this->assertCount(3, $completed, 'Not all notifications were processed within timeout');

        // Verify delivery success rate meets 99.9% requirement
        $successRate = $this->deliveryMetrics['successful'] / $this->deliveryMetrics['total'];
        $this->assertGreaterThanOrEqual(
            0.999,
            $successRate,
            'Delivery success rate below 99.9% requirement'
        );

        // Verify processing time meets < 30s requirement for 95th percentile
        sort($this->deliveryMetrics['processing_times']);
        $p95Index = (int) ceil(0.95 * count($this->deliveryMetrics['processing_times']));
        $p95Time = $this->deliveryMetrics['processing_times'][$p95Index - 1];
        
        $this->assertLessThanOrEqual(
            30000, // 30 seconds in milliseconds
            $p95Time,
            '95th percentile processing time exceeds 30 second requirement'
        );
    }

    /**
     * Tests vendor failover scenarios across multiple channels validating
     * failover timing and delivery success maintenance.
     *
     * @test
     */
    public function testMultiChannelFailoverScenario(): void
    {
        // Configure primary vendors to simulate failure
        $failureConfig = [
            'email' => ['vendor' => 'Iterable', 'error' => 'Service Unavailable'],
            'sms' => ['vendor' => 'Telnyx', 'error' => 'Rate Limited'],
            'push' => ['vendor' => 'SNS', 'error' => 'Network Error']
        ];

        foreach ($failureConfig as $channel => $config) {
            TestHelper::simulateVendorFailure($config['vendor'], $config['error']);
        }

        // Generate test notifications
        $notifications = [];
        foreach (['email', 'sms', 'push'] as $channel) {
            $notifications[$channel] = TestHelper::generateTestNotification($channel, [
                'template_id' => $this->testTemplates[$channel]['id'],
                'priority' => 'high'
            ]);
        }

        // Send notifications and track failover timing
        $failoverMetrics = [];
        foreach ($notifications as $channel => $notification) {
            $startTime = microtime(true);
            
            $notificationId = $this->notificationService->send(
                $notification['payload'],
                $channel,
                ['priority' => 'high']
            );

            // Wait for and verify failover
            $attempts = $this->notificationService->getDeliveryAttempts($notificationId);
            
            // Calculate failover time
            if (count($attempts) > 1) {
                $failoverTime = (float) ($attempts[1]['timestamp'] - $attempts[0]['timestamp']) * 1000;
                $failoverMetrics[$channel] = $failoverTime;
                
                // Verify failover completed within 2 second requirement
                $this->assertLessThanOrEqual(
                    2000, // 2 seconds in milliseconds
                    $failoverTime,
                    "Failover for {$channel} exceeded 2 second requirement"
                );
            }

            // Verify successful delivery through backup vendor
            TestHelper::assertNotificationDelivered($notificationId);
        }

        // Verify overall delivery success maintained during failover
        $this->assertGreaterThanOrEqual(
            0.999,
            $this->deliveryMetrics['successful'] / $this->deliveryMetrics['total'],
            'Delivery success rate not maintained during failover'
        );
    }

    /**
     * Tests high-volume concurrent delivery across multiple channels validating
     * system stability and performance under load.
     *
     * @test
     */
    public function testConcurrentMultiChannelLoad(): void
    {
        $batchSize = 1000; // Number of notifications per channel
        $notifications = [];
        
        // Generate large batch of notifications for each channel
        foreach (['email', 'sms', 'push'] as $channel) {
            $notifications[$channel] = TestHelper::generateBatchNotifications(
                $batchSize,
                $channel
            );
        }

        // Configure concurrent delivery
        $startTime = Carbon::now();
        $notificationIds = [];

        // Send notifications with high concurrency
        foreach ($notifications as $channel => $batch) {
            foreach ($batch as $notification) {
                $notificationIds[] = $this->notificationService->send(
                    $notification['payload'],
                    $channel,
                    ['priority' => 'normal']
                );
                $this->deliveryMetrics['total']++;
            }
        }

        // Monitor processing completion
        $completed = 0;
        $timeout = Carbon::now()->addMinutes(5);
        $processingTimes = [];

        while ($completed < count($notificationIds) && Carbon::now()->lt($timeout)) {
            foreach ($notificationIds as $id) {
                $status = $this->notificationService->getStatus($id);
                if (in_array($status['status'], ['delivered', 'failed'])) {
                    $completed++;
                    
                    // Record processing time
                    $processingTime = Carbon::parse($status['timestamps']['created'])
                        ->diffInMilliseconds(Carbon::parse($status['timestamps']['completed']));
                    $processingTimes[] = $processingTime;

                    // Update success metrics
                    if ($status['status'] === 'delivered') {
                        $this->deliveryMetrics['successful']++;
                    } else {
                        $this->deliveryMetrics['failed']++;
                    }
                }
            }
            usleep(100000); // 100ms pause between checks
        }

        // Calculate success rate
        $successRate = $this->deliveryMetrics['successful'] / $this->deliveryMetrics['total'];
        $this->assertGreaterThanOrEqual(
            0.999,
            $successRate,
            'Failed to maintain 99.9% success rate under load'
        );

        // Verify processing latency
        sort($processingTimes);
        $p95Index = (int) ceil(0.95 * count($processingTimes));
        $p95Time = $processingTimes[$p95Index - 1];
        
        $this->assertLessThanOrEqual(
            30000, // 30 seconds in milliseconds
            $p95Time,
            '95th percentile processing time exceeds 30 second requirement under load'
        );

        // Verify system stability
        $this->assertGreaterThanOrEqual(
            $this->deliveryMetrics['total'],
            $completed,
            'Not all notifications were processed'
        );
    }
}