<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rows created via the initial "Set Up Baseline" modal
        // (storeInitialBaselineSetup) froze original_planned_progress_percent but
        // forgot original_planned_date, so the Edit Original Baseline modal (which
        // requires BOTH frozen columns) found nothing for those projects. Backfill
        // the frozen date from the live planned_date for every original-plan row
        // that still has a NULL frozen date.
        DB::table('project_baseline_schedules')
            ->whereNotNull('original_planned_progress_percent')
            ->whereNull('original_planned_date')
            ->update(['original_planned_date' => DB::raw('planned_date')]);
    }

    public function down(): void
    {
        // Intentionally a no-op: this only restores data that should always
        // have been written in the first place.
    }
};
