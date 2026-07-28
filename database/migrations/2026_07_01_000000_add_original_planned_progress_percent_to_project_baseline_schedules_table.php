<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add the new "frozen original plan" column, separate from the live
        // planned_progress_percent column which can change post-extension
        Schema::table('project_baseline_schedules', function (Blueprint $table) {
            $table->decimal('original_planned_progress_percent', 5, 2)
                  ->nullable()
                  ->after('planned_progress_percent');
        });

        // Backfill: every row that already exists predates this feature,
        // so its "original" value IS its current value
        DB::table('project_baseline_schedules')->update([
            'original_planned_progress_percent' => DB::raw('planned_progress_percent')
        ]);
    }

    public function down(): void
    {
        Schema::table('project_baseline_schedules', function (Blueprint $table) {
            $table->dropColumn('original_planned_progress_percent');
        });
    }
};