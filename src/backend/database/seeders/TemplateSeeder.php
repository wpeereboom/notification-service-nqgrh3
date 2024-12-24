<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Template;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Template Seeder
 * 
 * Seeds default notification templates for Email, SMS, and Push channels
 * with proper versioning and vendor-specific metadata.
 */
class TemplateSeeder extends Seeder
{
    /**
     * Email service configurations with version tracking.
     *
     * @var array<string, array>
     */
    private array $emailConfigs = [
        'iterable' => [
            'version' => '1.0.0',
            'projectId' => 'default',
            'templateType' => 'transactional'
        ],
        'sendgrid' => [
            'version' => '2.0.0',
            'category' => 'system',
            'generation' => 'dynamic'
        ],
        'ses' => [
            'version' => '3.0.0',
            'configurationSet' => 'default',
            'templateFormat' => 'html'
        ]
    ];

    /**
     * SMS service configurations.
     *
     * @var array<string, array>
     */
    private array $smsConfigs = [
        'telnyx' => [
            'version' => '1.0.0',
            'messageType' => 'transactional',
            'messagingProfile' => 'default'
        ],
        'twilio' => [
            'version' => '2.0.0',
            'contentType' => 'text',
            'statusCallback' => true
        ]
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        try {
            Log::info('Starting template seeding process');

            // Create Welcome Email Template
            $this->createEmailTemplate(
                'welcome_email',
                'Welcome to Our Service',
                [
                    'subject' => 'Welcome to Our Service, {{name}}!',
                    'html' => '<div><h1>Welcome {{name}}!</h1><p>Thank you for joining our service.</p></div>',
                    'text' => 'Welcome {{name}}! Thank you for joining our service.',
                    'from' => 'notifications@service.com',
                    'replyTo' => 'support@service.com',
                    'metadata' => [
                        'category' => 'onboarding',
                        'priority' => 'high'
                    ]
                ]
            );

            // Create Password Reset Template
            $this->createEmailTemplate(
                'password_reset',
                'Reset Your Password',
                [
                    'subject' => 'Password Reset Request',
                    'html' => '<div><h2>Reset Your Password</h2><p>Click the link to reset: {{reset_link}}</p></div>',
                    'text' => 'Reset your password by clicking: {{reset_link}}',
                    'from' => 'security@service.com',
                    'replyTo' => 'no-reply@service.com',
                    'metadata' => [
                        'category' => 'security',
                        'priority' => 'urgent',
                        'expiry' => '1 hour'
                    ]
                ]
            );

            // Create SMS Verification Template
            $this->createSmsTemplate(
                'sms_verification',
                [
                    'content' => 'Your verification code is: {{code}}. Valid for 5 minutes.',
                    'metadata' => [
                        'type' => 'verification',
                        'ttl' => 300,
                        'priority' => 'high'
                    ]
                ]
            );

            // Create Push Notification Template
            $this->createPushTemplate(
                'push_notification',
                [
                    'title' => '{{title}}',
                    'body' => '{{message}}',
                    'data' => [
                        'action' => '{{action}}',
                        'deepLink' => '{{deep_link}}'
                    ],
                    'metadata' => [
                        'category' => 'general',
                        'badge' => 1,
                        'sound' => 'default'
                    ]
                ]
            );

            Log::info('Template seeding completed successfully');
        } catch (\Exception $e) {
            Log::error('Template seeding failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create an email template with vendor-specific configurations.
     *
     * @param string $name
     * @param string $subject
     * @param array $content
     * @return Template
     */
    private function createEmailTemplate(string $name, string $subject, array $content): Template
    {
        $template = Template::create([
            'name' => $name,
            'type' => 'email',
            'content' => $content,
            'active' => true,
            'version' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'metadata' => [
                'vendors' => [
                    'iterable' => array_merge(
                        $this->emailConfigs['iterable'],
                        ['templateId' => strtolower($name) . '_iterable']
                    ),
                    'sendgrid' => array_merge(
                        $this->emailConfigs['sendgrid'],
                        ['templateId' => strtolower($name) . '_sendgrid']
                    ),
                    'ses' => array_merge(
                        $this->emailConfigs['ses'],
                        ['templateId' => strtolower($name) . '_ses']
                    )
                ]
            ]
        ]);

        Log::info('Email template created', ['name' => $name]);
        return $template;
    }

    /**
     * Create an SMS template with vendor-specific configurations.
     *
     * @param string $name
     * @param array $content
     * @return Template
     */
    private function createSmsTemplate(string $name, array $content): Template
    {
        $template = Template::create([
            'name' => $name,
            'type' => 'sms',
            'content' => $content,
            'active' => true,
            'version' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'metadata' => [
                'vendors' => [
                    'telnyx' => array_merge(
                        $this->smsConfigs['telnyx'],
                        ['senderId' => 'SERVICE']
                    ),
                    'twilio' => array_merge(
                        $this->smsConfigs['twilio'],
                        ['messagingServiceSid' => 'default_service']
                    )
                ]
            ]
        ]);

        Log::info('SMS template created', ['name' => $name]);
        return $template;
    }

    /**
     * Create a push notification template with platform configurations.
     *
     * @param string $name
     * @param array $content
     * @return Template
     */
    private function createPushTemplate(string $name, array $content): Template
    {
        $template = Template::create([
            'name' => $name,
            'type' => 'push',
            'content' => $content,
            'active' => true,
            'version' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'metadata' => [
                'platforms' => [
                    'ios' => [
                        'sound' => 'default',
                        'badge' => 1,
                        'contentAvailable' => true
                    ],
                    'android' => [
                        'sound' => 'default',
                        'priority' => 'high',
                        'channelId' => 'default'
                    ]
                ]
            ]
        ]);

        Log::info('Push template created', ['name' => $name]);
        return $template;
    }
}