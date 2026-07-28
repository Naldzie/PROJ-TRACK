<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Create the prediction_history table for storing regression predictions
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('project_id')
                ->constrained()
                ->onDelete('cascade');
            $table->string('regression_method', 10);       // 'slr' or 'polynomial'
            $table->decimal('current_progress', 5, 2);      // progress when predicted
            $table->decimal('predicted_next', 5, 2);        // predicted next month %
            $table->decimal('actual_next', 5, 2)->nullable(); // filled later
            $table->decimal('r_squared', 8, 4);
            $table->json('regression_snapshot');            // full analysis at that point
            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_history');
    }
};
