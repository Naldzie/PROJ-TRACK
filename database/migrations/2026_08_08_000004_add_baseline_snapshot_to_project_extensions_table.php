<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a baseline snapshot to extension rows.
 *
 * A snapshot is the ENTIRE project_baseline_schedules state serialized to JSON,
 * captured at the moment an adjusted baseline is saved for an extension. It is
 * a RESTORE POINT, not a live mirror — it is never synced on individual month
 * add/edit/delete. Reverting the active approved extension restores from the
 * previous approved extension's snapshot (see extension_management_plan.md §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->json('baseline_snapshot')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->dropColumn('baseline_snapshot');
        });
    }
};
