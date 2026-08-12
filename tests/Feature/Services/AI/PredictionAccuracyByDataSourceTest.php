<?php

namespace Tests\Feature\Services\AI;

use App\Models\Category;
use App\Models\PredictionHistory;
use App\Models\Project;
use App\Services\AI\AIPredictionPresenter;
use App\Services\AI\AIPredictionService;
use App\Services\AI\BaselineComparisonService;
use App\Services\AI\CompletionComparisonService;
use App\Services\AI\CompletionEstimationService;
use App\Services\AI\ForecastReliabilityService;
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
 * C7 — accuracy-by-data_source logging.
 *
 * Every prediction_history row records how its forecast was produced
 * (project_history / hybrid / similar_projects). This test verifies:
 *   1. Own-data predictions are persisted with data_source 'project_history'.
 *   2. Supplemented (hybrid) predictions are persisted with 'hybrid'.
 *   3. predictionAccuracyByDataSource() aggregates fulfilled rows per source
 *      (count, accurate_count, accuracy_percent, avg_abs_error, avg_variance).
 */
class PredictionAccuracyByDataSourceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    private RegressionDatasetBuilder $datasetBuilder;
    private LinearRegressionService $regressionService;
    private ForecastService $forecastService;
    private BaselineComparisonService $comparisonService;
    private ProjectAssessmentService $assessmentService;
    private AIPredictionService $predictionService;
    private AIPredictionPresenter $presenter;
    private HistoricalSimilarityService $historicalSimilarityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->datasetBuilder = new RegressionDatasetBuilder();
        $this->regressionService = new LinearRegressionService();
        $polynomialService = new PolynomialRegressionService();
        $this->forecastService = new ForecastService($this->regressionService, $polynomialService);
        $this->comparisonService = new BaselineComparisonService();
        $this->assessmentService = new ProjectAssessmentService();
        $completionService = new CompletionEstimationService();
        $completionComparisonService = new CompletionComparisonService($completionService);
        $this->historicalSimilarityService = new HistoricalSimilarityService();
        $reliabilityService = new ForecastReliabilityService();
        $this->predictionService = new AIPredictionService(
            $this->datasetBuilder,
            $this->regressionService,
            $polynomialService,
            $this->forecastService,
            $this->comparisonService,
            $this->assessmentService,
            $completionService,
            $completionComparisonService,
            $this->historicalSimilarityService,
            $reliabilityService
        );
        $this->presenter = new AIPredictionPresenter(
            $this->predictionService,
            new RecommendationEngine()
        );
    }

    private function makeCategory(string $name = 'Accuracy Source Category'): Category
    {
        return Category::create([
            'name'        => $name,
            'description' => 'Test category',
            'is_active'   => true,
        ]);
    }

    private function seedSimilarCompletedProjects(int $categoryId, int $count = 3): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createProjectWithHistory(
                months: 6,
                actualProgress: [10, 25, 45, 65, 85, 100],
                overrides: [
                    'status'                => 'Completed',
                    'category_id'           => $categoryId,
                    'approved_budget'       => 5000000.00,
                    'planned_duration_days' => 730,
                ]
            );
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Own-data prediction → history row tagged data_source 'project_history'
     * ------------------------------------------------------------------------
     */
    public function test_own_data_prediction_logs_project_history_source(): void
    {
        $categoryId = $this->makeCategory()->id;

        $target = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [10, 20, 30, 40, 50, 60],
            overrides: [
                'status'               => 'Ongoing',
                'category_id'          => $categoryId,
                'approved_budget'      => 5000000.00,
                'planned_duration_days' => 730,
            ]
        );

        $report = $this->predictionService->generatePrediction($target);

        $this->assertSame('project_history', $report['regression']['data_source'] ?? 'project_history');

        $history = $target->predictionHistory()->get();
        $this->assertNotEmpty($history);

        foreach ($history as $row) {
            $this->assertSame('project_history', $row->data_source);
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Supplemented (hybrid) prediction → history row tagged 'hybrid'
     * ------------------------------------------------------------------------
     */
    public function test_hybrid_prediction_logs_hybrid_source(): void
    {
        $categoryId = $this->makeCategory()->id;
        $this->seedSimilarCompletedProjects($categoryId);

        $target = $this->createProjectWithHistory(
            months: 1,
            actualProgress: [5.0],
            overrides: [
                'status'               => 'Ongoing',
                'category_id'          => $categoryId,
                'approved_budget'      => 5000000.00,
                'planned_duration_days' => 730,
            ]
        );

        $this->assertSame('supplement', $this->historicalSimilarityService->shouldSupplement($target, 1));
        $report = $this->predictionService->generatePrediction($target);

        $this->assertSame('hybrid', $report['regression']['data_source']);

        $history = $target->predictionHistory()->get();
        $this->assertNotEmpty($history);

        foreach ($history as $row) {
            $this->assertSame('hybrid', $row->data_source);
        }
    }

    /**
     * ------------------------------------------------------------------------
     * predictionAccuracyByDataSource() aggregates fulfilled rows per source
     * ------------------------------------------------------------------------
     *
     * Seed fulfilled rows across three sources with known predicted/actual
     * pairs and verify count + accuracy math for each bucket.
     */
    public function test_accuracy_breakdown_by_data_source(): void
    {
        $categoryId = $this->makeCategory()->id;

        $projectA = $this->createProjectWithHistory(
            months: 3,
            actualProgress: [5, 10, 15],
            overrides: ['status' => 'Ongoing', 'category_id' => $categoryId]
        );
        $projectB = $this->createProjectWithHistory(
            months: 3,
            actualProgress: [5, 10, 15],
            overrides: ['status' => 'Ongoing', 'category_id' => $categoryId]
        );

        // project_history bucket: 50 vs 50 (accurate), 50 vs 55 (error 5)
        // hybrid bucket: 50 vs 51 (accurate), 50 vs 60 (error 10)
        // similar_projects bucket: legacy null source → grouped as project_history
        PredictionHistory::create([
            'project_id'        => $projectA->id,
            'month_label'       => '2026-01',
            'regression_method' => 'slr',
            'data_source'       => 'project_history',
            'current_progress'  => 40,
            'predicted_next'    => 50,
            'actual_next'       => 50,
            'r_squared'         => 0.9,
            'regression_snapshot' => [],
        ]);
        PredictionHistory::create([
            'project_id'        => $projectA->id,
            'month_label'       => '2026-02',
            'regression_method' => 'slr',
            'data_source'       => 'project_history',
            'current_progress'  => 40,
            'predicted_next'    => 50,
            'actual_next'       => 55,
            'r_squared'         => 0.9,
            'regression_snapshot' => [],
        ]);
        PredictionHistory::create([
            'project_id'        => $projectA->id,
            'month_label'       => '2026-03',
            'regression_method' => 'slr',
            'data_source'       => 'hybrid',
            'current_progress'  => 40,
            'predicted_next'    => 50,
            'actual_next'       => 51,
            'r_squared'         => 0.9,
            'regression_snapshot' => [],
        ]);
        PredictionHistory::create([
            'project_id'        => $projectA->id,
            'month_label'       => '2026-04',
            'regression_method' => 'slr',
            'data_source'       => 'hybrid',
            'current_progress'  => 40,
            'predicted_next'    => 50,
            'actual_next'       => 60,
            'r_squared'         => 0.9,
            'regression_snapshot' => [],
        ]);
        // Legacy row: no data_source → grouped into project_history.
        PredictionHistory::create([
            'project_id'        => $projectB->id,
            'month_label'       => '2026-01',
            'regression_method' => 'slr',
            'current_progress'  => 40,
            'predicted_next'    => 80,
            'actual_next'       => 81,
            'r_squared'         => 0.9,
            'regression_snapshot' => [],
        ]);

        $accuracy = $this->predictionService->predictionAccuracyByDataSource();

        // project_history: rows (50/50), (50/55), legacy (80/81)
        $ph = $accuracy['project_history'];
        $this->assertSame(3, $ph['count']);
        $this->assertSame(2, $ph['accurate_count']);
        $this->assertSame(round(2 / 3 * 100, 1), $ph['accuracy_percent']);
        // abs errors: 0 + 5 + 1 = 6 → avg 2.0; variances: 0 + 5 + 1 = 6 → avg 2.0
        $this->assertSame(2.0, $ph['avg_abs_error']);
        $this->assertSame(2.0, $ph['avg_variance']);

        // hybrid: rows (50/51), (50/60)
        $hy = $accuracy['hybrid'];
        $this->assertSame(2, $hy['count']);
        $this->assertSame(1, $hy['accurate_count']);
        $this->assertSame(50.0, $hy['accuracy_percent']);
        // abs errors: 1 + 10 = 11 → avg 5.5; variances: +1 + +10 = 11 → avg 5.5
        $this->assertSame(5.5, $hy['avg_abs_error']);
        $this->assertSame(5.5, $hy['avg_variance']);

        // No similar_projects rows → bucket absent (not zero).
        $this->assertArrayNotHasKey('similar_projects', $accuracy);
    }

    /**
     * ------------------------------------------------------------------------
     * Empty history → accuracy buckets empty
     * ------------------------------------------------------------------------
     */
    public function test_accuracy_breakdown_empty_when_no_history(): void
    {
        $this->assertSame([], $this->predictionService->predictionAccuracyByDataSource());
    }
}