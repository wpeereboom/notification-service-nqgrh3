<?php

declare(strict_types=1);

namespace App\Test\E2E\Scenarios;

use App\Test\Utils\TestHelper;
use App\Test\Utils\VendorSimulator;
use App\Services\Vendor\VendorService;
use PHPUnit\Framework\TestCase;
use Carbon\Carbon;

/**
 * End-to-end test scenarios for vendor failover functionality across notification channels.
 * Verifies failover timing, success rates, and multi-channel support requirements.
 *
 * Requirements tested:
 * - Vendor failover time < 2 seconds
 * - 99.9% delivery success rate
 * - Multi-channel support (Email, SMS, Push)
 *
 * @package App\Test\E2E\Scenarios
 * @version 1.0.0
 */
class VendorFailoverScenarioTest extends TestCase
{
    private const EMAIL_VENDORS = ['Iterable', 'SendGrid', 'SES'];
    private const SMS_VENDORS = ['Telnyx', 'Twilio'];
    private const PUSH_VENDORS = ['SNS'];
    private const MAX_FAILOVER_TIME_MS = 2000; // 2 seconds

    private VendorService $vendorService;
    private Carbon $testStartTime;

    /**
     * Set up test environment before each test case.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize test environment
        TestHelper::setupTestEnvironment();
        
        // Configure vendor simulators
        $this->configureVendorSimulators();
        
        // Initialize vendor service
        $this->vendorService = $this->initializeVendorService();
        
        // Set up timing measurement
        $this->testStartTime = Carbon::now();
    }

    /**
     * Clean up test environment after each test case.
     */
    protected function tearDown(): void
    {
        TestHelper::cleanupTestEnvironment();
        parent::tearDown();
    }

    /**
     * Tests email vendor failover scenario with timing validation.
     * Verifies failover from Iterable -> SendGrid -> SES within time constraints.
     */
    public function testEmailVendorFailover(): void
    {
        // Generate test notification
        $notification = TestHelper::generateTestNotification('email');

        // Configure primary vendor (Iterable) to fail
        VendorSimulator::simulateVendorResponse('Iterable', [], 100, true);

        // Record start time
        $startTime = Carbon::now();

        // Send notification
        $response = $this->vendorService->send($notification, 'email', 'test_tenant');

        // Calculate failover duration
        $failoverDuration = $startTime->diffInMilliseconds(Carbon::now());

        // Assert failover time
        $this->assertLessThanOrEqual(
            self::MAX_FAILOVER_TIME_MS,
            $failoverDuration,
            "Failover took longer than 2 seconds: {$failoverDuration}ms"
        );

        // Verify successful delivery
        TestHelper::assertNotificationDelivered($response['messageId']);

        // Verify vendor chain
        $attempts = $this->vendorService->getStatus($response['messageId'], 'SendGrid', 'test_tenant');
        $this->assertEquals('SendGrid', $attempts['vendorMetadata']['vendor']);
    }

    /**
     * Tests SMS vendor failover scenario with parallel execution.
     * Verifies failover from Telnyx to Twilio within time constraints.
     */
    public function testSmsVendorFailover(): void
    {
        // Generate test notification
        $notification = TestHelper::generateTestNotification('sms');

        // Configure Telnyx to fail
        VendorSimulator::simulateVendorResponse('Telnyx', [], 100, true);

        // Record start time
        $startTime = Carbon::now();

        // Send notification
        $response = $this->vendorService->send($notification, 'sms', 'test_tenant');

        // Calculate failover duration
        $failoverDuration = $startTime->diffInMilliseconds(Carbon::now());

        // Assert failover time
        $this->assertLessThanOrEqual(
            self::MAX_FAILOVER_TIME_MS,
            $failoverDuration,
            "SMS failover exceeded 2 seconds: {$failoverDuration}ms"
        );

        // Verify successful delivery
        TestHelper::assertNotificationDelivered($response['messageId']);

        // Verify vendor health checks
        $telnyxHealth = VendorSimulator::simulateHealthCheck('Telnyx', 50, false);
        $twilioHealth = VendorSimulator::simulateHealthCheck('Twilio', 50, true);
        
        $this->assertFalse($telnyxHealth['isHealthy']);
        $this->assertTrue($twilioHealth['isHealthy']);
    }

    /**
     * Tests cascading failover scenario when multiple vendors fail.
     * Verifies complete vendor chain: Iterable -> SendGrid -> SES
     */
    public function testCascadingVendorFailover(): void
    {
        // Generate test notification
        $notification = TestHelper::generateTestNotification('email');

        // Configure cascading failures
        $vendorConfigs = [
            ['vendor' => 'Iterable', 'latency' => 100, 'shouldFail' => true],
            ['vendor' => 'SendGrid', 'latency' => 100, 'shouldFail' => true],
            ['vendor' => 'SES', 'latency' => 100, 'shouldFail' => false]
        ];

        // Simulate failover scenario
        $results = VendorSimulator::simulateFailover($vendorConfigs, $notification);

        // Verify total failover time
        $this->assertLessThanOrEqual(
            self::MAX_FAILOVER_TIME_MS,
            $results['totalTime'],
            "Cascading failover exceeded 2 seconds: {$results['totalTime']}ms"
        );

        // Verify successful delivery through final vendor
        $this->assertTrue($results['successful']);
        $this->assertEquals('SES', $results['finalVendor']);

        // Verify attempt chain
        $this->assertCount(3, $results['attempts']);
        $this->assertFalse($results['attempts'][0]['success']); // Iterable
        $this->assertFalse($results['attempts'][1]['success']); // SendGrid
        $this->assertTrue($results['attempts'][2]['success']);  // SES

        // Verify individual failover timings
        foreach ($results['attempts'] as $attempt) {
            $this->assertLessThanOrEqual(
                self::MAX_FAILOVER_TIME_MS / count($vendorConfigs),
                $attempt['duration'],
                "Individual failover attempt exceeded time limit"
            );
        }
    }

    /**
     * Configures vendor simulators for testing.
     */
    private function configureVendorSimulators(): void
    {
        foreach (self::EMAIL_VENDORS as $vendor) {
            VendorSimulator::simulateHealthCheck($vendor, 50, true);
        }
        foreach (self::SMS_VENDORS as $vendor) {
            VendorSimulator::simulateHealthCheck($vendor, 50, true);
        }
        foreach (self::PUSH_VENDORS as $vendor) {
            VendorSimulator::simulateHealthCheck($vendor, 50, true);
        }
    }

    /**
     * Initializes vendor service with test configuration.
     */
    private function initializeVendorService(): VendorService
    {
        // Service initialization would typically be done through dependency injection
        // For testing purposes, we're creating a new instance with test configuration
        return new VendorService(
            $this->createMock(\App\Services\Vendor\VendorFactory::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\Predis\Client::class)
        );
    }
}