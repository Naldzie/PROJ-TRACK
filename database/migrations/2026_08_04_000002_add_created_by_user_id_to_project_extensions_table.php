<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Track who SUBMITTED an extension request (gaps #12).
// project_extensions already stores approved_by_user_id (the approver, null until
// approval), but never recorded the requester — so approval/rejection notices could
// not be targeted. This nullable FK captures the submitter in ProjectExtensionController::store().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            if (!Schema::hasColumn('project_extensions', 'created_by_user_id')) {
                $table->foreignId('created_by_user_id')->after('approved_at')->nullable()
                    ->constrained('users', 'id')
                    ->onDelete('set null');
                $table->index('created_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_extensions', function (Blueprint $table) {
            if (Schema::hasColumn('project_extensions', 'created_by_user_id')) {
                $table->dropForeign(['created_by_user_id']);
                $table->dropIndex(['created_by_user_id']);
                $table->dropColumn('created_by_user_id');
            }
        });
    }
};
