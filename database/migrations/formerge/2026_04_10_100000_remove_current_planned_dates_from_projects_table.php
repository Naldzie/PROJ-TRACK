<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Removes unused current_planned_* columns from projects table.
     * Adds missing original_planned_start_date for symmetry.
     * Design decision: Use planned_start/end_date as mutable fields (current state)
     * and original_planned_start/end_date as immutable reference.
     * No need for separate current_planned_* fields.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Add original_planned_start_date if not exists
            if (!Schema::hasColumn('projects', 'original_planned_start_date')) {
                $table->date('original_planned_start_date')->nullable()->after('planned_end_date');
            }
            
            // Drop unused current_planned_* columns (only if they exist)
            $columnsToDrops = [];
            if (Schema::hasColumn('projects', 'current_planned_start_date')) {
                $columnsToDrops[] = 'current_planned_start_date';
            }
            if (Schema::hasColumn('projects', 'current_planned_end_date')) {
                $columnsToDrops[] = 'current_planned_end_date';
            }
            
            if (!empty($columnsToDrops)) {
                $table->dropColumn($columnsToDrops);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('current_planned_start_date')->nullable()->after('original_planned_end_date');
            $table->date('current_planned_end_date')->nullable()->after('current_planned_start_date');
        });
    }
};
