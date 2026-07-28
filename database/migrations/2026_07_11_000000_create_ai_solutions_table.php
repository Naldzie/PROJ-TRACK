<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Create the ai_solutions table for storing AI-generated analysis text
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_solutions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('project_id')
                ->constrained()
                ->onDelete('cascade');
            $table->json('regression_snapshot');
            $table->string('model_used', 50);
            $table->text('analysis_text');
            $table->timestamp('created_at')->nullable();
        });
    }

    // Drop the ai_solutions table
    public function down(): void
    {
        Schema::dropIfExists('ai_solutions');
    }
};
