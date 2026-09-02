<?php

namespace Database\Seeders;

use App\Models\BudgetSource;
use Illuminate\Database\Seeder;

class BudgetSourceSeeder extends Seeder
{
    /**
     * Seed common Philippine government funding sources for public colleges.
     */
    public function run(): void
    {
        $sources = [
            [
                'name' => 'GAA',
                'description' => 'General Appropriations Act — regular national government budget',
                'is_active' => true,
            ],
            [
                'name' => 'CHED',
                'description' => 'Commission on Higher Education funding',
                'is_active' => true,
            ],
            [
                'name' => 'DPWH',
                'description' => 'Department of Public Works and Highways',
                'is_active' => true,
            ],
            [
                'name' => 'LGU',
                'description' => 'Local Government Unit contributions',
                'is_active' => true,
            ],
            [
                'name' => 'GAD',
                'description' => 'Gender and Development budget',
                'is_active' => true,
            ],
            [
                'name' => 'Special Trust Fund',
                'description' => 'Income-generated trust fund',
                'is_active' => true,
            ],
            [
                'name' => 'Regular Agency Fund',
                'description' => "Agency's own appropriation",
                'is_active' => true,
            ],
            [
                'name' => 'Foreign Assisted',
                'description' => 'International funding (JICA, ADB, World Bank, etc.)',
                'is_active' => true,
            ],
            [
                'name' => 'PPP',
                'description' => 'Public-Private Partnership',
                'is_active' => true,
            ],
        ];

        foreach ($sources as $sourceData) {
            BudgetSource::firstOrCreate(
                ['name' => $sourceData['name']],
                $sourceData
            );
        }
    }
}
