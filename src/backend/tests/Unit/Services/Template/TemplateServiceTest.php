<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Template;

use App\Models\Template;
use App\Services\Cache\RedisCacheService;
use App\Services\Template\TemplateService;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Twig\Environment as Twig;

/**
 * Comprehensive test suite for TemplateService
 * 
 * @covers \App\Services\Template\TemplateService
 * @version 1.0.0
 */
class TemplateServiceTest extends TestCase
{
    private TemplateService $templateService;
    private MockObject $templateMock;
    private MockObject $cacheMock;
    private MockObject $twigMock;
    private MockObject $loggerMock;

    /**
     * Test template data fixtures
     */
    private array $testTemplateData = [
        'id' => 'test-template-123',
        'name' => 'Welcome Email',
        'type' => 'email',
        'content' => [
            'subject' => 'Welcome to {{company}}',
            'body' => 'Hello {{name}}, welcome to our service!'
        ],
        'active' => true,
        'version' => 1
    ];

    /**
     * Set up test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects
        $this->templateMock = $this->createMock(Template::class);
        $this->cacheMock = $this->createMock(RedisCacheService::class);
        $this->twigMock = $this->createMock(Twig::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        // Initialize service with mocks
        $this->templateService = new TemplateService(
            $this->templateMock,
            $this->cacheMock,
            $this->twigMock,
            $this->loggerMock
        );
    }

    /**
     * @test
     * Test successful template creation
     */
    public function testCreateTemplateSuccess(): void
    {
        // Configure mocks
        $this->twigMock->expects($this->once())
            ->method('createTemplate')
            ->willReturn($this->createMock(\Twig\TemplateWrapper::class));

        $this->templateMock->expects($this->once())
            ->method('create')
            ->with($this->testTemplateData)
            ->willReturn($this->templateMock);

        $this->cacheMock->expects($this->once())
            ->method('set')
            ->willReturn(true);

        // Execute test
        $result = $this->templateService->create($this->testTemplateData);

        // Assert results
        $this->assertTrue($result);
    }

    /**
     * @test
     * Test template creation with invalid data
     */
    public function testCreateTemplateInvalidData(): void
    {
        $invalidData = ['name' => 'Invalid Template'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid template content');

        $this->templateService->create($invalidData);
    }

    /**
     * @test
     * Test successful template update with version control
     */
    public function testUpdateTemplateWithVersionControl(): void
    {
        $updateData = [
            'content' => [
                'subject' => 'Updated Welcome to {{company}}',
                'body' => 'Updated welcome message'
            ],
            'version' => 2
        ];

        // Configure mocks
        $this->templateMock->expects($this->once())
            ->method('findOrFail')
            ->with($this->testTemplateData['id'])
            ->willReturn($this->templateMock);

        $this->templateMock->expects($this->once())
            ->method('update')
            ->with($updateData)
            ->willReturn(true);

        $this->cacheMock->expects($this->once())
            ->method('delete')
            ->with('template:' . $this->testTemplateData['id']);

        // Execute test
        $result = $this->templateService->update($this->testTemplateData['id'], $updateData);

        // Assert results
        $this->assertTrue($result);
    }

    /**
     * @test
     * Test template version conflict detection
     */
    public function testTemplateVersionConflict(): void
    {
        $conflictData = ['version' => 1, 'content' => 'Conflicting content'];

        $this->templateMock->expects($this->once())
            ->method('findOrFail')
            ->willReturn($this->templateMock);

        $this->templateMock->method('getAttribute')
            ->with('version')
            ->willReturn(2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Version conflict detected');

        $this->templateService->update($this->testTemplateData['id'], $conflictData);
    }

    /**
     * @test
     * Test successful template rendering
     */
    public function testRenderTemplateSuccess(): void
    {
        $context = ['company' => 'Test Corp', 'name' => 'John'];
        $expectedOutput = 'Hello John, welcome to Test Corp!';

        // Configure mocks
        $this->cacheMock->expects($this->once())
            ->method('get')
            ->with('template:' . $this->testTemplateData['id'])
            ->willReturn($this->testTemplateData);

        $this->twigMock->expects($this->once())
            ->method('render')
            ->willReturn($expectedOutput);

        // Execute test
        $result = $this->templateService->render($this->testTemplateData['id'], $context);

        // Assert results
        $this->assertEquals($expectedOutput, $result);
    }

    /**
     * @test
     * Test template cache management
     */
    public function testTemplateCacheManagement(): void
    {
        // Configure cache miss then hit
        $this->cacheMock->expects($this->exactly(2))
            ->method('get')
            ->with('template:' . $this->testTemplateData['id'])
            ->willReturnOnConsecutiveCalls(null, $this->testTemplateData);

        $this->templateMock->expects($this->once())
            ->method('find')
            ->with($this->testTemplateData['id'])
            ->willReturn($this->templateMock);

        $this->templateMock->expects($this->once())
            ->method('toArray')
            ->willReturn($this->testTemplateData);

        // First call - cache miss
        $result1 = $this->templateService->find($this->testTemplateData['id']);
        // Second call - cache hit
        $result2 = $this->templateService->find($this->testTemplateData['id']);

        $this->assertEquals($this->testTemplateData, $result1);
        $this->assertEquals($this->testTemplateData, $result2);
    }

    /**
     * @test
     * Test template deletion with cache cleanup
     */
    public function testDeleteTemplateWithCacheCleanup(): void
    {
        // Configure mocks
        $this->templateMock->expects($this->once())
            ->method('findOrFail')
            ->with($this->testTemplateData['id'])
            ->willReturn($this->templateMock);

        $this->templateMock->expects($this->once())
            ->method('delete')
            ->willReturn(true);

        $this->cacheMock->expects($this->once())
            ->method('delete')
            ->with('template:' . $this->testTemplateData['id']);

        // Execute test
        $result = $this->templateService->delete($this->testTemplateData['id']);

        // Assert results
        $this->assertTrue($result);
    }

    /**
     * @test
     * Test template content validation
     */
    public function testTemplateContentValidation(): void
    {
        // Valid email template
        $validContent = json_encode([
            'subject' => 'Valid Subject',
            'body' => 'Valid Body'
        ]);

        // Invalid template
        $invalidContent = 'Invalid {json';

        // Configure mock
        $this->twigMock->expects($this->once())
            ->method('createTemplate')
            ->with($validContent)
            ->willReturn($this->createMock(\Twig\TemplateWrapper::class));

        // Test valid content
        $this->assertTrue($this->templateService->validate($validContent));

        // Test invalid content
        $this->assertFalse($this->templateService->validate($invalidContent));
    }

    /**
     * @test
     * Test template context validation
     */
    public function testTemplateContextValidation(): void
    {
        $invalidContext = ['invalid' => 'data'];

        $this->cacheMock->expects($this->once())
            ->method('get')
            ->willReturn([
                'type' => 'email',
                'content' => 'Test content'
            ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: subject');

        $this->templateService->render($this->testTemplateData['id'], $invalidContext);
    }

    /**
     * @test
     * Test cache failure handling
     */
    public function testCacheFailureHandling(): void
    {
        // Configure cache failure
        $this->cacheMock->expects($this->once())
            ->method('get')
            ->willThrowException(new RuntimeException('Cache connection failed'));

        $this->templateMock->expects($this->once())
            ->method('find')
            ->willReturn($this->templateMock);

        $this->templateMock->expects($this->once())
            ->method('toArray')
            ->willReturn($this->testTemplateData);

        // Execute test
        $result = $this->templateService->find($this->testTemplateData['id']);

        // Assert fallback to database
        $this->assertEquals($this->testTemplateData, $result);
    }
}