<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fixes the PROJECT_EXTENSIONS table to properly track who approved the extension.
     * Standardizes column name to 'approved_by_user_id' and ensures it's a proper FK to users table.
     * Works for both workspaces:
     * - User's workspace: already has approved_by_user_id, ensures it's properly constrained
     * - Teammate's workspace: has approved_by (string), converts to approved_by_user_id (FK)
     */
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            // Handle teammate's migration: drop incorrect string column if it exists
            if (Schema::hasColumn('project_extensions', 'approved_by')) {
                $table->dropColumn('approved_by');
            }
            
            // Handle user's workspace: drop if already exists (to re-create with constraint)
            if (Schema::hasColumn('project_extensions', 'approved_by_user_id')) {
                // If it exists but doesn't have a foreign key, drop it
                // This handles the case where it's just a plain INT column
                $table->dropColumn('approved_by_user_id');
            }
            
            // Add proper foreign key column for approver
            $table->foreignId('approved_by_user_id')->after('reason')->nullable()
                ->constrained('users', 'id')
                ->onDelete('set null');
            
            // Add index for quick lookups
            $table->index('approved_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            // Drop foreign key constraint first
            $table->dropForeign(['approved_by_user_id']);
            $table->dropIndex(['approved_by_user_id']);
            $table->dropColumn('approved_by_user_id');
            
            // Restore original string column for rollback
            $table->string('approved_by')->nullable()->after('reason');
        });
    }
};
