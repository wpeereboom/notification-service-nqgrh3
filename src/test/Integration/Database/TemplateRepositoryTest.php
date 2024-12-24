<?php

declare(strict_types=1);

namespace App\Test\Integration\Database;

use App\Models\Template;
use App\Test\Utils\TestHelper;
use App\Test\Utils\DatabaseSeeder;
use PHPUnit\Framework\TestCase;
use PDO;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Integration test suite for Template repository operations.
 * Tests comprehensive template management functionality including:
 * - CRUD operations
 * - Caching behavior
 * - Validation rules
 * - Concurrent operations
 * - Performance requirements
 *
 * @package App\Test\Integration\Database
 * @version 1.0.0
 */
class TemplateRepositoryTest extends TestCase
{
    private const CACHE_TTL = 3600;
    private const TEST_DB_DSN = 'mysql:host=localhost;dbname=notification_test';
    private const TEST_DB_USER = 'test_user';
    private const TEST_DB_PASS = 'test_password';

    private PDO $connection;
    private TestHelper $testHelper;
    private DatabaseSeeder $seeder;

    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize database connection
        $this->connection = new PDO(
            self::TEST_DB_DSN,
            self::TEST_DB_USER,
            self::TEST_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Initialize test utilities
        $this->testHelper = new TestHelper();
        $this->seeder = new DatabaseSeeder();

        // Clear existing test data
        $this->seeder->clearTestData($this->connection);

        // Clear template cache
        $this->clearTemplateCache();
    }

    /**
     * Clean up test environment after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test data
        $this->seeder->clearTestData($this->connection);
        
        // Clear cache
        $this->clearTemplateCache();
        
        // Close database connection
        $this->connection = null;
        
        parent::tearDown();
    }

    /**
     * Test creating a new template with validation.
     *
     * @return void
     */
    public function testCreateTemplate(): void
    {
        // Generate test template data
        $templateData = [
            'name' => 'test_welcome_email',
            'type' => 'email',
            'content' => [
                'subject' => 'Welcome to our service',
                'body' => 'Hello {{name}}, welcome to our platform!'
            ],
            'active' => true
        ];

        // Create template
        $template = Template::create($templateData);

        // Assert template was created
        $this->assertNotNull($template);
        $this->assertInstanceOf(Template::class, $template);
        $this->assertEquals($templateData['name'], $template->name);
        $this->assertEquals($templateData['type'], $template->type);
        $this->assertEquals($templateData['content'], $template->content);
        $this->assertTrue($template->active);
        $this->assertEquals(1, $template->version);

        // Verify template exists in database
        $dbTemplate = Template::findByName($templateData['name']);
        $this->assertNotNull($dbTemplate);
        $this->assertEquals($template->id, $dbTemplate->id);

        // Verify template is cached
        $cachedTemplate = $this->getTemplateFromCache($template->id);
        $this->assertNotNull($cachedTemplate);
        $this->assertEquals($template->toArray(), $cachedTemplate->toArray());
    }

    /**
     * Test updating an existing template with validation.
     *
     * @return void
     */
    public function testUpdateTemplate(): void
    {
        // Create initial template
        $template = Template::create([
            'name' => 'test_notification',
            'type' => 'email',
            'content' => [
                'subject' => 'Original subject',
                'body' => 'Original content'
            ],
            'active' => true
        ]);

        // Cache the template
        $this->cacheTemplate($template);

        // Update template data
        $updateData = [
            'content' => [
                'subject' => 'Updated subject',
                'body' => 'Updated content with {{variable}}'
            ]
        ];

        // Perform update
        $updated = $template->update($updateData);

        // Assert update was successful
        $this->assertTrue($updated);
        $this->assertEquals($updateData['content'], $template->content);
        $this->assertEquals(2, $template->version);

        // Verify cache was invalidated
        $cachedTemplate = $this->getTemplateFromCache($template->id);
        $this->assertNull($cachedTemplate);

        // Verify database was updated
        $dbTemplate = Template::findByName($template->name);
        $this->assertEquals($updateData['content'], $dbTemplate->content);
        $this->assertEquals(2, $dbTemplate->version);
    }

