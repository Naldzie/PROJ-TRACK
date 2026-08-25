<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->decimal('performance_rating', 3, 2)->nullable()->after('company_address');
            $table->decimal('on_time_delivery_pct', 5, 2)->nullable()->after('performance_rating');
            $table->unsignedInteger('completed_projects_count')->default(0)->after('on_time_delivery_pct');
            $table->decimal('avg_delay_days', 10, 2)->nullable()->after('completed_projects_count');
            $table->timestamp('last_project_completed_at')->nullable()->after('avg_delay_days');
        });
    }

    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropColumn([
                'performance_rating',
                'on_time_delivery_pct',
                'completed_projects_count',
                'avg_delay_days',
                'last_project_completed_at',
            ]);
        });
    }
};
