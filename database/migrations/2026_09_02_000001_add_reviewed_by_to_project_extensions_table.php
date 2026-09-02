<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B1 — Reviewer tracking (reviewed_by_user_id + reviewed_at)
 *
 * Adds current-reviewer attribution for both approved and rejected extensions.
 * - reviewed_by_user_id FK -> users.id SET NULL (who performed the latest approved/rejected decision)
 * - reviewed_at timestamp (when that decision was made)
 *
 * Keeps approved_by_user_id / approved_at as sync copy for one release for BC.
 * Backfills approved rows: reviewed_* = approved_* where status='approved'.
 * Rejected rows stay null until next transition overwrites (optional artisan backfill from ActivityLog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            // Add reviewer FK after approved_by_user_id (nullable, SET NULL on user delete)
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->after('approved_by_user_id')
                ->constrained('users', 'id')
                ->nullOnDelete();

            $table->index('reviewed_by_user_id');

            // Timestamp after approved_at
            $table->timestamp('reviewed_at')->nullable()->after('approved_at');
        });

        // Backfill: approved rows keep their approver as current reviewer
        DB::table('project_extensions')
            ->where('status', 'approved')
            ->whereNotNull('approved_by_user_id')
            ->update([
                'reviewed_by_user_id' => DB::raw('approved_by_user_id'),
                'reviewed_at' => DB::raw('approved_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropIndex(['reviewed_by_user_id']);
            $table->dropColumn(['reviewed_by_user_id', 'reviewed_at']);
        });
    }
};
