<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove planned_month_date from project_activities table since it's redundant.
     * The date can be retrieved via baseline_schedule_id foreign key relationship.
     * This enforces single source of truth and prevents sync issues.
     */
    public function up(): void
    {
        Schema::table('project_activities', function (Blueprint $table) {
            // Drop the index first
            $table->dropIndex(['project_id', 'planned_month_date']);
            // Drop the column
            $table->dropColumn('planned_month_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_activities', function (Blueprint $table) {
            // Restore the column
            $table->date('planned_month_date')->nullable()->after('description');
            // Restore the index
            $table->index(['project_id', 'planned_month_date']);
        });
    }
};
