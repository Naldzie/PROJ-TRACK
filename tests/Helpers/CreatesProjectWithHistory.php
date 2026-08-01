<?php

namespace Tests\Helpers;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;

/**
 * ============================================================================
 * CreatesProjectWithHistory
 * ============================================================================
 *
 * Purpose
 * -------
 * Test helper trait that creates a Project with N months of baseline
 * schedules and progress history records for use in AI service tests.
 *
 * Used By
 * -------
 * All AI module Feature tests (Phases 8.2–8.8)
 *
 * Usage
 * -----
 * use CreatesProjectWithHistory;
 * $project = $this->createProjectWithHistory(months: 10);
 * $project = $this->createProjectWithKnownDataset();
 *
 * Note
 * ----
 * The test class must use either RefreshDatabase or DatabaseMigrations
 * trait for the database to be available.
 */
trait CreatesProjectWithHistory
{
    /**
     * ------------------------------------------------------------------------
     * Create Project With Baseline And Progress History
     * ------------------------------------------------------------------------
     *
     * Creates a single project with:
     * • N months of baseline schedules (evenly spaced progress 0→100)
     * • N months of progress history records
     *
     * Parameters
     * ----------
     * @param int $months          Number of monthly records to create
     * @param array $actualProgress Optional explicit Y values.
     *                              If empty, generates near-linear progress.
     * @param array $overrides     Optional project attribute overrides.
     *
     * Returns
     * -------
     * @return Project  Project with baselineSchedules and progressHistory loaded
     *
     * Used By
     * -------
     * RegressionDatasetBuilderTest
     * LinearRegressionServiceTest  (via feature tests needing DB)
     * AIPredictionServiceTest
     */
    protected function createProjectWithHistory(
        int $months = 10,
        array $actualProgress = [],
        array $overrides = []
    ): Project {
        /*
        |--------------------------------------------------------------------------
        | Create Project
        |--------------------------------------------------------------------------
        */

        $project = Project::factory()->create(array_merge([
            'status' => 'Ongoing',
        ], $overrides));

        /*
        |--------------------------------------------------------------------------
        | Generate Actual Progress Values
        |--------------------------------------------------------------------------
        |
        | If not explicitly provided, generate near-linear cumulative progress
        | values that produce a strong R² for deterministic test results.
        |
        | Formula:
        |   progress = 5.2 × month_index - 10 + small random noise
        |
        | This produces values similar to the known Project 7 dataset:
        |   slope ≈ 5.2, intercept ≈ -10, R² ≈ 0.95
        |
        */

        if (empty($actualProgress)) {
            $actualProgress = [];
            for ($i = 1; $i <= $months; $i++) {
                $base = (5.2 * $i) - 10;
                $noise = fake()->randomFloat(2, -1.5, 1.5);
                $actualProgress[] = max(0, min(100, round($base + $noise, 2)));
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Create Baseline Schedules
        |--------------------------------------------------------------------------
        |
        | Each month gets an evenly-spaced planned progress target
        | progressing from 0% to 100% over the project duration.
        |
        */

        $startDate = now()->subMonths($months)->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $monthDate = (clone $startDate)->addMonths($i);
            $monthLabel = $monthDate->format('Y-m');
            $plannedDate = (clone $monthDate)->endOfMonth()->format('Y-m-d');
            $plannedProgress = round((($i + 1) / $months) * 100, 2);

            ProjectBaselineSchedule::factory()->create([
                'project_id'                     => $project->id,
                'month_label'                    => $monthLabel,
                'planned_date'                   => $plannedDate,
                'planned_progress_percent'       => $plannedProgress,
                'original_planned_progress_percent' => $plannedProgress,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Create Progress History Records
        |--------------------------------------------------------------------------
        |
        | One inspection per month, occurring a few days before month end.
        |
        */

        for ($i = 0; $i < $months; $i++) {
            $monthDate = (clone $startDate)->addMonths($i);
            $monthLabel = $monthDate->format('Y-m');
            $inspectionDate = (clone $monthDate)->endOfMonth()->subDays(fake()->numberBetween(1, 5))->format('Y-m-d');

            ProjectProgressHistory::factory()->create([
                'project_id'              => $project->id,
                'month_number'            => $i + 1,
                'month_label'             => $monthLabel,
                'inspection_date'         => $inspectionDate,
                'actual_progress_percent' => $actualProgress[$i],
                'progress_percentage'     => $actualProgress[$i],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Reload With Relationships
        |--------------------------------------------------------------------------
        */

        return $project->load(['baselineSchedules', 'progressHistory']);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project With Multiple Inspections Per Month
     * ------------------------------------------------------------------------
     *
     * Creates a project where some months have multiple inspections.
     * The RegressionDatasetBuilder should normalize these by keeping
     * only the latest inspection per month.
     *
     * Used By
     * -------
     * RegressionDatasetBuilderTest (monthly normalization tests)
     */
    protected function createProjectWithDuplicateInspections(): Project
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $startDate = now()->subMonths(4)->startOfMonth();

        /*
        |--------------------------------------------------------------------------
        | Baseline Schedules (4 months)
        |--------------------------------------------------------------------------
        */

        for ($i = 0; $i < 4; $i++) {
            $monthDate = (clone $startDate)->addMonths($i);
            $monthLabel = $monthDate->format('Y-m');

            ProjectBaselineSchedule::factory()->create([
                'project_id'                     => $project->id,
                'month_label'                    => $monthLabel,
                'planned_date'                   => (clone $monthDate)->endOfMonth()->format('Y-m-d'),
                'planned_progress_percent'       => round((($i + 1) / 4) * 100, 2),
                'original_planned_progress_percent' => round((($i + 1) / 4) * 100, 2),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Month 1 — Single inspection
        |--------------------------------------------------------------------------
        */

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_number'            => 1,
            'month_label'             => (clone $startDate)->addMonths(0)->format('Y-m'),
            'inspection_date'         => (clone $startDate)->addMonths(0)->endOfMonth()->subDays(3)->format('Y-m-d'),
            'actual_progress_percent' => 5.00,
            'progress_percentage'     => 5.00,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Month 2 — Three inspections (normalization test: keep last)
        |--------------------------------------------------------------------------
        */

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_number'            => 2,
            'month_label'             => (clone $startDate)->addMonths(1)->format('Y-m'),
            'inspection_date'         => (clone $startDate)->addMonths(1)->endOfMonth()->subDays(15)->format('Y-m-d'),
            'actual_progress_percent' => 10.00,
            'progress_percentage'     => 10.00,
        ]);

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_number'            => 2,
            'month_label'             => (clone $startDate)->addMonths(1)->format('Y-m'),
            'inspection_date'         => (clone $startDate)->addMonths(1)->endOfMonth()->subDays(8)->format('Y-m-d'),
            'actual_progress_percent' => 14.00,
            'progress_percentage'     => 14.00,
        ]);

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_number'            => 2,
            'month_label'             => (clone $startDate)->addMonths(1)->format('Y-m'),
            'inspection_date'         => (clone $startDate)->addMonths(1)->endOfMonth()->subDays(1)->format('Y-m-d'),
            'actual_progress_percent' => 18.50,
            'progress_percentage'     => 18.50,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Month 3 — Single inspection
        |--------------------------------------------------------------------------
        */

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_number'            => 3,
            'month_label'             => (clone $startDate)->addMonths(2)->format('Y-m'),
            'inspection_date'         => (clone $startDate)->addMonths(2)->endOfMonth()->subDays(2)->format('Y-m-d'),
            'actual_progress_percent' => 30.00,
            'progress_percentage'     => 30.00,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Month 4 — Two inspections (normalization test)
        |--------------------------------------------------------------------------
        */

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_number'            => 4,
            'month_label'             => (clone $startDate)->addMonths(3)->format('Y-m'),
            'inspection_date'         => (clone $startDate)->addMonths(3)->endOfMonth()->subDays(10)->format('Y-m-d'),
            'actual_progress_percent' => 38.00,
            'progress_percentage'     => 38.00,
        ]);

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_number'            => 4,
            'month_label'             => (clone $startDate)->addMonths(3)->format('Y-m'),
            'inspection_date'         => (clone $startDate)->addMonths(3)->endOfMonth()->subDays(1)->format('Y-m-d'),
            'actual_progress_percent' => 42.50,
            'progress_percentage'     => 42.50,
        ]);

        return $project->load(['baselineSchedules', 'progressHistory']);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project With Known Dataset (Project 7)
     * ------------------------------------------------------------------------
     *
     * Creates a project with the exact 10-observation dataset used during
     * original Phase 3 validation. This dataset produces known regression
     * values that tests can assert against.
     *
     * Known Regression Values (verified July 6–9, 2026):
     * • Slope:     5.1151515151515
     * • Intercept: -9.9333333333333
     * • R²:        0.95445434179074
     * • Equation:  y = 5.1152x - 9.9333
     * • Fit:       Excellent
     *
     * Y Values (actual cumulative progress %):
     *   Month  1:   1.00
     *   Month  2:   2.00
     *   Month  3:   4.00
     *   Month  4:   7.00
     *   Month  5:  12.00
     *   Month  6:  18.00
     *   Month  7:  25.00
     *   Month  8:  33.00
     *   Month  9:  39.00
     *   Month 10:  45.00
     *
     * Used By
     * -------
     * LinearRegressionServiceTest
     * AIPredictionServiceTest
     */
    protected function createProjectWithKnownDataset(): Project
    {
        $yValues = [1.00, 2.00, 4.00, 7.00, 12.00, 18.00, 25.00, 33.00, 39.00, 45.00];

        return $this->createProjectWithHistory(
            months: 10,
            actualProgress: $yValues,
            overrides: ['status' => 'Ongoing']
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project With Insufficient Data
     * ------------------------------------------------------------------------
     *
     * Creates a project with fewer than 2 observations (0 or 1 only).
     * Used to test edge case handling in AIPredictionService and Presenter.
     *
     * Used By
     * -------
     * AIPredictionServiceTest
     * AIPredictionPresenterTest
     * RecommendationEngineTest
     */
    protected function createProjectWithInsufficientData(int $observations = 0): Project
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        if ($observations === 1) {
            $monthDate = now()->startOfMonth();

            ProjectBaselineSchedule::factory()->create([
                'project_id'                     => $project->id,
                'month_label'                    => $monthDate->format('Y-m'),
                'planned_date'                   => $monthDate->endOfMonth()->format('Y-m-d'),
                'planned_progress_percent'       => 10.00,
                'original_planned_progress_percent' => 10.00,
            ]);

            ProjectProgressHistory::factory()->create([
                'project_id'              => $project->id,
                'month_number'            => 1,
                'month_label'             => $monthDate->format('Y-m'),
                'inspection_date'         => $monthDate->endOfMonth()->subDay()->format('Y-m-d'),
                'actual_progress_percent' => 5.00,
                'progress_percentage'     => 5.00,
            ]);
        }

        return $project->load(['baselineSchedules', 'progressHistory']);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Completed Project
     * ------------------------------------------------------------------------
     *
     * Creates a project with Completed status and actual end dates.
     *
     * Used By
     * -------
     * AIPredictionPresenterTest
     * RecommendationEngineTest
     */
    protected function createCompletedProject(): Project
    {
        $project = Project::factory()->completed()->create();

        return $project->load(['baselineSchedules', 'progressHistory']);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project Without Baseline Schedules
     * ------------------------------------------------------------------------
     *
     * Creates a project that has progress history but no baseline schedules.
     * Used to test fallback behavior when baseline data is missing.
     *
     * Used By
     * -------
     * RecommendationEngineTest
     * AIPredictionServiceTest
     */
    protected function createProjectWithoutBaselines(int $months = 5): Project
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $startDate = now()->subMonths($months)->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $monthDate = (clone $startDate)->addMonths($i);
            $progress = round((5.0 * ($i + 1)) - 3, 2);

            ProjectProgressHistory::factory()->create([
                'project_id'              => $project->id,
                'month_number'            => $i + 1,
                'month_label'             => $monthDate->format('Y-m'),
                'inspection_date'         => $monthDate->endOfMonth()->subDays(fake()->numberBetween(1, 5))->format('Y-m-d'),
                'actual_progress_percent' => $progress,
                'progress_percentage'     => $progress,
            ]);
        }

        return $project->load(['baselineSchedules', 'progressHistory']);
    }
}
