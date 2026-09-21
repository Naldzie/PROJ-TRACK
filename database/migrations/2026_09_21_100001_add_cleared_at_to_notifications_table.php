<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Clear read notifications" in the inbox (restudy P-6, 2026-09-21 —
// textfiles/notification_monitoring_restudy_plan.md). Cleared notices are
// HIDDEN, not deleted: NotificationService::notifyUnique() dedupes by looking
// for an existing row with the same type + key, so deleting a read overdue /
// warranty / not-started notice would make the next daily scan send it again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('cleared_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('cleared_at');
        });
    }
};
