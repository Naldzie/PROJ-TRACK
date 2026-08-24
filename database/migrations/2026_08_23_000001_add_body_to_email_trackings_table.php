<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_trackings', function (Blueprint $table) {
            if (!Schema::hasColumn('email_trackings', 'body')) {
                $table->longText('body')->nullable()->after('subject');
            }

            if (!Schema::hasColumn('email_trackings', 'is_edited')) {
                $table->boolean('is_edited')->default(false)->after('tracking_token');
            }

            if (!Schema::hasColumn('email_trackings', 'edited_by')) {
                $table->foreignId('edited_by')->nullable()->after('is_edited')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('email_trackings', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('edited_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_trackings', function (Blueprint $table) {
            if (Schema::hasColumn('email_trackings', 'edited_at')) {
                $table->dropColumn('edited_at');
            }
            if (Schema::hasColumn('email_trackings', 'edited_by')) {
                $table->dropConstrainedForeignId('edited_by');
            }
            if (Schema::hasColumn('email_trackings', 'is_edited')) {
                $table->dropColumn('is_edited');
            }
            if (Schema::hasColumn('email_trackings', 'body')) {
                $table->dropColumn('body');
            }
        });
    }
};
