<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_trackings', function (Blueprint $table) {
            if (!Schema::hasColumn('email_trackings', 'generated_by')) {
                $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete()->after('ai_solution_id');
            }
            if (!Schema::hasColumn('email_trackings', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('sent_at');
            }
            if (!Schema::hasColumn('email_trackings', 'is_read')) {
                $table->boolean('is_read')->default(false)->after('opened_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_trackings', function (Blueprint $table) {
            $table->dropColumn(['generated_by', 'delivered_at', 'is_read']);
        });
    }
};
