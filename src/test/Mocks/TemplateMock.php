<?php

declare(strict_types=1);

namespace App\Test\Mocks;

use App\Contracts\TemplateInterface;
use JsonSerializable;
use RuntimeException;
use InvalidArgumentException;

/**
 * Mock implementation of TemplateInterface for testing template-related functionality
 * with comprehensive tracking and assertion capabilities.
 *
 * @package App\Test\Mocks
 */
class TemplateMock implements TemplateInterface
{
    /** @var array<string, array> Storage for mock templates */
    private array $templates = [];

    /** @var array<string, array> Tracks template render calls with contexts */
    private array $renderedTemplates = [];

    /** @var array<string, bool> Configurable validation results */
    private array $validationResults = [];

    /** @var array<string, array> Tracks all method calls for assertions */
    private array $methodCalls = [];

    /**
     * Creates a new template with tracking for test assertions.
     *
     * @param array $data Template creation data
     * @return bool True if creation successful
     * @throws InvalidArgumentException If required fields are missing
     */
    public function create(array $data): bool
    {
        $this->trackMethodCall('create', ['data' => $data]);

        // Validate required fields
        $requiredFields = ['name', 'channel', 'content', 'metadata', 'active'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Validate channel type
        if (!in_array($data['channel'], ['email', 'sms', 'push'])) {
            throw new InvalidArgumentException('Invalid channel type');
        }

        $id = $this->generateUuid();
        $this->templates[$id] = array_merge($data, ['id' => $id]);

        return true;
    }

    /**
     * Updates an existing template with tracking.
     *
     * @param string $id Template ID
     * @param array $data Update data
     * @return bool True if update successful
     * @throws RuntimeException If template not found
     */
    public function update(string $id, array $data): bool
    {
        $this->trackMethodCall('update', ['id' => $id, 'data' => $data]);

        if (!isset($this->templates[$id])) {
            throw new RuntimeException('Template not found');
        }

        $this->templates[$id] = array_merge($this->templates[$id], $data);
        return true;
    }

    /**
     * Deletes a template with tracking.
     *
     * @param string $id Template ID
     * @return bool True if deletion successful
     * @throws RuntimeException If template not found
     */
    public function delete(string $id): bool
    {
        $this->trackMethodCall('delete', ['id' => $id]);

        if (!isset($this->templates[$id])) {
            throw new RuntimeException('Template not found');
        }

        unset($this->templates[$id]);
        return true;
    }

    /**
     * Finds a template by ID with tracking.
     *
     * @param string $id Template ID
     * @return array|null Template data or null if not found
     */
    public function find(string $id): ?array
    {
        $this->trackMethodCall('find', ['id' => $id]);
        return $this->templates[$id] ?? null;
    }

    /**
     * Finds a template by name with tracking.
     *
     * @param string $name Template name
     * @return array|null Template data or null if not found
     */
    public function findByName(string $name): ?array
    {
        $this->trackMethodCall('findByName', ['name' => $name]);

        foreach ($this->templates as $template) {
            if ($template['name'] === $name) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Mocks template rendering with context tracking.
     *
     * @param string $id Template ID
     * @param array $context Rendering context
     * @return string Mock rendered content
     * @throws RuntimeException If template not found
     */
    public function render(string $id, array $context): string
    {
        $this->trackMethodCall('render', ['id' => $id, 'context' => $context]);

        if (!isset($this->templates[$id])) {
            throw new RuntimeException('Template not found');
        }

        $template = $this->templates[$id];
        $this->renderedTemplates[$id] = [
            'template' => $template,
            'context' => $context,
            'timestamp' => time()
        ];

        // Return mock rendered content based on channel
        return match($template['channel']) {
            'email' => $this->mockEmailRender($template, $context),
            'sms' => $this->mockSmsRender($template, $context),
            'push' => $this->mockPushRender($template, $context),
            default => throw new RuntimeException('Invalid channel type')
        };
    }

    /**
     * Validates template content with configurable results.
     *
     * @param string $content Template content
     * @return bool Configured validation result
     */
    public function validate(string $content): bool
    {
        $this->trackMethodCall('validate', ['content' => $content]);
        return $this->validationResults[$content] ?? true;
    }

    /**
     * Configures validation behavior for testing.
     *
     * @param string $content Template content
     * @param bool $result Desired validation result
     */
    public function setValidationResult(string $content, bool $result): void
    {
        $this->validationResults[$content] = $result;
    }

    /**
     * Retrieves method call history for assertions.
     *
     * @param string $methodName Method name to retrieve calls for
     * @return array Method call history
     */
    public function getMethodCalls(string $methodName): array
    {
        return array_filter(
            $this->methodCalls,
            fn($call) => $call['method'] === $methodName
        );
    }

    /**
     * Implements JsonSerializable interface.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->templates;
    }

    /**
     * Generates a mock UUID for template IDs.
     *
     * @return string
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Tracks method calls for assertion purposes.
     *
     * @param string $method Method name
     * @param array $params Method parameters
     */
    private function trackMethodCall(string $method, array $params): void
    {
        $this->methodCalls[] = [
            'method' => $method,
            'params' => $params,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Mocks email template rendering.
     *
     * @param array $template Template data
     * @param array $context Render context
     * @return string
     */
    private function mockEmailRender(array $template, array $context): string
    {
        return "MOCK_EMAIL_CONTENT: {$template['name']} - Recipient: {$context['recipient']}";
    }

    /**
     * Mocks SMS template rendering.
     *
     * @param array $template Template data
     * @param array $context Render context
     * @return string
     */
    private function mockSmsRender(array $template, array $context): string
    {
        return "MOCK_SMS_CONTENT: {$template['name']} - To: {$context['recipient']}";
    }

    /**
     * Mocks push notification template rendering.
     *
     * @param array $template Template data
     * @param array $context Render context
     * @return string
     */
    private function mockPushRender(array $template, array $context): string
    {
        return "MOCK_PUSH_CONTENT: {$template['name']} - Title: {$context['title']}";
    }
}