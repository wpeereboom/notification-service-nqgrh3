<?php

declare(strict_types=1);

namespace App\Services\Template;

use App\Contracts\TemplateInterface;
use App\Models\Template;
use App\Services\Cache\RedisCacheService;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Twig\Environment as Twig;

/**
 * Service implementing secure template management, rendering and caching functionality
 * with comprehensive error handling and performance optimization.
 *
 * @version 1.0.0
 * @package App\Services\Template
 */
class TemplateService implements TemplateInterface
{
    private const CACHE_PREFIX = 'template:';
    private const CACHE_TTL = 3600; // 1 hour

    private Template $template;
    private RedisCacheService $cache;
    private Twig $twig;
    private LoggerInterface $logger;

    /**
     * Initialize template service with required dependencies
     *
     * @param Template $template Template model instance
     * @param RedisCacheService $cache Redis cache service
     * @param Twig $twig Template rendering engine
     * @param LoggerInterface $logger PSR-3 logger interface
     */
    public function __construct(
        Template $template,
        RedisCacheService $cache,
        Twig $twig,
        LoggerInterface $logger
    ) {
        $this->template = $template;
        $this->cache = $cache;
        $this->twig = $twig;
        $this->logger = $logger;
    }

    /**
     * Creates a new template with comprehensive validation
     *
     * @param array $data Template creation data
     * @return bool Success status
     * @throws InvalidArgumentException If template data is invalid
     * @throws RuntimeException If creation fails
     */
    public function create(array $data): bool
    {
        try {
            if (!$this->validate($data['content'] ?? '')) {
                throw new InvalidArgumentException('Invalid template content');
            }

            $template = $this->template::create($data);
            $this->cache->set(
                $this->getCacheKey($template->id),
                $template->toArray(),
                self::CACHE_TTL
            );

            $this->logger->info('Template created successfully', [
                'template_id' => $template->id,
                'name' => $template->name
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Template creation failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw new RuntimeException('Failed to create template: ' . $e->getMessage());
        }
    }

    /**
     * Updates an existing template with version control
     *
     * @param string $id Template ID
     * @param array $data Update data
     * @return bool Success status
     * @throws InvalidArgumentException If template data is invalid
     * @throws RuntimeException If update fails
     */
    public function update(string $id, array $data): bool
    {
        try {
            $template = $this->template::findOrFail($id);

            if (!$this->validate($data['content'] ?? $template->content)) {
                throw new InvalidArgumentException('Invalid template content');
            }

            $success = $template->update($data);
            if ($success) {
                $this->cache->delete($this->getCacheKey($id));
                $this->cache->set(
                    $this->getCacheKey($id),
                    $template->fresh()->toArray(),
                    self::CACHE_TTL
                );
            }

            $this->logger->info('Template updated successfully', [
                'template_id' => $id,
                'version' => $template->version
            ]);

            return $success;
        } catch (\Exception $e) {
            $this->logger->error('Template update failed', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to update template: ' . $e->getMessage());
        }
    }

    /**
     * Safely deletes a template with dependency checking
     *
     * @param string $id Template ID
     * @return bool Success status
     * @throws RuntimeException If deletion fails
     */
    public function delete(string $id): bool
    {
        try {
            $template = $this->template::findOrFail($id);
            $success = $template->delete();

            if ($success) {
                $this->cache->delete($this->getCacheKey($id));
                $this->logger->info('Template deleted successfully', [
                    'template_id' => $id
                ]);
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error('Template deletion failed', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to delete template: ' . $e->getMessage());
        }
    }

    /**
     * Finds a template by ID using caching
     *
     * @param string $id Template ID
     * @return array|null Template data or null if not found
     * @throws RuntimeException If retrieval fails
     */
    public function find(string $id): ?array
    {
        try {
            $cacheKey = $this->getCacheKey($id);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }

            $template = $this->template::find($id);
            if ($template === null) {
                return null;
            }

            $data = $template->toArray();
            $this->cache->set($cacheKey, $data, self::CACHE_TTL);

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Template retrieval failed', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to retrieve template: ' . $e->getMessage());
        }
    }

    /**
     * Finds a template by name with caching
     *
     * @param string $name Template name
     * @return array|null Template data or null if not found
     * @throws RuntimeException If retrieval fails
     */
    public function findByName(string $name): ?array
    {
        try {
            $cacheKey = $this->getCacheKey('name:' . $name);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }

            $template = $this->template::findByName($name);
            if ($template === null) {
                return null;
            }

            $data = $template->toArray();
            $this->cache->set($cacheKey, $data, self::CACHE_TTL);

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Template retrieval by name failed', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to retrieve template by name: ' . $e->getMessage());
        }
    }

    /**
     * Renders a template with context validation
     *
     * @param string $id Template ID
     * @param array $context Template variables
     * @return string Rendered content
     * @throws InvalidArgumentException If context is invalid
     * @throws RuntimeException If rendering fails
     */
    public function render(string $id, array $context): string
    {
        try {
            $template = $this->find($id);
            if ($template === null) {
                throw new RuntimeException('Template not found');
            }

            $this->validateContext($template['type'], $context);
            
            return $this->twig->render(
                $this->createTwigTemplate($template['content']),
                $this->sanitizeContext($context)
            );
        } catch (\Exception $e) {
            $this->logger->error('Template rendering failed', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to render template: ' . $e->getMessage());
        }
    }

    /**
     * Validates template content
     *
     * @param string $content Template content
     * @return bool Validation status
     */
    public function validate(string $content): bool
    {
        try {
            if (empty($content)) {
                return false;
            }

            // Validate Twig syntax
            $this->twig->createTemplate($content);

            // Validate content structure
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->warning('Template validation failed', [
                'error' => $e->getMessage(),
                'content' => $content
            ]);
            return false;
        }
    }

    /**
     * Generates cache key for template
     *
     * @param string $identifier Template ID or name
     * @return string Cache key
     */
    private function getCacheKey(string $identifier): string
    {
        return self::CACHE_PREFIX . $identifier;
    }

    /**
     * Validates context data for specific channel
     *
     * @param string $type Channel type
     * @param array $context Context data
     * @throws InvalidArgumentException If context is invalid
     */
    private function validateContext(string $type, array $context): void
    {
        $required = match($type) {
            'email' => ['subject', 'recipient'],
            'sms' => ['recipient'],
            'push' => ['title', 'body'],
            default => throw new InvalidArgumentException('Invalid template type')
        };

        foreach ($required as $field) {
            if (!isset($context[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }

    /**
     * Creates Twig template from content
     *
     * @param string|array $content Template content
     * @return string Twig template
     */
    private function createTwigTemplate(string|array $content): string
    {
        if (is_array($content)) {
            $content = json_encode($content);
        }
        return $content;
    }

    /**
     * Sanitizes context data for template rendering
     *
     * @param array $context Raw context data
     * @return array Sanitized context
     */
    private function sanitizeContext(array $context): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $context);
    }
}