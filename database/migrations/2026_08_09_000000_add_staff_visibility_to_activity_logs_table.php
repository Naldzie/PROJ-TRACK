<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `staff` visibility tier to activity_logs.
 *
 * Phase 8 (extension_management_plan.md, Decision 16): the two-value
 * ['all','admin_only'] enum cannot express "staff yes, contractor no". The
 * new `staff` tier is visible to admins + staff (internal) and hidden from
 * contractors (external users). No data backfill needed — existing rows stay
 * 'all' (default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->enum('visibility', ['all', 'staff', 'admin_only'])
                ->default('all')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            // Existing staff-level rows fall back to 'all' on rollback so the
            // enum change stays reversible without losing entries.
            DB::table('activity_logs')->where('visibility', 'staff')->update(['visibility' => 'all']);
            $table->enum('visibility', ['all', 'admin_only'])
                ->default('all')
                ->change();
        });
    }
};
