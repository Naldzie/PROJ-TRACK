<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Consolidated migration creating proposals table with final schema.
     * This combines the creation and simplification into a single migration.
     */
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            
            // File and project information
            $table->string('file_path')->nullable();
            $table->string('project_title');
            $table->text('project_description')->nullable();
            
            // Financial
            $table->decimal('estimated_budget', 15, 2)->nullable();
            
            // Timeline
            $table->date('proposed_start_date');
            $table->integer('proposed_duration_days')->nullable();
            $table->date('proposed_end_date')->nullable();
            
            // Submission tracking
            $table->timestamp('date_submitted')->nullable()->useCurrent();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Relations
            $table->foreignId('contractor_id')->nullable()->constrained('contractors')->nullOnDelete();
            
            // Project details
            $table->string('proposed_project_name')->nullable();
            $table->enum('category', ['new_construction', 'renovation', 'repair', 'maintenance'])->nullable();
            $table->string('location_site')->nullable();
            
            // Approval tracking
            $table->text('evaluation_remarks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approval_date')->nullable();
            
            // Timestamps
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
