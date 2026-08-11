<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 14 Step 1: next-month prediction is stored as a BAND, never a
// single point (Decision 6). Nullable columns keep existing rows valid as
// point-only until they are re-saved with a band.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_history', function (Blueprint $table) {
            $table->decimal('predicted_low', 5, 2)
                ->nullable()
                ->after('predicted_next');
            $table->decimal('predicted_high', 5, 2)
                ->nullable()
                ->after('predicted_low');
        });
    }

    public function down(): void
    {
        Schema::table('prediction_history', function (Blueprint $table) {
            $table->dropColumn(['predicted_high', 'predicted_low']);
        });
    }
};
