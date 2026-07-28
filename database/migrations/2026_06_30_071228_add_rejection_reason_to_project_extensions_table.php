<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds rejection_reason so an admin can record WHY an extension
     * request was rejected — staff need this to know what to revise
     * before resubmitting via the edit-mode flow.
     */
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
