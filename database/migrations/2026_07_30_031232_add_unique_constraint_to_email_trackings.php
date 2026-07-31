<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('
            DELETE e1 FROM email_trackings e1
            INNER JOIN email_trackings e2
            ON e1.ai_solution_id = e2.ai_solution_id
            AND e1.contractor_id = e2.contractor_id
            WHERE e1.id > e2.id
        ');

        Schema::table('email_trackings', function (Blueprint $table) {
            $table->unique(['ai_solution_id', 'contractor_id'], 'uq_email_trackings_ai_contractor');
        });
    }

    public function down(): void
    {
        Schema::table('email_trackings', function (Blueprint $table) {
            $table->dropUnique('uq_email_trackings_ai_contractor');
        });
    }
};