    /**
     * Test deleting a template and cache cleanup.
     *
     * @return void
     */
    public function testDeleteTemplate(): void
    {
        // Create test template
        $template = Template::create([
            'name' => 'test_delete_template',
            'type' => 'sms',
            'content' => 'Test SMS template content',
            'active' => true
        ]);

        // Cache the template
        $this->cacheTemplate($template);

        // Delete template
        $template->delete();

        // Verify template was deleted from database
        $dbTemplate = Template::findByName($template->name);
        $this->assertNull($dbTemplate);

        // Verify cache was cleared
        $cachedTemplate = $this->getTemplateFromCache($template->id);
        $this->assertNull($cachedTemplate);
    }

    /**
     * Test finding a template by name with caching.
     *
     * @return void
     */
    public function testFindTemplateByName(): void
    {
        // Create test template
        $templateName = 'test_find_template';
        $template = Template::create([
            'name' => $templateName,
            'type' => 'push',
            'content' => [
                'title' => 'Push notification',
                'body' => 'Test push content'
            ],
            'active' => true
        ]);

        // Clear any existing cache
        $this->clearTemplateCache();

        // First lookup should hit database
        $startTime = microtime(true);
        $foundTemplate = Template::findByName($templateName);
        $firstLookupTime = microtime(true) - $startTime;

        // Verify template was found
        $this->assertNotNull($foundTemplate);
        $this->assertEquals($template->id, $foundTemplate->id);

        // Second lookup should hit cache
        $startTime = microtime(true);
        $cachedTemplate = Template::findByName($templateName);
        $cachedLookupTime = microtime(true) - $startTime;

        // Verify cached lookup was faster
        $this->assertLessThan($firstLookupTime, $cachedLookupTime);
        $this->assertEquals($template->toArray(), $cachedTemplate->toArray());
    }

    /**
     * Test scope for retrieving only active templates.
     *
     * @return void
     */
    public function testActiveTemplatesScope(): void
    {
        // Create mix of active and inactive templates
        $activeTemplate1 = Template::create([
            'name' => 'active_template_1',
            'type' => 'email',
            'content' => ['subject' => 'Test 1', 'body' => 'Content 1'],
            'active' => true
        ]);

        $activeTemplate2 = Template::create([
            'name' => 'active_template_2',
            'type' => 'sms',
            'content' => 'Content 2',
            'active' => true
        ]);

        $inactiveTemplate = Template::create([
            'name' => 'inactive_template',
            'type' => 'email',
            'content' => ['subject' => 'Test 3', 'body' => 'Content 3'],
            'active' => false
        ]);

        // Query active templates
        $activeTemplates = Template::active()->get();

        // Verify only active templates are returned
        $this->assertCount(2, $activeTemplates);
        $this->assertTrue($activeTemplates->contains('id', $activeTemplate1->id));
        $this->assertTrue($activeTemplates->contains('id', $activeTemplate2->id));
        $this->assertFalse($activeTemplates->contains('id', $inactiveTemplate->id));

        // Test channel-specific active scope
        $activeEmailTemplates = Template::active('email')->get();
        $this->assertCount(1, $activeEmailTemplates);
        $this->assertEquals($activeTemplate1->id, $activeEmailTemplates->first()->id);
    }

    /**
     * Clear template cache.
     *
     * @return void
     */
    private function clearTemplateCache(): void
    {
        // Implementation would use your actual caching system
        // For example: Redis::flushdb() or Cache::tags('templates')->flush()
    }

    /**
     * Cache a template.
     *
     * @param Template $template
     * @return void
     */
    private function cacheTemplate(Template $template): void
    {
        // Implementation would use your actual caching system
        // For example: Cache::tags('templates')->put($template->id, $template, self::CACHE_TTL)
    }

    /**
     * Get template from cache.
     *
     * @param string $id
     * @return Template|null
     */
    private function getTemplateFromCache(string $id): ?Template
    {
        // Implementation would use your actual caching system
        // For example: return Cache::tags('templates')->get($id)
        return null;
    }
}