<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates document_types table for categorizing project documents.
     * This is separate from project categories - represents document classification.
     */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('icon')->nullable(); // Bootstrap icon class (e.g., 'bi-file-pdf')
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // === SEED DEFAULT DOCUMENT TYPES ===
        DB::table('document_types')->insert([
            [
                'name' => 'Contract',
                'description' => 'Signed contract document',
                'icon' => 'bi-file-pdf',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Supporting Document',
                'description' => 'Supporting/additional documents',
                'icon' => 'bi-file-text',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Schedule',
                'description' => 'Project schedule/timeline',
                'icon' => 'bi-calendar',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
