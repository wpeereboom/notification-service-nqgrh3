<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for creating the vendor_status table that tracks health and performance metrics
 * for notification service providers (Email, SMS, Push vendors).
 * 
 * This table supports:
 * - Vendor health monitoring with 30s check intervals
 * - Success rate tracking for failover decisions
 * - Performance metrics for maintaining 99.95% system uptime
 * 
 * @version 1.0.0
 * @see \App\Models\VendorStatus
 */
class CreateVendorStatusTable extends Migration
{
    /**
     * Run the migrations.
     * Creates the vendor_status table with comprehensive structure for vendor health monitoring.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('vendor_status', function (Blueprint $table) {
            // Primary identifier using UUID for distributed systems compatibility
            $table->uuid('id')->primary();
            
            // Vendor identification with uniqueness constraint
            $table->string('vendor', 255)
                  ->unique()
                  ->comment('Unique identifier for the notification service provider');
            
            // Health status with enumerated values and default
            $table->string('status', 20)
                  ->default('healthy')
                  ->comment('Current health status of the vendor');
            
            // Success rate with precision and range constraints
            $table->decimal('success_rate', 5, 2)
                  ->default(100.00)
                  ->comment('Rolling success rate percentage for notifications');
            
            // Timestamp for last health check
            $table->timestamp('last_check')
                  ->useCurrent()
                  ->comment('Timestamp of the last vendor health check');
            
            // Standard timestamps for record tracking
            $table->timestamps();

            // Add status check constraint
            $table->rawIndex(
                "(status IN ('healthy', 'degraded', 'unhealthy'))",
                'vendor_status_status_check'
            );

            // Add success rate range constraint
            $table->rawIndex(
                "(success_rate BETWEEN 0.00 AND 100.00)",
                'vendor_status_success_rate_check'
            );

            // Composite index for vendor failover queries
            $table->index(['vendor', 'status'], 'vendor_status_vendor_status_idx');
            
            // BRIN index for time-range queries on last_check
            $table->rawIndex(
                "USING BRIN (last_check)",
                'vendor_status_last_check_idx'
            );
            
            // Index for success rate queries
            $table->index(['success_rate'], 'vendor_status_success_rate_idx');
            
            // Add comment to the table
            $table->comment(
                'Tracks health status and performance metrics for notification service providers'
            );
        });
    }

    /**
     * Reverse the migrations.
     * Drops the vendor_status table and all associated indexes.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_status');
    }
}