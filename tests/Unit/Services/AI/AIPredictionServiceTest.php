<?php

namespace Tests\Unit\Services\AI;

use App\Models\PredictionHistory;
use App\Models\Project;
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
        $forecastService = new ForecastService(
            $regressionService,
            $polynomialService
        );
        $comparisonService = new BaselineComparisonService();
        $assessmentService = new ProjectAssessmentService();
        $completionService = new CompletionEstimationService();
        $completionComparisonService = new CompletionComparisonService($completionService);

        $this->predictionService = new AIPredictionService(
            $datasetBuilder,
            $regressionService,
            $polynomialService,
            $forecastService,
            $comparisonService,
            $assessmentService,
            $completionService,
            $completionComparisonService,
            new HistoricalSimilarityService()
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
            'completion',
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
     * 10-month project, current progress = 45.00, next predicted = 48.27
     */
    public function test_prediction_with_known_dataset(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $report = $this->predictionService->generatePrediction($project);

        // Current state
        $this->assertEquals(10, $report['current_state']['month_index']);
        $this->assertEquals(45.0, $report['current_state']['progress']);

        // Next prediction (Polynomial selected)
        $this->assertEquals(50.77, $report['next_prediction']['progress']);

        // Regression
        $this->assertEquals(10, $report['regression']['observation_count']);
        $this->assertEquals('Excellent', $report['regression']['fit_quality']);
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
    | Prediction History (Phase 7)
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Prediction history is saved after generatePrediction()
     * ------------------------------------------------------------------------
     *
     * Ensures that a PredictionHistory row is created with the correct
     * fields (project_id, regression_method, current_progress,
     * predicted_next, r_squared) and that actual_next remains null.
     */
    public function test_prediction_history_is_saved(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $historyBefore = PredictionHistory::where('project_id', $project->id)->count();
        $this->assertEquals(0, $historyBefore);

        $this->predictionService->generatePrediction($project);

        $historyAfter = PredictionHistory::where('project_id', $project->id)->get();
        $this->assertCount(1, $historyAfter);

        $record = $historyAfter->first();
        $this->assertEquals($project->id, $record->project_id);
        $this->assertEquals('polynomial', $record->regression_method);
        $this->assertEquals(45.0, $record->current_progress);
        $this->assertEquals(50.77, $record->predicted_next);
        $this->assertNotNull($record->r_squared);
        $this->assertNull($record->actual_next);
        $this->assertNotNull($record->regression_snapshot);
    }

    /**
     * ------------------------------------------------------------------------
     * Duplicate predictions are not saved (idempotency)
     * ------------------------------------------------------------------------
     *
     * Calling generatePrediction() twice with the same progress data
     * should not create a duplicate PredictionHistory row.
     */
    public function test_prediction_history_dedup(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $this->predictionService->generatePrediction($project);
        $this->predictionService->generatePrediction($project);

        $count = PredictionHistory::where('project_id', $project->id)->count();
        $this->assertEquals(1, $count);
    }

    /**
     * ------------------------------------------------------------------------
     * Prediction history is NOT saved for completed projects
     * ------------------------------------------------------------------------
     *
     * When current_progress >= 100, the service should skip saving
     * a prediction history record.
     */
    public function test_prediction_history_skipped_at_100_percent(): void
    {
        $project = $this->createCompletedProject();

        $this->predictionService->generatePrediction($project);

        $count = PredictionHistory::where('project_id', $project->id)->count();
        $this->assertEquals(0, $count);
    }

    /**
     * ------------------------------------------------------------------------
     * Prediction history is NOT saved for insufficient data
     * ------------------------------------------------------------------------
     */
    public function test_prediction_history_skipped_for_insufficient_data(): void
    {
        $project = $this->createProjectWithInsufficientData(observations: 0);

        $this->predictionService->generatePrediction($project);

        $count = PredictionHistory::where('project_id', $project->id)->count();
        $this->assertEquals(0, $count);
    }
}
