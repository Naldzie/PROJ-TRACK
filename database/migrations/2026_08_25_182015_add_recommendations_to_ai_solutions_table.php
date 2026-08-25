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
        Schema::table('ai_solutions', function (Blueprint $table) {
            $table->json('recommendations')->nullable()->after('analysis_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_solutions', function (Blueprint $table) {
            $table->dropColumn('recommendations');
        });
    }
};
