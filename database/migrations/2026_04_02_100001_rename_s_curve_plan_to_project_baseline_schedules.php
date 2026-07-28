<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rename s_curve_plan table to project_baseline_schedules
        // This standardizes the naming convention: project_* tables use snake_case plural
        Schema::rename('s_curve_plan', 'project_baseline_schedules');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback: rename back to original name
        Schema::rename('project_baseline_schedules', 's_curve_plan');
    }
};
