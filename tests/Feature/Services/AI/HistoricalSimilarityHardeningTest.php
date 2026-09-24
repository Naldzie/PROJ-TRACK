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
     *
     * Step 5: completed projects must also exceed MIN_SIMILARITY_SCORE on the
     * RAW similarity score (category 30 + budget 25 + duration 20 = 75 here),
     * so they are seeded with matching budget/duration, not random factory
     * values that would leave them category-only (score 30, below threshold).
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
                    'status'                => 'Completed',
                    'category_id'           => $categoryId,
                    'contract_price'        => 5000000.00,
                    'planned_duration_days' => 730,
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
            overrides: [
                'status'                => 'Ongoing',
                'category_id'           => $categoryId,
                'contract_price'        => 5000000.00,
                'planned_duration_days' => 730,
            ]
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
                'contract_price'       => 5000000.00,
                // Large fixed planned duration → merged X spans ~24 months
                // (monotonic 1..P, v2.6 §4.1) → regression has distinct
                // month indexes (avoids the all-identical-X edge).
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
            overrides: [
                'status'                => 'Ongoing',
                'category_id'           => $categoryId,
                'contract_price'        => 5000000.00,
                'planned_duration_days' => 730,
            ]
        );

        // Matches = 3, but usable curves = 2 → gate must refuse.
        $this->assertSame(3, $this->historicalSimilarityService->getSimilarProjectsCountFor($target));
        $this->assertSame([], $this->historicalSimilarityService->buildAggregateCurve($target));

        $dataset = $this->datasetBuilder->buildDataset($target);
        $this->assertNull($this->historicalSimilarityService->generateSupplementDataset($target, $dataset));
    }

    /**
     * ------------------------------------------------------------------------
     * v2.6 4.3 (null-safe similarity) — missing metadata neither tanks a
     * genuine match nor lets category-only through
     * ------------------------------------------------------------------------
     *
     * Two candidates, SAME category as the target, duration data on both.
     * The target has NO budget recorded, so budget must be omitted from the
     * denominator: a candidate with a partial-but-real duration match must
     * NOT be silently excluded (old additive scoring: 30 + 15.38 = 45 < 50),
     * while a candidate whose ONLY match is category still can't pass.
     */
    public function test_null_safe_similarity_does_not_tank_partial_match_and_blocks_category_only(): void
    {
        $categoryId = $this->makeCategory('NullSafe Category')->id;

        // Partial duration match (ratio 1000/730 = 1.37 → ~15.38 of 20 pts),
        // NO budget recorded on either side.
        $partial = Project::factory()->create([
            'status'                => 'Completed',
            'category_id'           => $categoryId,
            'contract_price'        => null,
            'planned_duration_days' => 1000,
        ]);

        // Category-only: duration recorded but very far off (ratio 0.08 → 0).
        $categoryOnly = Project::factory()->create([
            'status'                => 'Completed',
            'category_id'           => $categoryId,
            'contract_price'        => null,
            'planned_duration_days' => 60,
        ]);

        $target = Project::factory()->create([
            'status'               => 'Ongoing',
            'category_id'          => $categoryId,
            'contract_price'       => null,
            'planned_duration_days' => 730,
        ]);

        $similar = $this->historicalSimilarityService->findSimilarProjects($target);

        // Only the partial-duration match survives: its normalized score
        // (earned / available × 100, budget excluded from the denominator)
        // clears MIN_SIMILARITY_SCORE. The category-only candidate is gone —
        // it fails the "at least one non-category match" requirement.
        $this->assertCount(1, $similar);
        $this->assertSame($partial->id, $similar->first()->id);

        $details = $this->historicalSimilarityService->getSimilarProjectsDetails($target);
        $this->assertCount(1, $details);
        $this->assertSame(0.0, $details[0]['breakdown']['budget']);
        $this->assertGreaterThan(0.0, $details[0]['breakdown']['duration']);
    }

    /**
     * ------------------------------------------------------------------------
     * v2.6 4.4 (honest fit wording) — blended models never present the
     * synthetic R² as "Excellent curve fit"
     * ------------------------------------------------------------------------
     */
    public function test_presenter_uses_blend_wording_when_data_source_is_not_project_history(): void
    {
        $categoryId = $this->makeCategory('FitWording Category')->id;
        $this->seedSimilarCompletedProjects($categoryId, count: 3);

        $target = $this->createProjectWithHistory(
            months: 1,
            actualProgress: [5.0],
            overrides: [
                'status'               => 'Ongoing',
                'category_id'          => $categoryId,
                'contract_price'       => 5000000.00,
                'planned_duration_days' => 730,
            ]
        );

        $presented = $this->presenter->present($target);

        $this->assertSame('hybrid', $presented['regression_info']['data_source']);

        $display = $presented['regression_info']['fit_quality_display'];
        $this->assertStringContainsString('Model blended with category reference curve', $display);
        $this->assertStringContainsString('3 similar projects', $display);
        $this->assertStringNotContainsString('Excellent', $display);
    }

    /**
     * ------------------------------------------------------------------------
     * v2.6 4.4 + A4 — a 0-obs category-baseline fallback (data_source
     * 'similar_projects', 0 real observations) is labeled "Category baseline
     * estimate", never a "blend" of the project's own curve
     * ------------------------------------------------------------------------
     */
    public function test_presenter_uses_category_baseline_wording_for_zero_obs_fallback(): void
    {
        $categoryId = $this->makeCategory('Baseline Wording')->id;
        $this->seedSimilarCompletedProjects($categoryId, count: 3);

        $target = Project::factory()->create([
            'status'               => 'Ongoing',
            'category_id'          => $categoryId,
            'contract_price'       => 5000000.00,
            'planned_duration_days' => 730,
        ]);

        $presented = $this->presenter->present($target);

        $this->assertSame('similar_projects', $presented['regression_info']['data_source']);

        $display = $presented['regression_info']['fit_quality_display'];
        $this->assertStringContainsString('Category baseline estimate', $display);
        $this->assertStringContainsString('3 similar projects', $display);
        $this->assertStringNotContainsString('Excellent', $display);

        $disclaimer = $presented['regression_info']['similar_projects_disclaimer'] ?? '';
        $this->assertStringContainsString('Category baseline estimate', $disclaimer);
        $this->assertStringContainsString('not a statement about this project\'s own history', $disclaimer);
    }

    /**
     * ------------------------------------------------------------------------
     * A4 — a pure category-baseline (0 real observations) must NOT present the
     * borrowed curve as the project's own health: status is "NO ACTUAL DATA",
     * no risk/trend/confidence, real 0% progress, no completion estimate, and
     * the analysis text explicitly disclaims that the curve is a reference.
     * ------------------------------------------------------------------------
     */
    public function test_pure_category_baseline_health_overview_is_honest(): void
    {
        $categoryId = $this->makeCategory('A4 Baseline Honest')->id;
        $this->seedSimilarCompletedProjects($categoryId, count: 3);

        $target = Project::factory()->create([
            'status'                => 'Ongoing',
            'category_id'           => $categoryId,
            'contract_price'        => 5000000.00,
            'planned_duration_days' => 730,
        ]);

        $presented = $this->presenter->present($target);

        $this->assertTrue($presented['is_category_baseline']);
        $this->assertSame('similar_projects', $presented['regression_info']['data_source']);

        // Health overview reflects the project's REAL state, not the curve.
        $this->assertSame('NO ACTUAL DATA', $presented['status']);
        $this->assertSame('unknown', $presented['risk_level']);
        $this->assertSame('N/A', $presented['trend']);
        $this->assertEquals(0.0, $presented['current_progress']);
        $this->assertSame('Low', $presented['health_confidence']);
        $this->assertSame('Category baseline estimate', $presented['certainty_label']);

        // Nothing derived from the borrowed curve may be surfaced as a forecast.
        $this->assertNull($presented['estimated_completion']);
        $this->assertFalse($presented['can_estimate_completion']);
        $this->assertSame(0, $presented['predicted_delay_days']);
        $this->assertNull($presented['completion_reliability']);
        $this->assertNull($presented['ai_generated_at']);

        // The explanation must explicitly separate the reference from reality.
        $this->assertStringContainsString('No actual progress observations recorded', $presented['analysis_text']);
        $this->assertStringContainsString('NOT a statement about this project', $presented['analysis_text']);
    }

    /**
     * ------------------------------------------------------------------------
     * v2.6 4.4 — pure project history keeps the raw fit-quality wording
     * ------------------------------------------------------------------------
     */
    public function test_presenter_keeps_raw_fit_wording_for_project_history(): void
    {
        $categoryId = $this->makeCategory('FitWording History')->id;

        $target = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [10, 25, 45, 65, 85, 100],
            overrides: ['status' => 'Ongoing', 'category_id' => $categoryId]
        );

        $presented = $this->presenter->present($target);

        $this->assertSame('project_history', $presented['regression_info']['data_source']);
        $this->assertSame(
            $presented['regression_info']['fit_quality'],
            $presented['regression_info']['fit_quality_display']
        );
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
                'contract_price'       => 5000000.00,
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
     * STEP 5 (MIN_SIMILARITY_SCORE) — category-only match (RAW 30) is rejected;
     * a well-matched project (RAW 75) passes even though old (recency 0.5)
     * ------------------------------------------------------------------------
     *
     * The threshold filters on the RAW score BEFORE the recency multiplier:
     * a weak but recent match (category only, 30) is excluded, while an old
     * but well-matched project (category+budget+duration, 75 × 0.5 = 37.5
     * final) stays eligible — recency only reorders, never disqualifies.
     */
    public function test_step5_min_score_rejects_category_only_but_keeps_strong_match(): void
    {
        $categoryId = $this->makeCategory('Step5 Category')->id;

        // Weak match: same category, wildly different budget + duration.
        Project::factory()->create([
            'status'                => 'Completed',
            'category_id'           => $categoryId,
            'contract_price'        => 100000.00,
            'planned_duration_days' => 220,
        ]);

        // Strong match: same category + budget + duration, but old (6 yrs).
        $oldStrong = Project::factory()->create([
            'status'                => 'Completed',
            'category_id'           => $categoryId,
            'contract_price'        => 5000000.00,
            'planned_duration_days' => 730,
            'actual_end_date'       => now()->subYears(6),
        ]);

        $target = Project::factory()->create([
            'status'               => 'Ongoing',
            'category_id'          => $categoryId,
            'contract_price'       => 5000000.00,
            'planned_duration_days' => 730,
        ]);

        $similar = $this->historicalSimilarityService->findSimilarProjects($target);

        // Only the strong match survives the RAW >= 50 gate (75 raw); the
        // category-only one (30 raw) is gone even though its final recency
        // would have been full weight.
        $this->assertCount(1, $similar);
        $this->assertSame($oldStrong->id, $similar->first()->id);

        $details = $this->historicalSimilarityService->getSimilarProjectsDetails($target);
        $this->assertCount(1, $details);
        $this->assertEquals(75.0, $details[0]['breakdown']['category'] + $details[0]['breakdown']['budget'] + $details[0]['breakdown']['duration']);
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
            'contract_price'       => 1000000.00,
            'planned_duration_days' => 365,
            'actual_end_date'      => now()->subMonths(6),
        ]);

        Project::factory()->create([
            'status'               => 'Completed',
            'category_id'          => $categoryId,
            'contract_price'       => 1000000.00,
            'planned_duration_days' => 365,
            'actual_end_date'      => now()->subYears(6),
        ]);

        $target = Project::factory()->create([
            'status'               => 'Ongoing',
            'category_id'          => $categoryId,
            'contract_price'       => 1000000.00,
            'planned_duration_days' => 365,
        ]);

        $similar = $this->historicalSimilarityService->findSimilarProjects($target);

        $this->assertCount(2, $similar);
        $this->assertSame($recent->id, $similar->first()->id);
    }
}
