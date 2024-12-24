<?php

declare(strict_types=1);

namespace App\Test\Integration\Cli;

use App\Test\Utils\TestHelper;
use App\Cli\Commands\TemplateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use InvalidArgumentException;
use RuntimeException;

/**
 * Integration test suite for Template CLI command
 * Verifies template management functionality with comprehensive coverage
 * of operations, error scenarios, and accessibility features.
 *
 * @package App\Test\Integration\Cli
 * @version 1.0.0
 */
class TemplateCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private TemplateCommand $command;
    private TestHelper $testHelper;
    private const TEST_TEMPLATE_NAME = 'test_welcome_email';

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize test helper and environment
        $this->testHelper = new TestHelper();
        $this->testHelper->setupTestEnvironment();
        $this->testHelper->setupTestDatabase();
        
        // Create command instance with test configuration
        $this->command = new TemplateCommand(
            $this->testHelper->getApiService(),
            $this->testHelper->getOutputService()
        );
        
        // Set up command tester with accessibility support
        $this->commandTester = new CommandTester($this->command);
    }

    /**
     * Clean up test environment after each test
     */
    protected function tearDown(): void
    {
        $this->testHelper->cleanupTestEnvironment();
        parent::tearDown();
    }

    /**
     * Test listing templates with various filters and formats
     */
    public function testListTemplates(): void
    {
        // Create test templates
        $this->createTestTemplates();

        // Test basic list command
        $this->commandTester->execute([
            'command' => 'template',
            '--list' => true
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString(self::TEST_TEMPLATE_NAME, $output);
        $this->assertStringContainsString('[Template list]', $output); // Accessibility label

        // Test filtering by channel
        $this->commandTester->execute([
            'command' => 'template',
            '--list' => true,
            '--type' => 'email'
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('email', $output);
        $this->assertStringNotContainsString('sms', $output);

        // Test JSON output format
        $this->commandTester->execute([
            'command' => 'template',
            '--list' => true,
            '--format' => 'json'
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertJson($output);
    }

    /**
     * Test template creation with validation
     */
    public function testCreateTemplate(): void
    {
        $templateData = [
            'name' => self::TEST_TEMPLATE_NAME,
            'channel' => 'email',
            'content' => [
                'subject' => 'Welcome to our service',
                'body' => 'Hello {{name}}, welcome aboard!'
            ],
            'active' => true
        ];

        $this->commandTester->setInputs([json_encode($templateData)]); // Simulate stdin input

        $this->commandTester->execute([
            'command' => 'template',
            '--create' => true,
            'name' => self::TEST_TEMPLATE_NAME
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('created successfully', $output);
        $this->assertStringContainsString('[Template created]', $output); // Accessibility announcement
    }

    /**
     * Test template update functionality
     */
    public function testUpdateTemplate(): void
    {
        // Create template first
        $this->testCreateTemplate();

        $updateData = [
            'content' => [
                'subject' => 'Updated welcome message',
                'body' => 'Hello {{name}}, welcome to our updated service!'
            ]
        ];

        $this->commandTester->setInputs([json_encode($updateData)]); // Simulate stdin input

        $this->commandTester->execute([
            'command' => 'template',
            '--update' => true,
            'name' => self::TEST_TEMPLATE_NAME
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('updated successfully', $output);
        $this->assertStringContainsString('[Template updated]', $output); // Accessibility announcement
    }

    /**
     * Test template deletion with confirmation
     */
    public function testDeleteTemplate(): void
    {
        // Create template first
        $this->testCreateTemplate();

        $this->commandTester->execute([
            'command' => 'template',
            '--delete' => true,
            'name' => self::TEST_TEMPLATE_NAME
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('deleted successfully', $output);
        $this->assertStringContainsString('[Template deleted]', $output); // Accessibility announcement
    }

    /**
     * Test template validation rules
     */
    public function testTemplateValidation(): void
    {
        $invalidData = [
            'name' => 'invalid@template',
            'channel' => 'invalid',
            'content' => 'invalid'
        ];

        $this->commandTester->setInputs([json_encode($invalidData)]); // Simulate stdin input

        $this->commandTester->execute([
            'command' => 'template',
            '--create' => true,
            'name' => 'invalid@template'
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('ERROR', $output);
        $this->assertStringContainsString('Invalid template', $output);
    }

    /**
     * Test concurrent template operations
     */
    public function testConcurrentOperations(): void
    {
        $promises = [];
        
        // Attempt concurrent template creations
        for ($i = 0; $i < 5; $i++) {
            $templateData = [
                'name' => self::TEST_TEMPLATE_NAME . "_$i",
                'channel' => 'email',
                'content' => [
                    'subject' => "Template $i",
                    'body' => "Content $i"
                ]
            ];

            $this->commandTester->setInputs([json_encode($templateData)]);
            
            $promises[] = async(function() use ($i) {
                $this->commandTester->execute([
                    'command' => 'template',
                    '--create' => true,
                    'name' => self::TEST_TEMPLATE_NAME . "_$i"
                ]);
            });
        }

        // Wait for all operations to complete
        await($promises);

        // Verify all templates were created
        $this->commandTester->execute([
            'command' => 'template',
            '--list' => true
        ]);

        $output = $this->commandTester->getDisplay();
        for ($i = 0; $i < 5; $i++) {
            $this->assertStringContainsString(self::TEST_TEMPLATE_NAME . "_$i", $output);
        }
    }

    /**
     * Test error handling and recovery
     */
    public function testErrorHandling(): void
    {
        // Test invalid command options
        $this->commandTester->execute([
            'command' => 'template',
            '--invalid' => true
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('ERROR', $output);

        // Test network failure simulation
        $this->testHelper->mockNetworkFailure();
        
        $this->commandTester->execute([
            'command' => 'template',
            '--list' => true
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('network error', $output);
        $this->assertStringContainsString('[Error]', $output); // Accessibility announcement
    }

    /**
     * Create test templates for list operation testing
     */
    private function createTestTemplates(): void
    {
        $channels = ['email', 'sms', 'push'];
        
        foreach ($channels as $channel) {
            $templateData = [
                'name' => self::TEST_TEMPLATE_NAME . "_$channel",
                'channel' => $channel,
                'content' => $this->getTemplateContent($channel),
                'active' => true
            ];

            $this->commandTester->setInputs([json_encode($templateData)]);
            
            $this->commandTester->execute([
                'command' => 'template',
                '--create' => true,
                'name' => self::TEST_TEMPLATE_NAME . "_$channel"
            ]);
        }
    }

    /**
     * Get channel-specific template content
     */
    private function getTemplateContent(string $channel): array
    {
        switch ($channel) {
            case 'email':
                return [
                    'subject' => 'Test Email Template',
                    'body' => 'Email content with {{variable}}'
                ];
            case 'sms':
                return [
                    'body' => 'SMS content with {{variable}}'
                ];
            case 'push':
                return [
                    'title' => 'Test Push Notification',
                    'body' => 'Push content with {{variable}}'
                ];
            default:
                throw new InvalidArgumentException("Invalid channel: $channel");
        }
    }
}