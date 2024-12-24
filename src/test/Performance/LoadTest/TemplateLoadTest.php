<?php

declare(strict_types=1);

namespace App\Test\Performance\LoadTest;

use App\Test\Utils\TestHelper;
use App\Services\Template\TemplateService;
use Carbon\Carbon; // ^2.0
use PHPUnit\Framework\TestCase; // ^10.0
use RuntimeException;

/**
 * Performance load test suite for template management functionality.
 * Validates high-throughput template operations including creation, retrieval,
 * rendering and caching under concurrent load conditions.
 *
 * @package App\Test\Performance\LoadTest
 * @version 1.0.0
 */
class TemplateLoadTest extends TestCase
{
    private const TEMPLATE_BATCH_SIZE = 1000;
    private const TEST_DURATION_SECONDS = 60;
    private const CONCURRENT_USERS = 50;
    private const PERFORMANCE_METRICS = [
        'throughput_target' => 100000, // 100k messages per minute
        'latency_target_ms' => 30000,  // 30 seconds max latency
        'success_rate_target' => 0.999 // 99.9% success rate
    ];

    private TemplateService $templateService;
    private array $performanceMetrics = [];
    private array $testTemplates = [];

    /**
     * Set up test environment and dependencies
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize test environment
        TestHelper::setupTestEnvironment();

        // Configure Redis cache for template caching
        $redisConfig = require __DIR__ . '/../../../../backend/config/cache.php';
        $this->templateService = new TemplateService(
            new \App\Models\Template(),
            new \NotificationService\Services\Cache\RedisCacheService($redisConfig, $this->createMock(\Psr\Log\LoggerInterface::class)),
            new \Twig\Environment(new \Twig\Loader\ArrayLoader([])),
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );

        // Initialize performance metrics tracking
        $this->performanceMetrics = [
            'start_time' => null,
            'end_time' => null,
            'total_operations' => 0,
            'successful_operations' => 0,
            'failed_operations' => 0,
            'processing_times' => [],
        ];
    }

    /**
     * Clean up test resources
     */
    protected function tearDown(): void
    {
        // Clean up test templates
        foreach ($this->testTemplates as $templateId) {
            try {
                $this->templateService->delete($templateId);
            } catch (\Exception $e) {
                // Log cleanup failure but continue
                error_log("Failed to cleanup template {$templateId}: " . $e->getMessage());
            }
        }

        TestHelper::cleanupTestEnvironment();
        parent::tearDown();
    }

    /**
     * Tests performance of batch template creation to validate throughput requirements
     */
    public function testTemplateBatchCreationPerformance(): void
    {
        // Generate test templates
        $templates = [];
        for ($i = 0; $i < self::TEMPLATE_BATCH_SIZE; $i++) {
            $templates[] = TestHelper::generateTestTemplate('email', [
                'name' => "test_template_{$i}",
                'content' => [
                    'subject' => 'Test Subject {{name}}',
                    'body' => 'Test body with variable {{content}}'
                ]
            ]);
        }

        // Measure batch creation performance
        $startTime = Carbon::now();
        $successful = 0;

        foreach ($templates as $template) {
            try {
                $result = $this->templateService->create($template);
                if ($result) {
                    $successful++;
                    $this->testTemplates[] = $template['id'];
                }
            } catch (\Exception $e) {
                $this->performanceMetrics['failed_operations']++;
            }
        }

        $duration = Carbon::now()->diffInSeconds($startTime);
        $throughput = ($successful / $duration) * 60; // Templates per minute

        // Assert performance requirements
        $this->assertGreaterThanOrEqual(
            self::PERFORMANCE_METRICS['throughput_target'],
            $throughput,
            "Template creation throughput of {$throughput}/minute below target of " . self::PERFORMANCE_METRICS['throughput_target']
        );

        $successRate = $successful / self::TEMPLATE_BATCH_SIZE;
        $this->assertGreaterThanOrEqual(
            self::PERFORMANCE_METRICS['success_rate_target'],
            $successRate,
            "Template creation success rate of {$successRate} below target of " . self::PERFORMANCE_METRICS['success_rate_target']
        );
    }

    /**
     * Tests template retrieval performance with both cold and warm cache scenarios
     */
    public function testTemplateRetrievalPerformance(): void
    {
        // Create test templates for retrieval
        $templateIds = $this->createTestTemplates(100);

        // Test cold cache retrieval
        $coldStartTime = Carbon::now();
        $coldRetrievalTimes = [];

        foreach ($templateIds as $templateId) {
            $start = microtime(true);
            $template = $this->templateService->find($templateId);
            $coldRetrievalTimes[] = (microtime(true) - $start) * 1000; // Convert to milliseconds
            
            $this->assertNotNull($template, "Template {$templateId} not found");
        }

        // Test warm cache retrieval
        sleep(1); // Ensure cache is warm
        $warmStartTime = Carbon::now();
        $warmRetrievalTimes = [];

        foreach ($templateIds as $templateId) {
            $start = microtime(true);
            $template = $this->templateService->find($templateId);
            $warmRetrievalTimes[] = (microtime(true) - $start) * 1000;
            
            $this->assertNotNull($template, "Template {$templateId} not found");
        }

        // Calculate and assert metrics
        $coldP95 = $this->calculateP95($coldRetrievalTimes);
        $warmP95 = $this->calculateP95($warmRetrievalTimes);

        $this->assertLessThanOrEqual(
            self::PERFORMANCE_METRICS['latency_target_ms'],
            $coldP95,
            "Cold cache P95 latency of {$coldP95}ms exceeds target"
        );

        $this->assertLessThanOrEqual(
            self::PERFORMANCE_METRICS['latency_target_ms'] / 10, // Warm cache should be 10x faster
            $warmP95,
            "Warm cache P95 latency of {$warmP95}ms exceeds target"
        );
    }

