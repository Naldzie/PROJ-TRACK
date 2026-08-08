<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Freeze the original target date for rows that were part of the
        // originally approved plan, mirroring original_planned_progress_percent.
        // Manual edits/adjustments may move planned_date; reverting an extension
        // restores it to this frozen value.
        Schema::table('project_baseline_schedules', function (Blueprint $table) {
            $table->date('original_planned_date')
                  ->nullable()
                  ->after('original_planned_progress_percent');
        });

        // Backfill: rows that are part of the original plan keep their current
        // date as the frozen original. Extension-only rows stay NULL (they were
        // never part of the originally approved plan).
        DB::table('project_baseline_schedules')
            ->whereNotNull('original_planned_progress_percent')
            ->update(['original_planned_date' => DB::raw('planned_date')]);
    }

    public function down(): void
    {
        Schema::table('project_baseline_schedules', function (Blueprint $table) {
            $table->dropColumn('original_planned_date');
        });
    }
};
