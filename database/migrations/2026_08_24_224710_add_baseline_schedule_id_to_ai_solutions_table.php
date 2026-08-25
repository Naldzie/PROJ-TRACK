<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_solutions', function (Blueprint $table) {
            $table->foreignId('baseline_schedule_id')
                ->nullable()
                ->after('project_id')
                ->constrained('project_baseline_schedules')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_solutions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('baseline_schedule_id');
        });
    }
};
