<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Links an issue to the baseline month it belongs to (groundwork for the
     * Phase 10 feedback loop) and captures free-text resolution notes that are
     * required when an issue is Resolved/Closed.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->foreignId('baseline_schedule_id')
                ->nullable()
                ->after('severity')
                ->constrained('project_baseline_schedules')
                ->nullOnDelete();

            $table->text('resolution_notes')
                ->nullable()
                ->after('baseline_schedule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            if (Schema::hasColumn('issues', 'baseline_schedule_id')) {
                $table->dropConstrainedForeignId('baseline_schedule_id');
            }
            if (Schema::hasColumn('issues', 'resolution_notes')) {
                $table->dropColumn('resolution_notes');
            }
        });
    }
};
