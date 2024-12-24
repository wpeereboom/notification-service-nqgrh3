<?php

declare(strict_types=1);

namespace App\Contracts;

use JsonSerializable; // PHP 8.2

/**
 * Interface TemplateInterface
 * 
 * Defines the contract for template management and rendering functionality in the notification system.
 * Supports multiple notification channels with secure template processing and caching capabilities.
 *
 * @package App\Contracts
 */
interface TemplateInterface extends JsonSerializable
{
    /**
     * Creates a new template with comprehensive validation and security checks.
     *
     * Required data structure:
     * [
     *   'name' => string,           // Unique template identifier
     *   'channel' => string,        // Notification channel (email|sms|push)
     *   'content' => string|array,  // Template content appropriate for channel
     *   'metadata' => array,        // Additional template metadata
     *   'active' => bool           // Template activation status
     * ]
     *
     * @param array $data Template creation data including content and metadata
     * @return bool True if template creation successful, false if validation fails
     * @throws \InvalidArgumentException If required fields are missing or invalid
     * @throws \RuntimeException If template creation fails due to system error
     */
    public function create(array $data): bool;

    /**
     * Updates an existing template with validation and cache management.
     *
     * Updateable fields:
     * - content: Template content with channel-specific format
     * - metadata: Template metadata and configuration
     * - active: Template activation status
     *
     * @param string $id Template unique identifier
     * @param array $data Updated template data
     * @return bool True if update successful, false if template not found or validation fails
     * @throws \InvalidArgumentException If update data is invalid
     * @throws \RuntimeException If update operation fails
     */
    public function update(string $id, array $data): bool;

    /**
     * Safely deletes a template with cache cleanup.
     * Performs dependency checking before deletion.
     *
     * @param string $id Template unique identifier
     * @return bool True if deletion successful, false if template not found
     * @throws \RuntimeException If deletion fails or dependencies exist
     */
    public function delete(string $id): bool;

    /**
     * Retrieves a template by ID with caching support.
     * Returns complete template data including metadata.
     *
     * @param string $id Template unique identifier
     * @return array|null Template data if found, null if not found
     * @throws \RuntimeException If retrieval operation fails
     */
    public function find(string $id): ?array;

    /**
     * Retrieves a template by name with caching support.
     * Returns complete template data including metadata.
     *
     * @param string $name Template name
     * @return array|null Template data if found, null if not found
     * @throws \RuntimeException If retrieval operation fails
     */
    public function findByName(string $name): ?array;

    /**
     * Renders a template with context data for multiple notification channels.
     * Supports variable interpolation and content generation based on channel type.
     *
     * Context structure varies by channel:
     * - Email: ['subject', 'recipient', 'variables', ...]
     * - SMS: ['recipient', 'variables', ...]
     * - Push: ['title', 'body', 'data', ...]
     *
     * @param string $id Template unique identifier
     * @param array $context Template rendering context data
     * @return string Rendered template content
     * @throws \InvalidArgumentException If context data is invalid
     * @throws \RuntimeException If rendering fails
     */
    public function render(string $id, array $context): string;

    /**
     * Validates template syntax, structure, and security constraints.
     * Performs comprehensive validation including:
     * - Syntax checking
     * - Variable placeholder validation
     * - Security constraint verification
     * - Channel compatibility checking
     * - Structure validation
     *
     * @param string $content Template content to validate
     * @return bool True if template is valid and secure
     * @throws \InvalidArgumentException If content is malformed
     */
    public function validate(string $content): bool;
}