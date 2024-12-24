<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\NotificationInterface;
use Carbon\Carbon; // ^2.0
use Illuminate\Database\Eloquent\Model; // ^10.0
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection; // ^10.0
use Illuminate\Support\Facades\Cache; // ^10.0
use Illuminate\Support\Facades\Queue; // ^10.0
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Notification Model
 *
 * Handles high-throughput notification processing across multiple channels with
 * comprehensive delivery tracking and vendor failover support.
 *
 * @property string $id UUID of the notification
 * @property string $type Type of notification
 * @property array $payload JSONB storage of notification data
 * @property string $status Current notification status
 * @property string $channel Delivery channel (email, sms, push)
 * @property int $priority Processing priority level
 * @property array $metadata Additional tracking metadata
 * @property string $vendor_preference Preferred vendor for delivery
 * @property string $batch_id Optional batch processing ID
 * @property int $attempt_count Number of delivery attempts
 * @property float $success_rate Calculated delivery success rate
 * @property Carbon $created_at Creation timestamp
 * @property Carbon $updated_at Last update timestamp
 * @property Carbon $deleted_at Soft delete timestamp
 */
class Notification extends Model implements NotificationInterface
{
    use SoftDeletes;

    /**
     * Status constants for notification lifecycle
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRYING = 'retrying';

    /**
     * Channel constants
     */
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_PUSH = 'push';

    /**
     * Priority levels
     */
    public const PRIORITY_HIGH = 1;
    public const PRIORITY_NORMAL = 2;
    public const PRIORITY_LOW = 3;

    /**
     * System configuration
     */
    private const MAX_RETRY_ATTEMPTS = 3;
    private const SUCCESS_RATE_THRESHOLD = 0.999;
    private const CACHE_TTL = 3600;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'notifications';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'type',
        'payload',
        'status',
        'channel',
        'priority',
        'metadata',
        'vendor_preference',
        'batch_id',
        'attempt_count',
        'success_rate'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'priority' => 'integer',
        'attempt_count' => 'integer',
        'success_rate' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Create a new notification instance.
     *
     * @param array $attributes
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Generate UUID for new instances
        if (!isset($this->id)) {
            $this->id = (string) Str::uuid();
        }

