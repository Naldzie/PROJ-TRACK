<?php

namespace Database\Factories;

use App\Models\AiSolution;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiSolution>
 */
class AiSolutionFactory extends Factory
{
    protected $model = AiSolution::class;

    /**
     * ------------------------------------------------------------------------
     * Define the model's default state
     * ------------------------------------------------------------------------
     *
     * Note: This table has no updated_at column (const UPDATED_AT = null).
     */
    public function definition(): array
    {
        return [
            'project_id'          => Project::factory(),
            'regression_snapshot' => [
                'current_state' => ['progress' => 45.0, 'month_index' => 10],
                'next_prediction' => ['progress' => 46.33],
                'regression' => [
                    'equation' => 'y = 5.1152x - 9.9333',
                    'slope' => 5.1152,
                    'intercept' => -9.9333,
                    'r_squared' => 0.9545,
                    'fit_quality' => 'Excellent',
                    'observation_count' => 10,
                ],
                'statistics' => [
                    'observation_count' => 10,
                    'current_month_index' => 10,
                    'first_month' => '2025-09-01',
                    'last_month' => '2026-06-01',
                ],
            ],
            'model_used'          => 'gemini-flash-lite-latest',
            'analysis_text'       => fake()->paragraphs(3, true),
        ];
    }
}
