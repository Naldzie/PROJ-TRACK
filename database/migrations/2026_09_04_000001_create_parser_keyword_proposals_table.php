<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// L1 silent collection store: one row per distinct (field, label_phrase) correction.
// status=proposed until L2 review promotes it to live (or ≥3 occurrences auto-promote).
// Values (names, amounts) are NEVER stored — labels only (Decision L0b).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_keyword_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('field', 50)->index();
            $table->string('label_phrase', 255)->nullable();
            $table->text('excerpt')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->string('status', 20)->default('proposed')->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_keyword_proposals');
    }
};
