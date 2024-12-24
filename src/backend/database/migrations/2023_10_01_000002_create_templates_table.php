<?php

// illuminate/database ^10.0
use Illuminate\Database\Migrations\Migration;
// illuminate/support ^10.0
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateTemplatesTable extends Migration
{
    /**
     * Run the migrations to create the templates table.
     * 
     * This table stores notification templates with support for multiple channels
     * (email, sms, push) and uses JSONB for flexible content storage.
     * 
     * Schema includes:
     * - UUID primary key for distributed systems compatibility
     * - Template name and type combination for unique identification
     * - JSONB content storage for flexible template structure
     * - Active status flag for template management
     * - Timestamp fields for auditing
     * 
     * Indexes are optimized for:
     * - Unique template lookup by name and type
     * - Filtering by active status
     * - Channel-specific queries
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            // Primary key using UUID for distributed systems compatibility
            $table->uuid('id')->primary();
            
            // Template identification and categorization
            $table->string('name');
            $table->string('type');
            
            // Template content using JSONB for flexible structure
            $table->jsonb('content');
            
            // Template status flag
            $table->boolean('active')->default(true);
            
            // Timestamp fields for record tracking
            $table->timestamps();

            // Unique constraint on name and type combination
            $table->unique(['name', 'type'], 'templates_name_type_unique');
        });

        // Add check constraint for valid template types
        DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_type_check 
            CHECK (type IN ('email', 'sms', 'push'))");

        // Create index for active status filtering
        DB::statement('CREATE INDEX templates_active_index ON templates USING btree (active)');

        // Create index for channel-specific queries
        DB::statement('CREATE INDEX templates_type_index ON templates USING btree (type)');
    }

    /**
     * Reverse the migrations.
     * 
     * Drops the templates table and all associated indexes and constraints.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
}