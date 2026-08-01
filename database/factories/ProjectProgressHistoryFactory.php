<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectProgressHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectProgressHistory>
 */
class ProjectProgressHistoryFactory extends Factory
{
    protected $model = ProjectProgressHistory::class;

    /**
     * ------------------------------------------------------------------------
     * Define the model's default state
     * ------------------------------------------------------------------------
     *
     * Note: RegressionDatasetBuilder uses month_label for monthly grouping and
     * inspection_date for intra-month sorting.
     */
    public function definition(): array
    {
        $inspectionDate = fake()->dateTimeBetween('-1 year', 'now');

        return [
            'project_id'              => Project::factory(),
            'inspection_date'         => $inspectionDate,
            'month_label'             => $inspectionDate->format('Y-m'),
            'progress_percentage'     => fake()->randomFloat(2, 0, 100),
            'actual_progress_percent' => fake()->randomFloat(2, 0, 100),
            'planned_progress_percent' => fake()->randomFloat(2, 0, 100),
            'variance_percent'        => null,
            'previous_progress'       => null,
            'month_number'            => null,
            'status'                  => 'Submitted',
            'remarks'                 => fake()->optional()->sentence(),
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Set a specific inspection with known actual progress
     * ------------------------------------------------------------------------
     *
     * Used by CreatesProjectWithHistory trait to build sequential
     * progress records with increasing cumulative progress.
     */
    public function forMonth(
        int $monthNumber,
        string $monthLabel,
        string $inspectionDate,
        float $actualProgress
    ): static {
        return $this->state(fn () => [
            'month_number'            => $monthNumber,
            'month_label'             => $monthLabel,
            'inspection_date'         => $inspectionDate,
            'actual_progress_percent' => $actualProgress,
            'progress_percentage'     => $actualProgress,
        ]);
    }

    /**
     * ------------------------------------------------------------------------
     * Multiple inspections in the same month (for normalization tests)
     * ------------------------------------------------------------------------
     *
     * Creates a second/third inspection within the same calendar month.
     * Only the latest by inspection_date should survive normalization.
     */
    public function sameMonth(float $actualProgress, string $inspectionDate): static
    {
        return $this->state(fn (array $base) => [
            'actual_progress_percent' => $actualProgress,
            'progress_percentage'     => $actualProgress,
            'inspection_date'         => $inspectionDate,
            'month_label'             => $base['month_label'] ?? now()->format('Y-m'),
        ]);
    }
}
