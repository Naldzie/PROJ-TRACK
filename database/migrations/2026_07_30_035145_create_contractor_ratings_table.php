<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contractor_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained('contractors')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('ai_solution_id')->nullable()->constrained('ai_solutions')->nullOnDelete();
            $table->tinyInteger('rating')->unsigned();
            $table->text('ai_review_text')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contractor_id', 'project_id'], 'uq_ratings_contractor_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_ratings');
    }
};
