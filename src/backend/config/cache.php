<?php

declare(strict_types=1);

/**
 * Cache Configuration
 * 
 * Defines Redis ElastiCache settings for high-performance template and user preference caching
 * with clustering and failover support. Implements sophisticated memory management and 
 * connection handling for optimal performance.
 * 
 * @version 1.0.0
 * @package NotificationService\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache connection that gets used while
    | using this caching library. This connection is used when another is
    | not explicitly specified when executing a given caching function.
    |
    */
    'default' => env('CACHE_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    */
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'cluster' => true,
            'prefix' => 'notification_cache',

            // Cache TTL: 1 hour (3600 seconds)
            'ttl' => 3600,

            // Memory Management
            'max_memory' => '512MB',
            'eviction_policy' => 'volatile-lru',

            // Cluster Configuration
            'endpoints' => [
                'primary' => [
                    'host' => env('REDIS_HOST', 'localhost'),
                    'port' => env('REDIS_PORT', 6379),
                    'password' => env('REDIS_PASSWORD', null),
                    'database' => 0,
                    'timeout' => 2.0,
                    'retry_interval' => 100,
                    'read_timeout' => 2.0,
                ],
                'replicas' => [
                    'auto_discovery' => true,
                    'max_replicas' => 2,
                ],
            ],

            // Advanced Options
            'options' => [
                'cluster' => true,
                'parameters' => [
                    // TCP Keepalive settings for stable connections
                    'tcp_keepalive' => true,
                    'tcp_keepidle' => 60,
                    'tcp_keepintvl' => 30,
                    'tcp_keepcnt' => 3,
                ],
            ],
        ],

        // Array cache for local development and testing
        'array' => [
            'driver' => 'array',
            'serialize' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing a RAM based store such as Redis, it's helpful to prefix
    | your cache keys to avoid collisions with other applications using
    | the same cache servers. This value will be prepended to all keys.
    |
    */
    'prefix' => env(
        'CACHE_PREFIX',
        'notification_service_cache'
    ),

    /*
    |--------------------------------------------------------------------------
    | Cache Tags Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix used for cache tags to avoid collisions and provide clear
    | identification of cached items when using Redis cluster.
    |
    */
    'tags_prefix' => 'tags:notification:',

    /*
    |--------------------------------------------------------------------------
    | Cache Lock Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for distributed locks when using Redis cluster to prevent
    | race conditions and ensure data consistency across nodes.
    |
    */
    'lock' => [
        'enable' => true,
        'timeout' => 5,
        'retry_after' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Serialization
    |--------------------------------------------------------------------------
    |
    | Configure serialization settings for cache values to ensure proper
    | handling of complex data structures in Redis.
    |
    */
    'serialize' => [
        'enable' => true,
        'method' => 'igbinary', // Faster and more efficient than PHP serialization
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Metrics
    |--------------------------------------------------------------------------
    |
    | Settings for cache monitoring and performance metrics collection to
    | track cache efficiency and identify potential issues.
    |
    */
    'monitoring' => [
        'enable_metrics' => true,
        'sample_rate' => 0.1, // 10% sampling for performance metrics
        'slow_query_threshold' => 100, // milliseconds
    ],
];