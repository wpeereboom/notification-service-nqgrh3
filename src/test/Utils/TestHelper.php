<?php

declare(strict_types=1);

namespace App\Test\Utils;

use App\Models\Notification;
use Carbon\Carbon;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RuntimeException;

/**
 * Core test utility class providing comprehensive testing capabilities for the notification service.
 * Supports testing of multi-channel notifications, performance requirements, and delivery metrics.
 *
 * @package App\Test\Utils
 * @version 1.0.0
 */
class TestHelper extends TestCase
{
    /**
     * Test data generation prefixes
     */
    private const TEST_TEMPLATE_PREFIX = 'test_template_';
    private const TEST_NOTIFICATION_PREFIX = 'test_notification_';

    /**
     * Performance testing configuration
     */
    private const MAX_TEST_ATTEMPTS = 3;
    private const PERFORMANCE_BATCH_SIZE = 1000;
    private const MAX_LATENCY_MS = 30000; // 30 seconds
    private const VENDOR_FAILOVER_MS = 2000; // 2 seconds

    /**
     * @var Generator Faker instance for test data generation
     */
    private Generator $faker;

    /**
     * @var array Channel-specific test configurations
     */
    private array $channelConfigs = [
        'email' => [
            'templates' => ['welcome', 'reset_password', 'notification'],
            'vendors' => ['Iterable', 'SendGrid', 'SES']
        ],
        'sms' => [
            'templates' => ['verification', 'alert', 'reminder'],
            'vendors' => ['Telnyx', 'Twilio']
        ],
        'push' => [
            'templates' => ['update', 'alert', 'message'],
            'vendors' => ['SNS']
        ]
    ];

    /**
     * @var array Performance metrics tracking
     */
    private array $performanceMetrics = [
        'delivery_success' => 0,
        'delivery_total' => 0,
        'processing_times' => [],
        'vendor_failovers' => []
    ];

    /**
     * Initialize test helper with faker instance and testing configurations.
     */
    public function __construct()
    {
        parent::__construct();
        $this->faker = Factory::create();
        $this->initializeFakerProviders();
    }

    /**
     * Generates test notification data with channel-specific content and optional overrides.
     *
     * @param string $channel Notification channel (email|sms|push)
     * @param array $overrides Optional data overrides
     * @return array Generated notification data
     * @throws InvalidArgumentException If channel is invalid
     */
    public static function generateTestNotification(string $channel, array $overrides = []): array
    {
        $faker = Factory::create();
        $notificationId = self::TEST_NOTIFICATION_PREFIX . $faker->uuid;

        $baseData = [
            'id' => $notificationId,
            'created_at' => Carbon::now()->toIso8601String(),
            'status' => Notification::STATUS_PENDING,
            'channel' => $channel,
            'metadata' => [
                'test_run_id' => $faker->uuid,
                'environment' => 'testing'
            ]
        ];

        // Generate channel-specific content
        switch ($channel) {
            case 'email':
                $channelData = [
                    'recipient' => $faker->email,
                    'template_id' => self::TEST_TEMPLATE_PREFIX . $faker->randomElement(['welcome', 'reset']),
                    'context' => [
                        'name' => $faker->name,
                        'action_url' => $faker->url
                    ]
                ];
                break;

            case 'sms':
                $channelData = [
                    'recipient' => $faker->phoneNumber,
                    'template_id' => self::TEST_TEMPLATE_PREFIX . $faker->randomElement(['verify', 'alert']),
                    'context' => [
                        'code' => $faker->numerify('######'),
                        'expires_in' => '10 minutes'
                    ]
                ];
                break;

            case 'push':
                $channelData = [
                    'device_token' => $faker->sha256,
                    'template_id' => self::TEST_TEMPLATE_PREFIX . $faker->randomElement(['update', 'message']),
                    'context' => [
                        'title' => $faker->sentence(3),
                        'body' => $faker->sentence(10)
                    ]
                ];
                break;

            default:
                throw new InvalidArgumentException("Invalid notification channel: {$channel}");
        }

        return array_merge($baseData, $channelData, $overrides);
    }

