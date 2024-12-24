<?php

declare(strict_types=1);

namespace App\Test\E2E\Scenarios;

use App\Services\Notification\NotificationService;
use App\Services\Template\TemplateService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use App\Test\Utils\TestHelper;

/**
 * End-to-end test scenario that validates the complete welcome email notification flow
 * including template rendering, delivery, vendor failover, and performance metrics.
 *
 * @package App\Test\E2E\Scenarios
 * @version 1.0.0
 */
class WelcomeEmailScenarioTest extends TestCase
{
    private const WELCOME_TEMPLATE_NAME = 'welcome_email';
    private const DELIVERY_TIMEOUT = 30; // seconds
    private const VENDOR_FAILOVER_THRESHOLD = 2; // seconds
    private const TEST_USER_EMAIL = 'test@example.com';

    private NotificationService $notificationService;
    private TemplateService $templateService;
    private string $templateId;
    private Carbon $startTime;

    /**
     * Set up test environment before each test execution.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize test environment
        TestHelper::setupTestEnvironment();

        // Create test template
        $templateData = [
            'name' => self::WELCOME_TEMPLATE_NAME,
            'type' => 'email',
            'content' => [
                'subject' => 'Welcome to {{company_name}}',
                'body_html' => '<h1>Welcome {{name}}!</h1><p>Thank you for joining {{company_name}}.</p>',
                'body_text' => 'Welcome {{name}}! Thank you for joining {{company_name}}.'
            ],
            'metadata' => [
                'description' => 'Welcome email template for new users',
                'version' => '1.0.0'
            ],
            'active' => true
        ];

        $this->templateId = TestHelper::generateTestTemplate($templateData);
        $this->startTime = Carbon::now();
    }

    /**
     * Clean up test environment after each test execution.
     */
    protected function tearDown(): void
    {
        // Record test execution time
        $executionTime = Carbon::now()->diffInMilliseconds($this->startTime);
        $this->addToAssertionCount(1);
        $this->assertLessThanOrEqual(
            self::DELIVERY_TIMEOUT * 1000,
            $executionTime,
            "Test execution exceeded timeout of {self::DELIVERY_TIMEOUT} seconds"
        );

        // Clean up test environment
        TestHelper::cleanupTestEnvironment();
        parent::tearDown();
    }

    /**
     * Tests successful delivery of welcome email notification including performance metrics.
     *
     * @test
     */
    public function testWelcomeEmailDelivery(): void
    {
        // Prepare test data
        $userData = [
            'name' => 'John Doe',
            'email' => self::TEST_USER_EMAIL,
            'company_name' => 'Test Company'
        ];

        // Create notification payload
        $payload = [
            'recipient' => $userData['email'],
            'template_id' => $this->templateId,
            'context' => $userData,
            'metadata' => [
                'user_id' => uniqid('test_user_'),
                'test_run_id' => uniqid('test_run_')
            ]
        ];

        // Send notification
        $deliveryStart = Carbon::now();
        $notificationId = $this->notificationService->send($payload, 'email', [
            'priority' => 'high',
            'track_opens' => true,
            'track_clicks' => true
        ]);

        // Assert successful delivery
        TestHelper::assertNotificationDelivered($notificationId);

        // Verify delivery time
        $deliveryTime = Carbon::now()->diffInSeconds($deliveryStart);
        $this->assertLessThanOrEqual(
            self::DELIVERY_TIMEOUT,
            $deliveryTime,
            "Email delivery exceeded timeout of {self::DELIVERY_TIMEOUT} seconds"
        );

        // Verify notification content
        $status = $this->notificationService->getStatus($notificationId);
        $this->assertEquals('delivered', $status['status']);
        $this->assertArrayHasKey('tracking', $status);
    }

    /**
     * Tests correct rendering of welcome email template with variable substitution.
     *
     * @test
     */
    public function testWelcomeEmailTemplateRendering(): void
    {
        // Test context data
        $context = [
            'name' => 'Jane Doe',
            'company_name' => 'Test Company'
        ];

        // Render template
        $rendered = $this->templateService->render($this->templateId, $context);
        $content = json_decode($rendered, true);

        // Verify subject
        $this->assertEquals(
            'Welcome to Test Company',
            $content['subject'],
            'Template subject not rendered correctly'
        );

        // Verify HTML content
        $this->assertStringContainsString(
            '<h1>Welcome Jane Doe!</h1>',
            $content['body_html'],
            'Template HTML not rendered correctly'
        );

        // Verify text content
        $this->assertStringContainsString(
            'Welcome Jane Doe!',
            $content['body_text'],
            'Template text not rendered correctly'
        );

        // Validate template structure
        $this->assertTrue(
            $this->templateService->validate($rendered),
            'Rendered template failed validation'
        );
    }

    /**
     * Tests vendor failover scenario for welcome email delivery with timing validation.
     *
     * @test
     */
    public function testWelcomeEmailVendorFailover(): void
    {
        // Configure primary vendor to fail
        TestHelper::setupTestEnvironment([
            'vendor_config' => [
                'iterable' => ['force_failure' => true]
            ]
        ]);

        // Prepare notification data
        $payload = [
            'recipient' => self::TEST_USER_EMAIL,
            'template_id' => $this->templateId,
            'context' => [
                'name' => 'Test User',
                'company_name' => 'Test Company'
            ]
        ];

        // Record failover start time
        $failoverStart = Carbon::now();

        // Send notification
        $notificationId = $this->notificationService->send($payload, 'email');

        // Verify successful delivery through failover
        TestHelper::assertNotificationDelivered($notificationId);

        // Verify failover timing
        $failoverTime = Carbon::now()->diffInSeconds($failoverStart);
        $this->assertLessThanOrEqual(
            self::VENDOR_FAILOVER_THRESHOLD,
            $failoverTime,
            "Vendor failover exceeded threshold of {self::VENDOR_FAILOVER_THRESHOLD} seconds"
        );

        // Verify delivery attempts
        $attempts = $this->notificationService->getDeliveryAttempts($notificationId);
        $this->assertGreaterThan(
            1,
            count($attempts),
            'No failover attempts recorded'
        );

        // Verify successful delivery through secondary vendor
        $finalAttempt = end($attempts);
        $this->assertEquals(
            'successful',
            $finalAttempt['status'],
            'Final delivery attempt was not successful'
        );
        $this->assertNotEquals(
            'iterable',
            $finalAttempt['vendor'],
            'Delivery succeeded through failed vendor'
        );
    }
}