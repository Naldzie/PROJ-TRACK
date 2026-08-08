<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a structured reason category to extension requests.
 *
 * Mirrors EOT "grounds + narrative": the category is a dropdown
 * (Weather, Materials, Labor, Site access, Permits, Design change, Other)
 * while the existing free-text `reason` remains the detailed justification.
 * See extension_management_plan.md Decision 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->string('reason_category')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->dropColumn('reason_category');
        });
    }
};
