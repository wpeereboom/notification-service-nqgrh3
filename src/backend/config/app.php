<?php

declare(strict_types=1);

/**
 * Core Application Configuration
 * 
 * Defines fundamental settings, service providers, middleware, and essential components
 * for the high-throughput notification system supporting 100,000+ messages per minute
 * with 99.95% uptime and comprehensive monitoring.
 *
 * @version 1.0.0
 * @package NotificationService\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where system identification is needed.
    |
    */
    'name' => env('APP_NAME', 'Notification Service'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may affect various behaviors and error handling.
    |
    */
    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error. If disabled, a simple
    | generic error page is shown.
    |
    */
    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */
    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions.
    |
    */
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Add your own service providers to this
    | array to grant expanded functionality to your applications.
    |
    */
    'providers' => [
        // Core Service Providers
        App\Providers\AppServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\QueueServiceProvider::class,

        // Notification System Providers
        App\Providers\NotificationServiceProvider::class,
        App\Providers\TemplateServiceProvider::class,
        App\Providers\VendorServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */
    'aliases' => [
        'NotificationService' => App\Services\Notification\NotificationService::class,
        'TemplateService' => App\Services\Template\TemplateService::class,
        'VendorService' => App\Services\Vendor\VendorService::class,
        'CircuitBreaker' => App\Utils\CircuitBreaker::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    |
    | Configure performance-related settings for high-throughput processing
    | of 100,000+ messages per minute with optimal resource utilization.
    |
    */
    'performance' => [
        // Memory limit per process (512MB)
        'memory_limit' => env('APP_MEMORY_LIMIT', '512M'),

        // Maximum execution time for long-running processes (5 minutes)
        'max_execution_time' => env('APP_MAX_EXECUTION_TIME', 300),

        // Queue processing timeout (30 seconds)
        'queue_timeout' => env('APP_QUEUE_TIMEOUT', 30),

        // Process pool configuration
        'process_pool' => [
            'min_processes' => 5,
            'max_processes' => 20,
            'scale_factor' => 1.5,
        ],

        // Batch processing settings
        'batch' => [
            'size' => 1000,
            'timeout' => 30,
            'retry_attempts' => 3,
            'retry_delay' => 1000, // milliseconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure comprehensive monitoring and metrics collection for ensuring
    | 99.95% uptime and tracking system performance.
    |
    */
    'monitoring' => [
        // Enable detailed monitoring
        'enabled' => env('APP_MONITORING_ENABLED', true),

        // CloudWatch metrics configuration
        'cloudwatch' => [
            'namespace' => 'NotificationService',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'metrics_interval' => 60, // seconds
        ],

        // Health check configuration
        'health_check' => [
            'interval' => 30, // seconds
            'timeout' => 5, // seconds
            'failure_threshold' => 3,
        ],

        // Performance thresholds
        'thresholds' => [
            'latency_p95' => 30000, // milliseconds
            'error_rate' => 0.001, // 0.1%
            'queue_depth_warning' => 10000,
            'queue_depth_critical' => 50000,
        ],

        // Logging configuration
        'logging' => [
            'level' => env('APP_LOG_LEVEL', 'info'),
            'max_files' => 30,
            'channels' => ['daily', 'cloudwatch'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security settings for protecting sensitive notification data and
    | ensuring secure vendor integrations.
    |
    */
    'security' => [
        // API authentication
        'api_auth' => [
            'token_lifetime' => 3600, // 1 hour
            'refresh_token_lifetime' => 86400, // 24 hours
            'token_algorithm' => 'RS256',
        ],

        // Rate limiting
        'rate_limiting' => [
            'enabled' => true,
            'max_attempts' => 1000,
            'decay_minutes' => 1,
        ],

        // Circuit breaker configuration
        'circuit_breaker' => [
            'failure_threshold' => 5,
            'reset_timeout' => 30, // seconds
            'half_open_timeout' => 15, // seconds
        ],

        // Encryption settings
        'encryption' => [
            'algorithm' => 'AES-256-GCM',
            'key_rotation_interval' => 90, // days
        ],
    ],
];