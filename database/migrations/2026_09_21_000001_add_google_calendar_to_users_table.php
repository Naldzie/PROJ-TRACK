<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google Calendar OAuth storage.
     *
     * The login flow previously requested only the basic profile (no calendar
     * scope) and never persisted tokens, so automatic event push was
     * impossible. These columns hold the offline credentials needed to push
     * project start / due / inspection events to each involved user's own
     * Google Calendar.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'google_refresh_token')) {
                $table->text('google_refresh_token')->nullable()->after('google_token');
            }
            if (! Schema::hasColumn('users', 'google_token_expires_at')) {
                $table->timestamp('google_token_expires_at')->nullable()->after('google_refresh_token');
            }
            if (! Schema::hasColumn('users', 'google_calendar_enabled')) {
                $table->boolean('google_calendar_enabled')->default(false)->after('google_token_expires_at');
            }
            if (! Schema::hasColumn('users', 'google_calendar_connected_at')) {
                $table->timestamp('google_calendar_connected_at')->nullable()->after('google_calendar_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['google_calendar_connected_at', 'google_calendar_enabled', 'google_token_expires_at', 'google_refresh_token', 'google_id'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
