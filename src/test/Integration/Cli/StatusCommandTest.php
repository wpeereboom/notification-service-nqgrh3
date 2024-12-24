<?php

declare(strict_types=1);

namespace App\Test\Integration\Cli;

use App\Test\Utils\TestHelper;
use App\Models\Notification;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use InvalidArgumentException;
use RuntimeException;
use JsonException;

/**
 * Integration test suite for the CLI status command.
 * Tests notification status checking functionality with comprehensive coverage
 * of status states, output formats, and error scenarios.
 *
 * @package App\Test\Integration\Cli
 * @version 1.0.0
 */
class StatusCommandTest extends TestCase
{
    private const TEST_NOTIFICATION_ID = 'test_notification_123';
    private const TEST_TIMEOUT_SECONDS = 5;

    private CommandTester $commandTester;
    private TestHelper $testHelper;
    private array $testNotifications = [];

    /**
     * Set up test environment and dependencies.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->testHelper = new TestHelper();
        $this->commandTester = new CommandTester(new \App\Console\Commands\StatusCommand());
        
        // Configure test timeout
        ini_set('max_execution_time', (string)self::TEST_TIMEOUT_SECONDS);
    }

    /**
     * Clean up test environment after each test.
     */
    protected function tearDown(): void
    {
        foreach ($this->testNotifications as $notification) {
            $this->testHelper->cleanupTestEnvironment();
        }
        $this->testNotifications = [];
        parent::tearDown();
    }

    /**
     * Test status command with valid notification IDs in different states.
     *
     * @dataProvider getStatusTestData
     * @test
     */
    public function testStatusCommandWithValidId(string $status, array $expectedOutput): void
    {
        // Generate test notification with specified status
        $notification = $this->testHelper->generateTestNotification('email', [
            'id' => self::TEST_NOTIFICATION_ID,
            'status' => $status
        ]);
        $this->testNotifications[] = $notification;

        // Execute command
        $this->commandTester->execute([
            'id' => self::TEST_NOTIFICATION_ID,
            '--format' => 'json'
        ]);

        // Assert response
        $output = json_decode($this->commandTester->getDisplay(), true);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertEquals($status, $output['status']);
        $this->assertArrayHasKey('timestamps', $output);
        $this->assertArrayHasKey('metrics', $output);
        
        // Verify expected output structure
        foreach ($expectedOutput as $key => $value) {
            $this->assertArrayHasKey($key, $output);
            $this->assertEquals($value, $output[$key]);
        }
    }

    /**
     * Test status command with invalid notification ID.
     *
     * @test
     */
    public function testStatusCommandWithInvalidId(): void
    {
        $invalidId = 'invalid_notification_id';

        // Execute command with invalid ID
        $this->commandTester->execute([
            'id' => $invalidId
        ]);

        // Assert error response
        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('ERROR:', $output);
        $this->assertStringContainsString('Notification not found', $output);
    }

    /**
     * Test status command output formats.
     *
     * @dataProvider getOutputFormatTestData
     * @test
     */
    public function testStatusCommandOutputFormats(string $format, array $expectedStructure): void
    {
        // Generate test notification
        $notification = $this->testHelper->generateTestNotification('email', [
            'id' => self::TEST_NOTIFICATION_ID,
            'status' => Notification::STATUS_DELIVERED
        ]);
        $this->testNotifications[] = $notification;

        // Execute command with format
        $this->commandTester->execute([
            'id' => self::TEST_NOTIFICATION_ID,
            '--format' => $format
        ]);

        $output = $this->commandTester->getDisplay();
        
        switch ($format) {
            case 'json':
                $this->assertJson($output);
                $data = json_decode($output, true);
                foreach ($expectedStructure as $key) {
                    $this->assertArrayHasKey($key, $data);
                }
                break;
                
            case 'table':
                $this->assertStringContainsString('│', $output); // Table borders
                foreach ($expectedStructure as $header) {
                    $this->assertStringContainsString($header, $output);
                }
                break;
                
            case 'plain':
                foreach ($expectedStructure as $field) {
                    $this->assertStringContainsString($field, $output);
                }
                break;
        }
    }

    /**
     * Data provider for status test cases.
     *
     * @return array
     */
    public function getStatusTestData(): array
    {
        return [
            'pending_status' => [
                Notification::STATUS_PENDING,
                [
                    'status' => Notification::STATUS_PENDING,
                    'channel' => 'email',
                    'metrics' => ['attempts' => 0]
                ]
            ],
            'delivered_status' => [
                Notification::STATUS_DELIVERED,
                [
                    'status' => Notification::STATUS_DELIVERED,
                    'channel' => 'email',
                    'metrics' => ['attempts' => 1]
                ]
            ],
            'failed_status' => [
                Notification::STATUS_FAILED,
                [
                    'status' => Notification::STATUS_FAILED,
                    'channel' => 'email',
                    'metrics' => ['attempts' => 1]
                ]
            ],
            'processing_status' => [
                Notification::STATUS_PROCESSING,
                [
                    'status' => Notification::STATUS_PROCESSING,
                    'channel' => 'email',
                    'metrics' => ['attempts' => 0]
                ]
            ]
        ];
    }

    /**
     * Data provider for output format test cases.
     *
     * @return array
     */
    public function getOutputFormatTestData(): array
    {
        return [
            'json_format' => [
                'json',
                ['status', 'channel', 'timestamps', 'metrics']
            ],
            'table_format' => [
                'table',
                ['Status', 'Channel', 'Created At', 'Updated At']
            ],
            'plain_format' => [
                'plain',
                ['Status:', 'Channel:', 'Created:', 'Updated:']
            ]
        ];
    }

    /**
     * Test status command with rate limiting.
     *
     * @test
     */
    public function testStatusCommandWithRateLimiting(): void
    {
        // Generate multiple requests to trigger rate limit
        for ($i = 0; $i < 1001; $i++) {
            $this->commandTester->execute([
                'id' => self::TEST_NOTIFICATION_ID
            ]);
        }

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(429, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Rate limit exceeded', $output);
    }

    /**
     * Test status command with network timeout.
     *
     * @test
     */
    public function testStatusCommandWithTimeout(): void
    {
        // Mock a slow response
        $this->testHelper->mockVendorResponses([
            'delay' => self::TEST_TIMEOUT_SECONDS + 1
        ]);

        $this->commandTester->execute([
            'id' => self::TEST_NOTIFICATION_ID
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Request timed out', $output);
    }
}