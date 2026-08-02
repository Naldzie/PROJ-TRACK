<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Create the attachments table for record-level evidence files.
// Attachments are polymorphic (attachable: ProjectProgressHistory | Issue) —
// a separate concept from the formal, type-categorized documents library.
// Storage: storage/app/public/projects/attachments/{PROJECT_CODE}/{context}/...
// Access URL: APP_URL/storage/projects/attachments/{PROJECT_CODE}/{context}/{filename}
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            // Polymorphic parent (the progress record or issue this file proves)
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');

            // Project scope (enables cascade cleanup + project-level queries)
            $table->foreignId('project_id')
                ->constrained('projects')
                ->onDelete('cascade');

            // Classification: inspection | issue | resolution
            $table->string('context', 20)->nullable();

            // File metadata
            $table->string('original_name'); // Original client filename
            $table->text('file_path');       // Relative path on the public disk
            $table->unsignedBigInteger('file_size')->nullable(); // Bytes (stored, not recomputed)
            $table->string('mime_type', 100)->nullable();
            $table->string('uploaded_by')->nullable(); // User name or email

            $table->timestamps();

            // Indexes for morph lookups and project scoping
            $table->index(['attachable_type', 'attachable_id']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
