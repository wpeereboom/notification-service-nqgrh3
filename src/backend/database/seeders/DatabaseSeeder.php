<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Main database seeder class for the Notification Service.
 * 
 * Orchestrates the seeding of all initial data required by the notification service,
 * including templates, vendor configurations, and test data for development environments.
 * Ensures proper order of seeding and maintains referential integrity.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * List of seeders to run in order.
     *
     * @var array<class-string>
     */
    protected array $seeders = [
        TemplateSeeder::class,
    ];

    /**
     * Order of tables for truncation to maintain referential integrity.
     *
     * @var array<string>
     */
    private array $truncateOrder = [
        'delivery_attempts',
        'notifications',
        'templates',
        'vendor_configs'
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     * @throws \Exception If seeding fails
     */
    public function run(): void
    {
        try {
            Log::info('Starting database seeding process');

            DB::beginTransaction();

            // Clear existing data in development environments
            if ($this->isDevEnvironment()) {
                $this->truncateTables();
            }

            // Run all seeders in defined order
            foreach ($this->seeders as $seeder) {
                Log::info('Running seeder', ['seeder' => $seeder]);
                $this->call($seeder);
            }

            // Generate test data for development environment
            if ($this->isDevEnvironment()) {
                $this->seedTestData();
            }

            DB::commit();
            Log::info('Database seeding completed successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Database seeding failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Safely truncate all tables in correct order.
     *
     * @return void
     */
    private function truncateTables(): void
    {
        Log::info('Truncating existing data');

        // Disable foreign key checks for clean truncation
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($this->truncateOrder as $table) {
            DB::table($table)->truncate();
            Log::info('Truncated table', ['table' => $table]);
        }

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Check if running in development environment.
     *
     * @return bool
     */
    private function isDevEnvironment(): bool
    {
        $env = Config::get('app.env');
        return in_array($env, ['local', 'development'], true);
    }

    /**
     * Seed test data for development environment.
     *
     * @return void
     */
    private function seedTestData(): void
    {
        Log::info('Seeding test data for development');

        // Create sample notifications
        $notifications = [
            [
                'id' => '550e8400-e29b-41d4-a716-446655440000',
                'type' => 'email',
                'status' => 'delivered',
                'payload' => json_encode([
                    'recipient' => 'test@example.com',
                    'template_id' => 'welcome_email',
                    'context' => ['name' => 'Test User']
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'id' => '550e8400-e29b-41d4-a716-446655440001',
                'type' => 'sms',
                'status' => 'pending',
                'payload' => json_encode([
                    'recipient' => '+1234567890',
                    'template_id' => 'sms_verification',
                    'context' => ['code' => '123456']
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]
        ];

        foreach ($notifications as $notification) {
            DB::table('notifications')->insert($notification);
        }

        // Create sample delivery attempts
        $deliveryAttempts = [
            [
                'id' => '660e8400-e29b-41d4-a716-446655440000',
                'notification_id' => '550e8400-e29b-41d4-a716-446655440000',
                'vendor' => 'iterable',
                'status' => 'success',
                'response' => json_encode(['message_id' => 'msg_123', 'status' => 'delivered']),
                'attempted_at' => Carbon::now()
            ],
            [
                'id' => '660e8400-e29b-41d4-a716-446655440001',
                'notification_id' => '550e8400-e29b-41d4-a716-446655440001',
                'vendor' => 'telnyx',
                'status' => 'pending',
                'response' => json_encode(['message_id' => 'msg_124', 'status' => 'queued']),
                'attempted_at' => Carbon::now()
            ]
        ];

        foreach ($deliveryAttempts as $attempt) {
            DB::table('delivery_attempts')->insert($attempt);
        }

        // Create sample vendor configurations
        $vendorConfigs = [
            [
                'vendor' => 'iterable',
                'status' => 'active',
                'config' => json_encode([
                    'api_key' => 'test_key_iterable',
                    'project_id' => 'test_project',
                    'weight' => 60
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ],
            [
                'vendor' => 'telnyx',
                'status' => 'active',
                'config' => json_encode([
                    'api_key' => 'test_key_telnyx',
                    'messaging_profile_id' => 'test_profile',
                    'weight' => 70
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]
        ];

        foreach ($vendorConfigs as $config) {
            DB::table('vendor_configs')->insert($config);
        }

        Log::info('Test data seeding completed');
    }
}