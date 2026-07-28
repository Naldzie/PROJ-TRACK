<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates S_CURVE_PLAN table for static monthly progress targets.
     * This table is WRITE-ONCE: planned targets never change after project creation,
     * even if extensions are approved. Used for variance analysis against actual progress.
     */
    public function up(): void
    {
        Schema::create('s_curve_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            
            // Calendar-based date (never month_number INT)
            $table->date('planned_date');      // e.g., 2026-01-31, 2026-02-28
            $table->string('month_label');      // e.g., "2026-01", "Jan 2026"
            
            // Static planned progress target for this month
            $table->decimal('planned_progress_percent', 5, 2);
            
            // Optional description
            $table->text('description')->nullable();  // e.g., "Foundation work phase"
            
            // CRITICAL: Only created_at, no updated_at - table is immutable
            $table->timestamp('created_at')->useCurrent();
            
            // Indices for quick lookups
            $table->index(['project_id', 'planned_date']);
            $table->index(['project_id', 'month_label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_curve_plan');
    }
};
