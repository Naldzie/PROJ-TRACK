<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a provenance stamp (risk_origin_id) to attachments.
     *
     * When a risk is converted to an issue its evidence attachments are
     * transferred to the issue (relabelled context 'issue'). This column
     * records which risk each attachment originally belonged to so that, when
     * the converted issue is deleted, only the attachments that genuinely came
     * from the risk are returned to it (attachments added to the issue later
     * are deleted with the issue). The risk's own evidence is removed only
     * when the risk itself is deleted. Guarded with Schema::hasColumn so it is
     * a safe no-op on fresh installs that already define the column.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('attachments', 'risk_origin_id')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->unsignedBigInteger('risk_origin_id')->nullable()->after('context');
                $table->index('risk_origin_id');
            });
        }
    }

    /**
     * Reverse the migration (drop the column).
     */
    public function down(): void
    {
        if (Schema::hasColumn('attachments', 'risk_origin_id')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->dropIndex(['risk_origin_id']);
                $table->dropColumn('risk_origin_id');
            });
        }
    }
};
