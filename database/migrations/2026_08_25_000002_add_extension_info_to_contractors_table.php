<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->unsignedInteger('total_extensions_count')->default(0)->after('last_project_completed_at');
            $table->decimal('total_extension_days', 10, 2)->nullable()->after('total_extensions_count');
        });
    }

    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropColumn([
                'total_extensions_count',
                'total_extension_days',
            ]);
        });
    }
};
