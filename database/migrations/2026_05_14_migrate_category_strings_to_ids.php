<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Category;
use App\Models\Project;

return new class extends Migration
{
    /**
     * Run the migrations - migrate old string categories to category_id
     */
    public function up(): void
    {
        // Mapping of old category strings to new category names
        $categoryMapping = [
            'new_construction' => 'New Construction',
            'renovation' => 'Renovation',
            'repair' => 'Repair',
            'maintenance' => 'Maintenance',
        ];

        // Get all projects with old string categories
        $projects = Project::whereNotNull('category')
            ->where('category_id', null)
            ->get();

        foreach ($projects as $project) {
            // Find matching category name
            $oldCategoryValue = $project->category;
            $categoryName = null;

            // Try direct mapping first
            if (isset($categoryMapping[$oldCategoryValue])) {
                $categoryName = $categoryMapping[$oldCategoryValue];
            } else {
                // Try case-insensitive match with category names
                foreach (array_values($categoryMapping) as $mapping) {
                    if (strtolower($mapping) === strtolower($oldCategoryValue)) {
                        $categoryName = $mapping;
                        break;
                    }
                }
            }

            // If we found a matching category, update the project
            if ($categoryName) {
                $category = Category::where('name', $categoryName)->first();
                if ($category) {
                    $project->update(['category_id' => $category->id]);
                }
            }
        }
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        // Reset category_id to null when rolling back
        DB::table('projects')->update(['category_id' => null]);
    }
};
