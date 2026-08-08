<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `superseded` status to the project_extensions status enum.
 *
 * When an admin approves an extension, any other PENDING proposal whose
 * `new_end_date` is no longer after the new live deadline becomes obsolete
 * (its basis has been overtaken). Such rows are kept visible in history with
 * status `superseded` instead of being silently deleted or cancelled
 * (see extension_management_plan.md Decision 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'superseded'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            // Existing superseded rows are moved back to pending on rollback
            // so the enum change stays reversible without data loss.
            DB::table('project_extensions')->where('status', 'superseded')->update(['status' => 'pending']);
            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending')
                ->change();
        });
    }
};
