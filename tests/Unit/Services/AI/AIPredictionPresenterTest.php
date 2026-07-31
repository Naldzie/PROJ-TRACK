<?php

namespace Tests\Unit\Services\AI;

use App\Models\Project;
use App\Services\AI\AIPredictionPresenter;
use App\Services\AI\AIPredictionService;
use App\Services\AI\BaselineComparisonService;
use App\Services\AI\CompletionComparisonService;
use App\Services\AI\CompletionEstimationService;
use App\Services\AI\ForecastService;
use App\Services\AI\HistoricalSimilarityService;
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
 * AIPredictionPresenterTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the AIPredictionPresenter — the blade-facing layer that
 * transforms raw prediction data into display-ready format.
 *
 * Three Branches Tested
 * ---------------------
 * 1. Completed project  → presentCompleted()
 * 2. Insufficient data  → presentInsufficientData()
 * 3. Normal prediction  → presentNormal()
 *
 * Run With
 * --------
 *   php artisan test --filter=AIPredictionPresenterTest
 */
class AIPredictionPresenterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    private AIPredictionPresenter $presenter;

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

        $predictionService = new AIPredictionService(
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

        $recommendationEngine = new RecommendationEngine();

        $this->presenter = new AIPredictionPresenter(
            $predictionService,
            $recommendationEngine
        );
    }

    /*
    |==========================================================================
    | Completed Project
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Completed project returns is_completed flag
     * ------------------------------------------------------------------------
     */
    public function test_completed_project_returns_completed_flag(): void
    {
        $project = $this->createCompletedProject();

        $result = $this->presenter->present($project);

        $this->assertTrue($result['is_completed']);
        $this->assertTrue($result['is_mockup']);
    }

    /**
     * ------------------------------------------------------------------------
     * Completed project has completion dates
     * ------------------------------------------------------------------------
     */
    public function test_completed_project_has_dates(): void
    {
        $project = $this->createCompletedProject();

        $result = $this->presenter->present($project);

        $this->assertArrayHasKey('actual_completion', $result);
        $this->assertArrayHasKey('planned_completion', $result);
        $this->assertArrayHasKey('completion_delay', $result);
    }

    /**
     * ------------------------------------------------------------------------
     * Completed project has empty recommendations
     * ------------------------------------------------------------------------
     */
    public function test_completed_project_empty_recommendations(): void
    {
        $project = $this->createCompletedProject();

        $result = $this->presenter->present($project);

        $this->assertIsArray($result['structured_recommendations']);
        $this->assertCount(0, $result['structured_recommendations']);
    }

    /*
    |==========================================================================
    | Insufficient Data
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Insufficient data returns is_insufficient_data flag
     * ------------------------------------------------------------------------
     */
    public function test_insufficient_data_returns_flag(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $result = $this->presenter->present($project);

        $this->assertTrue($result['is_insufficient_data']);
        $this->assertFalse($result['is_completed']);
        $this->assertTrue($result['is_mockup']);
    }

    /**
     * ------------------------------------------------------------------------
     * Insufficient data returns correct status text
     * ------------------------------------------------------------------------
     */
    public function test_insufficient_data_status_text(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $result = $this->presenter->present($project);

        $this->assertEquals('INSUFFICIENT DATA', $result['status']);
        $this->assertEquals('unknown', $result['risk_level']);
        $this->assertEquals('gray', $result['status_color']);
    }

    /**
     * ------------------------------------------------------------------------
     * Insufficient data returns placeholder progress values
     * ------------------------------------------------------------------------
     */
    public function test_insufficient_data_placeholder_progress(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $result = $this->presenter->present($project);

        $this->assertIsFloat($result['current_progress']);
        $this->assertIsFloat($result['expected_progress']);
        $this->assertIsFloat($result['progress_gap']);
    }

    /**
     * ------------------------------------------------------------------------
     * Insufficient data returns placeholder values
     * ------------------------------------------------------------------------
     */
    public function test_insufficient_data_has_placeholder_values(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $result = $this->presenter->present($project);

        $this->assertNull($result['regression_info']);
        $this->assertNull($result['next_prediction']);
        $this->assertEquals(0, $result['current_month_index']);
        $this->assertEquals([], $result['forecast_data']);
        $this->assertEquals([], $result['assessment_data']);
        $this->assertEquals('STABLE', $result['trend']);
    }

    /*
    |==========================================================================
    | Normal Prediction — Structure
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Normal prediction returns all expected keys
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_has_all_keys(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $expectedKeys = [
            'is_mockup', 'is_completed', 'is_insufficient_data',
            'status', 'risk_level', 'status_color',
            'current_progress', 'expected_progress', 'progress_gap',
            'days_elapsed', 'days_total', 'days_remaining',
            'estimated_completion', 'can_estimate_completion',
            'planned_completion', 'predicted_delay_days',
            'certainty_label', 'confidence_percentage',
            'trend', 'trend_label',
            'behind_milestone', 'recommendations', 'structured_recommendations',
            'early_speed', 'recent_speed',
            'analysis_text', 'ai_generated_at',
            'regression_info', 'forecast_data', 'assessment_data',
            'next_prediction', 'current_month_index',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Normal prediction has correct flags
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_flags(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $this->assertTrue($result['is_mockup']);
        $this->assertFalse($result['is_completed']);
        $this->assertFalse($result['is_insufficient_data']);
    }

    /**
     * ------------------------------------------------------------------------
     * Normal prediction has regression info
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_has_regression_info(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $this->assertIsArray($result['regression_info']);
        $this->assertArrayHasKey('equation', $result['regression_info']);
        $this->assertArrayHasKey('slope', $result['regression_info']);
        $this->assertArrayHasKey('intercept', $result['regression_info']);
        $this->assertArrayHasKey('r_squared', $result['regression_info']);
        $this->assertArrayHasKey('fit_quality', $result['regression_info']);
        $this->assertArrayHasKey('observation_count', $result['regression_info']);
    }

    /**
     * ------------------------------------------------------------------------
     * Normal prediction has forecast and assessment arrays
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_has_forecast_and_assessment(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $this->assertIsArray($result['forecast_data']);
        $this->assertIsArray($result['assessment_data']);
    }

    /*
    |==========================================================================
    | Normal Prediction — Values
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Current progress matches last observation
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_current_progress(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $this->assertEquals(11.0, $result['current_progress']);
    }

    /**
     * ------------------------------------------------------------------------
     * Normal prediction has next_prediction value
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_has_next_prediction(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        // y = 2(6) + 1 = 13
        $this->assertEquals(13.0, $result['next_prediction']);
    }

    /**
     * ------------------------------------------------------------------------
     * Normal prediction has current_month_index
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_month_index(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $this->assertEquals(5, $result['current_month_index']);
    }

    /**
     * ------------------------------------------------------------------------
     * Normal prediction regression values are correct
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_regression_values(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $this->assertEquals(5, $result['regression_info']['observation_count']);
        $this->assertEquals(2.0, $result['regression_info']['slope']);
        $this->assertEquals(1.0, $result['regression_info']['intercept']);
        $this->assertEquals(1.0, $result['regression_info']['r_squared']);
    }

    /**
     * ------------------------------------------------------------------------
     * Normal prediction has analysis text
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_has_analysis_text(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $this->assertIsString($result['analysis_text']);
        $this->assertNotEmpty($result['analysis_text']);
    }

    /**
     * ------------------------------------------------------------------------
     * Normal prediction has recommendations
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_has_recommendations(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $this->assertIsArray($result['recommendations']);
        $this->assertIsArray($result['structured_recommendations']);
    }

    /*
    |==========================================================================
    | Known Dataset
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Present with known Project 7 dataset
     * ------------------------------------------------------------------------
     */
    public function test_present_with_known_dataset(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $result = $this->presenter->present($project);

        $this->assertFalse($result['is_completed']);
        $this->assertFalse($result['is_insufficient_data']);

        // Current progress = last value = 45.0
        $this->assertEquals(45.0, $result['current_progress']);

        // Current month = 10
        $this->assertEquals(10, $result['current_month_index']);

        // Regression info
        $this->assertEquals(10, $result['regression_info']['observation_count']);
        $this->assertEquals('Excellent', $result['regression_info']['fit_quality']);
    }

    /*
    |==========================================================================
    | Edge Cases
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Present with single observation returns insufficient data
     * ------------------------------------------------------------------------
     */
    public function test_single_observation_returns_insufficient(): void
    {
        $project = $this->createProjectWithInsufficientData(observations: 1);

        $result = $this->presenter->present($project);

        $this->assertTrue($result['is_insufficient_data']);
        $this->assertEquals('INSUFFICIENT DATA', $result['status']);
    }

    /**
     * ------------------------------------------------------------------------
     * Present with zero observations returns insufficient data
     * ------------------------------------------------------------------------
     */
    public function test_zero_observations_returns_insufficient(): void
    {
        $project = $this->createProjectWithInsufficientData(observations: 0);

        $result = $this->presenter->present($project);

        $this->assertTrue($result['is_insufficient_data']);
        $this->assertEquals('INSUFFICIENT DATA', $result['status']);
    }

    /**
     * ------------------------------------------------------------------------
     * Present with normal data returns ON TRACK or AT RISK status
     * ------------------------------------------------------------------------
     */
    public function test_normal_prediction_has_valid_status(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [3, 5, 7, 9, 11]
        );

        $result = $this->presenter->present($project);

        $this->assertContains($result['status'], ['ON TRACK', 'AT RISK']);
    }
}
