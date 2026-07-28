<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a status column to project_extensions to support the new
     * staff-request / admin-approval workflow. Existing rows (all of
     * which were created under the old "auto-approved on creation"
     * behavior) are backfilled to 'approved' so historical data stays
     * consistent with what actually happened.
     */
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending')
                ->after('reason');
        });

        // Backfill: every extension that already exists today was created
        // under the old auto-approve-on-creation flow, so it IS approved.
        DB::table('project_extensions')->update(['status' => 'approved']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
