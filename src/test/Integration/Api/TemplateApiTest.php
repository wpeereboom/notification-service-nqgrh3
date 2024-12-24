<?php

declare(strict_types=1);

namespace App\Test\Integration\Api;

use App\Test\Utils\TestHelper;
use App\Services\Template\TemplateService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Assert;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Integration test suite for Template API endpoints
 * Tests template management functionality including CRUD operations,
 * validation, caching, versioning and performance requirements.
 *
 * @package App\Test\Integration\Api
 * @version 1.0.0
 */
class TemplateApiTest extends TestCase
{
    private const BASE_URL = '/api/v1/templates';
    private const PERFORMANCE_BATCH_SIZE = 100;
    private const MAX_LATENCY_MS = 30000; // 30 seconds
    private const CACHE_TTL = 3600; // 1 hour

    private TestHelper $testHelper;
    private TemplateService $templateService;
    private array $testTemplates = [];
    private array $performanceMetrics = [];
    private array $headers;

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->testHelper = new TestHelper();
        $this->templateService = $this->getTemplateService();
        
        // Set up authentication headers
        $this->headers = [
            'Authorization' => 'Bearer ' . $this->generateTestToken(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ];

        // Initialize performance metrics tracking
        $this->performanceMetrics = [
            'creation_times' => [],
            'update_times' => [],
            'retrieval_times' => [],
            'cache_hits' => 0,
            'cache_misses' => 0
        ];
    }

    /**
     * Clean up test environment after each test
     */
    protected function tearDown(): void
    {
        // Clean up test templates
        foreach ($this->testTemplates as $template) {
            try {
                $this->templateService->delete($template['id']);
            } catch (\Exception $e) {
                // Log cleanup failure but don't fail test
                error_log("Failed to cleanup template: {$e->getMessage()}");
            }
        }

        // Clear template cache
        $this->clearTemplateCache();

        // Record performance metrics
        $this->logPerformanceMetrics();

        parent::tearDown();
    }

    /**
     * Test template creation with validation
     */
    public function testTemplateCreation(): void
    {
        // Test valid email template creation
        $emailTemplate = $this->generateTestTemplate('email');
        $startTime = microtime(true);
        
        $response = $this->makeApiRequest('POST', self::BASE_URL, $emailTemplate);
        
        $this->recordOperationTime('creation', microtime(true) - $startTime);
        
        $this->assertEquals(201, $response['status']);
        $this->assertNotEmpty($response['data']['id']);
        $this->testTemplates[] = $response['data'];

        // Test valid SMS template creation
        $smsTemplate = $this->generateTestTemplate('sms');
        $response = $this->makeApiRequest('POST', self::BASE_URL, $smsTemplate);
        
        $this->assertEquals(201, $response['status']);
        $this->testTemplates[] = $response['data'];

        // Test invalid template creation
        $invalidTemplate = $this->generateInvalidTemplate();
        $response = $this->makeApiRequest('POST', self::BASE_URL, $invalidTemplate);
        
        $this->assertEquals(422, $response['status']);
        $this->assertArrayHasKey('errors', $response);
    }

    /**
     * Test template retrieval and caching
     */
    public function testTemplateRetrieval(): void
    {
        // Create test template
        $template = $this->createTestTemplate();
        
        // First retrieval - should miss cache
        $startTime = microtime(true);
        $response = $this->makeApiRequest('GET', self::BASE_URL . '/' . $template['id']);
        $this->recordOperationTime('retrieval', microtime(true) - $startTime);
        
        $this->assertEquals(200, $response['status']);
        $this->assertEquals($template['id'], $response['data']['id']);
        
        // Second retrieval - should hit cache
        $startTime = microtime(true);
        $response = $this->makeApiRequest('GET', self::BASE_URL . '/' . $template['id']);
        $this->recordOperationTime('retrieval', microtime(true) - $startTime);
        
        $this->assertEquals(200, $response['status']);
        $this->performanceMetrics['cache_hits']++;
    }

    /**
     * Test template update with version control
     */
    public function testTemplateUpdate(): void
    {
        // Create test template
        $template = $this->createTestTemplate();
        
        // Update template content
        $updateData = [
            'content' => [
                'subject' => 'Updated Subject',
                'body' => 'Updated body content'
            ]
        ];
        
        $startTime = microtime(true);
        $response = $this->makeApiRequest(
            'PUT',
            self::BASE_URL . '/' . $template['id'],
            $updateData
        );
        $this->recordOperationTime('update', microtime(true) - $startTime);
        
        $this->assertEquals(200, $response['status']);
        $this->assertEquals($template['version'] + 1, $response['data']['version']);

        // Verify cache invalidation
        $cachedTemplate = $this->templateService->find($template['id']);
        $this->assertEquals($updateData['content'], $cachedTemplate['content']);
    }

