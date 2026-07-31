<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Link each prediction_history row to the baseline schedule of the predicted month.
// The baseline_schedule_id references the PREDICTED month's baseline (the month the
// prediction is FOR), enabling precise month-matched backfill of actual_next.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_history', function (Blueprint $table) {
            $table->foreignId('baseline_schedule_id')
                ->nullable()
                ->after('month_label')
                ->constrained('project_baseline_schedules')
                ->nullOnDelete();
            $table->index(['project_id', 'baseline_schedule_id']);
        });

        // Backfill existing rows: set baseline_schedule_id from the baseline whose
        // month_label matches the row's month_label (one-time data migration).
        $baselines = DB::table('project_baseline_schedules')
            ->select('id', 'project_id', 'month_label')
            ->get()
            ->keyBy(fn ($row) => $row->project_id . '|' . $row->month_label);

        DB::table('prediction_history')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($baselines) {
                foreach ($rows as $row) {
                    if ($row->month_label && ($baseline = $baselines->get($row->project_id . '|' . $row->month_label))) {
                        DB::table('prediction_history')
                            ->where('id', $row->id)
                            ->update(['baseline_schedule_id' => $baseline->id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('prediction_history', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'baseline_schedule_id']);
            $table->dropConstrainedForeignId('baseline_schedule_id');
        });
    }
};
