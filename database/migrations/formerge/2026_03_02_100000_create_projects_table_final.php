<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Consolidated migration creating projects table with final schema.
     * This combines the creation and all subsequent modifications into a single migration.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            
            // Project identification
            $table->string('code')->unique()->nullable();
            $table->string('title');
            $table->string('category')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            
            // Budget & financial
            $table->decimal('approved_budget', 15, 2)->nullable();
            
            // Timeline - planned
            $table->date('planned_start_date')->nullable();
            $table->integer('planned_duration_days')->nullable();
            $table->date('planned_end_date')->nullable();
            
            // Timeline - actual
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->integer('contract_duration_days')->nullable();
            $table->integer('actual_duration_days')->nullable();
            
            // Project management
            $table->string('assigned_project_manager')->nullable();
            $table->string('project_manager_contact')->nullable();
            $table->integer('estimated_workers')->nullable();
            
            // Status
            $table->string('status')->default('Planned');
            
            // Relations
            $table->unsignedBigInteger('contractor_id')->nullable();
            
            // Documents
            $table->string('signed_contract')->nullable();
            $table->json('supporting_documents')->nullable();
            
            // Archiving & notes
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->date('planned_handing_over_date')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('contractor_id')->references('id')->on('contractors')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
