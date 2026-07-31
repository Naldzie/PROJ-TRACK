<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_trackings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->nullable()->constrained('contractors')->nullOnDelete();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('ai_solution_id')->nullable()->constrained('ai_solutions')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('subject');
            $table->string('tracking_token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->integer('open_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_trackings');
    }
};
