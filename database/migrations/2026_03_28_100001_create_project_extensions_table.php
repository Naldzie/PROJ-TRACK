<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates PROJECT_EXTENSIONS table for tracking project extension requests.
     * Stores full audit trail of extension approvals with reasons and timestamps.
     * Original S_CURVE_PLAN stays unchanged; extensions tracked separately.
     */
    public function up(): void
    {
        Schema::create('project_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            
            // Extension tracking
            $table->integer('extension_number');        // 1st, 2nd, 3rd extension
            $table->date('original_end_date');          // Original planned end (from S_CURVE_PLAN)
            $table->date('new_end_date');               // Extended end date
            $table->integer('duration_days');           // new_end_date - original_end_date
            
            // Audit trail
            $table->text('reason');                     // Why extension was requested
            $table->string('approved_by')->nullable();  // User who approved
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();
            
            // Indices
            $table->index(['project_id', 'extension_number']);
            $table->index('approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_extensions');
    }
};