    /**
     * Test template validation rules
     */
    public function testTemplateValidation(): void
    {
        $testCases = [
            // Test empty content
            [
                'content' => '',
                'expectedStatus' => 422,
                'errorField' => 'content'
            ],
            // Test invalid email template
            [
                'content' => ['subject' => 'Test'],  // Missing body
                'type' => 'email',
                'expectedStatus' => 422,
                'errorField' => 'content'
            ],
            // Test oversized SMS template
            [
                'content' => str_repeat('a', 1601),  // Exceeds 1600 char limit
                'type' => 'sms',
                'expectedStatus' => 422,
                'errorField' => 'content'
            ]
        ];

        foreach ($testCases as $testCase) {
            $template = $this->generateTestTemplate($testCase['type'] ?? 'email');
            $template['content'] = $testCase['content'];
            
            $response = $this->makeApiRequest('POST', self::BASE_URL, $template);
            
            $this->assertEquals($testCase['expectedStatus'], $response['status']);
            $this->assertArrayHasKey($testCase['errorField'], $response['errors'] ?? []);
        }
    }

    /**
     * Test template performance requirements
     */
    public function testTemplatePerformance(): void
    {
        $templates = [];
        $startTime = microtime(true);

        // Create batch of templates
        for ($i = 0; $i < self::PERFORMANCE_BATCH_SIZE; $i++) {
            $template = $this->generateTestTemplate(
                $i % 2 === 0 ? 'email' : 'sms'
            );
            $response = $this->makeApiRequest('POST', self::BASE_URL, $template);
            $templates[] = $response['data'];
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $avgTimePerTemplate = $totalTime / self::PERFORMANCE_BATCH_SIZE;

        // Assert performance requirements
        $this->assertLessThan(
            self::MAX_LATENCY_MS,
            $avgTimePerTemplate,
            "Average template creation time exceeds maximum latency"
        );

        // Test concurrent retrieval
        $retrievalTimes = [];
        foreach ($templates as $template) {
            $startTime = microtime(true);
            $this->makeApiRequest('GET', self::BASE_URL . '/' . $template['id']);
            $retrievalTimes[] = (microtime(true) - $startTime) * 1000;
        }

        $avgRetrievalTime = array_sum($retrievalTimes) / count($retrievalTimes);
        $this->assertLessThan(
            self::MAX_LATENCY_MS / 10,  // Stricter requirement for retrieval
            $avgRetrievalTime,
            "Average template retrieval time exceeds maximum latency"
        );
    }

    /**
     * Generate test template data
     */
    private function generateTestTemplate(string $type): array
    {
        return [
            'name' => 'test_template_' . uniqid(),
            'type' => $type,
            'content' => $this->getTemplateContent($type),
            'active' => true
        ];
    }

    /**
     * Get template content based on type
     */
    private function getTemplateContent(string $type): array
    {
        switch ($type) {
            case 'email':
                return [
                    'subject' => 'Test Email Template',
                    'body' => 'This is a test email template with {{variable}}'
                ];
            case 'sms':
                return [
                    'body' => 'Test SMS template with {{code}}'
                ];
            case 'push':
                return [
                    'title' => 'Test Push Notification',
                    'body' => 'Test push message'
                ];
            default:
                throw new InvalidArgumentException("Invalid template type: {$type}");
        }
    }

    /**
     * Record operation timing
     */
    private function recordOperationTime(string $operation, float $time): void
    {
        $this->performanceMetrics["{$operation}_times"][] = $time * 1000;
    }

    /**
     * Log performance metrics
     */
    private function logPerformanceMetrics(): void
    {
        foreach ($this->performanceMetrics as $metric => $values) {
            if (is_array($values)) {
                $avg = array_sum($values) / count($values);
                error_log("Average {$metric}: {$avg}ms");
            } else {
                error_log("{$metric}: {$values}");
            }
        }
    }

    /**
     * Make API request with error handling
     */
    private function makeApiRequest(
        string $method,
        string $url,
        array $data = []
    ): array {
        try {
            $response = $this->client->request($method, $url, [
                'headers' => $this->headers,
                'json' => $data
            ]);

            return [
                'status' => $response->getStatusCode(),
                'data' => json_decode($response->getBody()->getContents(), true)
            ];
        } catch (\Exception $e) {
            return [
                'status' => $e->getCode(),
                'errors' => json_decode($e->getResponse()->getBody()->getContents(), true)
            ];
        }
    }

    /**
     * Clear template cache
     */
    private function clearTemplateCache(): void
    {
        foreach ($this->testTemplates as $template) {
            $this->templateService->clearCache($template['id']);
        }
    }

    /**
     * Generate test authentication token
     */
    private function generateTestToken(): string
    {
        // Implementation would generate a valid JWT token for testing
        return 'test_token';
    }

    /**
     * Get configured template service instance
     */
    private function getTemplateService(): TemplateService
    {
        // Implementation would return properly configured service instance
        return new TemplateService(/* dependencies */);
    }
}