    /**
     * Generates a batch of test notifications for performance testing.
     *
     * @param int $count Number of notifications to generate
     * @param string $channel Notification channel
     * @return array Array of generated notifications
     * @throws InvalidArgumentException If count exceeds batch size limit
     */
    public static function generateBatchNotifications(int $count, string $channel): array
    {
        if ($count > self::PERFORMANCE_BATCH_SIZE) {
            throw new InvalidArgumentException(
                "Batch size {$count} exceeds maximum of " . self::PERFORMANCE_BATCH_SIZE
            );
        }

        $notifications = [];
        $batchId = self::TEST_NOTIFICATION_PREFIX . 'batch_' . uniqid();

        for ($i = 0; $i < $count; $i++) {
            $notifications[] = self::generateTestNotification($channel, [
                'batch_id' => $batchId,
                'priority' => random_int(1, 3),
                'metadata' => [
                    'batch_index' => $i,
                    'batch_total' => $count
                ]
            ]);
        }

        return $notifications;
    }

    /**
     * Asserts that a notification was delivered successfully within timing constraints.
     *
     * @param string $notificationId Notification ID to check
     * @param bool $checkTiming Whether to validate timing constraints
     * @return void
     * @throws RuntimeException If notification status check fails
     */
    public static function assertNotificationDelivered(string $notificationId, bool $checkTiming = true): void
    {
        $notification = Notification::with('deliveryAttempts')->findOrFail($notificationId);
        
        // Assert successful delivery
        self::assertEquals(
            Notification::STATUS_DELIVERED,
            $notification->status,
            "Notification {$notificationId} was not delivered successfully"
        );

        if ($checkTiming) {
            // Check processing time
            $processingTime = Carbon::parse($notification->created_at)
                ->diffInMilliseconds($notification->updated_at);
            
            self::assertLessThanOrEqual(
                self::MAX_LATENCY_MS,
                $processingTime,
                "Notification processing exceeded maximum latency of " . self::MAX_LATENCY_MS . "ms"
            );

            // Check vendor failover time if applicable
            $attempts = $notification->deliveryAttempts;
            if ($attempts->count() > 1) {
                foreach ($attempts as $i => $attempt) {
                    if ($i === 0) continue;
                    
                    $failoverTime = Carbon::parse($attempts[$i-1]->attempted_at)
                        ->diffInMilliseconds($attempt->attempted_at);
                    
                    self::assertLessThanOrEqual(
                        self::VENDOR_FAILOVER_MS,
                        $failoverTime,
                        "Vendor failover exceeded maximum time of " . self::VENDOR_FAILOVER_MS . "ms"
                    );
                }
            }
        }
    }

    /**
     * Asserts batch delivery metrics meet performance requirements.
     *
     * @param array $notificationIds Array of notification IDs to check
     * @return void
     * @throws RuntimeException If metrics check fails
     */
    public static function assertBatchDeliveryMetrics(array $notificationIds): void
    {
        $notifications = Notification::with('deliveryAttempts')
            ->whereIn('id', $notificationIds)
            ->get();

        // Calculate success rate
        $successful = $notifications->filter(function ($notification) {
            return $notification->status === Notification::STATUS_DELIVERED;
        })->count();

        $successRate = $successful / count($notificationIds);
        self::assertGreaterThanOrEqual(
            0.999, // 99.9% success rate requirement
            $successRate,
            "Batch delivery success rate of {$successRate} is below required 99.9%"
        );

        // Calculate 95th percentile processing time
        $processingTimes = $notifications->map(function ($notification) {
            return Carbon::parse($notification->created_at)
                ->diffInMilliseconds($notification->updated_at);
        })->sort()->values();

        $p95Index = (int) ceil(0.95 * count($processingTimes));
        $p95Time = $processingTimes[$p95Index - 1];

        self::assertLessThanOrEqual(
            self::MAX_LATENCY_MS,
            $p95Time,
            "95th percentile processing time of {$p95Time}ms exceeds maximum of " . self::MAX_LATENCY_MS . "ms"
        );
    }

    /**
     * Initialize custom Faker providers for notification testing.
     *
     * @return void
     */
    private function initializeFakerProviders(): void
    {
        // Add custom providers if needed
        $this->faker->addProvider(new \Faker\Provider\Internet($this->faker));
        $this->faker->addProvider(new \Faker\Provider\PhoneNumber($this->faker));
    }
}