<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// E1-1 silent capture store: one row per distinct (contractor_id, header_hash) failed
// import. A row goes live only via E1-2 review (admin/staff approval) or ≥3 occurrences.
// Raw header text is kept (layout context, not project values); column_map is filled at
// E1-2 approval time (or E1-3 auto-proposal). source_type reserves the PDF twin (E1-PDF).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scurve_header_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->nullable()->constrained('contractors')->nullOnDelete();
            $table->string('source_type', 20)->default('excel');
            $table->text('header_rows_text');
            $table->string('header_hash', 64)->index();
            $table->json('column_map')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->string('status', 20)->default('proposed')->index();
            $table->string('source', 20)->default('auto-capture');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scurve_header_proposals');
    }
};
