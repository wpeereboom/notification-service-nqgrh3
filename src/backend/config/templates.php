<?php

declare(strict_types=1);

/**
 * Template Management System Configuration
 * 
 * This configuration file defines all settings for the notification service's
 * template management system including caching, storage, channel-specific configs,
 * validation rules, rendering options and defaults.
 * 
 * @version 1.0.0
 * @package Notification\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Template caching settings using Redis for high-performance template retrieval
    | and rendering. Includes TTL, prefix settings, and cache tags for efficient
    | cache management and invalidation.
    |
    */
    'cache' => [
        'enabled' => true,
        'ttl' => 3600, // 1 hour in seconds
        'prefix' => 'template:',
        'tags' => ['templates', 'notifications'],
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_timeout' => 30, // seconds
        'retry_after' => 60, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Database storage settings for templates using PostgreSQL. Includes connection
    | pooling, timeout settings, and retry logic for reliable template persistence.
    |
    */
    'storage' => [
        'driver' => 'database',
        'table' => 'templates',
        'connection' => 'pgsql',
        'pool' => [
            'min' => 2,
            'max' => 10,
        ],
        'timeout' => 5, // seconds
        'retry_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel-Specific Configurations
    |--------------------------------------------------------------------------
    |
    | Settings for each supported notification channel (email, SMS, push) including
    | allowed content types, size limits, variable support, and security settings.
    |
    */
    'channels' => [
        'email' => [
            'allowed_types' => ['html', 'text'],
            'default_type' => 'html',
            'max_size' => 102400, // 100 KB
            'allowed_variables' => ['user', 'content', 'metadata', 'tracking'],
            'sanitization' => [
                'html' => true,
                'css' => true,
                'scripts' => false,
            ],
            'attachments' => [
                'enabled' => true,
                'max_size' => 10485760, // 10 MB
                'allowed_types' => ['pdf', 'jpg', 'png'],
            ],
        ],
        'sms' => [
            'allowed_types' => ['text'],
            'default_type' => 'text',
            'max_size' => 1600, // Standard SMS character limit
            'allowed_variables' => ['user', 'content'],
            'unicode' => true,
            'segmentation' => true,
        ],
        'push' => [
            'allowed_types' => ['json'],
            'default_type' => 'json',
            'max_size' => 4096, // 4 KB
            'allowed_variables' => ['title', 'body', 'data', 'badge', 'sound'],
            'payload_encryption' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Template validation configuration including naming rules, content validation,
    | variable syntax, and security measures for template processing.
    |
    */
    'validation' => [
        'name' => [
            'required' => true,
            'min_length' => 3,
            'max_length' => 100,
            'pattern' => '^[a-zA-Z0-9_-]+$',
            'unique' => true,
        ],
        'content' => [
            'required' => true,
            'min_length' => 1,
            'sanitize' => true,
            'encoding' => 'UTF-8',
            'normalize' => true,
        ],
        'variables' => [
            'syntax' => '{{variable}}',
            'escape_html' => true,
            'strict_parsing' => true,
            'undefined_check' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rendering Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Template rendering settings using Twig engine, including caching, debugging,
    | sandbox settings, and optimization options for template compilation.
    |
    */
    'rendering' => [
        'engine' => 'twig',
        'cache' => true,
        'debug' => false,
        'auto_reload' => true,
        'strict_variables' => true,
        'sandbox' => [
            'enabled' => true,
            'allowed_tags' => ['if', 'for', 'set'],
            'allowed_filters' => ['escape', 'date', 'format'],
        ],
        'optimization' => [
            'compilation' => true,
            'inline' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Template Settings
    |--------------------------------------------------------------------------
    |
    | Default values for new templates including status, type, format, versioning,
    | and metadata fields.
    |
    */
    'defaults' => [
        'active' => true,
        'type' => 'email',
        'format' => 'html',
        'version' => 1,
        'language' => 'en',
        'metadata' => [
            'created_by' => 'system',
            'department' => 'notifications',
        ],
    ],
];