<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            if (!Schema::hasColumn('contractors', 'company_name')) {
                $table->string('company_name')->nullable();
            }
            if (!Schema::hasColumn('contractors', 'contact_number')) {
                $table->string('contact_number')->nullable();
            }
            if (!Schema::hasColumn('contractors', 'company_landline')) {
                $table->string('company_landline')->nullable();
            }
            if (!Schema::hasColumn('contractors', 'company_address')) {
                $table->string('company_address')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropColumn([
                'company_name',
                'contact_number',
                'company_landline',
                'company_address',
            ]);
        });
    }
};
