<?php

namespace Tests\Unit\Services\AI;

use App\Models\PredictionHistory;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
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
use Carbon\Carbon;
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

    /*
    |==========================================================================
    | Prediction History Output (Phase 7 fix)
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Prediction history shows the correct predicted month's actual
     * ------------------------------------------------------------------------
     *
     * After the fix, a second inspection in the same month overrides that
     * month's actual_next instead of corrupting the next month's prediction.
     * The presenter therefore lists May (pred 4.72 / actual 4.5) and not a
     * bogus June row carrying May's actual.
     */
    public function test_prediction_history_shows_correct_month_actual(): void
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

        foreach (['2026-02' => 1.0, '2026-03' => 1.5, '2026-04' => 2.0] as $month => $pct) {
            ProjectProgressHistory::create([
                'project_id'              => $project->id,
                'month_label'             => $month,
                'inspection_date'         => Carbon::parse($month . '-25')->format('Y-m-d'),
                'actual_progress_percent' => $pct,
                'progress_percentage'     => $pct,
            ]);
        }

        // April 2% → prediction FOR May
        app(AIPredictionService::class)->generatePrediction($project);

        // Simulate the controller sync: May's actual is 4.5
        PredictionHistory::where('project_id', $project->id)
            ->where('month_label', '2026-05')
            ->update(['actual_next' => 4.5]);

        $result = $this->presenter->present($project);

        $history = $result['prediction_history'];

        $this->assertCount(1, $history);
        $this->assertEquals('May 2026', $history[0]['month']);
        $this->assertEquals(4.5, $history[0]['actual']);
        $this->assertFalse($history[0]['pending']);
        $this->assertArrayNotHasKey('predicted_evolution', $history[0]);
        $this->assertArrayNotHasKey('prediction_count', $history[0]);
        $this->assertFalse($history->contains(fn ($h) => $h['month'] === 'Jun 2026'));
    }

    /**
     * ------------------------------------------------------------------------
     * The history shows ONLY the latest prediction per month (rev 3 keep-latest)
     * ------------------------------------------------------------------------
     *
     * A refreshed forecast updates the month's single row in place. Even when
     * legacy duplicate rows exist in the DB, the presenter groups by month and
     * surfaces only the newest row — the one carrying the actual.
     */
    public function test_history_shows_only_latest_prediction_per_month(): void
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

        foreach (['2026-02' => 1.0, '2026-03' => 1.5, '2026-04' => 2.0] as $month => $pct) {
            ProjectProgressHistory::create([
                'project_id'              => $project->id,
                'month_label'             => $month,
                'inspection_date'         => Carbon::parse($month . '-25')->format('Y-m-d'),
                'actual_progress_percent' => $pct,
                'progress_percentage'     => $pct,
            ]);
        }

        // First forecast FOR May (based on April 2.0%)
        app(AIPredictionService::class)->generatePrediction($project);

        $firstMayRow = PredictionHistory::where('project_id', $project->id)
            ->where('month_label', '2026-05')
            ->first();

        // Simulate a legacy superseded May row (rev 1/rev 2 data).
        $legacyDuplicate = PredictionHistory::create([
            'project_id'           => $project->id,
            'month_label'          => '2026-05',
            'baseline_schedule_id' => $firstMayRow->baseline_schedule_id,
            'regression_method'    => $firstMayRow->regression_method,
            'current_progress'     => 2.0,
            'predicted_next'       => 5.13,
            'r_squared'            => $firstMayRow->r_squared,
            'regression_snapshot'  => $firstMayRow->regression_snapshot,
        ]);

        // Simulate the controller sync: only the NEWEST May row receives the actual.
        $legacyDuplicate->update(['actual_next' => 5.8]);

        $result = $this->presenter->present($project);

        $history = $result['prediction_history'];

        $this->assertCount(1, $history);
        $this->assertEquals('May 2026', $history[0]['month']);
        $this->assertEquals($legacyDuplicate->predicted_next, $history[0]['predicted']);
        $this->assertEquals(5.8, $history[0]['actual']);
        $this->assertFalse($history[0]['pending']);
        $this->assertArrayNotHasKey('predicted_evolution', $history[0]);
        $this->assertArrayNotHasKey('prediction_count', $history[0]);

        $summary = $result['prediction_history_summary'];
        $this->assertEquals(1, $summary['total_with_actual']);
    }

    /**
     * ------------------------------------------------------------------------
     * Prediction history is NOT capped at 6 months (Fix D)
     * ------------------------------------------------------------------------
     *
     * A project with more than six months of prediction rows must surface ALL
     * of them — the old take(6) cap hid earlier months permanently.
     */
    public function test_prediction_history_is_not_capped_at_six(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $start = now()->subMonths(8)->startOfMonth();

        for ($i = 0; $i < 8; $i++) {
            $month = (clone $start)->addMonths($i)->format('Y-m');

            ProjectBaselineSchedule::create([
                'project_id'                       => $project->id,
                'month_label'                      => $month,
                'planned_date'                     => Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d'),
                'planned_progress_percent'         => ($i + 1) * 5,
                'original_planned_progress_percent'=> ($i + 1) * 5,
            ]);

            ProjectProgressHistory::create([
                'project_id'              => $project->id,
                'month_label'             => $month,
                'inspection_date'         => Carbon::parse($month . '-25')->format('Y-m-d'),
                'actual_progress_percent' => ($i + 1) * 2.0,
                'progress_percentage'     => ($i + 1) * 2.0,
            ]);

            PredictionHistory::create([
                'project_id'          => $project->id,
                'month_label'         => $month,
                'regression_method'   => 'linear',
                'current_progress'    => ($i + 1) * 2.0,
                'predicted_next'      => 2.0 + $i,
                'r_squared'           => 0.95,
                'regression_snapshot' => [],
            ]);
        }

        $result = $this->presenter->present($project);

        $this->assertGreaterThan(6, count($result['prediction_history']));
    }

    /**
     * ------------------------------------------------------------------------
     * Prediction history is explicitly ordered oldest → newest by month (Fix E)
     * ------------------------------------------------------------------------
     *
     * Even when prediction rows are inserted out of chronological order, the
     * presenter must render the history sorted by month_label ascending.
     */
    public function test_prediction_history_is_sorted_by_month_ascending(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $months = ['2026-08', '2026-06', '2026-07', '2026-04', '2026-05'];

        foreach ($months as $i => $month) {
            ProjectBaselineSchedule::create([
                'project_id'                       => $project->id,
                'month_label'                      => $month,
                'planned_date'                     => Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d'),
                'planned_progress_percent'         => ($i + 1) * 5,
                'original_planned_progress_percent'=> ($i + 1) * 5,
            ]);

            ProjectProgressHistory::create([
                'project_id'              => $project->id,
                'month_label'             => $month,
                'inspection_date'         => Carbon::parse($month . '-25')->format('Y-m-d'),
                'actual_progress_percent' => ($i + 1) * 2.0,
                'progress_percentage'     => ($i + 1) * 2.0,
            ]);

            PredictionHistory::create([
                'project_id'          => $project->id,
                'month_label'         => $month,
                'regression_method'   => 'linear',
                'current_progress'    => ($i + 1) * 2.0,
                'predicted_next'      => 2.0 + $i,
                'r_squared'           => 0.95,
                'regression_snapshot' => [],
            ]);
        }

        $result = $this->presenter->present($project);

        $monthLabels = $result['prediction_history']->pluck('month_label')->all();
        $expected = $monthLabels;
        sort($expected);

        $this->assertSame($expected, $monthLabels);
    }
}
