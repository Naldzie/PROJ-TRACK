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
                'is_active' => true,
            ],
            [
                'name' => 'Renovation',
                'description' => 'Building renovation and remodeling projects',
                'is_active' => true,
            ],
            [
                'name' => 'Repair',
                'description' => 'Repair and maintenance work',
                'is_active' => true,
            ],
            [
                'name' => 'Maintenance',
                'description' => 'Routine maintenance and upkeep',
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
