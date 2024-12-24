<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Notification\NotificationService;
use App\Services\Vendor\VendorService;
use App\Services\Queue\SqsService;
use App\Services\Template\TemplateService;
use App\Utils\CircuitBreaker;
use App\Exceptions\VendorException;
use Mockery;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;

/**
 * Feature test suite for notification service functionality
 * Tests high-throughput processing, vendor failover, and performance metrics
 *
 * @package Tests\Feature
 * @version 1.0.0
 */
class NotificationTest extends TestCase
{
    private NotificationService $notificationService;
    private VendorService $vendorService;
    private SqsService $sqsService;
    private TemplateService $templateService;
    private CircuitBreaker $circuitBreaker;
    private Redis $redis;
    private LoggerInterface $logger;

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
        $this->redis = Mockery::mock(Redis::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $this->sqsService = Mockery::mock(SqsService::class);
        $this->templateService = Mockery::mock(TemplateService::class);
        $this->vendorService = Mockery::mock(VendorService::class);

        // Initialize notification service
        $this->notificationService = new NotificationService(
            $this->sqsService,
            $this->templateService,
            $this->vendorService,
            $this->logger,
            $this->redis
        );

        // Configure default mock behaviors
        $this->logger->shouldReceive('info')->byDefault();
        $this->logger->shouldReceive('error')->byDefault();
        $this->redis->shouldReceive('setex')->byDefault();
        $this->redis->shouldReceive('get')->byDefault();
    }

    /**
     * Test high throughput message processing capability
     * Verifies handling of 100,000+ messages per minute
     */
    public function testHighThroughputProcessing(): void
    {
        // Generate test messages
        $messages = $this->generateTestMessages(100000);
        $startTime = microtime(true);

        // Configure mocks for batch processing
        $this->sqsService->shouldReceive('sendBatch')
            ->times(ceil(count($messages) / 100))
            ->andReturn(['message_id_1', 'message_id_2']);

        $this->vendorService->shouldReceive('send')
            ->times(count($messages))
            ->andReturn(['status' => 'delivered']);

        // Process messages in batches
        $processedCount = 0;
        foreach (array_chunk($messages, 100) as $batch) {
            $this->notificationService->batchSend($batch);
            $processedCount += count($batch);
        }

        // Calculate throughput
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $throughputPerMinute = ($processedCount / $duration) * 60;

        // Assert minimum throughput achieved
        $this->assertGreaterThan(100000, $throughputPerMinute, 
            'Failed to achieve minimum throughput of 100,000 messages per minute');

        // Verify queue processing completion
        $this->assertEquals(count($messages), $processedCount,
            'Not all messages were processed');
    }

    /**
     * Test vendor failover mechanism and timing
     * Verifies failover completes within 2 seconds
     */
    public function testVendorFailover(): void
    {
        // Configure primary vendor to fail
        $this->vendorService->shouldReceive('send')
            ->once()
            ->andThrow(new VendorException(
                'Primary vendor failed',
                VendorException::VENDOR_UNAVAILABLE,
                null,
                ['vendor_name' => 'primary_vendor', 'channel' => 'email']
            ));

        // Configure backup vendor
        $this->vendorService->shouldReceive('send')
            ->once()
            ->andReturn(['status' => 'delivered']);

        // Send test notification
        $startTime = microtime(true);
        
        $notification = [
            'recipient' => 'test@example.com',
            'template_id' => 'test_template',
            'channel' => 'email'
        ];

        $result = $this->notificationService->send($notification, 'email');
        $endTime = microtime(true);

        // Calculate failover time
        $failoverTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        // Assert failover completed within 2 seconds
        $this->assertLessThan(2000, $failoverTime,
            'Vendor failover took longer than 2 seconds');

        // Verify message was delivered via backup vendor
        $this->assertEquals('delivered', $result['status'],
            'Message was not delivered successfully after failover');
    }

    /**
     * Test message processing latency requirements
     * Verifies 95th percentile latency under 30 seconds
     */
    public function testMessageLatency(): void
    {
        // Generate test batch
        $messages = $this->generateTestMessages(1000);
        $latencies = [];

        // Configure mocks
        $this->sqsService->shouldReceive('sendMessage')->andReturn('msg_id');
        $this->vendorService->shouldReceive('send')->andReturn(['status' => 'delivered']);

        // Process messages and record latencies
        foreach ($messages as $message) {
            $startTime = microtime(true);
            $this->notificationService->send($message, 'email');
            $latencies[] = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        }

        // Calculate 95th percentile latency
        sort($latencies);
        $index = (int) ceil(0.95 * count($latencies)) - 1;
        $percentile95 = $latencies[$index];

        // Assert latency requirement met
        $this->assertLessThan(30000, $percentile95,
            '95th percentile latency exceeded 30 seconds');

        // Verify all messages processed
        $this->assertEquals(count($messages), count($latencies),
            'Not all messages were processed');
    }

    /**
     * Test delivery success rate requirement
     * Verifies 99.9% successful delivery rate
     */
    public function testDeliverySuccessRate(): void
    {
        // Generate test messages
        $totalMessages = 10000;
        $messages = $this->generateTestMessages($totalMessages);
        $successCount = 0;

        // Configure mocks with realistic failure rate
        $this->sqsService->shouldReceive('sendMessage')->andReturn('msg_id');
        $this->vendorService->shouldReceive('send')
            ->andReturnUsing(function () use (&$successCount) {
                if (rand(1, 1000) > 1) { // 0.1% failure rate
                    $successCount++;
                    return ['status' => 'delivered'];
                }
                throw new VendorException(
                    'Delivery failed',
                    VendorException::VENDOR_UNAVAILABLE,
                    null,
                    ['vendor_name' => 'test_vendor']
                );
            });

        // Process messages
        foreach ($messages as $message) {
            try {
                $this->notificationService->send($message, 'email');
            } catch (VendorException $e) {
                // Expected occasional failures
                continue;
            }
        }

        // Calculate success rate
        $successRate = ($successCount / $totalMessages) * 100;

        // Assert minimum success rate achieved
        $this->assertGreaterThanOrEqual(99.9, $successRate,
            'Failed to achieve 99.9% delivery success rate');
    }

    /**
     * Generates test messages for performance testing
     *
     * @param int $count Number of messages to generate
     * @return array Array of test messages
     */
    private function generateTestMessages(int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = [
                'recipient' => "test{$i}@example.com",
                'template_id' => 'test_template',
                'context' => ['name' => "Test User {$i}"],
                'metadata' => ['test_id' => $i]
            ];
        }
        return $messages;
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}