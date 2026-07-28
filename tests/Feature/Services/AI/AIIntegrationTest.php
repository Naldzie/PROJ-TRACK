<?php

namespace Tests\Feature\Services\AI;

use App\Models\Project;
use App\Services\AI\AIPredictionPresenter;
use App\Services\AI\AIPredictionService;
use App\Services\AI\BaselineComparisonService;
use App\Services\AI\CompletionEstimationService;
use App\Services\AI\ForecastService;
use App\Services\AI\ProjectAssessmentService;
use App\Services\AI\RecommendationEngine;
use App\Services\AI\Regression\LinearRegressionService;
use App\Services\AI\Regression\PolynomialRegressionService;
use App\Services\AI\Regression\RegressionDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * ============================================================================
 * AIIntegrationTest
 * ============================================================================
 *
 * Purpose
 * -------
 * End-to-end integration tests that validate the complete AI Prediction
 * pipeline — from project data through dataset building, regression,
 * forecasting, comparison, assessment, and presentation.
 *
 * These tests verify that all services work correctly together.
 *
 * Run With
 * --------
 *   php artisan test --filter=AIIntegrationTest
 *
 * Depends On
 * ----------
 * All AI sub-services operate together as a single pipeline.
 */
class AIIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    private RegressionDatasetBuilder $datasetBuilder;
    private LinearRegressionService $regressionService;
    private PolynomialRegressionService $polynomialService;
    private ForecastService $forecastService;
    private BaselineComparisonService $comparisonService;
    private ProjectAssessmentService $assessmentService;
    private AIPredictionService $predictionService;
    private AIPredictionPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->datasetBuilder = new RegressionDatasetBuilder();
        $this->regressionService = new LinearRegressionService();
        $this->polynomialService = new PolynomialRegressionService();
        $this->forecastService = new ForecastService(
            $this->regressionService,
            $this->polynomialService
        );
        $this->comparisonService = new BaselineComparisonService();
        $this->assessmentService = new ProjectAssessmentService();
        $this->completionService = new CompletionEstimationService();
        $this->predictionService = new AIPredictionService(
            $this->datasetBuilder,
            $this->regressionService,
            $this->polynomialService,
            $this->forecastService,
            $this->comparisonService,
            $this->assessmentService,
            $this->completionService
        );
        $this->presenter = new AIPredictionPresenter(
            $this->predictionService,
            new RecommendationEngine()
        );
    }

    /*
    |==========================================================================
    | Full Pipeline Consistency
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Full pipeline: dataset → regression → forecast → assessment
     * ------------------------------------------------------------------------
     *
     * Manually walks through each service step and verifies the output
     * of each stage is compatible with the next.
     */
    public function test_full_pipeline_consistency(): void
    {
        $project = $this->createProjectWithHistory(
            months: 8,
            actualProgress: [1, 3, 7, 12, 18, 25, 33, 40]
        );

        // Step 1: Build dataset
        $dataset = $this->datasetBuilder->buildDataset($project);
        $this->assertCount(8, $dataset['x']);
        $this->assertCount(8, $dataset['y']);
        $this->assertEquals(8, $dataset['statistics']['observation_count']);

        // Step 2: Run regression
        $analysis = $this->regressionService->analyzeDataset(
            $dataset['x'],
            $dataset['y']
        );
        $this->assertEquals(8, $analysis['observation_count']);
        $this->assertArrayHasKey('slope', $analysis);
        $this->assertArrayHasKey('intercept', $analysis);

        // Step 3: Generate forecast
        $currentMonthIndex = $dataset['statistics']['current_month_index'];
        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            $currentMonthIndex
        );
        // 8-month project, current = 8 → forecast = 0 remaining
        $this->assertCount(0, $forecast);

        // Step 4: Compare forecast
        $comparison = $this->comparisonService->compare($forecast);
        $this->assertCount(0, $comparison);

        // Step 5: Assess
        $assessment = $this->assessmentService->assess($comparison);
        $this->assertCount(0, $assessment);
    }

    /**
     * ------------------------------------------------------------------------
     * Forecast + comparison + assessment with remaining months
     * ------------------------------------------------------------------------
     *
     * 10-month project, current = 7 → 3 forecast months
     * Then compare and assess those 3 records.
     */
    public function test_pipeline_with_forecast_and_assessment(): void
    {
        $project = $this->createProjectWithHistory(
            months: 10,
            actualProgress: [3, 5, 7, 9, 11, 13, 15, 17, 19, 21]
        );

        $dataset = $this->datasetBuilder->buildDataset($project);
        $analysis = $this->regressionService->analyzeDataset(
            $dataset['x'],
            $dataset['y']
        );

        // current = 7 → 3 remaining months (8, 9, 10)
        $forecast = $this->forecastService->generateFutureForecast(
            $project,
            $analysis,
            currentMonthIndex: 7
        );

        $this->assertCount(3, $forecast);

        // Verify forecast month indices
        $this->assertEquals(8, $forecast[0]['month_index']);
        $this->assertEquals(9, $forecast[1]['month_index']);
        $this->assertEquals(10, $forecast[2]['month_index']);

        // Compare
        $comparison = $this->comparisonService->compare($forecast);
        $this->assertCount(3, $comparison);
        foreach ($comparison as $record) {
            $this->assertArrayHasKey('variance', $record);
        }

        // Assess
        $assessment = $this->assessmentService->assess($comparison);
        $this->assertCount(3, $assessment);
        foreach ($assessment as $record) {
            $this->assertArrayHasKey('status', $record);
            $this->assertArrayHasKey('risk_level', $record);
        }
    }

    /*
    |==========================================================================
    | End-to-End via AIPredictionService
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * AIPredictionService output is consistent across all sections
     * ------------------------------------------------------------------------
     *
     * The regression, statistics, forecast, and assessment sections
     * should all reference the same observation count.
     */
    public function test_prediction_service_consistency(): void
    {
        $project = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [2, 5, 9, 14, 20, 27]
        );

        $report = $this->predictionService->generatePrediction($project);

        // observation_count must match across sections
        $regressionCount = $report['regression']['observation_count'];
        $statsCount = $report['statistics']['observation_count'];

        $this->assertEquals(6, $regressionCount);
        $this->assertSame($regressionCount, $statsCount);
        $this->assertEquals(6, $report['current_state']['month_index']);

        // current_progress = last y value
        $this->assertEquals(27.0, $report['current_state']['progress']);
    }

    /*
    |==========================================================================
    | Duplicate Inspections Integration
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Project with duplicate inspections flows through all services
     * ------------------------------------------------------------------------
     *
     * Builds dataset from a project with multiple inspections per month,
     * runs regression, and verifies the output is consistent.
     */
    public function test_duplicate_inspections_through_pipeline(): void
    {
        $project = $this->createProjectWithDuplicateInspections();

        // Builder correctly normalizes
        $dataset = $this->datasetBuilder->buildDataset($project);
        $this->assertCount(4, $dataset['y']);
        $this->assertEquals([5.0, 18.5, 30.0, 42.5], $dataset['y']);

        // Regression runs on normalized data
        $analysis = $this->regressionService->analyzeDataset(
            $dataset['x'],
            $dataset['y']
        );
        $this->assertEquals(4, $analysis['observation_count']);

        // Prediction service handles it
        $report = $this->predictionService->generatePrediction($project);
        $this->assertEquals(4, $report['statistics']['observation_count']);
        $this->assertEquals(42.5, $report['current_state']['progress']);
    }

    /*
    |==========================================================================
    | Presenter End-to-End
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Presenter output for normal project
     * ------------------------------------------------------------------------
     *
     * Verify that the presenter output for a normal project has
     * consistent values across all sections.
     */
    public function test_presenter_integration_normal(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        // Verify consistency
        $this->assertEquals(11.0, $result['current_progress']);
        $this->assertEquals(5, $result['current_month_index']);
        $this->assertEquals(5, $result['regression_info']['observation_count']);
        $this->assertFalse($result['is_insufficient_data']);
        $this->assertFalse($result['is_completed']);
    }

    /**
     * ------------------------------------------------------------------------
     * Presenter handles the full span of project types
     * ------------------------------------------------------------------------
     */
    public function test_presenter_all_project_types(): void
    {
        // Completed
        $completed = $this->createCompletedProject();
        $completedResult = $this->presenter->present($completed);
        $this->assertTrue($completedResult['is_completed']);

        // Insufficient data (0 obs)
        $empty = Project::factory()->create(['status' => 'Ongoing']);
        $emptyResult = $this->presenter->present($empty);
        $this->assertTrue($emptyResult['is_insufficient_data']);

        // Normal
        $normal = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );
        $normalResult = $this->presenter->present($normal);
        $this->assertFalse($normalResult['is_completed']);
        $this->assertFalse($normalResult['is_insufficient_data']);
    }

    /**
     * ------------------------------------------------------------------------
     * Known dataset end-to-end match
     * ------------------------------------------------------------------------
     *
     * Verify that the full AIPredictionService → Presenter pipeline
     * produces the correct values for the validated Project 7 dataset.
     */
    public function test_known_dataset_end_to_end(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $report = $this->predictionService->generatePrediction($project);

        // Regression values
        $this->assertEquals(10, $report['regression']['observation_count']);
        $this->assertEquals(45.0, $report['current_state']['progress']);

        // Next prediction uses regression equation
        $predictedNext = $report['next_prediction']['progress'];
        $this->assertGreaterThan(45.0, $predictedNext);

        // Presenter
        $presented = $this->presenter->present($project);
        $this->assertEquals(45.0, $presented['current_progress']);
        $this->assertEquals(10, $presented['current_month_index']);
        $this->assertEquals($predictedNext, $presented['next_prediction']);
    }

    /*
    |==========================================================================
    | Edge Case: No Baselines
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Project without baselines still produces valid output
     * ------------------------------------------------------------------------
     *
     * The forecast should be empty (no baselines to forecast against),
     * but regression and current state should still be valid.
     */
    public function test_project_without_baselines(): void
    {
        $project = $this->createProjectWithoutBaselines(months: 5);

        $report = $this->predictionService->generatePrediction($project);

        // Regression runs on the 5 progress records
        $this->assertEquals(5, $report['regression']['observation_count']);
        $this->assertEquals(5, $report['current_state']['month_index']);

        // Forecast is empty (no baselines)
        $this->assertCount(0, $report['forecast']);
        $this->assertCount(0, $report['assessment']);

        // Presenter still works
        $presented = $this->presenter->present($project);
        $this->assertFalse($presented['is_completed']);
        $this->assertFalse($presented['is_insufficient_data']);
        $this->assertIsFloat($presented['current_progress']);
    }
}
