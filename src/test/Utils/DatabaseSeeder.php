<?php

declare(strict_types=1);

namespace App\Test\Utils;

use App\Models\Notification;
use App\Test\Utils\TestHelper;
use Carbon\Carbon; // ^2.0
use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Test database seeder utility class for generating consistent test data
 * across different test suites. Supports atomic operations and configurable
 * seeding patterns for notifications, templates, and delivery attempts.
 *
 * @package App\Test\Utils
 * @version 1.0.0
 */
class DatabaseSeeder
{
    /**
     * Default seeding configuration options
     */
    private const DEFAULT_OPTIONS = [
        'notification_count' => 10,
        'template_count' => 5,
        'vendor_count' => 3,
        'channel_distribution' => [
            'email' => 0.5,  // 50% email
            'sms' => 0.3,    // 30% SMS
            'push' => 0.2    // 20% push
        ],
        'status_distribution' => [
            Notification::STATUS_DELIVERED => 0.7,  // 70% delivered
            Notification::STATUS_PENDING => 0.2,    // 20% pending
            Notification::STATUS_FAILED => 0.1      // 10% failed
        ],
        'vendor_configs' => [
            'email' => ['Iterable', 'SendGrid', 'SES'],
            'sms' => ['Telnyx', 'Twilio'],
            'push' => ['SNS']
        ]
    ];

    /**
     * Seeds the test database with a consistent set of test data using atomic transactions.
     *
     * @param PDO $connection Database connection
     * @param array $options Optional seeding configuration overrides
     * @return void
     * @throws RuntimeException If seeding fails
     */
    public static function seedTestDatabase(PDO $connection, array $options = []): void
    {
        $options = array_merge(self::DEFAULT_OPTIONS, $options);

        try {
            // Begin transaction for atomic operation
            $connection->beginTransaction();

            // Clear existing test data
            self::clearTestData($connection);

            // Seed templates for each channel
            $templateIds = self::seedTestTemplates(
                $connection,
                $options['template_count']
            );

            // Seed notifications with varied statuses
            $notificationIds = self::seedTestNotifications(
                $connection,
                $options['notification_count'],
                $templateIds
            );

            // Seed delivery attempts
            self::seedDeliveryAttempts(
                $connection,
                $notificationIds,
                $options['vendor_configs']
            );

            // Seed vendor configurations
            self::seedVendorConfigs(
                $connection,
                $options['vendor_configs']
            );

            // Commit transaction
            $connection->commit();

        } catch (\Exception $e) {
            $connection->rollBack();
            throw new RuntimeException(
                "Failed to seed test database: " . $e->getMessage()
            );
        }
    }

    /**
     * Clears all test data from the database using atomic operations.
     *
     * @param PDO $connection Database connection
     * @return void
     * @throws RuntimeException If clearing fails
     */
    public static function clearTestData(PDO $connection): void
    {
        try {
            $connection->beginTransaction();

            // Disable foreign key checks temporarily
            $connection->exec('SET FOREIGN_KEY_CHECKS = 0');

            // Clear all test tables
            $connection->exec('TRUNCATE TABLE delivery_attempts');
            $connection->exec('TRUNCATE TABLE notifications');
            $connection->exec('TRUNCATE TABLE templates');
            $connection->exec('TRUNCATE TABLE vendor_status');

            // Re-enable foreign key checks
            $connection->exec('SET FOREIGN_KEY_CHECKS = 1');

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw new RuntimeException(
                "Failed to clear test data: " . $e->getMessage()
            );
        }
    }

    /**
     * Seeds test templates for different notification channels.
     *
     * @param PDO $connection Database connection
     * @param int $count Number of templates to create
     * @return array Array of created template IDs with channel mapping
     */
    private static function seedTestTemplates(PDO $connection, int $count): array
    {
        $templateIds = [];
        $channels = ['email', 'sms', 'push'];
        
        foreach ($channels as $channel) {
            $templatesPerChannel = ceil($count / count($channels));
            
            for ($i = 0; $i < $templatesPerChannel; $i++) {
                $template = TestHelper::generateTestTemplate($channel);
                
                $stmt = $connection->prepare(
                    "INSERT INTO templates (id, name, type, content, active, version, created_at)
                     VALUES (:id, :name, :type, :content, :active, :version, :created_at)"
                );

                $stmt->execute([
                    'id' => $template['id'],
                    'name' => $template['name'],
                    'type' => $channel,
                    'content' => json_encode($template['content']),
                    'active' => true,
                    'version' => 1,
                    'created_at' => Carbon::now()->toDateTimeString()
                ]);

                $templateIds[$channel][] = $template['id'];
            }
        }

        return $templateIds;
    }

