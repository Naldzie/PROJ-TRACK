<?php

namespace Tests\Unit\Services\AI;

use App\Models\PredictionHistory;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Services\AI\AIPredictionService;
use App\Services\AI\BaselineComparisonService;
use App\Services\AI\CompletionComparisonService;
use App\Services\AI\CompletionEstimationService;
use App\Services\AI\ForecastService;
use App\Services\AI\HistoricalSimilarityService;
use App\Services\AI\ProjectAssessmentService;
use App\Services\AI\Regression\LinearRegressionService;
use App\Services\AI\Regression\PolynomialRegressionService;
use App\Services\AI\Regression\RegressionDatasetBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * ============================================================================
 * AIPredictionServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the full AI prediction pipeline end-to-end.
 *
 * AIPredictionService is the only public entry point of the AI module.
 * It orchestrates:
 *   1. Dataset building   (RegressionDatasetBuilder)
 *   2. Regression analysis (LinearRegressionService)
 *   3. Next month predict
 *   4. Future forecast     (ForecastService)
 *   5. Baseline comparison (BaselineComparisonService)
 *   6. Risk assessment     (ProjectAssessmentService)
 *
 * Run With
 * --------
 *   php artisan test --filter=AIPredictionServiceTest
 */
class AIPredictionServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    private AIPredictionService $predictionService;

    protected function setUp(): void
    {
        parent::setUp();

        $datasetBuilder = new RegressionDatasetBuilder();
        $regressionService = new LinearRegressionService();
        $polynomialService = new PolynomialRegressionService();
        $forecastService = new ForecastService($regressionService, $polynomialService);
        $comparisonService = new BaselineComparisonService();
        $assessmentService = new ProjectAssessmentService();
        $completionService = new CompletionEstimationService();
        $completionComparisonService = new CompletionComparisonService($completionService);
        $historicalSimilarityService = new HistoricalSimilarityService();

        $this->predictionService = new AIPredictionService(
            $datasetBuilder,
            $regressionService,
            $polynomialService,
            $forecastService,
            $comparisonService,
            $assessmentService,
            $completionService,
            $completionComparisonService,
            $historicalSimilarityService
        );
    }

    /*
    |==========================================================================
    | Prediction Report Structure
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Prediction report has all expected top-level sections
     * ------------------------------------------------------------------------
     */
    public function test_prediction_report_has_expected_sections(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);

        $expectedSections = [
            'current_state',
            'next_prediction',
            'forecast',
            'assessment',
            'regression',
            'statistics',
        ];

        foreach ($expectedSections as $section) {
            $this->assertArrayHasKey($section, $report, "Missing section: {$section}");
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Current state has progress and month_index
     * ------------------------------------------------------------------------
     */
    public function test_current_state_has_progress_and_month_index(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);

        $this->assertArrayHasKey('progress', $report['current_state']);
        $this->assertArrayHasKey('month_index', $report['current_state']);
    }

    /**
     * ------------------------------------------------------------------------
     * Next prediction has progress key
     * ------------------------------------------------------------------------
     */
    public function test_next_prediction_has_progress(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);

        $this->assertArrayHasKey('progress', $report['next_prediction']);
    }

    /**
     * ------------------------------------------------------------------------
     * Forecast is an array
     * ------------------------------------------------------------------------
     */
    public function test_forecast_is_array(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);

        $this->assertIsArray($report['forecast']);
    }

    /**
     * ------------------------------------------------------------------------
     * Assessment is an array
     * ------------------------------------------------------------------------
     */
    public function test_assessment_is_array(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);

        $this->assertIsArray($report['assessment']);
    }

    /**
     * ------------------------------------------------------------------------
     * Regression section has analysis keys
     * ------------------------------------------------------------------------
     */
    public function test_regression_has_analysis_keys(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);
        $regression = $report['regression'];

        $expectedKeys = [
            'observation_count', 'mean_x', 'mean_y',
            'slope', 'intercept', 'equation',
            'r_squared', 'fit_quality',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $regression, "Missing regression key: {$key}");
        }
    }

    /*
    |==========================================================================
    | Prediction Values
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Current state progress matches last observation
     * ------------------------------------------------------------------------
     */
    public function test_current_progress_matches_last_observation(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);

        $this->assertEquals(11.0, $report['current_state']['progress']);
    }

    /**
     * ------------------------------------------------------------------------
     * Current month index matches observation count
     * ------------------------------------------------------------------------
     */
    public function test_current_month_index_matches_observation_count(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);

        $this->assertEquals(5, $report['current_state']['month_index']);
    }

    /**
     * ------------------------------------------------------------------------
     * Next predicted progress uses regression equation
     * ------------------------------------------------------------------------
     *
     * Dataset: y = 2x + 1 (slope=2, intercept=1)
     * Current month = 5, next month = 6
     * Expected: 2(6) + 1 = 13
     */
    public function test_next_predicted_progress_matches_regression(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);

        $this->assertEquals(13.0, $report['next_prediction']['progress']);
    }

    /**
     * ------------------------------------------------------------------------
     * Forecast count matches remaining baseline months
     * ------------------------------------------------------------------------
     *
     * 5-month project → 5 baselines.
     * Current month = 5 → remaining = 0.
     */
    public function test_forecast_count_with_complete_baseline(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $report = $this->predictionService->generatePrediction($project);

        // All 5 months observed → no remaining baseline → 0 forecast
        $this->assertCount(0, $report['forecast']);
    }

    /*
    |==========================================================================
    | Known Dataset
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Prediction with known Project 7 dataset
     * ------------------------------------------------------------------------
     *
     * 10-month project, current progress = 45.00, next predicted = 50.77
     *
     * The dataset curves upward, so polynomial regression is selected
     * (R² 0.9991 vs 0.9584 for SLR) and predicts 50.77 for month 11.
     */
    public function test_prediction_with_known_dataset(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $report = $this->predictionService->generatePrediction($project);

        // Current state
        $this->assertEquals(10, $report['current_state']['month_index']);
        $this->assertEquals(45.0, $report['current_state']['progress']);

        // Next prediction (polynomial: -0.0612(11³) + 1.4073(11²) - 3.8193(11) + 3.9333)
        $this->assertEquals(50.77, $report['next_prediction']['progress']);

        // Regression
        $this->assertEquals(10, $report['regression']['observation_count']);
        $this->assertEquals('Excellent', $report['regression']['fit_quality']);
    }

    /**
     * ------------------------------------------------------------------------
     * Fix H: next-month prediction is floored at the latest actual progress
     * ------------------------------------------------------------------------
     *
     * A declining dataset (y = [80, 70, 60, 50, 40], slope = -10) predicts
     * next month at 30, below the current 40. The monotonic clamp must
     * report next month at >= current progress, so 40.0.
     */
    public function test_next_prediction_floored_at_latest_actual(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [80, 70, 60, 50, 40]
        );

        $report = $this->predictionService->generatePrediction($project);

        $this->assertEquals(40.0, $report['current_state']['progress']);
        $this->assertGreaterThanOrEqual(
            $report['current_state']['progress'],
            $report['next_prediction']['progress']
        );
        $this->assertEquals(40.0, $report['next_prediction']['progress']);
    }

    /*
    |==========================================================================
    | Phase 14 Step 1 — Next-Month Prediction Band
    |==========================================================================
    */

    /**
     * Known dataset: polynomial selected (R² 0.9991), next = 50.77.
     * No fulfilled history rows and RMSE ~0.46 → margin falls to the 2.0%
     * floor → band 48.77–52.77.
     */
    public function test_next_prediction_report_includes_band(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $report = $this->predictionService->generatePrediction($project);

        $this->assertEquals(50.77, $report['next_prediction']['progress']);
        $this->assertEquals(48.77, $report['next_prediction']['progress_low']);
        $this->assertEquals(52.77, $report['next_prediction']['progress_high']);
        $this->assertEquals(2.0, $report['next_prediction']['margin']);
    }

    /**
     * Fulfilled history rows with large forecast error widen the margin
     * above the RMSE/floor: margin = max(RMSE, backtestAvgAbsError, 2.0).
     */
    public function test_backtest_error_widens_margin(): void
    {
        $project = $this->createProjectWithKnownDataset();

        // Simulate past forecasts that were 8% off on average.
        foreach ([1, 2, 3] as $i) {
            PredictionHistory::create([
                'project_id'           => $project->id,
                'month_label'          => '2025-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'regression_method'    => 'slr',
                'current_progress'     => $i * 10,
                'predicted_next'       => 20.0,
                'actual_next'          => 20.0 + 8.0 * ($i % 2 === 0 ? 1 : -1),
                'r_squared'            => 0.9,
                'regression_snapshot'  => ['slope' => 1, 'intercept' => 0],
            ]);
        }

        $report = $this->predictionService->generatePrediction($project);

        $this->assertEquals(8.0, $report['next_prediction']['margin']);
        $this->assertEquals(50.77 - 8.0, $report['next_prediction']['progress_low']);
        $this->assertEquals(50.77 + 8.0, $report['next_prediction']['progress_high']);
    }

    /**
     * Band is clamped to [0, 100]: a point near the bounds never spills out.
     */
    public function test_band_is_clamped_to_0_100(): void
    {
        $project = $this->createProjectWithHistory(
            months: 10,
            actualProgress: [5, 15, 30, 50, 75, 95, 100, 100, 100, 100]
        );

        $report = $this->predictionService->generatePrediction($project);

        $this->assertGreaterThanOrEqual(0, $report['next_prediction']['progress_low']);
        $this->assertLessThanOrEqual(100, $report['next_prediction']['progress_high']);
        $this->assertLessThanOrEqual(
            100,
            $report['next_prediction']['progress_high']
        );
    }

    /**
     * savePredictionHistory persists the band (predicted_low/high) on the
     * keep-latest row, and keep-latest refresh updates them in place.
     */
    public function test_prediction_history_persists_and_refreshes_band(): void
    {
        $project = $this->createMonthAlignedProject();

        $this->predictionService->generatePrediction($project);

        $rows = PredictionHistory::where('project_id', $project->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]->predicted_low);
        $this->assertNotNull($rows[0]->predicted_high);
        $this->assertLessThanOrEqual(
            $rows[0]->predicted_next,
            $rows[0]->predicted_low
        );
        $this->assertGreaterThanOrEqual(
            $rows[0]->predicted_next,
            $rows[0]->predicted_high
        );

        // A second inspection changes the forecast; the same row is refreshed
        // with a new band (keep-latest), not duplicated.
        $firstId = $rows[0]->id;
        $this->createProgressRecord($project, '2026-05', 4.0, '2026-05-15');
        $this->predictionService->generatePrediction($project);

        $juneRows = PredictionHistory::where('project_id', $project->id)
            ->where('month_label', '2026-06')
            ->get();
        $this->assertCount(1, $juneRows);
        $this->assertNotEquals($firstId, $juneRows[0]->id);
        $this->assertNotNull($juneRows[0]->predicted_low);
        $this->assertNotNull($juneRows[0]->predicted_high);
    }

    /*
    |==========================================================================
    | Edge Cases
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Empty history (no progress records)
     * ------------------------------------------------------------------------
     *
     * Should handle gracefully: current progress = 0, empty forecast.
     */
    public function test_prediction_with_empty_history(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $report = $this->predictionService->generatePrediction($project);

        $this->assertEquals(0, $report['current_state']['progress']);
        $this->assertEquals(0, $report['current_state']['month_index']);
        $this->assertCount(0, $report['forecast']);
        $this->assertCount(0, $report['assessment']);
        $this->assertEquals(0, $report['statistics']['observation_count']);
    }

    /*
    |==========================================================================
    | Prediction History Auto-Save (Phase 7 fix)
    |==========================================================================
    */

    /**
     * Create a project with baseline schedules for Feb..Jun 2026 and progress
     * records for Feb, Mar, Apr — so April is the latest observation and the
     * prediction generated from it is FOR May.
     */
    private function createMonthAlignedProject(): Project
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        foreach (['2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $i => $month) {
            ProjectBaselineSchedule::create([
                'project_id'                       => $project->id,
                'month_label'                      => $month,
                'planned_date'                     => Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d'),
                'planned_progress_percent'         => ($i + 1) * 5,
                'original_planned_progress_percent'=> ($i + 1) * 5,
            ]);
        }

        $progress = ['2026-02' => 1.0, '2026-03' => 1.5, '2026-04' => 2.0];
        foreach ($progress as $month => $pct) {
            $this->createProgressRecord($project, $month, $pct);
        }

        return $project;
    }

    /**
     * Create a single progress record for a month.
     */
    private function createProgressRecord(Project $project, string $month, float $pct, ?string $inspectionDate = null): ProjectProgressHistory
    {
        return ProjectProgressHistory::create([
            'project_id'              => $project->id,
            'month_label'             => $month,
            'inspection_date'         => $inspectionDate ?? Carbon::parse($month . '-25')->format('Y-m-d'),
            'actual_progress_percent' => $pct,
            'progress_percentage'     => $pct,
        ]);
    }

    /**
     * April 2% → prediction saved FOR May, linked to the May baseline.
     */
    public function test_prediction_saves_predicted_month_label_and_baseline_id(): void
    {
        $project = $this->createMonthAlignedProject();

        $this->predictionService->generatePrediction($project);

        $rows = PredictionHistory::where('project_id', $project->id)->get();
        $this->assertCount(1, $rows);

        $mayBaseline = ProjectBaselineSchedule::where('project_id', $project->id)
            ->where('month_label', '2026-05')
            ->first();

        $this->assertEquals('2026-05', $rows[0]->month_label);
        $this->assertEquals($mayBaseline->id, $rows[0]->baseline_schedule_id);
        $this->assertEquals(2.0, $rows[0]->current_progress);
        $this->assertNotNull($rows[0]->predicted_next);
    }

    /**
     * Re-running the prediction for the same project month does not create a
     * second row for the same predicted month.
     */
    public function test_prediction_does_not_duplicate_on_repeated_generation(): void
    {
        $project = $this->createMonthAlignedProject();

        $this->predictionService->generatePrediction($project);
        $this->predictionService->generatePrediction($project);
        $this->predictionService->generatePrediction($project);

        $this->assertCount(1, PredictionHistory::where('project_id', $project->id)->get());
    }

    /**
     * A second inspection in the same month UPDATES the forecast row for the
     * next predicted month in place (keep-latest). Identical same-data
     * re-generation stays a no-op; no duplicate rows are ever created for a
     * month whose forecast already exists.
     */
    public function test_second_inspection_updates_prediction_in_place(): void
    {
        $project = $this->createMonthAlignedProject();

        // April → May prediction saved
        $this->predictionService->generatePrediction($project);

        // May 15 (4%) → prediction for June saved
        $this->createProgressRecord($project, '2026-05', 4.0, '2026-05-15');
        $this->predictionService->generatePrediction($project);
        $this->assertCount(2, PredictionHistory::where('project_id', $project->id)->get());

        // May 22 (4.5%) → the June forecast changes: the SAME June row is
        // refreshed in place (keep-latest), not duplicated.
        $this->createProgressRecord($project, '2026-05', 4.5, '2026-05-22');
        $this->predictionService->generatePrediction($project);

        $rows = PredictionHistory::where('project_id', $project->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $juneRows = $rows->where('month_label', '2026-06')->values();
        $this->assertCount(1, $juneRows);
        $this->assertEquals(4.5, $juneRows[0]->current_progress);
        $this->assertNotNull($juneRows[0]->predicted_next);

        // Identical same-data re-generation adds nothing more.
        $this->predictionService->generatePrediction($project);
        $this->assertCount(2, PredictionHistory::where('project_id', $project->id)->get());
    }
}
