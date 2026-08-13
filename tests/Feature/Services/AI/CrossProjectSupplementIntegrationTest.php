<?php

namespace Tests\Feature\Services\AI;

use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectProgressHistory;
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
    private CompletionComparisonService $completionComparisonService;

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
        $this->completionComparisonService = new CompletionComparisonService($completionService);
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
            $this->completionComparisonService,
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
                // budget intentionally far from the seeded class (5M) so the
                // similarity gate deterministically fails (BUDGET_TOLERANCE
                // 0.20) — this test guards the NO-class path.
                'approved_budget'      => 9999999.99,
            ]
        );

        // 6 observations ≥ max(2, ceil(plannedMonths/3)) → no supplement
        $this->assertSame('none', $this->historicalSimilarityService->shouldSupplement($target, 6));

        $report = $this->predictionService->generatePrediction($target);

        // The target's budget (10M) and 180-day duration are both outside the
        // class tolerances (5M / 730 days), so the similarity gate fails and
        // NO class reference exists → the blend stays off and the project keeps
        // pure project_history. (Positive blend coverage lives in
        // test_phase2_blend_applies_when_class_matches.)
        $this->assertSame('project_history', $report['regression']['data_source'] ?? 'project_history');
    }

    /**
     * ------------------------------------------------------------------------
     * Phase 2 (Decision 8) — blend applies even when the supplement threshold
     * is reached, PROVIDED a usable category class exists.
     * ------------------------------------------------------------------------
     */
    public function test_phase2_blend_applies_when_class_matches(): void
    {
        $categoryId = $this->makeCategory('Blend Category')->id;
        $this->seedSimilarCompletedProjects($categoryId);

        $target = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [10, 25, 45, 65, 85, 100],
            overrides: [
                'status'                => 'Ongoing',
                'category_id'           => $categoryId,
                'approved_budget'       => 5000000.00,
                'planned_duration_days' => 730,
            ]
        );

        // 6 observations ≥ max(2, ceil(plannedMonths/3)) → no supplement
        $this->assertSame('none', $this->historicalSimilarityService->shouldSupplement($target, 6));

        $report = $this->predictionService->generatePrediction($target);

        // Decision 8: the Phase 2 blend replaces the old sparse-only supplement
        // — a project with enough own data AND a usable category class is
        // blended (data_source 'blended'), pulling the next-month prediction,
        // forecast band, and reliability toward the class curve.
        $this->assertSame('blended', $report['regression']['data_source'] ?? 'project_history');
        $this->assertArrayHasKey('blend_weight', $report['regression']);
        $this->assertGreaterThan(0, $report['regression']['blend_weight']);

        // Blend weight w = obs/(obs+4) → 6 real obs → 0.6.
        $this->assertEqualsWithDelta(0.6, $report['regression']['blend_weight'], 0.01);
    }

    /**
     * ------------------------------------------------------------------------
     * A2 — implausible polynomial completion falls back to the class curve
     * (T6/T7, tied to Fix H item 3)
     * ------------------------------------------------------------------------
     *
     * A polynomial that would otherwise emit a past completion date (or a
     * descending tail) must NOT leave the polynomial method "N/A" when a usable
     * category class exists. CompletionComparisonService substitutes a class-
     * derived estimate (flagged fallback_method) blended with schedule-aware so
     * the comparison keeps a full 3-method valid_count and a sane spread.
     *
     * Coefficients mirror the unit test's descending-tail cubic (a=-0.1,
     * b=0.6, c=28.8, d=-144.2) — root in the FUTURE but f'(x)<0 → the own-model
     * path rejects it, so the fallback must kick in.
     */
    public function test_a2_class_fallback_substitutes_implausible_polynomial(): void
    {
        $categoryId = $this->makeCategory('A2 Category')->id;
        $this->seedSimilarCompletedProjects($categoryId);

        $target = $this->createProjectWithHistory(
            months: 12,
            actualProgress: [2, 4, 8, 15, 24, 35, 49, 63, 77, 86, 89, 92],
            overrides: [
                'status'                => 'Ongoing',
                'category_id'           => $categoryId,
                'approved_budget'       => 5000000.00,
                'planned_duration_days' => 360,
            ]
        );

        $analysis = [
            'regression_method'     => 'polynomial',
            'a'                     => -0.1,
            'b'                     => 0.6,
            'c'                     => 28.8,
            'd'                     => -144.2,
            'r_squared'             => 0.9983,
            'observation_count'     => 12,
            'synthetic_points_count'=> 0,
        ];

        // Class pool must actually be usable (aggregate non-empty)
        $reference = $this->historicalSimilarityService->buildClassReference(
            $target,
            $target->baselineSchedules->count()
        );
        $this->assertNotEmpty($reference['median'] ?? []);

        // currentProgress = 50 is inside the class band (median caps ~59.59 for
        // this pool), so the class fallback has evidence to anchor on. The
        // coefficients are what make the OWN polynomial implausible.
        $result = $this->completionComparisonService->estimateAll(
            $target->planned_end_date,
            $analysis,
            9,
            50.0,
            $target->baselineSchedules,
            $reference
        );

        // T6: the polynomial method is NOT N/A — it carries a class-derived
        // fallback, and all three methods contribute to the comparison.
        $poly = $result['methods']['polynomial'];
        $this->assertNotNull($poly);
        $this->assertSame('class_aggregate', $poly['fallback_method'] ?? null);
        $this->assertGreaterThan(9, $poly['estimated_completion_index']);

        // T7: valid_count stays 3 and the spread/agreement reflect the bounded
        // dates (no 408-day past-date artifact).
        $this->assertSame(3, $result['comparison']['valid_count']);
        $this->assertGreaterThan(0, $result['comparison']['earliest_date']->diffInDays($result['comparison']['latest_date']));
    }

    /**
     * ------------------------------------------------------------------------
     * A2 — no class reference → backward-compatible null (no fallback)
     * ------------------------------------------------------------------------
     *
     * When estimateAll() is called without a class reference the implausible
     * polynomial still returns null, exactly as before A2.
     */
    public function test_a2_without_class_reference_keeps_null_polynomial(): void
    {
        $categoryId = $this->makeCategory('A2 No Class')->id;
        $target = $this->createProjectWithHistory(
            months: 12,
            actualProgress: [2, 4, 8, 15, 24, 35, 49, 63, 77, 86, 89, 92],
            overrides: [
                'status'                => 'Ongoing',
                'category_id'           => $categoryId,
                'approved_budget'       => 5000000.00,
                'planned_duration_days' => 360,
            ]
        );

        $analysis = [
            'regression_method'      => 'polynomial',
            'a'                      => -0.1,
            'b'                      => 0.6,
            'c'                      => 28.8,
            'd'                      => -144.2,
            'r_squared'              => 0.9983,
            'observation_count'      => 12,
            'synthetic_points_count' => 0,
        ];

        $result = $this->completionComparisonService->estimateAll(
            $target->planned_end_date,
            $analysis,
            9,
            92.0,
            $target->baselineSchedules
        );

        $this->assertNull($result['methods']['polynomial']);
    }

    /**
     * ------------------------------------------------------------------------
     * T6 — B5/B6: blend-mode project surfaces a usable class_standing block
     * ------------------------------------------------------------------------
     *
     * A project blended against a real similar-project pool must surface
     * class_standing with usable=true, a tier from the allowed set, numeric
     * bands, and a self-consistent at-risk flag (run >= 2 ⟺ at_risk).
     * Exact tier/run values are not asserted here (the scale-ratio
     * normalization makes them pool-dependent) — threshold math is unit-tested
     * in ClassStandingTest with a fixed reference.
     */
    public function test_b56_blend_project_surfaces_usable_class_standing(): void
    {
        $categoryId = $this->makeCategory('B6 Blend Category')->id;
        $this->seedSimilarCompletedProjects($categoryId);

        $target = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [10, 25, 45, 65, 85, 100],
            overrides: [
                'status'                => 'Ongoing',
                'category_id'           => $categoryId,
                'approved_budget'       => 5000000.00,
                'planned_duration_days' => 180,
            ]
        );

        $report = $this->predictionService->generatePrediction($target);

        $this->assertSame('blended', $report['regression']['data_source'] ?? 'project_history');

        $standing = $report['class_standing'];
        $this->assertTrue($standing['usable']);
        $this->assertSame(3, $standing['similar_count']);
        $this->assertContains($standing['tier'], ['below_p25', 'p25_50', 'p50_75', 'above_p75']);
        $this->assertNotNull($standing['tier_label']);
        $this->assertIsNumeric($standing['lower_band']);
        $this->assertIsNumeric($standing['median_band']);
        $this->assertIsNumeric($standing['upper_band']);
        // Self-consistency: at_risk implies a run of >= 2; a run < 2 means
        // at_risk is false.
        $this->assertSame($standing['at_risk'], $standing['at_risk_run'] >= 2);

        // Presenter exposes the block for the blade.
        $presented = $this->presenter->present($target);
        $this->assertTrue($presented['class_standing']['usable']);
        $this->assertContains(
            $presented['class_standing']['tier'],
            ['below_p25', 'p25_50', 'p50_75', 'above_p75']
        );
    }

    /**
     * ------------------------------------------------------------------------
     * T7 — B5/B6 honesty: no similar pool → class_standing unusable and no
     * B6 recommendation is fired.
     * ------------------------------------------------------------------------
     */
    public function test_b56_no_pool_leaves_class_standing_unusable_and_no_b6_recommendation(): void
    {
        $categoryId = $this->makeCategory('B6 No Pool')->id;

        $target = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [2, 5, 9, 14, 20, 27],
            overrides: [
                'status'                => 'Ongoing',
                'category_id'           => $categoryId,
                'approved_budget'       => 5000000.00,
                'planned_duration_days' => 180,
            ]
        );

        $this->assertSame(0, $this->historicalSimilarityService->getSimilarProjectsCountFor($target));

        $report = $this->predictionService->generatePrediction($target);

        $this->assertFalse($report['class_standing']['usable']);
        $this->assertNull($report['class_standing']['tier']);
        $this->assertNull($report['class_standing']['tier_label']);
        $this->assertFalse($report['class_standing']['at_risk']);
        $this->assertSame(0, $report['class_standing']['at_risk_run']);

        // No B6 recommendation may fire without a usable pool.
        $engine = new RecommendationEngine();
        $recommendations = $engine->generate($target, $report);

        foreach ($recommendations as $recommendation) {
            $this->assertNotContains('trailed similar projects', [$recommendation['message']]);
            $this->assertNotContains('below the lower band of similar projects', [$recommendation['reason']]);
        }
    }

    /**
     * ------------------------------------------------------------------------
     * T8 — B6 caller normalization: duplicate inspections in one calendar month
     * must NOT inflate the at-risk run.
     *
     * generatePrediction() collapses progressHistory() to ONE latest inspection
     * per month_label before building class standing. Adding a redundant
     * (earlier-dated) inspection to the final month therefore changes neither
     * at_risk_run nor at_risk — the run counts calendar months, not rows.
     * ------------------------------------------------------------------------
     */
    public function test_b56_multi_inspection_month_does_not_inflate_run(): void
    {
        $categoryId = $this->makeCategory('B6 Multi Inspection')->id;
        $this->seedSimilarCompletedProjects($categoryId);

        $target = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [10, 25, 45, 65, 85, 100],
            overrides: [
                'status'                => 'Ongoing',
                'category_id'           => $categoryId,
                'approved_budget'       => 5000000.00,
                'planned_duration_days' => 180,
            ]
        );

        $reportBefore = $this->predictionService->generatePrediction($target);
        $standingBefore = $reportBefore['class_standing'];

        // The original month-6 inspection.
        $finalMonth = $target->progressHistory->sortByDesc('inspection_date')->first();

        // A redundant inspection in the SAME calendar month but earlier date
        // and a below-band progress value. Without caller normalization this
        // would look like an extra trailing month and extend the at-risk run.
        ProjectProgressHistory::factory()->create([
            'project_id'              => $target->id,
            'month_number'            => $finalMonth->month_number,
            'month_label'             => $finalMonth->month_label,
            'inspection_date'         => \Illuminate\Support\Carbon::parse($finalMonth->inspection_date)->subDays(10)->format('Y-m-d'),
            'actual_progress_percent' => 1.0,
            'progress_percentage'     => 1.0,
        ]);

        $reportAfter = $this->predictionService->generatePrediction($target->fresh());
        $standingAfter = $reportAfter['class_standing'];

        // Same usable pool, same calendar months → identical run.
        $this->assertTrue($standingBefore['usable']);
        $this->assertTrue($standingAfter['usable']);
        $this->assertSame($standingBefore['at_risk_run'], $standingAfter['at_risk_run']);
        $this->assertSame($standingBefore['at_risk'], $standingAfter['at_risk']);
    }
}
