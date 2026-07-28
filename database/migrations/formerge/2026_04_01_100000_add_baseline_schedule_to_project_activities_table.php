<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds baseline_schedule_id foreign key column to project_activities table
     * to link activities to specific baseline schedules (months).
     * This allows activities to be organized by month within the s_curve_plan.
     */
    public function up(): void
    {
        Schema::table('project_activities', function (Blueprint $table) {
            // Add foreign key to s_curve_plan (baseline schedule) if it doesn't exist
            if (!Schema::hasColumn('project_activities', 'baseline_schedule_id')) {
                $table->foreignId('baseline_schedule_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('s_curve_plan')
                    ->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_activities', function (Blueprint $table) {
            $table->dropForeignKey(['baseline_schedule_id']);
            $table->dropColumn(['baseline_schedule_id']);
        });
    }
};
