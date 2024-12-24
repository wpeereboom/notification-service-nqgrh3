<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notification;

use App\Services\Notification\NotificationService;
use App\Services\Queue\SqsService;
use App\Services\Template\TemplateService;
use App\Services\Vendor\VendorService;
use App\Exceptions\VendorException;
use App\Contracts\NotificationInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Comprehensive test suite for NotificationService verifying high-throughput processing,
 * multi-channel delivery, vendor failover, and retry mechanisms.
 *
 * @covers \App\Services\Notification\NotificationService
 */
class NotificationServiceTest extends TestCase
{
    private NotificationService $notificationService;
    private MockObject $sqsServiceMock;
    private MockObject $templateServiceMock;
    private MockObject $vendorServiceMock;
    private MockObject $loggerMock;
    private MockObject $redisMock;

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects
        $this->sqsServiceMock = $this->createMock(SqsService::class);
        $this->templateServiceMock = $this->createMock(TemplateService::class);
        $this->vendorServiceMock = $this->createMock(VendorService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->redisMock = $this->createMock(Redis::class);

        // Initialize notification service
        $this->notificationService = new NotificationService(
            $this->sqsServiceMock,
            $this->templateServiceMock,
            $this->vendorServiceMock,
            $this->loggerMock,
            $this->redisMock
        );
    }

    /**
     * Test high-volume batch processing capability
     */
    public function testHighVolumeBatchProcessing(): void
    {
        // Prepare test data
        $batchSize = 1000;
        $notifications = [];
        for ($i = 0; $i < $batchSize; $i++) {
            $notifications[] = [
                'recipient' => "user{$i}@example.com",
                'template_id' => 'welcome_template',
                'channel' => NotificationInterface::CHANNEL_EMAIL,
                'context' => ['name' => "User {$i}"]
            ];
        }

        // Configure mocks for batch processing
        $this->templateServiceMock->expects($this->exactly($batchSize))
            ->method('render')
            ->willReturn('Rendered template content');

        $this->sqsServiceMock->expects($this->exactly($batchSize))
            ->method('sendMessage')
            ->willReturn('msg_' . uniqid());

        $this->redisMock->expects($this->exactly($batchSize * 2))
            ->method('hincrby')
            ->willReturn(1);

        // Record start time
        $startTime = microtime(true);

        // Process notifications
        $results = [];
        foreach ($notifications as $notification) {
            $results[] = $this->notificationService->send(
                $notification,
                NotificationInterface::CHANNEL_EMAIL
            );
        }

        // Calculate processing time
        $processingTime = microtime(true) - $startTime;

        // Verify throughput meets requirements (100,000+ per minute)
        $projectedPerMinute = ($batchSize / $processingTime) * 60;
        $this->assertGreaterThan(100000, $projectedPerMinute);

        // Verify all notifications were processed
        $this->assertCount($batchSize, $results);
        foreach ($results as $result) {
            $this->assertNotEmpty($result);
            $this->assertStringStartsWith('notif_', $result);
        }
    }

    /**
     * Test vendor failover mechanism
     */
    public function testVendorFailoverMechanism(): void
    {
        // Test data
        $notificationId = 'notif_' . uniqid();
        $payload = [
            'recipient' => 'user@example.com',
            'content' => 'Test message',
            'channel' => NotificationInterface::CHANNEL_EMAIL
        ];

        // Configure primary vendor to fail
        $primaryVendorException = new VendorException(
            'Primary vendor unavailable',
            VendorException::VENDOR_UNAVAILABLE,
            null,
            ['vendor_name' => 'primary_vendor', 'channel' => 'email']
        );

        // Configure vendor service mock for failover
        $this->vendorServiceMock->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($primaryVendorException),
                ['status' => 'delivered', 'vendor' => 'backup_vendor']
            );

        // Configure Redis for status tracking
        $this->redisMock->expects($this->exactly(3))
            ->method('setex')
            ->willReturn(true);

        // Attempt delivery
        $result = $this->notificationService->send($payload, NotificationInterface::CHANNEL_EMAIL);

        // Verify successful failover
        $status = $this->notificationService->getStatus($result);
        $this->assertEquals('delivered', $status['status']);
        $this->assertEquals('backup_vendor', $status['vendor']);

        // Verify failover timing
        $attempts = $this->notificationService->getDeliveryAttempts($result);
        $this->assertCount(2, $attempts);
        $timeDiff = $attempts[1]['timestamp'] - $attempts[0]['timestamp'];
        $this->assertLessThan(2, $timeDiff); // Failover within 2 seconds
    }

    /**
     * Test retry mechanism with exponential backoff
     */
    public function testRetryMechanismWithBackoff(): void
    {
        // Test data
        $notificationId = 'notif_' . uniqid();
        $failedStatus = [
            'status' => NotificationInterface::STATUS_FAILED,
            'attempts' => 1
        ];

        // Configure mocks for retry scenario
        $this->redisMock->expects($this->exactly(2))
            ->method('get')
            ->with("notification:status:{$notificationId}")
            ->willReturn(json_encode($failedStatus));

        $this->redisMock->expects($this->exactly(2))
            ->method('lrange')
            ->willReturn([
                json_encode(['timestamp' => time(), 'status' => 'failed'])
            ]);

        $this->sqsServiceMock->expects($this->once())
            ->method('sendMessage')
            ->with($this->callback(function ($message) {
                return isset($message['retry_count']) && $message['retry_count'] === 2;
            }))
            ->willReturn('msg_' . uniqid());

        // Attempt retry
        $result = $this->notificationService->retry($notificationId);

        // Verify retry configuration
        $this->assertEquals(NotificationInterface::STATUS_RETRYING, $result['status']);
        $this->assertEquals(2, $result['attempt']);
        $this->assertGreaterThan(1000, $result['delay']); // Verify exponential backoff
    }

    /**
     * Test validation of notification payload
     */
    public function testNotificationPayloadValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        
        $invalidPayload = [
            // Missing required recipient
            'content' => 'Test message'
        ];

        $this->notificationService->send($invalidPayload, NotificationInterface::CHANNEL_EMAIL);
    }

    /**
     * Test template rendering integration
     */
    public function testTemplateRendering(): void
    {
        $payload = [
            'recipient' => 'user@example.com',
            'template_id' => 'welcome_template',
            'context' => ['name' => 'Test User']
        ];

        $this->templateServiceMock->expects($this->once())
            ->method('render')
            ->with('welcome_template', ['name' => 'Test User'])
            ->willReturn('Welcome Test User!');

        $this->sqsServiceMock->expects($this->once())
            ->method('sendMessage')
            ->willReturn('msg_' . uniqid());

        $result = $this->notificationService->send(
            $payload,
            NotificationInterface::CHANNEL_EMAIL
        );

        $this->assertNotEmpty($result);
    }

    /**
     * Test delivery status tracking
     */
    public function testDeliveryStatusTracking(): void
    {
        $notificationId = 'notif_' . uniqid();
        $status = [
            'status' => NotificationInterface::STATUS_DELIVERED,
            'timestamp' => time()
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with("notification:status:{$notificationId}")
            ->willReturn(json_encode($status));

        $this->redisMock->expects($this->once())
            ->method('lrange')
            ->willReturn([
                json_encode(['status' => 'queued', 'timestamp' => time() - 60]),
                json_encode(['status' => 'delivered', 'timestamp' => time()])
            ]);

        $result = $this->notificationService->getStatus($notificationId);

        $this->assertEquals(NotificationInterface::STATUS_DELIVERED, $result['status']);
        $this->assertCount(2, $result['attempts']);
    }
}