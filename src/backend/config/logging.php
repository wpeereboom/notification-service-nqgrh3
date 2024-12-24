<?php

/**
 * Notification Service Logging Configuration
 * 
 * Provides comprehensive logging configuration with enhanced security, performance,
 * and compliance features supporting multiple channels and advanced processing.
 * 
 * @package NotificationService
 * @version 1.0.0
 * 
 * External dependencies:
 * - monolog/monolog: ^3.0
 * - aws/aws-sdk-php: ^3.0
 */

use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\WebProcessor;
use Monolog\Logger;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */
    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */
    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, the framework uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    */
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['cloudwatch', 'daily'],
            'ignore_exceptions' => false,
            'buffer_size' => 1000,
            'flush_interval' => 5,
        ],

        'cloudwatch' => [
            'driver' => 'custom',
            'via' => \AWS\CloudWatch\CloudWatchLoggerFactory::class,
            'name' => 'notification-service',
            'group' => 'notification-service-logs',
            'retention' => 90,
            'level' => env('LOG_LEVEL', 'debug'),
            'batch_size' => 10000,
            'retry_count' => 3,
            'tags' => [
                'environment' => env('APP_ENV', 'production'),
                'service' => 'notification',
            ],
            'error_handler' => \AWS\CloudWatch\ErrorHandler::class,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/notification-service/notification.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 30,
            'permission' => 0644,
            'rotation' => [
                'size' => '100MB',
                'time' => '00:00',
                'compress' => true,
                'compression_type' => 'gzip',
            ],
            'failure_handler' => [
                'disk_full' => 'cleanup_old_logs',
                'max_retries' => 3,
                'fallback_path' => '/tmp/notification-service-fallback.log',
            ],
        ],

        'emergency' => [
            'driver' => 'daily',
            'path' => storage_path('logs/notification-service/emergency.log'),
            'level' => 'emergency',
            'permission' => 0600,
            'alert_threshold' => 5,
            'alert_interval' => 300,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Notification Service Logger',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Formatters
    |--------------------------------------------------------------------------
    |
    | Configure the formatters used for different logging channels. The JSON
    | formatter provides structured logging with customizable fields and
    | sanitization options for sensitive data.
    |
    */
    'formatters' => [
        'json' => [
            'format' => 'json',
            'include' => [
                'timestamp',
                'level',
                'message',
                'context',
                'request_id',
                'notification_id',
                'vendor',
                'channel'
            ],
            'datetime_format' => 'Y-m-d\TH:i:s.uP',
            'extra_fields' => [
                'environment' => true,
                'process_id' => true,
                'memory_usage' => true,
            ],
            'sanitization' => [
                'fields' => ['email', 'phone', 'password'],
                'replacement' => '[REDACTED]',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Processors
    |--------------------------------------------------------------------------
    |
    | Configure the processors that add additional context information to log
    | records. These processors enrich the log data with valuable debugging
    | and tracking information.
    |
    */
    'processors' => [
        'request_id' => [
            'enabled' => true,
            'header' => 'X-Request-ID',
            'generator' => 'uuid4',
        ],
        'notification_context' => [
            'enabled' => true,
            'fields' => [
                'notification_id',
                'template_id',
                'channel',
                'priority',
            ],
        ],
        'vendor_metadata' => [
            'enabled' => true,
            'include_status' => true,
            'include_response_time' => true,
        ],
        'memory_usage' => [
            'enabled' => true,
            'format' => 'mb',
            'include_peak' => true,
        ],
        'git_commit' => [
            'enabled' => true,
            'format' => 'short',
        ],
        'environment_info' => [
            'enabled' => true,
            'fields' => [
                'app_env',
                'php_version',
                'server_name',
            ],
        ],
    ],
];