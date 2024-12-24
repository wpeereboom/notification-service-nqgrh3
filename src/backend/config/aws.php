<?php

/**
 * AWS Services Configuration
 * 
 * Comprehensive configuration for AWS services used in the Notification Service.
 * Includes settings for credentials, regions, SQS, SES, SNS, and monitoring.
 * 
 * @version 1.0.0
 * @package NotificationService\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | AWS Credentials Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for AWS authentication including IAM role support,
    | credential rotation, and security settings.
    |
    */
    'credentials' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'token' => env('AWS_SESSION_TOKEN'),
        'use_iam_role' => true,
        'rotation_days' => 90,
        'require_encryption' => true,
        'validate_credentials' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Region Configuration
    |--------------------------------------------------------------------------
    |
    | Primary and backup region settings with automatic failover support
    | and health monitoring configuration.
    |
    */
    'region' => [
        'default' => 'us-east-1',
        'backup' => 'us-west-2',
        'auto_switch' => true,
        'health_check_interval' => 30, // seconds
        'failover_threshold' => 2,
        'retry_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | SQS Configuration
    |--------------------------------------------------------------------------
    |
    | Amazon SQS settings for message queue management including
    | DLQ configuration, encryption, and monitoring.
    |
    */
    'sqs' => [
        'version' => '2012-11-05',
        'queue_prefix' => 'notification-service',
        'batch_size' => 10,
        'visibility_timeout' => 30,
        'wait_time' => 5,
        'dlq_enabled' => true,
        'dlq_suffix' => '-dlq',
        'max_receive_count' => 3,
        'message_retention_days' => 14,
        'encryption_enabled' => true,
        'monitoring_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | SES Configuration
    |--------------------------------------------------------------------------
    |
    | Amazon SES settings for email delivery including bounce handling,
    | tracking, and custom headers configuration.
    |
    */
    'ses' => [
        'version' => '2010-12-01',
        'region' => 'us-east-1',
        'from_email' => env('AWS_SES_FROM_EMAIL'),
        'configuration_set' => 'notification-service',
        'max_recipients' => 50,
        'bounce_handling' => true,
        'complaint_handling' => true,
        'feedback_forwarding' => true,
        'tracking_enabled' => true,
        'custom_headers' => [
            'X-Environment' => env('APP_ENV'),
            'X-Mailer' => 'NotificationService',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SNS Configuration
    |--------------------------------------------------------------------------
    |
    | Amazon SNS settings for push notifications including platform applications,
    | delivery retry policies, and message attributes.
    |
    */
    'sns' => [
        'version' => '2010-03-31',
        'platform_applications' => [
            'ios' => env('AWS_SNS_IOS_ARN'),
            'android' => env('AWS_SNS_ANDROID_ARN'),
        ],
        'topic_prefix' => 'notification-service',
        'batch_size' => 100,
        'message_retention_days' => 7,
        'delivery_retry_policy' => [
            'num_retries' => 3,
            'retry_delay' => 60, // seconds
            'max_delay_target' => 300, // seconds
            'num_max_delay_retries' => 2,
        ],
        'message_attributes' => [
            'Priority' => 'High',
            'MessageType' => 'Notification',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | AWS monitoring settings including CloudWatch logs, metrics,
    | X-Ray tracing, and alert thresholds.
    |
    */
    'monitoring' => [
        'enabled' => true,
        'cloudwatch_logs' => true,
        'metrics_enabled' => true,
        'xray_enabled' => true,
        'alarm_sns_topic' => env('AWS_MONITORING_SNS_TOPIC'),
        'log_retention_days' => 30,
        'metric_namespace' => 'NotificationService',
        'alert_thresholds' => [
            'error_rate' => 5, // percentage
            'latency_ms' => 1000,
            'throttle_rate' => 10, // percentage
        ],
    ],
];