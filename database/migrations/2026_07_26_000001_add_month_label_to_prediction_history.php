<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_history', function (Blueprint $table) {
            $table->string('month_label', 10)->nullable()->after('project_id');
            $table->index(['project_id', 'month_label']);
        });
    }

    public function down(): void
    {
        Schema::table('prediction_history', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'month_label']);
            $table->dropColumn('month_label');
        });
    }
};
