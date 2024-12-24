<?php

declare(strict_types=1);

namespace App\Test\Integration\Cli;

use App\Test\Utils\TestHelper;
use App\Test\Utils\DatabaseSeeder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use App\Console\Commands\NotifyCommand;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Integration test suite for the CLI notify command.
 * Verifies end-to-end notification sending functionality, error handling,
 * and validation through the command line interface.
 *
 * @package App\Test\Integration\Cli
 * @version 1.0.0
 */
class NotifyCommandTest extends TestCase
{
    /**
     * @var CommandTester Command testing utility
     */
    private CommandTester $commandTester;

    /**
     * @var NotifyCommand Command instance under test
     */
    private NotifyCommand $notifyCommand;

    /**
     * Test data constants
     */
    private const TEST_TEMPLATE_ID = 'test_template_123';
    private const TEST_RECIPIENT_EMAIL = 'test@example.com';
    private const TEST_RECIPIENT_SMS = '+1234567890';
    private const TEST_RECIPIENT_PUSH = 'device_token_123';

    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize test environment
        TestHelper::setupTestEnvironment();

        // Mock notification service providers
        TestHelper::mockNotificationServices([
            'email' => ['Iterable', 'SendGrid', 'SES'],
            'sms' => ['Telnyx', 'Twilio'],
            'push' => ['SNS']
        ]);

        // Seed test database with templates and test data
        DatabaseSeeder::seedTestDatabase(
            $this->getConnection(),
            [
                'template_count' => 5,
                'notification_count' => 0 // Start clean for notification tests
            ]
        );

        // Initialize command tester
        $this->notifyCommand = new NotifyCommand();
        $this->commandTester = new CommandTester($this->notifyCommand);
    }

    /**
     * Clean up test environment after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test environment
        TestHelper::cleanupTestEnvironment();

        // Clear test database
        DatabaseSeeder::clearTestData($this->getConnection());

        parent::tearDown();
    }

    /**
     * Test sending notification with valid template and recipient.
     *
     * @test
     * @return void
     */
    public function testSendNotificationWithValidTemplate(): void
    {
        // Execute command with valid inputs
        $this->commandTester->execute([
            'command' => 'notify:send',
            '--template' => self::TEST_TEMPLATE_ID,
            '--recipient' => self::TEST_RECIPIENT_EMAIL,
            '--channel' => 'email',
            '--format' => 'json'
        ]);

        // Assert successful execution
        $this->assertEquals(0, $this->commandTester->getStatusCode());

        // Get command output
        $output = json_decode($this->commandTester->getDisplay(), true);

        // Verify notification creation and delivery
        $this->assertArrayHasKey('notification_id', $output);
        $notificationId = $output['notification_id'];

        // Assert notification delivered successfully
        TestHelper::assertNotificationDelivered($notificationId);

        // Verify delivery time within acceptable range
        $this->assertLessThanOrEqual(
            30000, // 30 seconds max latency
            Carbon::parse($output['created_at'])
                ->diffInMilliseconds(Carbon::parse($output['completed_at']))
        );

        // Assert output format matches specification
        $this->assertArrayHasKey('status', $output);
        $this->assertArrayHasKey('channel', $output);
        $this->assertArrayHasKey('vendor', $output);
    }

    /**
     * Test sending notifications through all supported channels.
     *
     * @test
     * @return void
     */
    public function testSendNotificationAcrossAllChannels(): void
    {
        // Test email notification
        $emailResult = $this->commandTester->execute([
            'command' => 'notify:send',
            '--template' => self::TEST_TEMPLATE_ID,
            '--recipient' => self::TEST_RECIPIENT_EMAIL,
            '--channel' => 'email'
        ]);
        $this->assertEquals(0, $emailResult);

        // Test SMS notification
        $smsResult = $this->commandTester->execute([
            'command' => 'notify:send',
            '--template' => self::TEST_TEMPLATE_ID,
            '--recipient' => self::TEST_RECIPIENT_SMS,
            '--channel' => 'sms'
        ]);
        $this->assertEquals(0, $smsResult);

        // Test push notification
        $pushResult = $this->commandTester->execute([
            'command' => 'notify:send',
            '--template' => self::TEST_TEMPLATE_ID,
            '--recipient' => self::TEST_RECIPIENT_PUSH,
            '--channel' => 'push'
        ]);
        $this->assertEquals(0, $pushResult);
    }

    /**
     * Test sending notification with invalid template ID.
     *
     * @test
     * @return void
     */
    public function testSendNotificationWithInvalidTemplate(): void
    {
        $this->commandTester->execute([
            'command' => 'notify:send',
            '--template' => 'invalid_template_id',
            '--recipient' => self::TEST_RECIPIENT_EMAIL,
            '--channel' => 'email'
        ]);

        // Assert error status code
        $this->assertEquals(1, $this->commandTester->getStatusCode());

        // Verify error message
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Template not found', $output);
    }

    /**
     * Test sending notification with invalid recipient format.
     *
     * @test
     * @return void
     */
    public function testSendNotificationWithInvalidRecipient(): void
    {
        $this->commandTester->execute([
            'command' => 'notify:send',
            '--template' => self::TEST_TEMPLATE_ID,
            '--recipient' => 'invalid-email',
            '--channel' => 'email'
        ]);

        // Assert error status code
        $this->assertEquals(1, $this->commandTester->getStatusCode());

        // Verify error message
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Invalid recipient format', $output);
    }

    /**
     * Test sending notification without required options.
     *
     * @test
     * @return void
     */
    public function testSendNotificationWithoutRequiredOptions(): void
    {
        $this->commandTester->execute([
            'command' => 'notify:send',
            '--recipient' => self::TEST_RECIPIENT_EMAIL
        ]);

        // Assert error status code
        $this->assertEquals(1, $this->commandTester->getStatusCode());

        // Verify error message lists missing required options
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Required option --template is missing', $output);
    }

    /**
     * Test notification sending with simulated vendor failure.
     *
     * @test
     * @return void
     */
    public function testSendNotificationWithVendorFailure(): void
    {
        // Mock primary vendor to simulate failure
        TestHelper::mockNotificationServices([
            'email' => [
                'Iterable' => ['status' => 'failed'],
                'SendGrid' => ['status' => 'active']
            ]
        ]);

        $this->commandTester->execute([
            'command' => 'notify:send',
            '--template' => self::TEST_TEMPLATE_ID,
            '--recipient' => self::TEST_RECIPIENT_EMAIL,
            '--channel' => 'email'
        ]);

        // Assert successful execution despite primary vendor failure
        $this->assertEquals(0, $this->commandTester->getStatusCode());

        // Get command output
        $output = json_decode($this->commandTester->getDisplay(), true);

        // Verify failover to secondary vendor
        $this->assertEquals('SendGrid', $output['vendor']);

        // Assert notification delivered successfully
        TestHelper::assertNotificationDelivered($output['notification_id']);

        // Verify failover time within limits
        $this->assertLessThanOrEqual(
            2000, // 2 seconds max failover time
            Carbon::parse($output['vendor_failover_at'])
                ->diffInMilliseconds(Carbon::parse($output['created_at']))
        );
    }
}