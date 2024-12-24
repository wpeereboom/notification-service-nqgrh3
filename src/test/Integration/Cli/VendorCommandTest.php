<?php

declare(strict_types=1);

namespace App\Test\Integration\Cli;

use App\Test\Utils\TestHelper;
use App\Test\Utils\VendorSimulator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration test suite for vendor CLI command functionality.
 * Tests vendor status checks, configuration, health monitoring, and failover scenarios.
 *
 * @package App\Test\Integration\Cli
 * @version 1.0.0
 */
class VendorCommandTest extends TestCase
{
    private const TEST_VENDOR_PREFIX = 'test_vendor_';
    private const VENDOR_HEALTH_CHECK_INTERVAL = 30; // seconds
    private const VENDOR_FAILOVER_THRESHOLD = 2000; // milliseconds

    private TestHelper $testHelper;
    private VendorSimulator $vendorSimulator;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->testHelper = new TestHelper();
        $this->vendorSimulator = new VendorSimulator();
    }

    /**
     * Clean up test environment after each test.
     */
    protected function tearDown(): void
    {
        $this->testHelper->cleanupTestEnvironment();
        parent::tearDown();
    }

    /**
     * Test vendor status check command functionality.
     *
     * @test
     */
    public function testVendorStatusCheck(): void
    {
        // Configure test vendors
        $emailVendors = ['iterable', 'sendgrid', 'ses'];
        $smsVendors = ['telnyx', 'twilio'];

        foreach ($emailVendors as $vendor) {
            $response = $this->vendorSimulator->simulateHealthCheck(
                $vendor,
                50, // 50ms latency
                true // healthy
            );
            
            $this->assertTrue($response['isHealthy'], "Vendor $vendor should be healthy");
            $this->assertLessThanOrEqual(
                self::VENDOR_HEALTH_CHECK_INTERVAL * 1000,
                $response['latency'],
                "Health check latency for $vendor exceeds maximum"
            );
        }

        foreach ($smsVendors as $vendor) {
            $response = $this->vendorSimulator->simulateHealthCheck(
                $vendor,
                50,
                true
            );
            
            $this->assertTrue($response['isHealthy'], "Vendor $vendor should be healthy");
            $this->assertArrayHasKey('diagnostics', $response, "Vendor $vendor response missing diagnostics");
        }
    }

    /**
     * Test vendor configuration update command.
     *
     * @test
     */
    public function testVendorConfigurationUpdate(): void
    {
        // Test email vendor configuration
        $emailConfig = [
            'vendor' => 'iterable',
            'priority' => 1,
            'weight' => 70,
            'retry_attempts' => 3,
            'timeout' => 5000
        ];

        $response = $this->vendorSimulator->simulateVendorResponse(
            $emailConfig['vendor'],
            ['type' => 'config_update', 'config' => $emailConfig],
            100
        );

        $this->assertEquals('sent', $response['status'], 'Configuration update should succeed');
        $this->assertArrayHasKey('vendorResponse', $response, 'Response should contain vendor details');

        // Test SMS vendor configuration
        $smsConfig = [
            'vendor' => 'telnyx',
            'priority' => 1,
            'weight' => 60,
            'retry_attempts' => 2,
            'timeout' => 3000
        ];

        $response = $this->vendorSimulator->simulateVendorResponse(
            $smsConfig['vendor'],
            ['type' => 'config_update', 'config' => $smsConfig],
            100
        );

        $this->assertEquals('sent', $response['status'], 'Configuration update should succeed');
    }

    /**
     * Test vendor health monitoring functionality.
     *
     * @test
     */
    public function testVendorHealthMonitoring(): void
    {
        // Configure multiple vendors for testing
        $vendors = [
            ['vendor' => 'iterable', 'latency' => 50, 'shouldFail' => false],
            ['vendor' => 'sendgrid', 'latency' => 150, 'shouldFail' => false],
            ['vendor' => 'ses', 'latency' => 200, 'shouldFail' => false]
        ];

        // Test normal operation
        foreach ($vendors as $config) {
            $response = $this->vendorSimulator->simulateHealthCheck(
                $config['vendor'],
                $config['latency'],
                !$config['shouldFail']
            );

            $this->assertTrue($response['isHealthy'], "Vendor {$config['vendor']} should be healthy");
            $this->assertLessThanOrEqual(
                self::VENDOR_HEALTH_CHECK_INTERVAL * 1000,
                $response['latency'],
                "Health check interval exceeded for {$config['vendor']}"
            );
        }

        // Test failover scenario
        $failoverResults = $this->vendorSimulator->simulateFailover(
            array_merge($vendors, [
                ['vendor' => 'iterable', 'latency' => 50, 'shouldFail' => true]
            ]),
            ['type' => 'test_notification']
        );

        $this->assertTrue($failoverResults['successful'], 'Failover should succeed');
        $this->assertLessThanOrEqual(
            self::VENDOR_FAILOVER_THRESHOLD,
            $failoverResults['totalTime'],
            'Failover time exceeds maximum threshold'
        );
    }

    /**
     * Test handling of invalid vendor commands.
     *
     * @test
     */
    public function testInvalidVendorCommands(): void
    {
        // Test invalid vendor name
        $this->expectException(\InvalidArgumentException::class);
        $this->vendorSimulator->simulateVendorResponse(
            'invalid_vendor',
            ['type' => 'test'],
            50
        );

        // Test invalid configuration
        try {
            $response = $this->vendorSimulator->simulateVendorResponse(
                'iterable',
                ['type' => 'config_update', 'config' => ['invalid' => true]],
                50
            );
            $this->fail('Should throw exception for invalid configuration');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid configuration', $e->getMessage());
        }

        // Test excessive latency
        $response = $this->vendorSimulator->simulateVendorResponse(
            'sendgrid',
            ['type' => 'test'],
            5000 // Excessive latency
        );
        
        $this->assertLessThanOrEqual(
            self::VENDOR_FAILOVER_THRESHOLD,
            $response['vendorResponse']['details']['latency'],
            'Should cap latency at failover threshold'
        );
    }

    /**
     * Test vendor status metrics and reporting.
     *
     * @test
     */
    public function testVendorStatusMetrics(): void
    {
        $vendor = 'iterable';
        $response = $this->vendorSimulator->simulateHealthCheck($vendor, 50, true);

        $this->assertArrayHasKey('diagnostics', $response, 'Response should include diagnostics');
        $this->assertArrayHasKey('successRate', $response['diagnostics'], 'Diagnostics should include success rate');
        $this->assertGreaterThanOrEqual(
            0.999,
            $response['diagnostics']['successRate'],
            'Success rate should meet 99.9% requirement'
        );

        // Test throughput metrics
        $this->assertArrayHasKey('throughput', $response['diagnostics'], 'Diagnostics should include throughput');
        $this->assertGreaterThanOrEqual(
            100000,
            $response['diagnostics']['throughput'] * 60, // Convert to per minute
            'Throughput should meet minimum requirement'
        );
    }
}