<?php

/**
 * Service Provider Configuration
 * 
 * This file defines configurations for external vendor integrations including:
 * - Email providers (Iterable, SendGrid, SES)
 * - SMS providers (Telnyx, Twilio)
 * - Push notification services (AWS SNS)
 * 
 * Features:
 * - Multi-channel support
 * - Load balancing with weighted distribution
 * - Automatic failover handling
 * - Health check monitoring
 * - Retry policies
 * 
 * @version 1.0.0
 */

// Global timeout for vendor API calls (in seconds)
define('VENDOR_TIMEOUT', env('VENDOR_TIMEOUT', 5));

// Health check interval for vendor status monitoring (in seconds)
define('HEALTH_CHECK_INTERVAL', env('HEALTH_CHECK_INTERVAL', 30));

return [
    /*
    |--------------------------------------------------------------------------
    | Email Service Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for email delivery services with failover support.
    | Priority order: Iterable -> SendGrid -> Amazon SES
    |
    */
    'email' => [
        'iterable' => [
            'api_key' => env('ITERABLE_API_KEY'),
            'api_endpoint' => env('ITERABLE_API_ENDPOINT'),
            'timeout' => VENDOR_TIMEOUT,
            'weight' => 40, // 40% of traffic
            'health_check_interval' => HEALTH_CHECK_INTERVAL,
            'retry_attempts' => 3,
            'retry_delay' => 2, // seconds
            'priority' => 1, // Primary provider
        ],
        'sendgrid' => [
            'api_key' => env('SENDGRID_API_KEY'),
            'api_version' => 'v3',
            'timeout' => VENDOR_TIMEOUT,
            'weight' => 40, // 40% of traffic
            'health_check_interval' => HEALTH_CHECK_INTERVAL,
            'retry_attempts' => 3,
            'retry_delay' => 2, // seconds
            'priority' => 2, // Secondary provider
        ],
        'ses' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'timeout' => VENDOR_TIMEOUT,
            'weight' => 20, // 20% of traffic
            'health_check_interval' => HEALTH_CHECK_INTERVAL,
            'retry_attempts' => 3,
            'retry_delay' => 2, // seconds
            'priority' => 3, // Tertiary provider
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Service Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for SMS delivery services with failover support.
    | Priority order: Telnyx -> Twilio
    |
    */
    'sms' => [
        'telnyx' => [
            'api_key' => env('TELNYX_API_KEY'),
            'timeout' => VENDOR_TIMEOUT,
            'weight' => 60, // 60% of traffic
            'health_check_interval' => HEALTH_CHECK_INTERVAL,
            'retry_attempts' => 3,
            'retry_delay' => 2, // seconds
            'priority' => 1, // Primary provider
        ],
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'timeout' => VENDOR_TIMEOUT,
            'weight' => 40, // 40% of traffic
            'health_check_interval' => HEALTH_CHECK_INTERVAL,
            'retry_attempts' => 3,
            'retry_delay' => 2, // seconds
            'priority' => 2, // Secondary provider
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Notification Services
    |--------------------------------------------------------------------------
    |
    | Configuration for push notification delivery via AWS SNS.
    | Supports both iOS and Android platforms.
    |
    */
    'push' => [
        'sns' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'timeout' => VENDOR_TIMEOUT,
            'health_check_interval' => HEALTH_CHECK_INTERVAL,
            'platform_applications' => [
                'ios' => [
                    'arn' => env('AWS_SNS_IOS_ARN'),
                    'sandbox_arn' => env('AWS_SNS_IOS_SANDBOX_ARN'),
                ],
                'android' => [
                    'arn' => env('AWS_SNS_ANDROID_ARN'),
                ],
            ],
            'retry_attempts' => 3,
            'retry_delay' => 1, // seconds
            'priority' => 1,
        ],
    ],
];