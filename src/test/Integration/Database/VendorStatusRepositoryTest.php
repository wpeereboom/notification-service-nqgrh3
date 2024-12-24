<?php

declare(strict_types=1);

namespace App\Test\Integration\Database;

use App\Models\VendorStatus;
use App\Test\Utils\TestHelper;
use App\Test\Utils\DatabaseSeeder;
use PHPUnit\Framework\TestCase;
use Carbon\Carbon; // ^2.0
use PDO;
use InvalidArgumentException;
use RuntimeException;

/**
 * Integration test suite for VendorStatus repository operations.
 * Tests vendor health monitoring, status transitions, and failover scenarios.
 *
 * @package App\Test\Integration\Database
 * @version 1.0.0
 */
class VendorStatusRepositoryTest extends TestCase
{
    /**
     * Test constants for vendor identifiers
     */
    private const TEST_VENDOR_ITERABLE = 'iterable';
    private const TEST_VENDOR_SENDGRID = 'sendgrid';
    private const TEST_VENDOR_TELNYX = 'telnyx';

    /**
     * Test constants for health check configuration
     */
    private const HEALTH_CHECK_INTERVAL = 30; // seconds
    private const FAILOVER_THRESHOLD = 2; // seconds

    /**
     * @var PDO Database connection
     */
    private PDO $connection;

    /**
     * @var VendorStatus VendorStatus model instance
     */
    private VendorStatus $vendorStatus;

    /**
     * @var Carbon Current test time
     */
    private Carbon $testTime;

    /**
     * Set up test environment before each test case.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize test environment
        TestHelper::setupTestEnvironment();
        
        // Set fixed test time
        $this->testTime = Carbon::now();
        Carbon::setTestNow($this->testTime);
        
        // Initialize database connection and seed test data
        $this->connection = new PDO(
            getenv('TEST_DB_DSN'),
            getenv('TEST_DB_USER'),
            getenv('TEST_DB_PASS')
        );
        
        DatabaseSeeder::seedTestDatabase($this->connection);
        
        // Initialize VendorStatus model
        $this->vendorStatus = new VendorStatus();
    }

    /**
     * Clean up test environment after each test case.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clear test data
        DatabaseSeeder::clearTestData($this->connection);
        
        // Reset Carbon mock
        Carbon::setTestNow();
        
        // Clean up test environment
        TestHelper::cleanupTestEnvironment();
        
        parent::tearDown();
    }

    /**
     * Test filtering vendors by healthy status and success rate threshold.
     *
     * @return void
     */
    public function testVendorHealthyScope(): void
    {
        // Create test vendors with varying health statuses
        $healthyVendor = VendorStatus::create([
            'vendor' => self::TEST_VENDOR_ITERABLE,
            'status' => VendorStatus::VENDOR_STATUS_HEALTHY,
            'success_rate' => 0.99,
            'last_check' => $this->testTime
        ]);

        $degradedVendor = VendorStatus::create([
            'vendor' => self::TEST_VENDOR_SENDGRID,
            'status' => VendorStatus::VENDOR_STATUS_DEGRADED,
            'success_rate' => 0.85,
            'last_check' => $this->testTime
        ]);

        $unhealthyVendor = VendorStatus::create([
            'vendor' => self::TEST_VENDOR_TELNYX,
            'status' => VendorStatus::VENDOR_STATUS_UNHEALTHY,
            'success_rate' => 0.70,
            'last_check' => $this->testTime
        ]);

        // Query healthy vendors
        $healthyVendors = VendorStatus::healthy()->get();

        // Assert only healthy vendor with high success rate is returned
        $this->assertCount(1, $healthyVendors);
        $this->assertEquals(self::TEST_VENDOR_ITERABLE, $healthyVendors->first()->vendor);
        $this->assertGreaterThanOrEqual(0.95, $healthyVendors->first()->success_rate);
    }

    /**
     * Test vendor status transitions between healthy, degraded, and unhealthy states.
     *
     * @return void
     */
    public function testVendorStatusTransitions(): void
    {
        // Create initial healthy vendor
        $vendor = VendorStatus::create([
            'vendor' => self::TEST_VENDOR_ITERABLE,
            'status' => VendorStatus::VENDOR_STATUS_HEALTHY,
            'success_rate' => 0.99,
            'last_check' => $this->testTime
        ]);

        // Simulate degraded performance
        $vendor->success_rate = 0.85;
        $vendor->save();
        
        $this->assertEquals(VendorStatus::VENDOR_STATUS_DEGRADED, $vendor->fresh()->status);

        // Simulate continued failures
        $vendor->success_rate = 0.60;
        $vendor->save();
        
        $this->assertEquals(VendorStatus::VENDOR_STATUS_UNHEALTHY, $vendor->fresh()->status);

        // Simulate recovery
        $vendor->success_rate = 0.98;
        $vendor->save();
        
        $this->assertEquals(VendorStatus::VENDOR_STATUS_HEALTHY, $vendor->fresh()->status);
    }

    /**
     * Test health check interval detection and timing accuracy.
     *
     * @return void
     */
    public function testHealthCheckIntervals(): void
    {
        // Create vendor with specific last check time
        $vendor = VendorStatus::create([
            'vendor' => self::TEST_VENDOR_ITERABLE,
            'status' => VendorStatus::VENDOR_STATUS_HEALTHY,
            'success_rate' => 0.99,
            'last_check' => $this->testTime
        ]);

        // Test before interval expiration
        $this->assertFalse($vendor->needsHealthCheck());

        // Advance time past interval
        Carbon::setTestNow($this->testTime->addSeconds(self::HEALTH_CHECK_INTERVAL + 1));
        
        // Verify health check needed
        $this->assertTrue($vendor->fresh()->needsHealthCheck());

        // Perform health check
        $vendor->last_check = Carbon::now();
        $vendor->save();

        // Verify interval reset
        $this->assertFalse($vendor->fresh()->needsHealthCheck());
    }

    /**
     * Test vendor failover timing requirements.
     *
     * @return void
     */
    public function testVendorFailoverTiming(): void
    {
        // Setup primary and secondary vendors
        $primaryVendor = VendorStatus::create([
            'vendor' => self::TEST_VENDOR_ITERABLE,
            'status' => VendorStatus::VENDOR_STATUS_HEALTHY,
            'success_rate' => 0.99,
            'last_check' => $this->testTime
        ]);

        $secondaryVendor = VendorStatus::create([
            'vendor' => self::TEST_VENDOR_SENDGRID,
            'status' => VendorStatus::VENDOR_STATUS_HEALTHY,
            'success_rate' => 0.98,
            'last_check' => $this->testTime
        ]);

        // Record start time
        $startTime = microtime(true);

        // Trigger primary vendor failure
        $primaryVendor->status = VendorStatus::VENDOR_STATUS_UNHEALTHY;
        $primaryVendor->success_rate = 0.50;
        $primaryVendor->save();

        // Measure failover time
        $failoverTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Assert failover completes within threshold
        $this->assertLessThanOrEqual(
            self::FAILOVER_THRESHOLD * 1000, // Convert to milliseconds
            $failoverTime,
            "Vendor failover exceeded maximum threshold of {self::FAILOVER_THRESHOLD} seconds"
        );

        // Verify secondary vendor is now active
        $activeVendor = VendorStatus::healthy()->first();
        $this->assertEquals(self::TEST_VENDOR_SENDGRID, $activeVendor->vendor);
    }
}