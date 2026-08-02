<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Add project scope to the global activity log so the Overview tab can show a
// project-filtered Recent Activity timeline. Nullable + nullOnDelete so the
// global audit trail survives project deletion.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('user_id')
                ->constrained('projects')
                ->nullOnDelete();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
