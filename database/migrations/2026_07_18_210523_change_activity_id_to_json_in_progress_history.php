<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert project_progress_history.activity_id to a JSON array of ids.
     *
     * The MySQL path uses INFORMATION_SCHEMA / SHOW INDEX / JSON_* helpers.
     * The SQLite path (used by the phpunit test suite) performs the same
     * logical conversion with PRAGMA introspection and PHP-side JSON
     * encoding, since SQLite has no INFORMATION_SCHEMA or JSON functions.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->upSqlite();

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

    private function upSqlite(): void
    {
        $fkExists = collect(DB::select("PRAGMA foreign_key_list('project_progress_history')"))
            ->contains(fn ($fk) => $fk->from === 'activity_id' && $fk->table === 'project_activities');

        $indexExists = collect(DB::select("PRAGMA index_list('project_progress_history')"))
            ->contains(fn ($index) => $index->name === 'project_progress_history_activity_id_foreign');

        if ($fkExists) {
            Schema::table('project_progress_history', function ($table) {
                $table->dropForeign(['activity_id']);
            });
        }

        if ($indexExists) {
            Schema::table('project_progress_history', function ($table) {
                $table->dropIndex('project_progress_history_activity_id_foreign');
            });
        }

        // Wrap any existing scalar activity_id values in a JSON array
        // (SQLite has no JSON type or JSON_* functions, so convert in PHP).
        DB::table('project_progress_history')
            ->whereNotNull('activity_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->activity_id, true);
                    $array = is_array($decoded)
                        ? array_values(array_map('intval', $decoded))
                        : [(int) $row->activity_id];

                    DB::table('project_progress_history')
                        ->where('id', $row->id)
                        ->update(['activity_id' => json_encode($array)]);
                }
            });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->downSqlite();

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

    private function downSqlite(): void
    {
        DB::table('project_progress_history')
            ->whereNotNull('activity_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->activity_id, true);
                    $scalar = is_array($decoded) ? (int) ($decoded[0] ?? 0) : (int) $row->activity_id;

                    DB::table('project_progress_history')
                        ->where('id', $row->id)
                        ->update(['activity_id' => $scalar]);
                }
            });

        $fkExists = collect(DB::select("PRAGMA foreign_key_list('project_progress_history')"))
            ->contains(fn ($fk) => $fk->from === 'activity_id' && $fk->table === 'project_activities');

        if (!$fkExists) {
            Schema::table('project_progress_history', function ($table) {
                $table->foreign('activity_id')->references('id')->on('project_activities')->onDelete('cascade');
            });
        }
    }
};
