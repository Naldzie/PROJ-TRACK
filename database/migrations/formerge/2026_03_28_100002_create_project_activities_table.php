<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates PROJECT_ACTIVITIES table for simple activity checklists (Model A).
     * Activities are simple monthly tasks with boolean completion status (is_completed).
     * NO weights, NO progress%, NO dates - just monthly checklist items.
     * Replaces PROJECT_MILESTONES for the Activity Checklist model.
     */
    public function up(): void
    {
        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            
            // Activity identification
            $table->string('activity_name');
            $table->text('description')->nullable();
            
            // Month identification
            $table->string('month_label');         // e.g., "2026-01", "Jan 2026"
            
            // Simple completion status - TRUE = ✓ done, FALSE = ✗ not done
            $table->boolean('is_completed')->default(false);
            
            $table->timestamps();
            
            // Indices for month-based queries
            $table->index(['project_id', 'month_label']);
            $table->index('is_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_activities');
    }
};
