<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds calendar-based date fields and variance calculation to PROJECT_PROGRESS_HISTORY.
     * Modifies existing table to support Activity Checklist model (calendar-based, not month_number INT).
     * Adds variance_percent field for planned vs actual comparison.
     */
    public function up(): void
    {
        Schema::table('project_progress_history', function (Blueprint $table) {
            // Add calendar-based month tracking (NEW)
            $table->date('inspection_month_date')->nullable()->after('milestone_id');
            $table->string('month_label')->nullable()->after('inspection_month_date');
            
            // Add variance calculation (NEW)
            $table->decimal('variance_percent', 5, 2)->nullable()->after('progress_percentage');
            
            // Rename progress_percentage to actual_progress_percent for clarity
            // Note: Can't rename column directly, so we add new field and keep old one
            // After data migration, old column will be removed
            $table->decimal('actual_progress_percent', 5, 2)->nullable()->after('variance_percent');
            $table->decimal('planned_progress_percent', 5, 2)->nullable()->after('actual_progress_percent');
            
            // Add foreign key to activities (in addition to milestone_id)
            $table->foreignId('activity_id')->nullable()->constrained('project_activities')->onDelete('cascade')->after('project_id');
            
            // Add month_number for filtering/grouping (NEW)
            $table->integer('month_number')->nullable()->after('planned_progress_percent');
            
            // Add index for new columns
            $table->index(['project_id', 'inspection_month_date']);
            $table->index(['project_id', 'month_label']);
            $table->index('variance_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_progress_history', function (Blueprint $table) {
            // Drop foreign key and indices first
            $table->dropForeignKey(['activity_id']);
            $table->dropIndex(['project_id', 'inspection_month_date']);
            $table->dropIndex(['project_id', 'month_label']);
            $table->dropIndex(['variance_percent']);
            
            // Drop new columns
            $table->dropColumn([
                'inspection_month_date',
                'month_label',
                'variance_percent',
                'actual_progress_percent',
                'planned_progress_percent',
                'activity_id',
                'month_number'
            ]);
        });
    }
};
