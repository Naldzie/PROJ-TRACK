<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds soft-delete support to extension rows.
 *
 * Deleting an extension becomes a soft delete (deleted_at set), keeping the
 * full history in the database. Admin can restore from the Archived panel or
 * permanently delete there. See extension_management_plan.md Decision 12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->softDeletes()->after('baseline_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
