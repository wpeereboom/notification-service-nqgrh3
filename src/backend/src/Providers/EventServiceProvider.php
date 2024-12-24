<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use App\Services\Notification\NotificationService;
use App\Services\Queue\SqsService;
use App\Events\{
    NotificationSent,
    NotificationFailed,
    VendorFailoverTriggered,
    CircuitBreakerStateChanged
};
use App\Listeners\{
    NotificationEventSubscriber,
    VendorFailoverSubscriber,
    CircuitBreakerSubscriber,
    MetricsCollector
};

/**
 * Service provider responsible for registering event listeners and subscribers
 * for high-throughput notification processing and comprehensive monitoring.
 *
 * @package App\Providers
 * @version 1.0.0
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * Event to listener mappings for notification system.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        NotificationSent::class => [
            MetricsCollector::class . '@handleNotificationSent',
        ],
        NotificationFailed::class => [
            MetricsCollector::class . '@handleNotificationFailed',
        ],
        VendorFailoverTriggered::class => [
            VendorFailoverSubscriber::class . '@handleFailover',
        ],
        CircuitBreakerStateChanged::class => [
            CircuitBreakerSubscriber::class . '@handleStateChange',
        ],
    ];

    /**
     * Event subscribers for the notification system.
     *
     * @var array<int, class-string>
     */
    protected $subscribe = [
        NotificationEventSubscriber::class,
        VendorFailoverSubscriber::class,
        CircuitBreakerSubscriber::class,
        MetricsCollector::class,
    ];

    /**
     * Event dispatcher instance.
     *
     * @var EventDispatcherInterface
     */
    protected EventDispatcherInterface $events;

    /**
     * Batch processing configuration.
     *
     * @var array
     */
    protected array $batchConfig = [
        'size' => 1000,
        'timeout' => 30,
        'retry_attempts' => 3,
        'retry_delay' => 1000,
    ];

    /**
     * Create a new service provider instance.
     *
     * @param EventDispatcherInterface $events
     * @return void
     */
    public function __construct(EventDispatcherInterface $events)
    {
        $this->events = $events;
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Bind event dispatcher interface
        $this->app->singleton(EventDispatcherInterface::class, function ($app) {
            return $this->events;
        });

        // Register batch processing configuration
        $this->app->singleton('notification.batch.config', function ($app) {
            return $this->batchConfig;
        });

        // Register metrics collector
        $this->app->singleton(MetricsCollector::class, function ($app) {
            return new MetricsCollector(
                $app->make('redis'),
                $app->make('log')
            );
        });

        // Register notification event subscriber
        $this->app->singleton(NotificationEventSubscriber::class, function ($app) {
            return new NotificationEventSubscriber(
                $app->make(NotificationService::class),
                $app->make('log')
            );
        });

        // Register vendor failover subscriber
        $this->app->singleton(VendorFailoverSubscriber::class, function ($app) {
            return new VendorFailoverSubscriber(
                $app->make('log'),
                $app->make('redis')
            );
        });

        // Register circuit breaker subscriber
        $this->app->singleton(CircuitBreakerSubscriber::class, function ($app) {
            return new CircuitBreakerSubscriber(
                $app->make('log'),
                $app->make('redis')
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Configure high-throughput event processing
        $this->configureBatchProcessing();

        // Register event subscribers
        foreach ($this->subscribe as $subscriber) {
            $this->events->addSubscriber($this->app->make($subscriber));
        }

        // Register individual event listeners
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                $this->events->listen($event, $listener);
            }
        }

        // Configure event monitoring
        $this->configureEventMonitoring();
    }

    /**
     * Configure batch processing for high-throughput events.
     *
     * @return void
     */
    protected function configureBatchProcessing(): void
    {
        $this->app->make(SqsService::class)->configureBatchProcessing([
            'batch_size' => $this->batchConfig['size'],
            'processing_timeout' => $this->batchConfig['timeout'],
            'retry_attempts' => $this->batchConfig['retry_attempts'],
            'retry_delay' => $this->batchConfig['retry_delay'],
            'enable_dead_letter_queue' => true,
        ]);
    }

    /**
     * Configure comprehensive event monitoring.
     *
     * @return void
     */
    protected function configureEventMonitoring(): void
    {
        $metrics = $this->app->make(MetricsCollector::class);

        // Configure real-time metrics collection
        $metrics->configureMetrics([
            'enable_real_time' => true,
            'sampling_rate' => 0.1, // 10% sampling
            'aggregation_interval' => 60, // 1 minute
            'retention_period' => 86400, // 24 hours
        ]);

        // Configure performance monitoring
        $metrics->configurePerformanceMonitoring([
            'latency_threshold' => 100, // milliseconds
            'error_threshold' => 0.001, // 0.1% error rate
            'throughput_threshold' => 1000, // events per second
        ]);
    }

    /**
     * Determines if events should be automatically discovered.
     *
     * @return bool
     */
    public static function shouldDiscoverEvents(): bool
    {
        return true;
    }
}