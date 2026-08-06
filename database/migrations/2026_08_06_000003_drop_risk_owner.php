<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the risks.owner column (free-text "responsible person" field).
     *
     * The owner field was decorative: optional free text that no business
     * logic (notifications, reports, exports, AI) ever read. It will be
     * re-added later as a proper assignee once the client specifies the
     * requirement. This migration is guarded with Schema::hasColumn so it is
     * a safe no-op on fresh installs (the create migration no longer defines
     * the column) while dropping it on databases that already have it.
     */
    public function up(): void
    {
        if (Schema::hasColumn('risks', 'owner')) {
            Schema::table('risks', function (Blueprint $table) {
                $table->dropColumn('owner');
            });
        }
    }

    /**
     * Reverse the migrations (re-add the column).
     */
    public function down(): void
    {
        if (! Schema::hasColumn('risks', 'owner')) {
            Schema::table('risks', function (Blueprint $table) {
                $table->string('owner')->nullable()->after('mitigation_plan');
            });
        }
    }
};
