<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AI-4 harvest sourcing: every proposal records its origin so reviewers can tell
// human-taught rows ('correction') from AI-assisted rows ('AI-assisted').
// Existing rows default to 'correction' (everything harvested before AI-3 was).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parser_keyword_proposals', function (Blueprint $table) {
            $table->string('source', 20)->default('correction')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('parser_keyword_proposals', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
