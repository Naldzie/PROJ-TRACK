<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates documents table for storing project document metadata.
     * Follows ERD design with proper relationships to projects and document_types.
     * 
     * File storage path pattern: storage/app/public/projects/documents/PRJ-{CODE}/{document_type}-{timestamp}.ext
     * Access URL: APP_URL/storage/projects/documents/PRJ-{CODE}/{filename}
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            
            // Relationships
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('document_type_id');
            
            // Document metadata
            $table->string('document_name'); // Original file name
            $table->text('file_path'); // Relative path: projects/documents/PRJ-{CODE}/filename.pdf
            $table->string('uploaded_by')->nullable(); // User name or ID
            $table->text('description')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->onDelete('cascade');
            
            $table->foreign('document_type_id')
                ->references('id')
                ->on('document_types')
                ->onDelete('restrict');
            
            // Indexes for performance
            $table->index('project_id');
            $table->index('document_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
