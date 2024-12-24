<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model; // ^10.0
use Carbon\Carbon; // ^2.0
use App\Contracts\NotificationInterface;

/**
 * DeliveryAttempt Model
 * 
 * Represents a delivery attempt for a notification with comprehensive vendor response 
 * tracking and performance monitoring capabilities. Supports the system's 99.9% 
 * delivery success rate target through detailed status tracking.
 *
 * @property string $id UUID of the delivery attempt
 * @property string $notification_id UUID of the associated notification
 * @property string $vendor Name of the delivery vendor
 * @property string $status Current status of the delivery attempt
 * @property array $response JSONB storage of complete vendor response
 * @property Carbon $attempted_at Timestamp of the attempt
 * @property Carbon $created_at Creation timestamp
 * @property Carbon $updated_at Last update timestamp
 *
 * @package App\Models
 * @version 1.0.0
 */
class DeliveryAttempt extends Model
{
    /**
     * Status constants for delivery attempt tracking
     */
    public const STATUS_SUCCESSFUL = 'successful';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'delivery_attempts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'notification_id',
        'vendor',
        'status',
        'response',
        'attempted_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'response' => 'array',
        'attempted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
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
     * Create a new delivery attempt instance.
     *
     * @param array $attributes
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Initialize with pending status if not set
        if (!isset($this->status)) {
            $this->status = self::STATUS_PENDING;
        }

        // Set attempted_at to current time if not provided
        if (!isset($this->attempted_at)) {
            $this->attempted_at = Carbon::now();
        }

        // Initialize empty response if not set
        if (!isset($this->response)) {
            $this->response = [];
        }
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable(): string
    {
        return 'delivery_attempts';
    }

    /**
     * Check if the delivery attempt was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESSFUL;
    }

    /**
     * Check if the delivery attempt failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark the delivery attempt as successful with vendor response.
     *
     * @param array $response The vendor response data
     * @return bool
     */
    public function markAsSuccessful(array $response): bool
    {
        $this->status = self::STATUS_SUCCESSFUL;
        $this->response = $response;
        $this->attempted_at = Carbon::now();
        
        return $this->save();
    }

    /**
     * Mark the delivery attempt as failed with error details.
     *
     * @param array $response The error response data
     * @return bool
     */
    public function markAsFailed(array $response): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->response = $response;
        $this->attempted_at = Carbon::now();
        
        return $this->save();
    }

    /**
     * Get the notification associated with this delivery attempt.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }

    /**
     * Scope a query to only include successful attempts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESSFUL);
    }

    /**
     * Scope a query to only include failed attempts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Get the attempt duration in milliseconds.
     *
     * @return int
     */
    public function getDurationMs(): int
    {
        return $this->created_at->diffInMilliseconds($this->attempted_at);
    }
}