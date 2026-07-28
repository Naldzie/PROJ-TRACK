<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds extension tracking fields to PROJECTS table.
     * Enables quick reference to current vs original end dates and extension count.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Original contract end date (FROZEN - never changes after project start)
            $table->date('original_planned_end_date')->nullable()->after('planned_end_date');
            
            // Current planned end date (UPDATED when extensions approved)
            $table->date('current_planned_end_date')->nullable()->after('original_planned_end_date');
            
            // How many times project extended
            $table->integer('extension_count')->default(0)->after('current_planned_end_date');
            
            // Index for quick lookups
            $table->index('extension_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'original_planned_end_date',
                'current_planned_end_date',
                'extension_count'
            ]);
        });
    }
};
