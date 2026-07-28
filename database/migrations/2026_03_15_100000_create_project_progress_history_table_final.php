<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Consolidated migration creating project_progress_history table with final schema.
     * This combines creation, milestone support, and audit field additions into a single migration.
     */
    public function up(): void
    {
        Schema::create('project_progress_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('milestone_id')->nullable()->constrained('project_milestones')->onDelete('cascade');
            
            // Inspection/Recording details
            $table->date('inspection_date');
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->decimal('previous_progress', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->string('recorded_by')->nullable();
            
            // Timeline tracking
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->string('status')->nullable();
            
            // Audit trail for edits
            $table->json('original_values')->nullable();
            $table->string('edited_by')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->integer('edit_count')->default(0);
            
            // Timestamps
            $table->timestamps();
            
            // Indices for quick lookups
            $table->index(['project_id', 'inspection_date']);
            $table->index(['milestone_id', 'inspection_date']);
            $table->index('edited_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_progress_history');
    }
};
