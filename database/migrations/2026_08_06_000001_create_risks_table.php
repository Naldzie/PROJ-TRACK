<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the risks table (Risk Register, gap #20).
     *
     * Mirrors the issues table structure: a project-scoped record with a
     * status lifecycle plus risk-specific fields (probability, impact,
     * computed score, mitigation plan, lifecycle timestamps).
     *
     * Called by: php artisan migrate
     */
    public function up(): void
    {
        Schema::create('risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->string('risk_name');
            $table->text('description')->nullable();
            $table->enum('probability', ['Low', 'Medium', 'High'])->default('Medium');
            $table->enum('impact', ['Low', 'Medium', 'High'])->default('Medium');
            // risk_score is computed at write time as probability × impact (1-9)
            $table->unsignedSmallInteger('risk_score')->default(1);
            $table->text('mitigation_plan')->nullable();
            $table->enum('status', ['Open', 'Mitigated', 'Closed'])->default('Open');
            $table->date('identified_at')->nullable();
            $table->timestamp('mitigated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // Speed up per-project risk lookups and status filtering
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risks');
    }
};
