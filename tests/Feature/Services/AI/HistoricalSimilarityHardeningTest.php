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
 * Cross-Project "Safety Valves" (C8) hardening tests.
 *
 * Verifies the two Phase 8 "recommended before defense" items:
 *   1. MIN_SIMILAR_PROJECTS gate — refuse to borrow an aggregate curve when
 *      fewer than 3 similar projects have a USABLE curve (>= 4 observations).
 *   2. Synthetic scale-ratio floor — a tiny first observation can no longer
 *      flatten the borrowed curve to the old 0.2 ratio.
 * Plus the optional recency weighting (recent projects rank higher).
 */
class HistoricalSimilarityHardeningTest extends TestCase
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
    private function makeCategory(string $name = 'Safety Valves Category'): Category
    {
        return Category::create([
            'name'        => $name,
            'description' => 'Test category',
            'is_active'   => true,
        ]);
    }

    /**
     * Seed completed similar projects (same category) with a usable curve.
     * A project is "usable" only when it has >= MIN_OBSERVATIONS_FOR_CURVE
     * (4) progress records, so the gate must count these, not raw matches.
     */
    private function seedSimilarCompletedProjects(int $categoryId, int $count = 3, int $months = 6): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createProjectWithHistory(
                months: $months,
                actualProgress: array_map(
                    fn ($m) => round((100 / $months) * $m, 2),
                    range(1, $months)
                ),
                overrides: [
                    'status'     => 'Completed',
                    'category_id' => $categoryId,
                ]
            );
        }
    }

    /**
     * ------------------------------------------------------------------------
     * GATE — fewer than 3 usable similar projects → refuse to borrow
     * ------------------------------------------------------------------------
     */
    public function test_gate_blocks_supplement_with_two_similar_projects(): void
    {
        $categoryId = $this->makeCategory('Two Similars')->id;
        $this->seedSimilarCompletedProjects($categoryId, count: 2);

        $target = $this->createProjectWithHistory(
            months: 1,
            actualProgress: [5.0],
            overrides: ['status' => 'Ongoing', 'category_id' => $categoryId]
        );

        // The mode is still 'supplement', but the generator must refuse.
        $this->assertSame('supplement', $this->historicalSimilarityService->shouldSupplement($target, 1));
        $this->assertSame([], $this->historicalSimilarityService->buildAggregateCurve($target));

        $dataset = $this->datasetBuilder->buildDataset($target);
        $this->assertNull($this->historicalSimilarityService->generateSupplementDataset($target, $dataset));

        // End-to-end: prediction falls back to the project's own data.
        $report = $this->predictionService->generatePrediction($target);
        $this->assertSame('project_history', $report['regression']['data_source'] ?? 'project_history');

        $presented = $this->presenter->present($target);
        $this->assertNotSame('hybrid', $presented['regression_info']['data_source'] ?? 'project_history');
    }

    /**
     * ------------------------------------------------------------------------
     * GATE — exactly 3 usable similar projects → supplement still works
     * ------------------------------------------------------------------------
     */
    public function test_gate_allows_supplement_with_three_similar_projects(): void
    {
        $categoryId = $this->makeCategory('Three Similars')->id;
        $this->seedSimilarCompletedProjects($categoryId, count: 3);

        $target = $this->createProjectWithHistory(
            months: 1,
            actualProgress: [5.0],
            overrides: [
                'status'               => 'Ongoing',
                'category_id'          => $categoryId,
                // Large fixed duration → supplement target = 8 → ≥6 synthetic
                // points prepended, so merged X has distinct month indexes
                // (avoids the all-identical-X edge for a 1-obs short project).
                'planned_duration_days' => 730,
            ]
        );

        $this->assertNotEmpty($this->historicalSimilarityService->buildAggregateCurve($target));

        $dataset = $this->datasetBuilder->buildDataset($target);
        $this->assertNotNull($this->historicalSimilarityService->generateSupplementDataset($target, $dataset));

        $report = $this->predictionService->generatePrediction($target);
        $this->assertSame('hybrid', $report['regression']['data_source']);
        $this->assertGreaterThan(0, $report['regression']['synthetic_points_count']);
    }

    /**
     * ------------------------------------------------------------------------
     * GATE — counts USABLE curves only (matched but too little history)
     * ------------------------------------------------------------------------
     */
    public function test_gate_counts_only_usable_curves(): void
    {
        $categoryId = $this->makeCategory('Mixed Usability')->id;

        // 3 matched projects, but only 2 have a usable curve (>= 4 obs).
        $this->seedSimilarCompletedProjects($categoryId, count: 2, months: 6);
        $this->seedSimilarCompletedProjects($categoryId, count: 1, months: 2);

        $target = $this->createProjectWithHistory(
            months: 1,
            actualProgress: [5.0],
            overrides: ['status' => 'Ongoing', 'category_id' => $categoryId]
        );

        // Matches = 3, but usable curves = 2 → gate must refuse.
        $this->assertSame(3, $this->historicalSimilarityService->getSimilarProjectsCountFor($target));
        $this->assertSame([], $this->historicalSimilarityService->buildAggregateCurve($target));

        $dataset = $this->datasetBuilder->buildDataset($target);
        $this->assertNull($this->historicalSimilarityService->generateSupplementDataset($target, $dataset));
    }

    /**
     * ------------------------------------------------------------------------
     * DETAILS — every similar project (name/score/breakdown) reaches the
     * presenter when cross-project activates, for the blade console.log
     * ------------------------------------------------------------------------
     */
    public function test_similar_project_details_flow_to_presenter_in_hybrid_mode(): void
    {
        $categoryId = $this->makeCategory('Details Category')->id;
        $this->seedSimilarCompletedProjects($categoryId, count: 3);

        $target = $this->createProjectWithHistory(
            months: 1,
            actualProgress: [5.0],
            overrides: [
                'status'               => 'Ongoing',
                'category_id'          => $categoryId,
                'planned_duration_days' => 730,
            ]
        );

        $presented = $this->presenter->present($target);

        $this->assertSame('hybrid', $presented['regression_info']['data_source']);
        $details = $presented['regression_info']['similar_projects_details'];

        $this->assertNotNull($details);
        $this->assertCount(3, $details);

        foreach ($details as $sp) {
            $this->assertArrayHasKey('id', $sp);
            $this->assertArrayHasKey('code', $sp);
            $this->assertArrayHasKey('title', $sp);
            $this->assertArrayHasKey('score', $sp);
            $this->assertArrayHasKey('usable', $sp);
            $this->assertArrayHasKey('recency', $sp);
            $this->assertArrayHasKey('breakdown', $sp);
            $this->assertArrayHasKey('category', $sp['breakdown']);
            $this->assertArrayHasKey('budget', $sp['breakdown']);
            $this->assertArrayHasKey('duration', $sp['breakdown']);
            $this->assertArrayHasKey('contractor', $sp['breakdown']);
            $this->assertArrayHasKey('workers', $sp['breakdown']);
            $this->assertGreaterThan(0, $sp['score']);
        }
    }

    /**
     * ------------------------------------------------------------------------
     * FLOOR — tiny first observation cannot flatten the borrowed curve
     * ------------------------------------------------------------------------
     */
    public function test_scale_ratio_floor_prevents_near_flat_borrowed_curve(): void
    {
        $project = Project::factory()->create([
            'status'               => 'Ongoing',
            'planned_duration_days' => 365,
        ]);

        // Flat median curve: any percentile returns 10.0.
        $aggregate = [
            50 => ['median' => 10.0, 'lower' => null, 'upper' => null],
        ];

        // First actual is 0.5% vs aggregate 10.0 → raw ratio 0.05.
        // The floor must lift it to MIN_SCALE_RATIO (0.5), so synthetic
        // progress = 10.0 * 0.5 = 5.0 (old floor 0.2 would give 2.0).
        $points = $this->historicalSimilarityService->generateSyntheticPoints(
            $aggregate,
            $project,
            targetCount: 3,
            actualX: [1],
            actualY: [0.5]
        );

        $this->assertNotEmpty($points);
        $this->assertEquals(5.0, $points[0]['progress']);
        $this->assertEquals(5.0, $points[1]['progress']);
        $this->assertEquals(5.0, $points[2]['progress']);
    }

    /**
     * ------------------------------------------------------------------------
     * RECENCY (optional Step 3) — recent similar project outranks an old one
     * ------------------------------------------------------------------------
     */
    public function test_recent_similar_project_outranks_old_one(): void
    {
        $categoryId = $this->makeCategory('Recency Category')->id;

        $recent = Project::factory()->create([
            'status'               => 'Completed',
            'category_id'          => $categoryId,
            'approved_budget'      => 1000000.00,
            'planned_duration_days' => 365,
            'actual_end_date'      => now()->subMonths(6),
        ]);

        Project::factory()->create([
            'status'               => 'Completed',
            'category_id'          => $categoryId,
            'approved_budget'      => 1000000.00,
            'planned_duration_days' => 365,
            'actual_end_date'      => now()->subYears(6),
        ]);

        $target = Project::factory()->create([
            'status'               => 'Ongoing',
            'category_id'          => $categoryId,
            'approved_budget'      => 1000000.00,
            'planned_duration_days' => 365,
        ]);

        $similar = $this->historicalSimilarityService->findSimilarProjects($target);

        $this->assertCount(2, $similar);
        $this->assertSame($recent->id, $similar->first()->id);
    }
}
