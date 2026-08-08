<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectBaselineSchedule>
 */
class ProjectBaselineScheduleFactory extends Factory
{
    protected $model = ProjectBaselineSchedule::class;

    /**
     * ------------------------------------------------------------------------
     * Define the model's default state
     * ------------------------------------------------------------------------
     *
     * Note: This table has no updated_at column (const UPDATED_AT = null).
     * The created_at is set automatically by the migration default.
     */
    public function definition(): array
    {
        return [
            'project_id'                     => Project::factory(),
            'planned_date'                   => fake()->dateTimeBetween('-1 year', '+1 year'),
            'month_label'                    => fake()->date('Y-m'),
            'planned_progress_percent'       => fake()->randomFloat(2, 0, 100),
            'original_planned_progress_percent' => null,
            'description'                    => fake()->sentence(),
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Set a specific planned progress with frozen original value
     * ------------------------------------------------------------------------
     *
     * Used for baseline schedules that were part of the original plan.
     */
    public function originalPlan(): static
    {
        return $this->state(function (array $attributes) {
            $progress = $attributes['planned_progress_percent'] ?? fake()->randomFloat(2, 0, 100);

            return [
                'planned_progress_percent'          => $progress,
                'original_planned_progress_percent' => $progress,
                'original_planned_date'             => $attributes['planned_date'] ?? null,
            ];
        });
    }

    /**
     * ------------------------------------------------------------------------
     * Set a specific month and planned progress
     * ------------------------------------------------------------------------
     *
     * Used by CreatesProjectWithHistory trait to build sequential baselines.
     */
    public function forMonth(int $monthIndex, string $monthLabel, string $plannedDate, float $plannedProgress): static
    {
        return $this->state(fn () => [
            'month_label'                    => $monthLabel,
            'planned_date'                   => $plannedDate,
            'planned_progress_percent'       => $plannedProgress,
            'original_planned_progress_percent' => $plannedProgress,
            'original_planned_date'          => $plannedDate,
        ]);
    }
}
