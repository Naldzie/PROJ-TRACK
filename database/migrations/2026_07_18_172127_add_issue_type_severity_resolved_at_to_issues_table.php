<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Add issue_type, severity, and resolved_at columns to the issues table.
     *
     * issue_type:   Categorizes the issue domain (material, contractor, weather, etc.)
     * severity:     Impact level (Low, Medium, High, Critical)
     * resolved_at:  Timestamp auto-set when status changes to Resolved/Closed
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->string('issue_type', 50)->nullable()->after('status');
            $table->string('severity', 20)->nullable()->after('issue_type');
            $table->timestamp('resolved_at')->nullable()->after('severity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn(['issue_type', 'severity', 'resolved_at']);
        });
    }
};
