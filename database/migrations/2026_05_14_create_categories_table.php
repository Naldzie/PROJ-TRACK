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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('color')->default('#667eea'); // Hex color code
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes(); // For soft delete capability
        });

        // Add category_id to projects table if it doesn't already have it
        if (!Schema::hasColumn('projects', 'category_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreignId('category_id')->nullable()->after('code')->constrained('categories')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
        });

        Schema::dropIfExists('categories');
    }
};
