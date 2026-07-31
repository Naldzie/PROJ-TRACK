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
        DB::table('email_trackings as e1')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('email_trackings as e2')
                    ->whereColumn('e2.ai_solution_id', 'e1.ai_solution_id')
                    ->whereColumn('e2.contractor_id', 'e1.contractor_id')
                    ->whereColumn('e2.id', '<', 'e1.id');
            })
            ->delete();

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
