<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('project_id');
            $table->string('title')->nullable();

            $table->date('start_date');
            $table->date('end_date');

            $table->string('file_path'); // file storage

            $table->timestamps();

            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};