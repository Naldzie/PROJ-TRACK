<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Risk → Issue conversion (gap #20 enhancement).
     *
     * Adds the fields that connect a risk to the issue it produces when it
     * materializes, lets a risk threaten a specific baseline month, and adds
     * the 'Realized' lifecycle status (a risk that came true).
     *
     * Called by: php artisan migrate
     */
    public function up(): void
    {
        // Risks: optional baseline month + pointer to the converted issue
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('baseline_schedule_id')
                ->nullable()
                ->after('owner')
                ->constrained('project_baseline_schedules')
                ->nullOnDelete();

            $table->foreignId('converted_to_issue_id')
                ->nullable()
                ->after('closed_at')
                ->constrained('issues')
                ->nullOnDelete();

            $table->index(['baseline_schedule_id']);
        });

        // Issues: reverse pointer back to the risk that produced this issue
        Schema::table('issues', function (Blueprint $table) {
            $table->foreignId('source_risk_id')
                ->nullable()
                ->after('resolution_notes')
                ->constrained('risks')
                ->nullOnDelete();

            $table->index(['source_risk_id']);
        });

        // risks.status is a MySQL ENUM; extend it with 'Realized' via raw SQL
        // (avoids doctrine/dbal column-alter complications).
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE risks MODIFY COLUMN status ENUM('Open','Mitigated','Closed','Realized') NOT NULL DEFAULT 'Open'"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            if (Schema::hasColumn('issues', 'source_risk_id')) {
                $table->dropConstrainedForeignId('source_risk_id');
            }
        });

        Schema::table('risks', function (Blueprint $table) {
            if (Schema::hasColumn('risks', 'converted_to_issue_id')) {
                $table->dropConstrainedForeignId('converted_to_issue_id');
            }
            if (Schema::hasColumn('risks', 'baseline_schedule_id')) {
                $table->dropConstrainedForeignId('baseline_schedule_id');
            }
        });

        // Revert the status enum to its original three values (MySQL only)
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                "ALTER TABLE risks MODIFY COLUMN status ENUM('Open','Mitigated','Closed') NOT NULL DEFAULT 'Open'"
            );
        }
    }
};
