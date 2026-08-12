<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// C7 (accuracy-by-data_source logging): record how each forecast was produced
// so error rates can be compared across modes (project_history / hybrid /
// similar_projects) and the blend weight k can be tuned empirically.
// Nullable keeps existing rows valid as "unknown source" until re-saved.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_history', function (Blueprint $table) {
            $table->string('data_source', 20)
                ->nullable()
                ->after('regression_method');
        });
    }

    public function down(): void
    {
        Schema::table('prediction_history', function (Blueprint $table) {
            $table->dropColumn('data_source');
        });
    }
};
