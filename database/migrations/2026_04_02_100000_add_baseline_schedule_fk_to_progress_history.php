<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CRITICAL FIX: Add baseline_schedule_id foreign key to project_progress_history
     * 
     * Issue: When deleting a baseline schedule (month target), progress history records
     * were orphaned because they had no FK relationship to the schedule. They were only
     * linked via month_label string (soft link), not enforced by database.
     * 
     * Result: When creating a new schedule for the same month, old progress records
     * showed up because queries used month_label matching.
     * 
     * Solution: Add FK with CASCADE DELETE to ensure complete cleanup
     */
    public function up(): void
    {
        Schema::table('project_progress_history', function (Blueprint $table) {
            // Add foreign key to baseline schedule (s_curve_plan table)
            // WITH CASCADE DELETE to ensure orphaned records are removed
            if (!Schema::hasColumn('project_progress_history', 'baseline_schedule_id')) {
                $table->foreignId('baseline_schedule_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('s_curve_plan')
                    ->onDelete('cascade');
                
                // Add index for efficient lookups by baseline schedule
                $table->index(['project_id', 'baseline_schedule_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_progress_history', function (Blueprint $table) {
            // Drop index first
            $table->dropIndex(['project_id', 'baseline_schedule_id']);
            
            // Drop foreign key and column together
            $table->dropConstrainedForeignId('baseline_schedule_id');
        });
    }
};
