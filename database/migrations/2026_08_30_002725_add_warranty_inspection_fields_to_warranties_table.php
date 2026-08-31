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
        Schema::table('warranties', function (Blueprint $table) {
            $table->unsignedBigInteger('contractor_id')->nullable()->after('project_id');
            $table->date('completion_date')->nullable()->after('title');
            $table->date('mid_year_inspection')->nullable()->after('end_date');
            $table->date('final_inspection')->nullable()->after('mid_year_inspection');
            $table->text('remarks')->nullable()->after('final_inspection');

            $table->foreign('contractor_id')
                ->references('id')
                ->on('contractors')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropForeign(['contractor_id']);
            $table->dropColumn(['contractor_id', 'completion_date', 'mid_year_inspection', 'final_inspection', 'remarks']);
        });
    }
};