    /**
     * Seeds test notifications with various statuses and channel distributions.
     *
     * @param PDO $connection Database connection
     * @param int $count Number of notifications to create
     * @param array $templateIds Template IDs by channel
     * @return array Array of created notification IDs
     */
    private static function seedTestNotifications(
        PDO $connection,
        int $count,
        array $templateIds
    ): array {
        $notificationIds = [];
        $distribution = self::DEFAULT_OPTIONS['channel_distribution'];
        
        foreach ($distribution as $channel => $percentage) {
            $notificationsForChannel = (int) ceil($count * $percentage);
            
            for ($i = 0; $i < $notificationsForChannel; $i++) {
                $templateId = $templateIds[$channel][array_rand($templateIds[$channel])];
                $notification = TestHelper::generateTestNotification($channel, [
                    'template_id' => $templateId
                ]);

                $stmt = $connection->prepare(
                    "INSERT INTO notifications (id, type, payload, status, channel, created_at)
                     VALUES (:id, :type, :payload, :status, :channel, :created_at)"
                );

                $stmt->execute([
                    'id' => $notification['id'],
                    'type' => $notification['type'],
                    'payload' => json_encode($notification['payload']),
                    'status' => $notification['status'],
                    'channel' => $channel,
                    'created_at' => Carbon::now()->toDateTimeString()
                ]);

                $notificationIds[] = $notification['id'];
            }
        }

        return $notificationIds;
    }

    /**
     * Seeds delivery attempts for notifications with success/failure patterns.
     *
     * @param PDO $connection Database connection
     * @param array $notificationIds Notification IDs to create attempts for
     * @param array $vendorConfigs Vendor configuration by channel
     * @return void
     */
    private static function seedDeliveryAttempts(
        PDO $connection,
        array $notificationIds,
        array $vendorConfigs
    ): void {
        foreach ($notificationIds as $notificationId) {
            $stmt = $connection->prepare(
                "SELECT channel FROM notifications WHERE id = ?"
            );
            $stmt->execute([$notificationId]);
            $channel = $stmt->fetchColumn();

            $vendors = $vendorConfigs[$channel];
            $attempt = TestHelper::generateTestDeliveryAttempt($notificationId, $vendors[0]);

            $stmt = $connection->prepare(
                "INSERT INTO delivery_attempts (id, notification_id, vendor, status, response, attempted_at)
                 VALUES (:id, :notification_id, :vendor, :status, :response, :attempted_at)"
            );

            $stmt->execute([
                'id' => $attempt['id'],
                'notification_id' => $notificationId,
                'vendor' => $attempt['vendor'],
                'status' => $attempt['status'],
                'response' => json_encode($attempt['response']),
                'attempted_at' => Carbon::now()->toDateTimeString()
            ]);
        }
    }

    /**
     * Seeds vendor configurations with test credentials.
     *
     * @param PDO $connection Database connection
     * @param array $vendorConfigs Vendor configuration by channel
     * @return void
     */
    private static function seedVendorConfigs(
        PDO $connection,
        array $vendorConfigs
    ): void {
        foreach ($vendorConfigs as $channel => $vendors) {
            foreach ($vendors as $vendor) {
                $stmt = $connection->prepare(
                    "INSERT INTO vendor_status (vendor, channel, status, success_rate, last_check)
                     VALUES (:vendor, :channel, :status, :success_rate, :last_check)"
                );

                $stmt->execute([
                    'vendor' => $vendor,
                    'channel' => $channel,
                    'status' => 'active',
                    'success_rate' => 0.999,
                    'last_check' => Carbon::now()->toDateTimeString()
                ]);
            }
        }
    }
}