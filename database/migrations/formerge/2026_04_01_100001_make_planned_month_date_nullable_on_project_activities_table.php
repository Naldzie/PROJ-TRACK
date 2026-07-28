<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Make planned_month_date nullable since we now use baseline_schedule_id
     * to track which month an activity belongs to.
     */
    public function up(): void
    {
        Schema::table('project_activities', function (Blueprint $table) {
            $table->date('planned_month_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_activities', function (Blueprint $table) {
            $table->date('planned_month_date')->nullable(false)->change();
        });
    }
};
