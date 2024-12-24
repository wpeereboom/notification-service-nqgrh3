<?php

// illuminate/database ^10.0
use Illuminate\Database\Migrations\Migration;
// illuminate/support ^10.0
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateDeliveryAttemptsTable extends Migration
{
    /**
     * Run the migrations to create the delivery_attempts table.
     * 
     * This table tracks all notification delivery attempts with:
     * - High-throughput support for 100,000+ messages/minute
     * - Comprehensive vendor response tracking
     * - Daily partitioning for efficient querying and retention
     * - Optimized indexes for status monitoring
     * - 30-day retention policy with automated cleanup
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table) {
            // Primary key using UUID for distributed systems
            $table->uuid('id')->primary();
            
            // Reference to parent notification
            $table->uuid('notification_id');
            $table->foreign('notification_id')
                  ->references('id')
                  ->on('notifications')
                  ->onDelete('cascade');
            
            // Delivery attempt details
            $table->string('vendor');
            $table->string('status');
            $table->jsonb('response');
            $table->timestamp('attempted_at');
            
            // Standard timestamps
            $table->timestamps();
        });

        // Add check constraint for valid vendors
        DB::statement("ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_vendor_check 
            CHECK (vendor IN ('iterable', 'sendgrid', 'ses', 'telnyx', 'twilio', 'sns'))");

        // Add check constraint for valid statuses
        DB::statement("ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_status_check 
            CHECK (status IN ('successful', 'failed'))");

        // Create indexes for efficient querying
        DB::statement('CREATE INDEX delivery_attempts_notification_id_attempted_at_idx 
            ON delivery_attempts USING btree (notification_id, attempted_at)');
        DB::statement('CREATE INDEX delivery_attempts_status_vendor_idx 
            ON delivery_attempts USING btree (status, vendor)');
        DB::statement('CREATE INDEX delivery_attempts_attempted_at_idx 
            ON delivery_attempts USING brin (attempted_at)');

        // Implement daily range partitioning on attempted_at
        DB::statement('
            CREATE TABLE delivery_attempts_partition OF delivery_attempts
            PARTITION BY RANGE (attempted_at)
        ');

        // Create initial partitions
        $this->createInitialPartitions();

        // Set up automated partition management
        $this->setupPartitionManagement();
    }

    /**
     * Create initial partitions for the delivery_attempts table.
     *
     * @return void
     */
    private function createInitialPartitions(): void
    {
        $currentDate = now()->startOfDay();
        
        // Create partitions for current day and next 29 days (30-day retention)
        for ($i = 0; $i < 30; $i++) {
            $startDate = $currentDate->copy()->addDays($i)->format('Y-m-d');
            $endDate = $currentDate->copy()->addDays($i + 1)->format('Y-m-d');
            
            DB::statement("
                CREATE TABLE delivery_attempts_y{$currentDate->format('Y')}_d{$currentDate->format('z')}
                PARTITION OF delivery_attempts_partition
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
            CREATE OR REPLACE FUNCTION manage_delivery_attempts_partitions()
            RETURNS void AS $$
            DECLARE
                future_date date;
                partition_date date;
                partition_name text;
                retention_date date;
            BEGIN
                -- Create future partition if needed
                future_date := date_trunc(\'day\', now()) + interval \'30 days\';
                partition_date := date_trunc(\'day\', future_date);
                partition_name := \'delivery_attempts_y\' || to_char(partition_date, \'YYYY\') || \'_d\' || to_char(partition_date, \'DDD\');
                
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_tables
                    WHERE tablename = partition_name
                ) THEN
                    EXECUTE format(
                        \'CREATE TABLE %I PARTITION OF delivery_attempts_partition
                        FOR VALUES FROM (%L) TO (%L)\',
                        partition_name,
                        partition_date,
                        partition_date + interval \'1 day\'
                    );
                END IF;

                -- Drop old partitions beyond retention period
                retention_date := date_trunc(\'day\', now() - interval \'30 days\');
                FOR partition_name IN
                    SELECT tablename
                    FROM pg_tables
                    WHERE tablename LIKE \'delivery_attempts_y%\'
                    AND tablename ~ \'_d[0-9]+$\'
                LOOP
                    IF to_date(substring(partition_name from \'y([0-9]{4})_d([0-9]{3})\'), \'YYYYDDD\') < retention_date THEN
                        EXECUTE format(\'DROP TABLE %I\', partition_name);
                    END IF;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql;
        ');

        // Create scheduled job for partition management
        DB::statement('
            SELECT cron.schedule(\'0 0 * * *\', \'SELECT manage_delivery_attempts_partitions()\');
        ');
    }

    /**
     * Reverse the migrations.
     * 
     * Drops the delivery_attempts table and all associated objects including:
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
        DB::statement('SELECT cron.unschedule(\'manage_delivery_attempts_partitions\');');
        
        // Drop partition management function
        DB::statement('DROP FUNCTION IF EXISTS manage_delivery_attempts_partitions();');
        
        // Drop main table (cascades to partitions)
        Schema::dropIfExists('delivery_attempts');
    }
}