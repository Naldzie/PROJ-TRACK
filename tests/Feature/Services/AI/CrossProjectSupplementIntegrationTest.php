<?php

namespace Tests\Feature\Services\AI;

use App\Models\Category;
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
 * Cross-Project Supplement end-to-end integration tests.
 *
 * Fills the gap noted as Open Item #3 in the Phase 8 progress log:
 * generateFallbackDataset / generateSupplementDataset were never covered by a
 * DB-backed integration test, so nothing verified the cross-project path after
 * the inspection_date refactor.
 */
class CrossProjectSupplementIntegrationTest extends TestCase
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

    /**
     * Create a real category (project.category_id is a foreign key).
     */
    private function makeCategory(string $name = 'Infrastructure'): Category
    {
        return Category::create([
            'name'        => $name,
            'description' => 'Test category',
            'is_active'   => true,
        ]);
    }

    /**
     * Seed completed projects (same category) with history so an aggregate
     * curve exists for the target project to draw synthetic points from.
     */
    private function seedSimilarCompletedProjects(int $categoryId, int $count = 3): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createProjectWithHistory(
                months: 6,
                actualProgress: [10, 25, 45, 65, 85, 100],
                overrides: [
                    'status'     => 'Completed',
                    'category_id' => $categoryId,
                ]
            );
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Supplement mode: 1 real observation + similar completed projects
     * ------------------------------------------------------------------------
     *
     * Verifies the whole cross-project pipeline still works after the
     * inspection_date refactor: shouldSupplement picks 'supplement', the
     * dataset is prepended with synthetic points, the report is tagged
     * data_source 'hybrid', and the current progress stays the real value.
     */
    public function test_supplement_mode_prepends_synthetic_points_and_tags_hybrid(): void
    {
        $categoryId = $this->makeCategory()->id;
        $this->seedSimilarCompletedProjects($categoryId);

        $target = $this->createProjectWithHistory(
            months: 1,
            actualProgress: [5.0],
            overrides: ['status' => 'Ongoing', 'category_id' => $categoryId]
        );

        // 1 observation < threshold → supplement
        $this->assertSame('supplement', $this->historicalSimilarityService->shouldSupplement($target, 1));

        $report = $this->predictionService->generatePrediction($target);

        // Supplemented data must reach ≥ 2 observations so regression can run
        $this->assertGreaterThanOrEqual(2, $report['regression']['observation_count']);
        $this->assertSame('hybrid', $report['regression']['data_source']);
        $this->assertGreaterThan(0, $report['regression']['synthetic_points_count']);

        // The one real observation is preserved as the current progress
        $this->assertEquals(5.0, $report['current_state']['progress']);

        // Presenter exposes the cross-project metadata
        $presented = $this->presenter->present($target);
        $this->assertFalse($presented['is_insufficient_data']);
        $this->assertSame('hybrid', $presented['regression_info']['data_source']);
        $this->assertGreaterThan(0, $presented['regression_info']['synthetic_points_count']);
    }

    /**
     * ------------------------------------------------------------------------
     * No similar completed projects → no override, still produces report
     * ------------------------------------------------------------------------
     *
     * A project with 1 observation but no completed projects of the same
     * category degrades gracefully (data_source stays project_history).
     */
    public function test_no_similar_projects_degrades_gracefully(): void
    {
        $categoryId = $this->makeCategory('Unique Category')->id;

        $target = $this->createProjectWithHistory(
            months: 1,
            actualProgress: [5.0],
            overrides: ['status' => 'Ongoing', 'category_id' => $categoryId]
        );

        $this->assertSame(0, $this->historicalSimilarityService->getSimilarProjectsCountFor($target));

        $report = $this->predictionService->generatePrediction($target);

        // Regression still runs on the real observation (or minimal report)
        $this->assertSame('project_history', $report['regression']['data_source'] ?? 'project_history');
    }

    /**
     * ------------------------------------------------------------------------
     * Zero observations → shouldSupplement returns 'none'
     * ------------------------------------------------------------------------
     */
    public function test_zero_observations_returns_none(): void
    {
        $categoryId = $this->makeCategory('Zero Category')->id;
        $target = Project::factory()->create(['status' => 'Ongoing', 'category_id' => $categoryId]);

        $this->assertSame('none', $this->historicalSimilarityService->shouldSupplement($target, 0));
    }

    /**
     * ------------------------------------------------------------------------
     * Completed projects are never supplemented
     * ------------------------------------------------------------------------
     */
    public function test_completed_project_never_supplements(): void
    {
        $categoryId = $this->makeCategory('Completed Category')->id;
        $completed = $this->createProjectWithHistory(
            months: 2,
            actualProgress: [10, 20],
            overrides: ['status' => 'Completed', 'category_id' => $categoryId]
        );

        $this->assertSame('none', $this->historicalSimilarityService->shouldSupplement($completed, 2));
    }

    /**
     * ------------------------------------------------------------------------
     * Enough observations (≥ threshold) → no supplement
     * ------------------------------------------------------------------------
     */
    public function test_threshold_reached_returns_none(): void
    {
        $categoryId = $this->makeCategory('Threshold Category')->id;
        $this->seedSimilarCompletedProjects($categoryId);

        $target = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [10, 25, 45, 65, 85, 100],
            overrides: [
                'status'               => 'Ongoing',
                'category_id'          => $categoryId,
                // 180 days → 6 planned months → threshold max(2, 6/3)=2
                'planned_duration_days' => 180,
            ]
        );

        // 6 observations ≥ max(2, ceil(plannedMonths/3)) → none
        $this->assertSame('none', $this->historicalSimilarityService->shouldSupplement($target, 6));

        $report = $this->predictionService->generatePrediction($target);
        $this->assertSame('project_history', $report['regression']['data_source'] ?? 'project_history');
    }
}
