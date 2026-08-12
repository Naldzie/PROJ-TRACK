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
     *
     * Step 5: completed projects must exceed MIN_SIMILARITY_SCORE on the RAW
     * similarity score (category 30 + budget 25 + duration 20 = 75 here), so
     * budget/duration are fixed to match the targets, not random factory
     * values that would leave them category-only (score 30, below threshold).
     */
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
     * Supplement mode: 1 real observation + similar completed projects
     * ------------------------------------------------------------------------
     *
     * Verifies the whole cross-project pipeline still works after the
     * inspection_date refactor: shouldSupplement picks 'supplement', the
     * dataset is augmented with synthetic points (v2.6 §4.1 monotonic merge),
     * the report is tagged data_source 'hybrid', and the current progress
     * stays the real value.
     */
    public function test_supplement_mode_augments_dataset_and_tags_hybrid(): void
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
                // Large fixed planned duration → the merged X spans ~24
                // months (1..P monotonic, v2.6 §4.1), so regression has
                // plenty of distinct month indexes (no all-identical-X crash).
                'planned_duration_days' => 730,
            ]
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
     * v2.6 §4.1 — merged X-axis is MONOTONIC 1..P with the real value at its
     * own month (no duplicated month 1, no synthetic restart at 1)
     * ------------------------------------------------------------------------
     */
    public function test_supplement_merge_x_is_monotonic_with_real_value_at_own_month(): void
    {
        $categoryId = $this->makeCategory()->id;
        $this->seedSimilarCompletedProjects($categoryId);

        $target = $this->createProjectWithHistory(
            months: 2,
            actualProgress: [5.0, 12.0],
            overrides: [
                'status'               => 'Ongoing',
                'category_id'          => $categoryId,
                'approved_budget'      => 5000000.00,
                'planned_duration_days' => 730,
            ]
        );

        $dataset = $this->datasetBuilder->buildDataset($target);
        $supplement = $this->historicalSimilarityService->generateSupplementDataset($target, $dataset);

        $this->assertNotNull($supplement);

        $x = $supplement['x'];
        $y = $supplement['y'];

        // Real months 1..2 land at their own month (5.0 and 12.0) — NOT
        // shifted up by a synthetic prefix.
        $this->assertSame(5.0, $y[0]);
        $this->assertSame(12.0, $y[1]);

        // X is strictly increasing 1..P — no repeated month 1.
        for ($i = 1; $i < count($x); $i++) {
            $this->assertGreaterThan($x[$i - 1], $x[$i]);
        }
        $this->assertSame(1.0, $x[0]);

        // The last real month points into the tail: current_month_index is
        // preserved, so predictions anchor on the real 12.0, not synthetic.
        $this->assertSame(2, $supplement['statistics']['current_month_index']);
        $this->assertEquals(12.0, $y[$supplement['statistics']['current_month_index'] - 1]);
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
     * Zero observations → 'fallback' when planned duration exists (A4)
     * ------------------------------------------------------------------------
     *
     * v2.5 §3 A4 / v2.6 §5.6: a brand-new project with 0 observations shows a
     * clearly-labeled category baseline instead of blank Insufficient Data —
     * BUT only when there is a planned duration to anchor the curve to.
     */
    public function test_zero_observations_with_planned_duration_returns_fallback(): void
    {
        $categoryId = $this->makeCategory('Zero Category')->id;
        $target = Project::factory()->create([
            'status'               => 'Ongoing',
            'category_id'          => $categoryId,
            'planned_duration_days' => 730,
        ]);

        $this->assertSame('fallback', $this->historicalSimilarityService->shouldSupplement($target, 0));
    }

    public function test_zero_observations_without_planned_duration_returns_none(): void
    {
        $categoryId = $this->makeCategory('Zero Category')->id;
        $target = Project::factory()->create([
            'status'                => 'Ongoing',
            'category_id'           => $categoryId,
            'planned_duration_days' => null,
        ]);

        $this->assertSame('none', $this->historicalSimilarityService->shouldSupplement($target, 0));
    }

    /**
     * ------------------------------------------------------------------------
     * A4 end-to-end: 0-obs + valid pool → category baseline curve
     * ------------------------------------------------------------------------
     */
    public function test_zero_observations_with_pool_renders_category_baseline(): void
    {
        $categoryId = $this->makeCategory('Baseline Category')->id;
        $this->seedSimilarCompletedProjects($categoryId);

        $target = Project::factory()->create([
            'status'               => 'Ongoing',
            'category_id'          => $categoryId,
            'approved_budget'      => 5000000.00,
            'planned_duration_days' => 730,
        ]);

        $this->assertSame('fallback', $this->historicalSimilarityService->shouldSupplement($target, 0));

        $report = $this->predictionService->generatePrediction($target);

        $this->assertSame('similar_projects', $report['regression']['data_source']);
        // A4 fix: a 0-obs project sits at month 1 (start), not at the end of
        // its plan — so current progress reads the class curve at month 1.
        $this->assertSame(1, $report['current_state']['month_index']);
        $this->assertGreaterThan(0, $report['current_state']['progress']);
        $this->assertSame(3, $report['regression']['similar_projects_count']);
    }

    /**
     * ------------------------------------------------------------------------
     * A4 honesty: no pool → still falls through to project_history/insufficient
     * ------------------------------------------------------------------------
     */
    public function test_zero_observations_without_pool_degrades_gracefully(): void
    {
        $categoryId = $this->makeCategory('No Pool Category')->id;
        $target = Project::factory()->create([
            'status'               => 'Ongoing',
            'category_id'          => $categoryId,
            'planned_duration_days' => 730,
        ]);

        $this->assertSame(0, $this->historicalSimilarityService->getSimilarProjectsCountFor($target));

        $report = $this->predictionService->generatePrediction($target);

        // buildAggregateCurve() returns [] (< MIN_SIMILAR_PROJECTS usable
        // curves) → generateFallbackDataset() null → own data / minimal path.
        $this->assertSame('project_history', $report['regression']['data_source'] ?? 'project_history');
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
