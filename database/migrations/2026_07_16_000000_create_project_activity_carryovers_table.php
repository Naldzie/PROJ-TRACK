<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates PROJECT_ACTIVITY_CARRYOVERS table for tracking activity carryovers.
     * Each row records one carryover event — moving an incomplete activity from
     * one month's target to another. Carryover_number tracks how many times this
     * activity has been carried over (1st, 2nd, 3rd...).
     *
     * This is an immutable log table: no updated_at, no modification of existing rows.
     */
    public function up(): void
    {
        Schema::create('project_activity_carryovers', function (Blueprint $table) {
            $table->id();

            // The activity being carried over
            $table->foreignId('activity_id')
                ->constrained('project_activities')
                ->onDelete('cascade');

            // Project (denormalized for query convenience)
            $table->foreignId('project_id')
                ->constrained()
                ->onDelete('cascade');

            // Source month
            $table->foreignId('from_baseline_schedule_id')
                ->constrained('project_baseline_schedules')
                ->onDelete('cascade');
            $table->string('from_month_label');

            // Target month
            $table->foreignId('to_baseline_schedule_id')
                ->constrained('project_baseline_schedules')
                ->onDelete('cascade');
            $table->string('to_month_label');

            // Carryover chain tracking (1st, 2nd, 3rd...)
            $table->integer('carryover_number')->default(1);

            // User who performed the carryover
            $table->foreignId('carried_over_by')
                ->constrained('users')
                ->onDelete('cascade');

            $table->timestamp('created_at')->nullable();

            // Index for querying carryover chains
            $table->index(['activity_id', 'carryover_number']);
            $table->index(['project_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_activity_carryovers');
    }
};