        // Set default values for new instances
        $this->status = $this->status ?? self::STATUS_PENDING;
        $this->priority = $this->priority ?? self::PRIORITY_NORMAL;
        $this->attempt_count = $this->attempt_count ?? 0;
        $this->success_rate = $this->success_rate ?? 1.0;
        $this->metadata = $this->metadata ?? [];
    }

    /**
     * Send a notification through the specified channel.
     *
     * @param array $payload
     * @param string $channel
     * @param array $options
     * @return string
     * @throws InvalidArgumentException
     */
    public function send(array $payload, string $channel, array $options = []): string
    {
        // Validate channel
        if (!in_array($channel, [self::CHANNEL_EMAIL, self::CHANNEL_SMS, self::CHANNEL_PUSH])) {
            throw new InvalidArgumentException('Invalid notification channel');
        }

        // Validate payload
        if (!$this->validatePayload($payload, $channel)) {
            throw new InvalidArgumentException('Invalid payload for channel');
        }

        try {
            // Create notification record
            $notification = self::create([
                'type' => $payload['type'] ?? 'default',
                'payload' => $payload,
                'channel' => $channel,
                'priority' => $options['priority'] ?? self::PRIORITY_NORMAL,
                'metadata' => array_merge(
                    $options['metadata'] ?? [],
                    ['source_ip' => request()->ip()]
                ),
                'vendor_preference' => $options['vendor'] ?? null,
                'batch_id' => $options['batch_id'] ?? null
            ]);

            // Queue for processing with priority
            Queue::pushOn(
                "notifications-{$channel}",
                new \App\Jobs\ProcessNotification($notification->id),
                $notification->priority
            );

            // Cache initial status
            $this->cacheStatus($notification->id, self::STATUS_QUEUED);

            return $notification->id;
        } catch (\Exception $e) {
            throw new RuntimeException('Failed to queue notification: ' . $e->getMessage());
        }
    }

    /**
     * Get current notification status with caching.
     *
     * @param string $notificationId
     * @return array
     * @throws InvalidArgumentException
     */
    public function getStatus(string $notificationId): array
    {
        $cacheKey = "notification:status:{$notificationId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($notificationId) {
            $notification = self::with('deliveryAttempts')->findOrFail($notificationId);

            return [
                'status' => $notification->status,
                'channel' => $notification->channel,
                'timestamps' => [
                    'created' => $notification->created_at->toIso8601String(),
                    'updated' => $notification->updated_at->toIso8601String()
                ],
                'vendor' => $notification->vendor_preference,
                'metrics' => [
                    'attempts' => $notification->attempt_count,
                    'success_rate' => $notification->success_rate
                ]
            ];
        });
    }

    /**
     * Get delivery attempts for a notification.
     *
     * @param string $notificationId
     * @return array
     * @throws InvalidArgumentException
     */
    public function getDeliveryAttempts(string $notificationId): array
    {
        $notification = self::with('deliveryAttempts')->findOrFail($notificationId);

        return $notification->deliveryAttempts->map(function ($attempt) {
            return [
                'vendor' => $attempt->vendor,
                'status' => $attempt->status,
                'timestamp' => $attempt->attempted_at->toIso8601String(),
                'response' => $attempt->response,
                'error' => $attempt->response['error'] ?? null
            ];
        })->toArray();
    }

    /**
     * Retry a failed notification.
     *
     * @param string $notificationId
     * @param array $options
     * @return array
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function retry(string $notificationId, array $options = []): array
    {
        $notification = self::findOrFail($notificationId);

        if ($notification->status !== self::STATUS_FAILED) {
            throw new InvalidArgumentException('Only failed notifications can be retried');
        }

        if ($notification->attempt_count >= self::MAX_RETRY_ATTEMPTS) {
            throw new RuntimeException('Maximum retry attempts exceeded');
        }

        try {
            // Update notification for retry
            $notification->status = self::STATUS_RETRYING;
            $notification->attempt_count++;
            $notification->vendor_preference = $options['vendor'] ?? $this->selectNextVendor($notification);
            $notification->save();

            // Queue retry attempt
            Queue::pushOn(
                "notifications-retry",
                new \App\Jobs\ProcessNotification($notification->id),
                self::PRIORITY_HIGH
            );

            return [
                'retry_id' => $notification->id,
                'vendor' => $notification->vendor_preference,
                'status' => $notification->status
            ];
        } catch (\Exception $e) {
            throw new RuntimeException('Failed to initiate retry: ' . $e->getMessage());
        }
    }

    /**
     * Get the delivery attempts for this notification.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function deliveryAttempts()
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /**
     * Get the template associated with this notification.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Validate payload for specific channel.
     *
     * @param array $payload
     * @param string $channel
     * @return bool
     */
    private function validatePayload(array $payload, string $channel): bool
    {
        switch ($channel) {
            case self::CHANNEL_EMAIL:
                return isset($payload['recipient'])
                    && filter_var($payload['recipient'], FILTER_VALIDATE_EMAIL)
                    && isset($payload['template_id']);

            case self::CHANNEL_SMS:
                return isset($payload['recipient'])
                    && isset($payload['template_id']);

            case self::CHANNEL_PUSH:
                return isset($payload['device_token'])
                    && isset($payload['template_id']);

            default:
                return false;
        }
    }

    /**
     * Cache notification status.
     *
     * @param string $id
     * @param string $status
     * @return void
     */
    private function cacheStatus(string $id, string $status): void
    {
        Cache::put(
            "notification:status:{$id}",
            ['status' => $status, 'updated_at' => Carbon::now()->toIso8601String()],
            self::CACHE_TTL
        );
    }

    /**
     * Select next vendor for retry attempt.
     *
     * @param Notification $notification
     * @return string
     */
    private function selectNextVendor(Notification $notification): string
    {
        // Implement vendor selection logic based on channel and previous attempts
        $failedVendors = $notification->deliveryAttempts()
            ->failed()
            ->pluck('vendor')
            ->toArray();

        // Get available vendors for channel
        $vendors = config("notifications.vendors.{$notification->channel}");

        // Filter out failed vendors
        $availableVendors = array_diff($vendors, $failedVendors);

        return !empty($availableVendors)
            ? array_shift($availableVendors)
            : $vendors[0]; // Fallback to first vendor if all have failed
    }
}