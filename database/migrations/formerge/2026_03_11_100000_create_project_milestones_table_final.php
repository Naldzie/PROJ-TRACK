<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Consolidated migration creating project_milestones table with final schema.
     * This combines the creation and removal of sort_order into a single migration.
     */
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            
            // Milestone identification
            $table->string('name');
            $table->text('description')->nullable();
            
            // Weight - percentage contribution to overall project
            $table->decimal('weight_percentage', 5, 2)->default(0);
            
            // Planned/Scheduled dates
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            
            // Actual dates
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            
            // Progress percentage of this milestone (0-100)
            $table->decimal('progress_percentage', 5, 2)->default(0);
            
            // Status
            $table->enum('status', ['Not Started', 'In Progress', 'Completed', 'Delayed', 'On Hold'])->default('Not Started');
            
            // Additional fields
            $table->text('remarks')->nullable();
            $table->string('assigned_to')->nullable();
            
            $table->timestamps();
            
            // Index for faster queries
            $table->index('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
    }
};
