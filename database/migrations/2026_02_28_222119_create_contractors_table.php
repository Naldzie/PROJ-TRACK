<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->string('contractor_id')->nullable();
            $table->string('company_name')->nullable();
            $table->string('contractor_name')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('company_landline')->nullable();
            $table->string('company_address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractors');
    }
};