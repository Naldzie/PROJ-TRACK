<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $fkExists = !empty(DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_progress_history' AND COLUMN_NAME = 'activity_id' AND CONSTRAINT_NAME = 'project_progress_history_activity_id_foreign'"));

        $indexExists = !empty(DB::select("SHOW INDEX FROM project_progress_history WHERE Key_name = 'project_progress_history_activity_id_foreign'"));

        if ($fkExists) {
            Schema::table('project_progress_history', function ($table) {
                $table->dropForeign('project_progress_history_activity_id_foreign');
            });
        }

        if ($indexExists) {
            Schema::table('project_progress_history', function ($table) {
                $table->dropIndex('project_progress_history_activity_id_foreign');
            });
        }

        $isJson = DB::select("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_progress_history' AND COLUMN_NAME = 'activity_id'")[0]->DATA_TYPE ?? '';

        if ($isJson !== 'json') {
            DB::statement("ALTER TABLE project_progress_history MODIFY activity_id JSON NULL");
        }

        DB::statement("UPDATE project_progress_history SET activity_id = JSON_ARRAY(activity_id) WHERE activity_id IS NOT NULL AND JSON_VALID(activity_id) = 0");

        DB::statement("UPDATE project_progress_history SET activity_id = JSON_ARRAY(CAST(JSON_UNQUOTE(JSON_EXTRACT(activity_id, '$[0]')) AS UNSIGNED)) WHERE activity_id IS NOT NULL AND JSON_VALID(activity_id) = 1 AND JSON_TYPE(activity_id) NOT IN ('ARRAY')");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $isJson = DB::select("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_progress_history' AND COLUMN_NAME = 'activity_id'")[0]->DATA_TYPE ?? '';

        if ($isJson === 'json') {
            DB::statement("UPDATE project_progress_history SET activity_id = JSON_UNQUOTE(JSON_EXTRACT(activity_id, '$[0]')) WHERE activity_id IS NOT NULL");
            DB::statement("ALTER TABLE project_progress_history MODIFY activity_id BIGINT UNSIGNED NULL");
        }

        $fkExists = !empty(DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_progress_history' AND COLUMN_NAME = 'activity_id' AND CONSTRAINT_NAME = 'project_progress_history_activity_id_foreign'"));

        if (!$fkExists) {
            Schema::table('project_progress_history', function ($table) {
                $table->foreign('activity_id')->references('id')->on('project_activities')->onDelete('cascade');
            });
        }
    }
};
