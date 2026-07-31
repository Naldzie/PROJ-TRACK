<?php

namespace Tests\Unit\Services\AI;

use App\Models\Project;
use App\Services\AI\ForecastService;
use App\Services\AI\Regression\LinearRegressionService;
use App\Services\AI\Regression\RegressionDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * ============================================================================
 * ForecastServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates that ForecastService correctly generates future progress
 * forecasts using regression analysis and the project's Effective Baseline.
 *
 * Key Behaviour
 * -------------
 * • Generates one forecast record per remaining baseline month
 * • Uses LinearRegressionService to predict cumulative progress
 * • Clamps predictions to valid range (0%–100%)
 * • Returns structured forecast records
 *
 * Depends On
 * ----------
 * ForecastService           — the service under test
 * LinearRegressionService   — used internally for prediction
 * RegressionDatasetBuilder   — used to prepare the dataset for regression
 * CreatesProjectWithHistory — test helper for creating test data
 *
 * Run With
 * --------
 *   php artisan test --filter=ForecastServiceTest
 */
class ForecastServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    private ForecastService $forecastService;
    private LinearRegressionService $regressionService;
    private RegressionDatasetBuilder $datasetBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->regressionService = new LinearRegressionService();
        $this->datasetBuilder = new RegressionDatasetBuilder();
        $this->forecastService = new ForecastService(
            $this->regressionService
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Build a regression analysis from a project's data
     * ------------------------------------------------------------------------
     *
     * Convenience method used by multiple tests.
     */
    private function buildAnalysis(Project $project): array
    {
        $dataset = $this->datasetBuilder->buildDataset($project);

        return $this->regressionService->analyzeDataset(
            $dataset['x'],
            $dataset['y']
        );
    }

    /*
    |==========================================================================
    | Forecast Structure
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Forecast returns array of records
     * ------------------------------------------------------------------------
     */
    public function test_forecast_returns_array(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 3
        );

        $this->assertIsArray($forecast);
    }

    /**
     * ------------------------------------------------------------------------
     * Each forecast record has all expected keys
     * ------------------------------------------------------------------------
     */
    public function test_forecast_record_has_expected_keys(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 3
        );

        $this->assertNotEmpty($forecast);

        $expectedKeys = [
            'month_index',
            'month_label',
            'planned_date',
            'planned_progress',
            'predicted_progress',
        ];

        foreach ($forecast as $record) {
            foreach ($expectedKeys as $key) {
                $this->assertArrayHasKey($key, $record, "Missing key: {$key}");
            }
        }
    }

    /*
    |==========================================================================
    | Forecast Count
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Forecast count equals remaining baseline months
     * ------------------------------------------------------------------------
     *
     * 5-month project, current month index = 3
     * → 2 remaining months (4, 5)
     */
    public function test_forecast_count_with_remaining_months(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 3
        );

        $this->assertCount(2, $forecast);
    }

    /**
     * ------------------------------------------------------------------------
     * Forecast count with more remaining months
     * ------------------------------------------------------------------------
     *
     * 10-month project, current month index = 4
     * → 6 remaining months (5, 6, 7, 8, 9, 10)
     */
    public function test_forecast_count_with_many_remaining(): void
    {
        $project = $this->createProjectWithHistory(
            months: 10,
            actualProgress: [1, 2, 4, 7, 12, 18, 25, 33, 39, 45]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 4
        );

        $this->assertCount(6, $forecast);
    }

    /**
     * ------------------------------------------------------------------------
     * Forecast is empty when current month >= baseline count
     * ------------------------------------------------------------------------
     *
     * 5-month project, current month index = 5
     * → 0 remaining months (no baseline months left)
     */
    public function test_forecast_empty_at_baseline_end(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 5
        );

        $this->assertCount(0, $forecast);
    }

    /**
     * ------------------------------------------------------------------------
     * Forecast is empty when current month exceeds baseline count
     * ------------------------------------------------------------------------
     */
    public function test_forecast_empty_when_past_baseline_end(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 10
        );

        $this->assertCount(0, $forecast);
    }

    /*
    |==========================================================================
    | Forecast Month Indices
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Forecast month indices start at currentMonthIndex + 1
     * ------------------------------------------------------------------------
     *
     * 5-month project, current month index = 2
     * → month_index: 3, 4, 5
     */
    public function test_forecast_month_indices_are_sequential(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 2
        );

        $this->assertCount(3, $forecast);
        $this->assertEquals(3, $forecast[0]['month_index']);
        $this->assertEquals(4, $forecast[1]['month_index']);
        $this->assertEquals(5, $forecast[2]['month_index']);
    }

    /*
    |==========================================================================
    | Predicted Values
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Forecast uses regression equation for prediction
     * ------------------------------------------------------------------------
     *
     * Dataset: y = 2x + 1 (slope=2, intercept=1)
     *
     * 10-month project (y = [3, 5, 7, ..., 21]), current = 7
     * Remaining months: 8, 9, 10
     *   Month 8:  2(8) + 1   = 17
     *   Month 9:  2(9) + 1   = 19
     *   Month 10: 2(10) + 1  = 21
     */
    public function test_forecast_predicted_values_match_regression(): void
    {
        $yValues = [];
        for ($i = 1; $i <= 10; $i++) {
            $yValues[] = (2 * $i) + 1;
        }

        $project = $this->createProjectWithHistory(
            months: 10,
            actualProgress: $yValues
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 7
        );

        $this->assertCount(3, $forecast);
        $this->assertEquals(17.0, $forecast[0]['predicted_progress']);
        $this->assertEquals(19.0, $forecast[1]['predicted_progress']);
        $this->assertEquals(21.0, $forecast[2]['predicted_progress']);
    }

    /**
     * ------------------------------------------------------------------------
     * Forecast with known Project 7 dataset
     * ------------------------------------------------------------------------
     *
     * Known regression: slope ≈ 5.2121, intercept ≈ -10.0667
     *
     * 10-month project, current = 8
     * Remaining: months 9, 10
     *   Month  9: 5.2121(9)  - 10.0667 ≈ 36.84
     *   Month 10: 5.2121(10) - 10.0667 ≈ 42.05
     */
    public function test_forecast_with_known_dataset(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 8
        );

        $this->assertCount(2, $forecast);

        // Month 9
        $this->assertEquals(9, $forecast[0]['month_index']);
        $expectedMonth9 = round((5.2121212121212 * 9) - 10.0666666666667, 2);
        $this->assertEquals($expectedMonth9, $forecast[0]['predicted_progress']);

        // Month 10
        $this->assertEquals(10, $forecast[1]['month_index']);
        $expectedMonth10 = round((5.2121212121212 * 10) - 10.0666666666667, 2);
        $this->assertEquals($expectedMonth10, $forecast[1]['predicted_progress']);
    }

    /*
    |==========================================================================
    | Clamping
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * All forecast predictions are in valid range [0, 100]
     * ------------------------------------------------------------------------
     *
     * The service clamps predictions to ensure they never exceed
     * the valid cumulative progress range.
     */
    public function test_forecast_predictions_in_valid_range(): void
    {
        $project = $this->createProjectWithHistory(
            months: 10,
            actualProgress: [1, 2, 4, 7, 12, 18, 25, 33, 39, 45]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 8
        );

        foreach ($forecast as $record) {
            $this->assertGreaterThanOrEqual(0, $record['predicted_progress']);
            $this->assertLessThanOrEqual(100, $record['predicted_progress']);
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Forecast clamps over-100 predictions to 100
     * ------------------------------------------------------------------------
     *
     * A fast-progress dataset with steep slope produces predictions > 100
     * for later forecast months, which should be clamped to 100%.
     */
    public function test_forecast_clamps_over_100_to_100(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Rapid progress: [5, 15, 30, 50, 75, 95, 100, 100, 100, 100]
        | Regression: slope ≈ 11.94, intercept ≈ 1.33
        | Month 9:  11.94(9)  + 1.33 ≈ 108.79 → clamped to 100
        | Month 10: 11.94(10) + 1.33 ≈ 120.73 → clamped to 100
        |--------------------------------------------------------------------------
        */

        $project = $this->createProjectWithHistory(
            months: 10,
            actualProgress: [5, 15, 30, 50, 75, 95, 100, 100, 100, 100]
        );

        $analysis = $this->buildAnalysis($project);

        // current = 8 → forecast months 9, 10
        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 8
        );

        $this->assertCount(2, $forecast);
        $this->assertEquals(100.0, $forecast[0]['predicted_progress']);
        $this->assertEquals(100.0, $forecast[1]['predicted_progress']);
    }

    /*
    |==========================================================================
    | Planned Progress
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Forecast planned_progress matches baseline
     * ------------------------------------------------------------------------
     *
     * The planned_progress in each forecast record should reflect the
     * project's Effective Baseline planned progress for that month.
     */
    public function test_forecast_planned_progress_matches_baseline(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 3
        );

        $this->assertCount(2, $forecast);

        // Baseline month 4 (index 3) → planned 80%
        // Baseline month 5 (index 4) → planned 100%
        $baselines = $project->baselineSchedules;

        $this->assertEquals(
            (float) $baselines[3]->planned_progress_percent,
            $forecast[0]['planned_progress']
        );

        $this->assertEquals(
            (float) $baselines[4]->planned_progress_percent,
            $forecast[1]['planned_progress']
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Forecast planned_date matches baseline
     * ------------------------------------------------------------------------
     */
    public function test_forecast_planned_date_matches_baseline(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $analysis = $this->buildAnalysis($project);

        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 3
        );

        $baselines = $project->baselineSchedules;

        $this->assertEquals(
            $baselines[3]->planned_date,
            $forecast[0]['planned_date']
        );

        $this->assertEquals(
            $baselines[4]->planned_date,
            $forecast[1]['planned_date']
        );
    }
}