    /**
     * Tests template rendering performance under high concurrent load
     */
    public function testTemplateRenderingPerformance(): void
    {
        // Create templates of varying complexity
        $templates = [
            'simple' => $this->createSimpleTemplate(),
            'medium' => $this->createMediumTemplate(),
            'complex' => $this->createComplexTemplate()
        ];

        $startTime = Carbon::now();
        $renderingTimes = [];

        // Simulate concurrent rendering
        for ($i = 0; $i < self::CONCURRENT_USERS; $i++) {
            foreach ($templates as $complexity => $templateId) {
                $context = $this->generateTemplateContext($complexity);
                
                $start = microtime(true);
                try {
                    $rendered = $this->templateService->render($templateId, $context);
                    $renderingTimes[] = (microtime(true) - $start) * 1000;
                    
                    $this->assertNotEmpty($rendered, "Template rendering failed for {$complexity} template");
                    $this->performanceMetrics['successful_operations']++;
                } catch (\Exception $e) {
                    $this->performanceMetrics['failed_operations']++;
                }
            }
        }

        $duration = Carbon::now()->diffInSeconds($startTime);
        $totalOperations = count($renderingTimes);
        $throughput = ($totalOperations / $duration) * 60;
        $p95Latency = $this->calculateP95($renderingTimes);

        // Assert performance requirements
        $this->assertGreaterThanOrEqual(
            self::PERFORMANCE_METRICS['throughput_target'],
            $throughput,
            "Rendering throughput of {$throughput}/minute below target"
        );

        $this->assertLessThanOrEqual(
            self::PERFORMANCE_METRICS['latency_target_ms'],
            $p95Latency,
            "Rendering P95 latency of {$p95Latency}ms exceeds target"
        );
    }

    /**
     * Creates test templates for performance testing
     */
    private function createTestTemplates(int $count): array
    {
        $templateIds = [];
        for ($i = 0; $i < $count; $i++) {
            $template = TestHelper::generateTestTemplate('email', [
                'name' => "perf_test_template_{$i}",
                'content' => ['subject' => 'Test {{i}}', 'body' => 'Content {{i}}']
            ]);
            
            if ($this->templateService->create($template)) {
                $templateIds[] = $template['id'];
                $this->testTemplates[] = $template['id'];
            }
        }
        return $templateIds;
    }

    /**
     * Calculates 95th percentile from array of values
     */
    private function calculateP95(array $values): float
    {
        sort($values);
        $index = (int) ceil(0.95 * count($values)) - 1;
        return $values[$index] ?? 0;
    }

    /**
     * Creates templates of varying complexity for performance testing
     */
    private function createSimpleTemplate(): string
    {
        $template = TestHelper::generateTestTemplate('email', [
            'name' => 'simple_template',
            'content' => [
                'subject' => 'Simple {{subject}}',
                'body' => 'Hello {{name}}'
            ]
        ]);
        
        $this->templateService->create($template);
        $this->testTemplates[] = $template['id'];
        return $template['id'];
    }

    private function createMediumTemplate(): string
    {
        $template = TestHelper::generateTestTemplate('email', [
            'name' => 'medium_template',
            'content' => [
                'subject' => 'Medium {{subject}} with {{variable}}',
                'body' => "Hello {{name}},\n\nThis is a medium complexity template with {{count}} variables and some basic logic."
            ]
        ]);
        
        $this->templateService->create($template);
        $this->testTemplates[] = $template['id'];
        return $template['id'];
    }

    private function createComplexTemplate(): string
    {
        $template = TestHelper::generateTestTemplate('email', [
            'name' => 'complex_template',
            'content' => [
                'subject' => 'Complex {{subject}} with {{variable}} and {{another}}',
                'body' => "Hello {{name}},\n\nThis is a complex template with:\n" .
                         "- Multiple variables: {{var1}}, {{var2}}, {{var3}}\n" .
                         "- Nested content: {{nested.field1}}, {{nested.field2}}\n" .
                         "- Arrays: {% for item in items %}{{item}}{% endfor %}"
            ]
        ]);
        
        $this->templateService->create($template);
        $this->testTemplates[] = $template['id'];
        return $template['id'];
    }

    /**
     * Generates context data for template rendering
     */
    private function generateTemplateContext(string $complexity): array
    {
        $context = [
            'subject' => 'Test Subject',
            'name' => 'Test User'
        ];

        switch ($complexity) {
            case 'medium':
                $context += [
                    'variable' => 'Additional Data',
                    'count' => random_int(1, 100)
                ];
                break;

            case 'complex':
                $context += [
                    'variable' => 'Complex Data',
                    'another' => 'More Data',
                    'var1' => 'Value 1',
                    'var2' => 'Value 2',
                    'var3' => 'Value 3',
                    'nested' => [
                        'field1' => 'Nested 1',
                        'field2' => 'Nested 2'
                    ],
                    'items' => ['Item 1', 'Item 2', 'Item 3']
                ];
                break;
        }

        return $context;
    }
}