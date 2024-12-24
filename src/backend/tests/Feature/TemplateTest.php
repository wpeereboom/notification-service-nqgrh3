<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Models\Template;
use App\Services\Template\TemplateService;
use App\Test\Utils\TestHelper;
use Carbon\Carbon;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Feature test suite for template management functionality
 * Ensures comprehensive test coverage for template operations
 *
 * @package App\Tests\Feature
 * @version 1.0.0
 */
class TemplateTest extends TestCase
{
    private TemplateService $templateService;
    private TestHelper $testHelper;
    private Generator $faker;
    private const CACHE_TTL = 3600;

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->faker = Factory::create();
        $this->testHelper = new TestHelper();
        $this->templateService = $this->getTemplateService();
        
        // Clean test environment
        $this->testHelper->setupTestCache();
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        $this->testHelper->cleanupTestCache();
        parent::tearDown();
    }

    /**
     * Test template creation across all channels
     */
    public function testCreateTemplate(): void
    {
        $channels = ['email', 'sms', 'push'];
        
        foreach ($channels as $channel) {
            // Generate test template data
            $templateData = $this->generateTemplateData($channel);
            
            // Create template
            $created = $this->templateService->create($templateData);
            
            // Assert creation success
            $this->assertTrue($created, "Failed to create {$channel} template");
            
            // Verify template in database
            $template = Template::findByName($templateData['name']);
            $this->assertNotNull($template, "Template not found in database");
            $this->assertEquals($channel, $template->type);
            
            // Verify template cache
            $cached = $this->templateService->find($template->id);
            $this->assertNotNull($cached, "Template not found in cache");
            $this->assertEquals($templateData['content'], $cached['content']);
        }
    }

    /**
     * Test template update functionality
     */
    public function testUpdateTemplate(): void
    {
        // Create initial template
        $templateData = $this->generateTemplateData('email');
        $this->templateService->create($templateData);
        $template = Template::findByName($templateData['name']);
        
        // Update template
        $updateData = [
            'content' => [
                'subject' => 'Updated Subject',
                'body' => 'Updated body content with {{variable}}'
            ],
            'active' => true
        ];
        
        $updated = $this->templateService->update($template->id, $updateData);
        $this->assertTrue($updated, "Failed to update template");
        
        // Verify update in database
        $refreshed = Template::find($template->id);
        $this->assertEquals($updateData['content'], $refreshed->content);
        $this->assertTrue($refreshed->active);
        $this->assertEquals($template->version + 1, $refreshed->version);
        
        // Verify cache invalidation
        $cached = $this->templateService->find($template->id);
        $this->assertEquals($updateData['content'], $cached['content']);
    }

    /**
     * Test template deletion functionality
     */
    public function testDeleteTemplate(): void
    {
        // Create template for deletion
        $templateData = $this->generateTemplateData('sms');
        $this->templateService->create($templateData);
        $template = Template::findByName($templateData['name']);
        
        // Delete template
        $deleted = $this->templateService->delete($template->id);
        $this->assertTrue($deleted, "Failed to delete template");
        
        // Verify deletion
        $this->assertNull(Template::find($template->id));
        $this->assertNull($this->templateService->find($template->id));
        
        // Verify soft delete
        $this->assertNotNull(Template::withTrashed()->find($template->id));
    }

    /**
     * Test template rendering functionality
     */
    public function testRenderTemplate(): void
    {
        $channels = [
            'email' => [
                'content' => [
                    'subject' => 'Welcome {{name}}',
                    'body' => 'Hello {{name}}, welcome to {{service}}'
                ],
                'context' => [
                    'name' => 'John Doe',
                    'service' => 'Our Platform'
                ]
            ],
            'sms' => [
                'content' => 'Your verification code is {{code}}',
                'context' => [
                    'code' => '123456'
                ]
            ],
            'push' => [
                'content' => [
                    'title' => 'New message from {{sender}}',
                    'body' => '{{message}}'
                ],
                'context' => [
                    'sender' => 'Support',
                    'message' => 'Your ticket has been updated'
                ]
            ]
        ];

        foreach ($channels as $channel => $data) {
            // Create template
            $templateData = $this->generateTemplateData($channel, $data['content']);
            $this->templateService->create($templateData);
            $template = Template::findByName($templateData['name']);
            
            // Render template
            $rendered = $this->templateService->render($template->id, $data['context']);
            
            // Verify rendering
            foreach ($data['context'] as $key => $value) {
                $this->assertStringContainsString($value, $rendered);
            }
        }
    }

    /**
     * Test template caching functionality
     */
    public function testTemplateCaching(): void
    {
        // Create template
        $templateData = $this->generateTemplateData('email');
        $this->templateService->create($templateData);
        $template = Template::findByName($templateData['name']);
        
        // Verify initial cache
        $startTime = microtime(true);
        $cached = $this->templateService->find($template->id);
        $firstFetch = microtime(true) - $startTime;
        
        // Verify cache hit
        $startTime = microtime(true);
        $cachedAgain = $this->templateService->find($template->id);
        $secondFetch = microtime(true) - $startTime;
        
        // Assert cache performance
        $this->assertLessThan($firstFetch, $secondFetch, "Cache hit not faster than initial fetch");
        $this->assertEquals($cached, $cachedAgain, "Cache inconsistency detected");
        
        // Test cache invalidation
        $template->update(['content' => ['subject' => 'Updated']]);
        $updatedCache = $this->templateService->find($template->id);
        $this->assertNotEquals($cached['content'], $updatedCache['content']);
    }

    /**
     * Test template validation functionality
     */
    public function testTemplateValidation(): void
    {
        $invalidTemplates = [
            'email' => [
                // Missing subject
                'content' => ['body' => 'Test body']
            ],
            'sms' => [
                // Exceeds length limit
                'content' => str_repeat('x', 1601)
            ],
            'push' => [
                // Missing title
                'content' => ['body' => 'Test notification']
            ]
        ];

        foreach ($invalidTemplates as $channel => $data) {
            $templateData = $this->generateTemplateData($channel, $data['content']);
            
            // Attempt to create invalid template
            try {
                $this->templateService->create($templateData);
                $this->fail("Should have thrown validation exception for {$channel}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid template', $e->getMessage());
            }
        }
    }

    /**
     * Generate test template data
     */
    private function generateTemplateData(string $channel, ?array $content = null): array
    {
        return [
            'name' => "test_template_{$channel}_" . $this->faker->uuid,
            'type' => $channel,
            'content' => $content ?? $this->getDefaultContent($channel),
            'active' => true,
            'metadata' => [
                'created_by' => 'test_suite',
                'environment' => 'testing'
            ]
        ];
    }

    /**
     * Get default content for channel
     */
    private function getDefaultContent(string $channel): array|string
    {
        return match($channel) {
            'email' => [
                'subject' => $this->faker->sentence,
                'body' => $this->faker->paragraph
            ],
            'sms' => $this->faker->text(160),
            'push' => [
                'title' => $this->faker->sentence(3),
                'body' => $this->faker->sentence
            ],
            default => throw new RuntimeException("Invalid channel: {$channel}")
        };
    }

    /**
     * Get configured template service instance
     */
    private function getTemplateService(): TemplateService
    {
        // Initialize service with test configuration
        return new TemplateService(
            new Template(),
            $this->testHelper->getTestCacheService(),
            $this->testHelper->getTestTwigEnvironment(),
            $this->testHelper->getTestLogger()
        );
    }
}