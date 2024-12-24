<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\TemplateInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Template Model
 *
 * Represents a notification template with support for multiple channels (Email, SMS, Push)
 * and content types with JSONB storage, implementing comprehensive validation and caching.
 *
 * @property string $id
 * @property string $name
 * @property string $type
 * @property array $content
 * @property bool $active
 * @property int $version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $last_used_at
 * @property Carbon $deleted_at
 *
 * @method static Builder|Template active(string $channel = null)
 */
class Template extends Model implements TemplateInterface, JsonSerializable
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'templates';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'type',
        'content',
        'active',
        'version',
        'last_used_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'content' => 'json',
        'active' => 'boolean',
        'version' => 'integer',
        'last_used_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<string>
     */
    protected $hidden = [
        'deleted_at',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<string>
     */
    protected $appends = [
        'is_valid',
        'supported_channels',
    ];

    /**
     * Cache key prefix for template instances.
     *
     * @var string
     */
    private const CACHE_KEY_PREFIX = 'template:';

    /**
     * Cache TTL in seconds (1 hour).
     *
     * @var int
     */
    private const CACHE_TTL = 3600;

    /**
     * Supported notification channels.
     *
     * @var array<string>
     */
    private const SUPPORTED_CHANNELS = ['email', 'sms', 'push'];

    /**
     * Create a new template instance.
     *
     * @param array $attributes
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->version = $this->version ?? 1;
        $this->active = $this->active ?? false;
    }

    /**
     * Create a new template with validation and caching.
     *
     * @param array $data Template creation data
     * @return static
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public static function create(array $data): static
    {
        if (!self::validateTemplateData($data)) {
            throw new InvalidArgumentException('Invalid template data provided');
        }

        try {
            $template = new static($data);
            $template->save();

            // Cache the new template
            self::cacheTemplate($template);

            Log::info('Template created', ['template_id' => $template->id, 'name' => $template->name]);

            return $template;
        } catch (\Exception $e) {
            Log::error('Template creation failed', ['error' => $e->getMessage(), 'data' => $data]);
            throw new RuntimeException('Failed to create template: ' . $e->getMessage());
        }
    }

    /**
     * Update the template with version control.
     *
     * @param array $data Update data
     * @return bool
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function update(array $data = []): bool
    {
        if (!$this->validate($data['content'] ?? $this->content)) {
            throw new InvalidArgumentException('Invalid template content');
        }

        try {
            $this->version++;
            $this->fill($data);
            
            // Clear the template cache
            $this->clearCache();
            
            $updated = $this->save();
            
            if ($updated) {
                Log::info('Template updated', [
                    'template_id' => $this->id,
                    'version' => $this->version
                ]);
            }

            return $updated;
        } catch (\Exception $e) {
            Log::error('Template update failed', [
                'template_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to update template: ' . $e->getMessage());
        }
    }

    /**
     * Find a template by name with caching support.
     *
     * @param string $name Template name
     * @return static|null
     */
    public static function findByName(string $name): ?static
    {
        $cacheKey = self::CACHE_KEY_PREFIX . 'name:' . $name;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($name) {
            return static::where('name', $name)
                ->where('active', true)
                ->orderBy('version', 'desc')
                ->first();
        });
    }

    /**
     * Scope query to only active templates.
     *
     * @param Builder $query
     * @param string|null $channel
     * @return Builder
     */
    public function scopeActive(Builder $query, ?string $channel = null): Builder
    {
        $query = $query->where('active', true);

        if ($channel !== null) {
            $query->where('type', $channel);
        }

        return $query->orderBy('version', 'desc');
    }

    /**
     * Validate template content.
     *
     * @param string $content
     * @return bool
     */
    public function validate(string $content): bool
    {
        try {
            // Validate basic structure
            if (empty($content)) {
                return false;
            }

            // Validate channel-specific content
            switch ($this->type) {
                case 'email':
                    return $this->validateEmailTemplate($content);
                case 'sms':
                    return $this->validateSmsTemplate($content);
                case 'push':
                    return $this->validatePushTemplate($content);
                default:
                    return false;
            }
        } catch (\Exception $e) {
            Log::warning('Template validation failed', [
                'template_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Render template with context data.
     *
     * @param string $id
     * @param array $context
     * @return string
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function render(string $id, array $context): string
    {
        try {
            $template = self::findOrFail($id);
            $content = $template->content;

            // Update last used timestamp
            $template->update(['last_used_at' => Carbon::now()]);

            return $this->renderContent($content, $context);
        } catch (\Exception $e) {
            Log::error('Template rendering failed', [
                'template_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to render template: ' . $e->getMessage());
        }
    }

    /**
     * Get the template's validity status.
     *
     * @return bool
     */
    public function getIsValidAttribute(): bool
    {
        return $this->validate((string)json_encode($this->content));
    }

    /**
     * Get supported channels for the template.
     *
     * @return array
     */
    public function getSupportedChannelsAttribute(): array
    {
        return self::SUPPORTED_CHANNELS;
    }

    /**
     * Cache a template instance.
     *
     * @param Template $template
     * @return void
     */
    private static function cacheTemplate(Template $template): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $template->id;
        Cache::put($cacheKey, $template, self::CACHE_TTL);
    }

    /**
     * Clear the template cache.
     *
     * @return void
     */
    private function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_PREFIX . $this->id);
        Cache::forget(self::CACHE_KEY_PREFIX . 'name:' . $this->name);
    }

    /**
     * Validate template data before creation.
     *
     * @param array $data
     * @return bool
     */
    private static function validateTemplateData(array $data): bool
    {
        return isset($data['name'])
            && isset($data['type'])
            && isset($data['content'])
            && in_array($data['type'], self::SUPPORTED_CHANNELS);
    }

    /**
     * Validate email template content.
     *
     * @param string $content
     * @return bool
     */
    private function validateEmailTemplate(string $content): bool
    {
        $data = json_decode($content, true);
        return isset($data['subject']) && isset($data['body']);
    }

    /**
     * Validate SMS template content.
     *
     * @param string $content
     * @return bool
     */
    private function validateSmsTemplate(string $content): bool
    {
        return strlen($content) <= 1600; // Maximum SMS length
    }

    /**
     * Validate push notification template content.
     *
     * @param string $content
     * @return bool
     */
    private function validatePushTemplate(string $content): bool
    {
        $data = json_decode($content, true);
        return isset($data['title']) && isset($data['body']);
    }

    /**
     * Render template content with context data.
     *
     * @param array $content
     * @param array $context
     * @return string
     */
    private function renderContent(array $content, array $context): string
    {
        // Replace variables in content with context data
        $rendered = json_encode($content);
        foreach ($context as $key => $value) {
            $rendered = str_replace("{{$key}}", (string)$value, $rendered);
        }
        return $rendered;
    }
}