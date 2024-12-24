<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Ramsey\Uuid\Uuid; // ^4.0
use DateTime;

/**
 * VendorStatus Model
 * 
 * Represents the health status and performance metrics of notification service providers.
 * Tracks vendor availability, success rates, and monitoring intervals for the failover system.
 *
 * @property string $id UUID primary key
 * @property string $vendor Vendor identifier (e.g., 'iterable', 'sendgrid', 'telnyx')
 * @property string $status Current health status (healthy, degraded, unhealthy)
 * @property float $success_rate Message delivery success rate (0.0 to 1.0)
 * @property DateTime $last_check Timestamp of last health check
 * @property DateTime $created_at
 * @property DateTime $updated_at
 *
 * @method static Builder healthy() Scope for healthy vendors
 * @method static Builder byVendor(string $vendor) Scope for specific vendor
 */
class VendorStatus extends Model
{
    use HasFactory;

    /**
     * Vendor health status constants
     */
    public const VENDOR_STATUS_HEALTHY = 'healthy';
    public const VENDOR_STATUS_DEGRADED = 'degraded';
    public const VENDOR_STATUS_UNHEALTHY = 'unhealthy';

    /**
     * Health check interval in seconds
     */
    public const VENDOR_HEALTH_CHECK_INTERVAL = 30;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'vendor_status';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'vendor',
        'status',
        'success_rate',
        'last_check'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'success_rate' => 'float',
        'last_check' => 'datetime',
        'id' => 'string'
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
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $model->id = Uuid::uuid4()->toString();
        });
    }

    /**
     * Scope query to only include healthy vendors with acceptable success rates.
     * Healthy vendors must have:
     * - Status set to healthy
     * - Success rate >= 95%
     * - Recent health check within interval
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeHealthy(Builder $query): Builder
    {
        return $query->where('status', self::VENDOR_STATUS_HEALTHY)
            ->where('success_rate', '>=', 0.95)
            ->where('last_check', '>=', now()->subSeconds(self::VENDOR_HEALTH_CHECK_INTERVAL));
    }

    /**
     * Scope query to filter by specific vendor name.
     *
     * @param Builder $query
     * @param string $vendor
     * @return Builder
     */
    public function scopeByVendor(Builder $query, string $vendor): Builder
    {
        return $query->where('vendor', $vendor);
    }

    /**
     * Determine if vendor needs a health check based on configured interval.
     *
     * @return bool
     */
    public function needsHealthCheck(): bool
    {
        if (!$this->last_check) {
            return true;
        }

        $elapsedSeconds = now()->diffInSeconds($this->last_check);
        return $elapsedSeconds >= self::VENDOR_HEALTH_CHECK_INTERVAL;
    }
}