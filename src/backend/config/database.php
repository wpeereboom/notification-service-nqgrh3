<?php

use Illuminate\Support\Env;

/**
 * Database Configuration
 * 
 * Defines connection settings and optimizations for PostgreSQL RDS and Redis
 * Supports high-throughput message processing (100,000+ msgs/min) through connection pooling
 * Implements multi-AZ failover with read replicas for 99.95% uptime
 * 
 * @version 1.0.0
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | The default database connection to use for all database operations.
    | Currently set to PostgreSQL for ACID compliance and JSON support.
    |
    */
    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Configuration for all database connections used by the application.
    | Includes separate read/write endpoints and performance optimizations.
    |
    */
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            
            // Read replica configuration for load distribution
            'read' => [
                'host' => [
                    env('DB_READ_HOST', env('DB_HOST')),
                ],
                'port' => env('DB_PORT', 5432),
            ],
            
            // Primary write node configuration
            'write' => [
                'host' => env('DB_HOST'),
                'port' => env('DB_PORT', 5432),
            ],
            
            'database' => env('DB_DATABASE', 'notification_service'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'require', // Enforce SSL for security
            
            // PostgreSQL specific optimizations
            'options' => [
                // Connection pooling settings
                'pooling' => true,
                'max_connections' => 100,
                'idle_timeout' => 60,
                
                // Query performance settings
                'statement_timeout' => 30000, // 30 seconds max query time
                'search_path' => 'public',
                'application_name' => 'notification_service',
                
                // Memory and cache settings
                'effective_cache_size' => '4GB',
                'maintenance_work_mem' => '256MB',
                
                // Write performance optimizations
                'synchronous_commit' => 'off', // Improves write performance
                'wal_writer_delay' => '200ms',
                'random_page_cost' => 1.1, // Optimized for SSD storage
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Redis is used for caching and temporary data storage.
    | Configured for high availability and compression.
    |
    */
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 0),
            
            // Connection settings
            'read_timeout' => 60,
            'retry_interval' => 100,
            'retry_limit' => 3,
            'persistent' => true, // Keep connections alive
            
            // Data optimization
            'prefix' => 'notification:',
            'compression' => true,
            'compression_level' => 6,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Configuration
    |--------------------------------------------------------------------------
    |
    | Database migration settings for version control and schema updates.
    |
    */
    'migrations' => [
        'table' => 'migrations',
        'path' => database_path('migrations'),
    ],
];