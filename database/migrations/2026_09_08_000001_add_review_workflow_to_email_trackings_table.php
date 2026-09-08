<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI review-window workflow columns.
     *
     * status: draft (manually composed) | pending_review (auto-generated on
     *   the 25th, editable during the 5-day window) | sent
     * review_until: end of the review window — the auto-send step only sends
     *   pending_review rows whose review_until has passed (i.e. the 30th).
     */
    public function up(): void
    {
        Schema::table('email_trackings', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('subject');
            $table->timestamp('review_until')->nullable()->after('status');
        });

        // Backfill from existing sent_at state (sent rows keep behaving as sent).
        DB::table('email_trackings')->whereNotNull('sent_at')->update(['status' => 'sent']);
        DB::table('email_trackings')->whereNull('sent_at')->update(['status' => 'draft']);
    }

    public function down(): void
    {
        Schema::table('email_trackings', function (Blueprint $table) {
            $table->dropColumn(['status', 'review_until']);
        });
    }
};
