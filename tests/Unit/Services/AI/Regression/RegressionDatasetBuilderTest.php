<?php

namespace Tests\Unit\Services\AI\Regression;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Services\AI\Regression\RegressionDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * ============================================================================
 * RegressionDatasetBuilderTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates that RegressionDatasetBuilder correctly transforms project
 * progress history records into a regression-ready dataset.
 *
 * Key Behaviour
 * -------------
 * • Retrieves project progress history ordered chronologically
 * • Normalizes multiple inspections per month (keeps only the latest)
 * • Generates sequential Project Month Index values (1, 2, 3, ...)
 * • Returns x (month indices), y (actual progress %), records, and statistics
 *
 * Depends On
 * ----------
 * RegressionDatasetBuilder  — the service under test
 * CreatesProjectWithHistory — test helper trait for creating test data
 *
 * Run With
 * --------
 *   php artisan test --filter=RegressionDatasetBuilderTest
 */
class RegressionDatasetBuilderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    private RegressionDatasetBuilder $datasetBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->datasetBuilder = new RegressionDatasetBuilder();
    }

    /*
    |==========================================================================
    | Dataset Structure
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Build dataset returns correct top-level structure
     * ------------------------------------------------------------------------
     *
     * Verifies that buildDataset() returns an array containing all four
     * expected keys: x, y, records, statistics.
     */
    public function test_build_dataset_returns_correct_structure(): void
    {
        $project = $this->createProjectWithHistory(months: 5);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertIsArray($dataset);
        $this->assertArrayHasKey('x', $dataset);
        $this->assertArrayHasKey('y', $dataset);
        $this->assertArrayHasKey('records', $dataset);
        $this->assertArrayHasKey('statistics', $dataset);
    }

    /**
     * ------------------------------------------------------------------------
     * Statistics structure
     * ------------------------------------------------------------------------
     *
     * Verifies the statistics sub-array contains observation_count,
     * current_month_index, first_month, and last_month.
     */
    public function test_build_dataset_statistics_has_expected_keys(): void
    {
        $project = $this->createProjectWithHistory(months: 5);

        $dataset = $this->datasetBuilder->buildDataset($project);
        $statistics = $dataset['statistics'];

        $this->assertArrayHasKey('observation_count', $statistics);
        $this->assertArrayHasKey('current_month_index', $statistics);
        $this->assertArrayHasKey('first_month', $statistics);
        $this->assertArrayHasKey('last_month', $statistics);
    }

    /*
    |==========================================================================
    | Dataset Values — 10 Months
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Build dataset with 10 months
     * ------------------------------------------------------------------------
     *
     * Creates a project with 10 months of progress history and verifies
     * the output contains exactly 10 observations.
     */
    public function test_build_dataset_with_10_months(): void
    {
        $project = $this->createProjectWithHistory(months: 10);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertCount(10, $dataset['x']);
        $this->assertCount(10, $dataset['y']);
        $this->assertCount(10, $dataset['records']);
    }

    /**
     * ------------------------------------------------------------------------
     * X values are sequential months 1 to N
     * ------------------------------------------------------------------------
     *
     * The Project Month Index should start at 1 and increment by 1
     * for each observation.
     */
    public function test_build_dataset_x_values_are_sequential_month_indices(): void
    {
        $project = $this->createProjectWithHistory(months: 7);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertEquals([1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0], $dataset['x']);
    }

    /**
     * ------------------------------------------------------------------------
     * Y values match actual progress from DB
     * ------------------------------------------------------------------------
     *
     * The y array should contain the actual_progress_percent values from
     * the normalized progress history records in order.
     */
    public function test_build_dataset_y_values_match_actual_progress(): void
    {
        $project = $this->createProjectWithHistory(
            months: 4,
            actualProgress: [10.00, 25.00, 40.00, 55.00]
        );

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertEquals([10.0, 25.0, 40.0, 55.0], $dataset['y']);
    }

    /*
    |==========================================================================
    | Statistics Values
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Statistics observation_count
     * ------------------------------------------------------------------------
     */
    public function test_build_dataset_observation_count_matches(): void
    {
        $project = $this->createProjectWithHistory(months: 8);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertEquals(8, $dataset['statistics']['observation_count']);
    }

    /**
     * ------------------------------------------------------------------------
     * Statistics current_month_index
     * ------------------------------------------------------------------------
     *
     * The current month index should equal the number of observations,
     * representing the most recent completed month.
     */
    public function test_build_dataset_current_month_index_matches(): void
    {
        $project = $this->createProjectWithHistory(months: 6);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertEquals(6, $dataset['statistics']['current_month_index']);
    }

    /**
     * ------------------------------------------------------------------------
     * Statistics first_month is a Carbon instance
     * ------------------------------------------------------------------------
     *
     * inspection_date is an Eloquent date column, so it returns
     * a Carbon instance from the model accessor.
     */
    public function test_build_dataset_first_month_is_carbon_instance(): void
    {
        $project = $this->createProjectWithHistory(months: 3);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertInstanceOf(
            \Illuminate\Support\Carbon::class,
            $dataset['statistics']['first_month']
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Statistics last_month is a Carbon instance
     * ------------------------------------------------------------------------
     */
    public function test_build_dataset_last_month_is_carbon_instance(): void
    {
        $project = $this->createProjectWithHistory(months: 3);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertInstanceOf(
            \Illuminate\Support\Carbon::class,
            $dataset['statistics']['last_month']
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Statistics observation_count equals count of x
     * ------------------------------------------------------------------------
     */
    public function test_build_dataset_observation_count_equals_count_x(): void
    {
        $project = $this->createProjectWithHistory(months: 10);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertSame(
            $dataset['statistics']['observation_count'],
            count($dataset['x'])
        );
    }

    /*
    |==========================================================================
    | Monthly Normalization — Duplicate Inspections
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Build dataset normalizes duplicate inspections
     * ------------------------------------------------------------------------
     *
     * When multiple inspections exist in the same calendar month, only the
     * latest inspection (by inspection_date) should be retained for regression.
     *
     * Test data has:
     *   Month 1 — 1 inspection  (5.00)
     *   Month 2 — 3 inspections (10.00, 14.00, 18.50 — keep 18.50)
     *   Month 3 — 1 inspection  (30.00)
     *   Month 4 — 2 inspections (38.00, 42.50 — keep 42.50)
     */
    public function test_build_dataset_normalizes_duplicate_inspections(): void
    {
        $project = $this->createProjectWithDuplicateInspections();

        $dataset = $this->datasetBuilder->buildDataset($project);

        // Should have 4 normalized records (one per month)
        $this->assertCount(4, $dataset['y']);

        // Month 1 — only inspection: 5.00
        $this->assertEquals(5.00, $dataset['y'][0]);

        // Month 2 — latest of 3 inspections: 18.50
        $this->assertEquals(18.50, $dataset['y'][1]);

        // Month 3 — only inspection: 30.00
        $this->assertEquals(30.00, $dataset['y'][2]);

        // Month 4 — latest of 2 inspections: 42.50
        $this->assertEquals(42.50, $dataset['y'][3]);
    }

    /**
     * ------------------------------------------------------------------------
     * Normalized record count matches months, not total inspections
     * ------------------------------------------------------------------------
     *
     * Total DB records = 8 (1 + 3 + 1 + 2), but normalized output
     * should be 4 (one per calendar month).
     */
    public function test_build_dataset_normalized_count_is_less_than_total_records(): void
    {
        $project = $this->createProjectWithDuplicateInspections();

        $totalRecords = $project->progressHistory->count();
        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertEquals(4, $dataset['statistics']['observation_count']);
        $this->assertLessThan($totalRecords, $dataset['statistics']['observation_count']);
    }

    /**
     * ------------------------------------------------------------------------
     * Normalized records are sorted by month
     * ------------------------------------------------------------------------
     */
    public function test_build_dataset_normalized_records_are_sorted_by_month(): void
    {
        $project = $this->createProjectWithDuplicateInspections();

        $dataset = $this->datasetBuilder->buildDataset($project);

        $records = $dataset['records'];
        $this->assertCount(4, $records);

        // Verify ascending order
        for ($i = 1; $i < $records->count(); $i++) {
            $prev = $records[$i - 1]->month_label;
            $curr = $records[$i]->month_label;
            $this->assertLessThanOrEqual($curr, $prev);
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Normalization preserves original database records
     * ------------------------------------------------------------------------
     *
     * The normalization should not modify the underlying project history.
     * All original records must remain in the database unchanged.
     */
    public function test_build_dataset_preserves_original_records(): void
    {
        $project = $this->createProjectWithDuplicateInspections();

        $originalCount = $project->progressHistory->count();

        // Build dataset (should not mutate DB)
        $this->datasetBuilder->buildDataset($project);

        // Reload and verify all records still exist
        $project->load('progressHistory');
        $this->assertCount($originalCount, $project->progressHistory);

        // Verify specific values unchanged
        $month2Records = $project->progressHistory
            ->where('month_number', 2)
            ->sortBy('inspection_date')
            ->values();

        $this->assertCount(3, $month2Records);
        $this->assertEquals(10.00, $month2Records[0]->actual_progress_percent);
        $this->assertEquals(14.00, $month2Records[1]->actual_progress_percent);
        $this->assertEquals(18.50, $month2Records[2]->actual_progress_percent);
    }

    /*
    |==========================================================================
    | Edge Cases
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Build dataset with empty progress history
     * ------------------------------------------------------------------------
     *
     * When a project has no progress history records, the dataset should
     * return empty x/y arrays and observation_count = 0.
     */
    public function test_build_dataset_with_empty_history(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertIsArray($dataset['x']);
        $this->assertIsArray($dataset['y']);
        $this->assertCount(0, $dataset['x']);
        $this->assertCount(0, $dataset['y']);
        $this->assertEquals(0, $dataset['statistics']['observation_count']);
        $this->assertNull($dataset['statistics']['first_month']);
        $this->assertNull($dataset['statistics']['last_month']);
    }

    /**
     * ------------------------------------------------------------------------
     * Build dataset with a single progress record
     * ------------------------------------------------------------------------
     *
     * A single observation should produce x = [1] and y = [value].
     */
    public function test_build_dataset_with_single_record(): void
    {
        $project = $this->createProjectWithInsufficientData(observations: 1);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertCount(1, $dataset['x']);
        $this->assertCount(1, $dataset['y']);
        $this->assertEquals(1.0, $dataset['x'][0]);
        $this->assertEquals(5.00, $dataset['y'][0]);
        $this->assertEquals(1, $dataset['statistics']['observation_count']);
        $this->assertNotNull($dataset['statistics']['first_month']);
        $this->assertNotNull($dataset['statistics']['last_month']);
    }

    /**
     * ------------------------------------------------------------------------
     * Build dataset with zero records
     * ------------------------------------------------------------------------
     *
     * No progress history at all — empty arrays with observation_count = 0.
     */
    public function test_build_dataset_with_zero_records(): void
    {
        $project = $this->createProjectWithInsufficientData(observations: 0);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertCount(0, $dataset['x']);
        $this->assertCount(0, $dataset['y']);
        $this->assertEquals(0, $dataset['statistics']['observation_count']);
    }

    /*
    |==========================================================================
    | Known Dataset Validation
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Build dataset with known Project 7 data
     * ------------------------------------------------------------------------
     *
     * Uses the validated 10-observation dataset from Phase 3.
     * Verifies the builder produces the exact expected x and y arrays.
     *
     * Known Y values:
     *   [1.00, 2.00, 4.00, 7.00, 12.00, 18.00, 25.00, 33.00, 39.00, 45.00]
     */
    public function test_build_dataset_with_known_dataset(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertCount(10, $dataset['x']);
        $this->assertCount(10, $dataset['y']);

        // Sequential month indices
        $expectedX = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];
        $this->assertEquals($expectedX, $dataset['x']);

        // Known Project 7 progress values
        $expectedY = [1.00, 2.00, 4.00, 7.00, 12.00, 18.00, 25.00, 33.00, 39.00, 45.00];
        $this->assertEquals($expectedY, $dataset['y']);

        // Statistics
        $this->assertEquals(10, $dataset['statistics']['observation_count']);
        $this->assertEquals(10, $dataset['statistics']['current_month_index']);
    }

    /**
     * ------------------------------------------------------------------------
     * Build dataset records contain Eloquent models
     * ------------------------------------------------------------------------
     *
     * The 'records' collection should contain actual Model instances
     * that can be used for further analysis.
     */
    public function test_build_dataset_records_are_eloquent_models(): void
    {
        $project = $this->createProjectWithHistory(months: 5);

        $dataset = $this->datasetBuilder->buildDataset($project);

        foreach ($dataset['records'] as $record) {
            $this->assertInstanceOf(
                \App\Models\ProjectProgressHistory::class,
                $record
            );
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Same month + same inspection_date → highest id wins (Fix B)
     * ------------------------------------------------------------------------
     *
     * When two inspections share the same month AND the same inspection date,
     * the `inspection_date` sort is non-deterministic. The highest-id record
     * (the later write) must be retained so the "current progress" status card
     * never shows the older value.
     */
    public function test_build_dataset_picks_latest_id_when_same_month_and_same_inspection_date(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);
        $monthLabel = now()->subMonth()->format('Y-m');
        $inspectionDate = now()->subMonth()->endOfMonth()->subDays(2)->format('Y-m-d');

        ProjectBaselineSchedule::factory()->create([
            'project_id'                       => $project->id,
            'month_label'                      => $monthLabel,
            'planned_date'                     => now()->subMonth()->endOfMonth()->format('Y-m-d'),
            'planned_progress_percent'         => 20.00,
            'original_planned_progress_percent'=> 20.00,
        ]);

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_number'            => 1,
            'month_label'             => $monthLabel,
            'inspection_date'         => $inspectionDate,
            'actual_progress_percent' => 10.00,
            'progress_percentage'     => 10.00,
        ]);

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_number'            => 1,
            'month_label'             => $monthLabel,
            'inspection_date'         => $inspectionDate,
            'actual_progress_percent' => 60.00,
            'progress_percentage'     => 60.00,
        ]);

        $dataset = $this->datasetBuilder->buildDataset($project);

        $this->assertCount(1, $dataset['y']);
        $this->assertEquals(60.00, $dataset['y'][0]);
    }

    /**
     * ------------------------------------------------------------------------
     * Build dataset x and y are float arrays
     * ------------------------------------------------------------------------
     *
     * Both x and y values must be floats for regression compatibility.
     */
    public function test_build_dataset_x_and_y_are_floats(): void
    {
        $project = $this->createProjectWithHistory(months: 5);

        $dataset = $this->datasetBuilder->buildDataset($project);

        foreach ($dataset['x'] as $value) {
            $this->assertIsFloat($value);
        }

        foreach ($dataset['y'] as $value) {
            $this->assertIsFloat($value);
        }
    }

    /*
    |==========================================================================
    | Cross-Project Isolation
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Projects are isolated
     * ------------------------------------------------------------------------
     *
     * Building a dataset for one project should not include records
     * from other projects.
     */
    public function test_build_dataset_isolation_between_projects(): void
    {
        $projectA = $this->createProjectWithHistory(
            months: 3,
            actualProgress: [10.00, 20.00, 30.00]
        );

        $projectB = $this->createProjectWithHistory(
            months: 2,
            actualProgress: [50.00, 80.00]
        );

        $datasetA = $this->datasetBuilder->buildDataset($projectA);
        $datasetB = $this->datasetBuilder->buildDataset($projectB);

        $this->assertCount(3, $datasetA['y']);
        $this->assertEquals([10.0, 20.0, 30.0], $datasetA['y']);

        $this->assertCount(2, $datasetB['y']);
        $this->assertEquals([50.0, 80.0], $datasetB['y']);
    }
}
