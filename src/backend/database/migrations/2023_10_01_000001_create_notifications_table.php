<?php

// illuminate/database ^10.0
use Illuminate\Database\Migrations\Migration;
// illuminate/support ^10.0
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateNotificationsTable extends Migration
{
    /**
     * Run the migrations to create the notifications table.
     * 
     * This table serves as the core storage for all notification records with:
     * - High-throughput support (100,000+ messages/minute)
     * - Multi-channel delivery (email, sms, push, chat)
     * - Efficient partitioning and indexing strategies
     * - 90-day retention policy with automated cleanup
     * 
     * Schema includes:
     * - UUID primary key for distributed systems compatibility
     * - JSONB payload for flexible notification content
     * - Status tracking with constrained values
     * - Channel type with constrained values
     * - Template reference for content management
     * - Timestamp fields for partitioning and retention
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            // Primary key using UUID for distributed systems
            $table->uuid('id')->primary();
            
            // Notification classification and routing
            $table->string('type');
            $table->string('channel');
            
            // Notification content and state
            $table->jsonb('payload');
            $table->string('status');
            
            // Template reference
            $table->uuid('template_id');
            $table->foreign('template_id')
                  ->references('id')
                  ->on('templates')
                  ->restrictOnDelete();
            
            // Timestamp fields for partitioning and tracking
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });

        // Add check constraint for valid notification statuses
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_status_check 
            CHECK (status IN ('pending', 'processing', 'delivered', 'failed'))");

        // Add check constraint for valid channels
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check 
            CHECK (channel IN ('email', 'sms', 'push', 'chat'))");

        // Create indexes for efficient querying
        DB::statement('CREATE INDEX notifications_status_type_index ON notifications USING btree (status, type)');
        DB::statement('CREATE INDEX notifications_template_id_index ON notifications USING btree (template_id)');
        DB::statement('CREATE INDEX notifications_created_at_index ON notifications USING brin (created_at)');

        // Implement monthly range partitioning on created_at
        DB::statement('
            CREATE TABLE notifications_partition OF notifications
            PARTITION BY RANGE (created_at)
        ');

        // Create initial partitions for 3 months (90-day retention)
        $this->createInitialPartitions();

        // Set up automated partition management
        $this->setupPartitionManagement();
    }

    /**
     * Create initial partitions for the notifications table.
     *
     * @return void
     */
    private function createInitialPartitions(): void
    {
        $currentMonth = now()->startOfMonth();
        
        // Create partitions for current month and next 2 months
        for ($i = 0; $i < 3; $i++) {
            $startDate = $currentMonth->copy()->addMonths($i)->format('Y-m-d');
            $endDate = $currentMonth->copy()->addMonths($i + 1)->format('Y-m-d');
            
            DB::statement("
                CREATE TABLE notifications_y{$currentMonth->format('Y')}_m{$currentMonth->format('m')}
                PARTITION OF notifications_partition
                FOR VALUES FROM ('$startDate') TO ('$endDate')
            ");
        }
    }

    /**
     * Set up automated partition management for retention policy.
     *
     * @return void
     */
    private function setupPartitionManagement(): void
    {
        // Create function to manage partitions
        DB::statement('
            CREATE OR REPLACE FUNCTION manage_notifications_partitions()
            RETURNS void AS $$
            DECLARE
                future_date date;
                partition_date date;
                partition_name text;
                retention_date date;
            BEGIN
                -- Create future partition if needed
                future_date := date_trunc(\'month\', now()) + interval \'3 months\';
                partition_date := date_trunc(\'month\', future_date);
                partition_name := \'notifications_y\' || to_char(partition_date, \'YYYY\') || \'_m\' || to_char(partition_date, \'MM\');
                
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_tables
                    WHERE tablename = partition_name
                ) THEN
                    EXECUTE format(
                        \'CREATE TABLE %I PARTITION OF notifications_partition
                        FOR VALUES FROM (%L) TO (%L)\',
                        partition_name,
                        partition_date,
                        partition_date + interval \'1 month\'
                    );
                END IF;

                -- Drop old partitions beyond retention period
                retention_date := date_trunc(\'month\', now() - interval \'90 days\');
                FOR partition_name IN
                    SELECT tablename
                    FROM pg_tables
                    WHERE tablename LIKE \'notifications_y%\'
                    AND tablename ~ \'_m[0-9]+$\'
                LOOP
                    IF to_date(substring(partition_name from \'y([0-9]{4})_m([0-9]{2})\'), \'YYYYMM\') < retention_date THEN
                        EXECUTE format(\'DROP TABLE %I\', partition_name);
                    END IF;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql;
        ');

        // Create scheduled job for partition management
        DB::statement('
            SELECT cron.schedule(\'0 0 * * *\', \'SELECT manage_notifications_partitions()\');
        ');
    }

    /**
     * Reverse the migrations.
     * 
     * Drops the notifications table and all associated objects including:
     * - Partitions
     * - Indexes
     * - Constraints
     * - Partition management function
     *
     * @return void
     */
    public function down(): void
    {
        // Remove partition management job
        DB::statement('SELECT cron.unschedule(\'manage_notifications_partitions\');');
        
        // Drop partition management function
        DB::statement('DROP FUNCTION IF EXISTS manage_notifications_partitions();');
        
        // Drop main table (cascades to partitions)
        Schema::dropIfExists('notifications');
    }
}