<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define original categories
        $categories = [
            [
                'name' => 'New Construction',
                'description' => 'New construction projects',
                'color' => '#667eea',
                'is_active' => true,
            ],
            [
                'name' => 'Renovation',
                'description' => 'Building renovation and remodeling projects',
                'color' => '#10b981',
                'is_active' => true,
            ],
            [
                'name' => 'Repair',
                'description' => 'Repair and maintenance work',
                'color' => '#f59e0b',
                'is_active' => true,
            ],
            [
                'name' => 'Maintenance',
                'description' => 'Routine maintenance and upkeep',
                'color' => '#3b82f6',
                'is_active' => true,
            ],
        ];

        // Create categories if they don't already exist
        foreach ($categories as $categoryData) {
            Category::firstOrCreate(
                ['name' => $categoryData['name']],
                $categoryData
            );
        }
    }
}
