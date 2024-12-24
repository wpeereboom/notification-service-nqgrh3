<?php

/**
 * Queue Configuration
 * 
 * Defines comprehensive queue settings for the notification service using AWS SQS
 * Optimized for handling 100,000+ messages per minute with robust retry policies
 * 
 * @version 1.0.0
 * @package NotificationService
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports a variety of backends via a unified API.
    | Here we set the default queue connection to AWS SQS for the notification
    | service.
    |
    */
    'default' => env('QUEUE_CONNECTION', 'sqs'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Configure the queue connections, specifically AWS SQS settings optimized
    | for high throughput and reliability with dead-letter queue support.
    |
    */
    'connections' => [
        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('QUEUE_PREFIX', 'notification-service'),
            'queue' => env('SQS_QUEUE', 'default'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            
            // Optimized visibility timeout for processing high-volume messages
            'visibility_timeout' => 30,
            
            // Enable long polling to reduce empty responses
            'wait_time' => 5,
            
            // Dead Letter Queue configuration for failed messages
            'dead_letter' => [
                'enabled' => true,
                'max_attempts' => 3,
                'queue' => env('SQS_DLQ', 'notification-service-dlq'),
            ],
            
            // Additional SQS-specific options
            'options' => [
                'MessageRetentionPeriod' => 345600, // 4 days
                'ReceiveMessageWaitTimeSeconds' => 5,
                'MaximumMessageSize' => 262144, // 256 KB
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue worker settings optimized for processing high volumes
    | of notification messages efficiently.
    |
    */
    'workers' => [
        // Maximum number of jobs a worker should process
        'max_jobs' => env('QUEUE_WORKER_MAX_JOBS', 1000),
        
        // Memory limit per worker in MB
        'memory_limit' => env('QUEUE_WORKER_MEMORY_LIMIT', 512),
        
        // Maximum execution time for a job in seconds
        'timeout' => env('QUEUE_WORKER_TIMEOUT', 30),
        
        // Sleep duration when no jobs are available
        'sleep' => env('QUEUE_WORKER_SLEEP', 2),
        
        // Maximum attempts before job is considered failed
        'max_attempts' => env('QUEUE_WORKER_MAX_ATTEMPTS', 3),
        
        // Force stop worker after timeout
        'force_stop_after_timeout' => true,
        
        // Enable supervisor monitoring
        'supervisor' => [
            'enabled' => true,
            'processes' => env('QUEUE_WORKER_PROCESSES', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Message Batching Configuration
    |--------------------------------------------------------------------------
    |
    | Configure message batching for optimal throughput when processing
    | large volumes of notifications.
    |
    */
    'batching' => [
        // Enable batch processing
        'enabled' => true,
        
        // Maximum batch size
        'size' => env('QUEUE_BATCH_SIZE', 10),
        
        // Maximum wait time for batch completion
        'wait_time' => env('QUEUE_BATCH_WAIT', 5),
        
        // Auto-flush incomplete batches
        'auto_flush' => true,
        
        // Batch processing options
        'options' => [
            'max_execution_time' => 30,
            'memory_threshold' => 384, // MB
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Policy Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry policies with exponential backoff for handling
    | transient failures.
    |
    */
    'retry' => [
        // Enable retry mechanism
        'enabled' => true,
        
        // Maximum retry attempts
        'max_attempts' => env('QUEUE_RETRY_MAX_ATTEMPTS', 3),
        
        // Initial delay in seconds
        'initial_delay' => env('QUEUE_RETRY_INITIAL_DELAY', 5),
        
        // Delay multiplier for exponential backoff
        'multiplier' => env('QUEUE_RETRY_MULTIPLIER', 2),
        
        // Maximum delay between retries in seconds
        'max_delay' => env('QUEUE_RETRY_MAX_DELAY', 60),
        
        // Retry specific error types
        'retry_on' => [
            'Aws\Sqs\Exception\SqsException',
            'ErrorException',
            'RuntimeException',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Logging
    |--------------------------------------------------------------------------
    |
    | Configure queue monitoring and logging settings for observability.
    |
    */
    'monitoring' => [
        // Enable detailed queue monitoring
        'enabled' => true,
        
        // Metrics collection interval in seconds
        'interval' => env('QUEUE_MONITOR_INTERVAL', 60),
        
        // Enable queue metrics export to CloudWatch
        'cloudwatch' => [
            'enabled' => true,
            'namespace' => 'NotificationService/Queue',
        ],
        
        // Alert thresholds
        'alerts' => [
            'queue_depth_threshold' => env('QUEUE_DEPTH_THRESHOLD', 10000),
            'processing_time_threshold' => env('QUEUE_PROCESSING_TIME_THRESHOLD', 30),
        ],
    ],
];