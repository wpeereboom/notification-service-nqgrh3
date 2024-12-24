<?php

declare(strict_types=1);

/**
 * Vendor Configuration File
 * 
 * Defines comprehensive settings for multi-vendor notification delivery system including
 * email, SMS, and push notification providers with failover and routing logic.
 * 
 * @version 1.0.0
 */

// Global timeout settings (in seconds)
const VENDOR_TIMEOUT = 5;
const HEALTH_CHECK_INTERVAL = 30;
const FAILOVER_THRESHOLD = 2;

return [
    'email' => [
        'providers' => [
            [
                'name' => 'iterable',
                'service' => 'IterableService',
                'priority' => 1,
                'weight' => 40,
                'api_version' => 'v1',
                'timeout' => VENDOR_TIMEOUT,
                'retry_attempts' => 3,
                'circuit_breaker' => [
                    'failure_threshold' => 5,
                    'reset_timeout' => 30,
                ],
                'endpoints' => [
                    'base_url' => 'https://api.iterable.com/api',
                    'health_check' => '/health',
                    'send' => '/email/send',
                ],
                'rate_limits' => [
                    'requests_per_second' => 500,
                    'burst_size' => 1000,
                ],
            ],
            [
                'name' => 'sendgrid',
                'service' => 'SendGridService',
                'priority' => 2,
                'weight' => 60,
                'api_version' => 'v3',
                'timeout' => VENDOR_TIMEOUT,
                'retry_attempts' => 3,
                'circuit_breaker' => [
                    'failure_threshold' => 5,
                    'reset_timeout' => 30,
                ],
                'endpoints' => [
                    'base_url' => 'https://api.sendgrid.com/v3',
                    'health_check' => '/health',
                    'send' => '/mail/send',
                ],
                'rate_limits' => [
                    'requests_per_second' => 600,
                    'burst_size' => 1200,
                ],
            ],
        ],
        'failover' => [
            'enabled' => true,
            'threshold_seconds' => FAILOVER_THRESHOLD,
            'health_check_interval' => HEALTH_CHECK_INTERVAL,
            'strategy' => 'priority', // Options: priority, round-robin
        ],
        'routing' => [
            'default_provider' => 'iterable',
            'rules' => [
                'high_priority' => [
                    'condition' => 'priority === "high"',
                    'provider' => 'sendgrid',
                ],
                'bulk_mail' => [
                    'condition' => 'type === "bulk"',
                    'provider' => 'iterable',
                ],
            ],
        ],
    ],

    'sms' => [
        'providers' => [
            [
                'name' => 'telnyx',
                'service' => 'TelnyxService',
                'priority' => 1,
                'weight' => 100,
                'api_version' => 'v2',
                'timeout' => VENDOR_TIMEOUT,
                'retry_attempts' => 3,
                'circuit_breaker' => [
                    'failure_threshold' => 5,
                    'reset_timeout' => 30,
                ],
                'endpoints' => [
                    'base_url' => 'https://api.telnyx.com/v2',
                    'health_check' => '/health',
                    'send' => '/messages',
                ],
                'rate_limits' => [
                    'requests_per_second' => 100,
                    'burst_size' => 200,
                ],
                'message_options' => [
                    'max_length' => 1600,
                    'concatenation' => true,
                    'unicode_support' => true,
                ],
            ],
        ],
        'failover' => [
            'enabled' => true,
            'threshold_seconds' => FAILOVER_THRESHOLD,
            'health_check_interval' => HEALTH_CHECK_INTERVAL,
            'strategy' => 'priority',
        ],
        'routing' => [
            'default_provider' => 'telnyx',
            'rules' => [
                'emergency' => [
                    'condition' => 'priority === "emergency"',
                    'provider' => 'telnyx',
                    'options' => [
                        'retry_attempts' => 5,
                        'timeout' => 3,
                    ],
                ],
            ],
        ],
    ],

    'push' => [
        'providers' => [
            [
                'name' => 'sns',
                'service' => 'SnsService',
                'priority' => 1,
                'weight' => 100,
                'region' => 'us-east-1',
                'timeout' => VENDOR_TIMEOUT,
                'retry_attempts' => 3,
                'circuit_breaker' => [
                    'failure_threshold' => 5,
                    'reset_timeout' => 30,
                ],
                'options' => [
                    'message_structure' => 'json',
                    'delivery_policy' => [
                        'numRetries' => 3,
                        'retryDelaySeconds' => 1,
                        'maxDelaySeconds' => 5,
                        'numMaxDelayRetries' => 3,
                    ],
                ],
                'rate_limits' => [
                    'requests_per_second' => 300,
                    'burst_size' => 600,
                ],
            ],
        ],
        'failover' => [
            'enabled' => false,
        ],
        'routing' => [
            'default_provider' => 'sns',
            'rules' => [
                'high_priority' => [
                    'condition' => 'priority === "high"',
                    'provider' => 'sns',
                    'options' => [
                        'delivery_policy' => [
                            'numRetries' => 5,
                            'retryDelaySeconds' => 0.5,
                        ],
                    ],
                ],
            ],
        ],
    ],

    'global' => [
        'logging' => [
            'enabled' => true,
            'level' => 'info',
            'channels' => ['cloudwatch', 'stderr'],
        ],
        'monitoring' => [
            'health_checks' => [
                'enabled' => true,
                'interval' => HEALTH_CHECK_INTERVAL,
                'timeout' => 5,
            ],
            'metrics' => [
                'enabled' => true,
                'namespace' => 'NotificationService',
                'dimensions' => ['Environment', 'Provider', 'Channel'],
            ],
        ],
        'performance' => [
            'timeout_buffer' => 1, // Additional seconds added to provider timeout
            'concurrent_requests' => 50,
            'batch_size' => 100,
        ],
    ],
];