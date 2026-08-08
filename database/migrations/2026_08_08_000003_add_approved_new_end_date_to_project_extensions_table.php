<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the partial-approval date to extension requests.
 *
 * Real EOT claims are often granted less than requested. `new_end_date`
 * stays as REQUESTED; when an admin grants a lower date, `approved_new_end_date`
 * records it and becomes the source of truth for the project deadline.
 * See extension_management_plan.md Decision 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->date('approved_new_end_date')->nullable()->after('new_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->dropColumn('approved_new_end_date');
        });
    }
};
