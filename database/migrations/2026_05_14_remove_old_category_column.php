<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Remove old category column from projects table
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Drop the old category column
            if (Schema::hasColumn('projects', 'category')) {
                $table->dropColumn('category');
            }
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Restore the old category column if rolling back
            $table->string('category')->nullable()->after('code');
        });
    }
};
