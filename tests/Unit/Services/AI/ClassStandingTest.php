<?php

namespace Tests\Unit\Services\AI;

use App\Models\Project;
use App\Services\AI\AIPredictionService;
use App\Services\AI\BaselineComparisonService;
use App\Services\AI\CompletionComparisonService;
use App\Services\AI\CompletionEstimationService;
use App\Services\AI\ForecastReliabilityService;
use App\Services\AI\ForecastService;
use App\Services\AI\HistoricalSimilarityService;
use App\Services\AI\ProjectAssessmentService;
use App\Services\AI\Regression\LinearRegressionService;
use App\Services\AI\Regression\PolynomialRegressionService;
use App\Services\AI\Regression\RegressionDatasetBuilder;
use Carbon\Carbon;
use Tests\TestCase;
use ReflectionMethod;

/**
 * ============================================================================
 * B5/B6 — Class Standing Unit Tests
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates AIPredictionService::buildClassStanding():
 *   • B5-lite tier edges (below P25 / P25-50 / 50-75 / above P75).
 *   • B6 at-risk run detection (2+ consecutive months below the lower band).
 *   • Run disarm when a record at/above the band appears.
 *   • Honesty gate when no usable class reference exists.
 *   • Extended-project clamping to the plan's last band.
 *
 * Runs
 * ----
 *   php artisan test --filter=ClassStandingTest
 */
class ClassStandingTest extends TestCase
{
    private AIPredictionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $datasetBuilder = new RegressionDatasetBuilder();
        $regressionService = new LinearRegressionService();
        $polynomialService = new PolynomialRegressionService();
        $forecastService = new ForecastService($regressionService, $polynomialService);
        $completionService = new CompletionEstimationService();

        $this->service = new AIPredictionService(
            $datasetBuilder,
            $regressionService,
            $polynomialService,
            $forecastService,
            new BaselineComparisonService(),
            new ProjectAssessmentService(),
            $completionService,
            new CompletionComparisonService($completionService),
            new HistoricalSimilarityService(),
            new ForecastReliabilityService()
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Invoke the private buildClassStanding() via reflection.
     * ------------------------------------------------------------------------
     */
    private function classStanding(
        ?array $reference,
        int $currentMonthIndex,
        float $currentProgress,
        array $realObservations = []
    ): array {
        $method = new ReflectionMethod(AIPredictionService::class, 'buildClassStanding');
        $method->setAccessible(true);

        return $method->invoke(
            $this->service,
            new Project(),
            $reference,
            $currentMonthIndex,
            $currentProgress,
            $realObservations
        );
    }

    /**
     * Build a deterministic class reference: 12 planned months, linear bands,
     * P25 = month X 2, median = month X 4, P75 = month X 6.
     */
    private function reference(): array
    {
        $median = [];
        $lower  = [];
        $upper  = [];

        for ($i = 1; $i <= 12; $i++) {
            $median[$i] = (float) round($i * 4.0, 2);
            $lower[$i]  = (float) round($i * 2.0, 2);
            $upper[$i]  = (float) round($i * 6.0, 2);
        }

        return [
            'median'          => $median,
            'lower'           => $lower,
            'upper'           => $upper,
            'similar_projects_count' => 5,
        ];
    }

    /**
     * Build structured real observations: one latest-inspection record per
     * calendar month, keyed by its genuine month_number.
     */
    private function observations(array $monthToProgress): array
    {
        return collect($monthToProgress)->map(
            fn ($progress, $monthNumber) => [
                'month_number'             => (int) $monthNumber,
                'actual_progress_percent'  => (float) $progress,
            ]
        )->values()->toArray();
    }

    /**
     * T1 — Tier edges at the current month.
     * ------------------------------------------------------------------------
     * Month 5: lower = 10, median = 20, upper = 30.
     */
    public function test_tier_edges_against_band(): void
    {
        // actual strictly < P25 → below_p25
        $result = $this->classStanding($this->reference(), 5, 9.9);
        $this->assertTrue($result['usable']);
        $this->assertSame('below_p25', $result['tier']);
        $this->assertStringContainsString('slowest quarter', $result['tier_label']);

        // actual AT P25 (10.0) is NOT below_p25 — consistent with B6's strict
        // "< band" run rule (boundary consistency fix).
        $result = $this->classStanding($this->reference(), 5, 10.0);
        $this->assertSame('p25_50', $result['tier']);

        // P25 < actual < Median → p25_50
        $result = $this->classStanding($this->reference(), 5, 15.0);
        $this->assertSame('p25_50', $result['tier']);
        $this->assertStringContainsString('lower-middle', $result['tier_label']);

        // actual AT median (20.0) → p50_75 (median boundary is upper-inclusive)
        $result = $this->classStanding($this->reference(), 5, 20.0);
        $this->assertSame('p50_75', $result['tier']);

        // Median < actual <= P75 → p50_75
        $result = $this->classStanding($this->reference(), 5, 25.0);
        $this->assertSame('p50_75', $result['tier']);
        $this->assertStringContainsString('upper-middle', $result['tier_label']);

        // actual > P75 → above_p75
        $result = $this->classStanding($this->reference(), 5, 31.0);
        $this->assertSame('above_p75', $result['tier']);
        $this->assertStringContainsString('above the 75th', $result['tier_label']);
    }

    /**
     * T2 — 2+ consecutive trailing months below the lower band → at-risk.
     */
    public function test_at_risk_run_detected_after_two_consecutive_records(): void
    {
        // Month 1 lower = 2, Month 2 lower = 4 → trailing actuals 1.0/3.0
        // are both below the band.
        $result = $this->classStanding(
            $this->reference(),
            2,
            3.0,
            $this->observations([1 => 1.0, 2 => 3.0])
        );

        $this->assertTrue($result['usable']);
        $this->assertTrue($result['at_risk']);
        $this->assertSame(2, $result['at_risk_run']);
    }

    /**
     * T3 — A record at/above the band disarms the run, even with older dips.
     */
    public function test_record_at_or_above_band_resets_run(): void
    {
        // Month 1 below (1.0 < 2), Month 2 AT band (4.0 >= 4) → run resets.
        // Month 3 below (5.0 < 6) → run = 1, not at-risk.
        $result = $this->classStanding(
            $this->reference(),
            3,
            5.0,
            $this->observations([1 => 1.0, 2 => 4.0, 3 => 5.0])
        );

        $this->assertFalse($result['at_risk']);
        $this->assertSame(1, $result['at_risk_run']);
    }

    /**
     * T3b — A GAP in the monthly record (skipped calendar month) resets the
     * run: "2 consecutive months" means calendar months, not inspections.
     */
    public function test_gap_in_monthly_record_resets_run(): void
    {
        // Months 1-2 below the band, month 3 has NO record (skipped), then
        // month 4 is below. Because calendar month 3 is absent, the run can
        // only count month 4 → at-risk needs 2 consecutive months, so this is
        // run = 1, not at-risk. (Without the gap rule the old index-based
        // logic would have seen months 1,2,4 as "months 1,2,3".)
        $result = $this->classStanding(
            $this->reference(),
            4,
            8.0,
            $this->observations([1 => 1.0, 2 => 3.0, 4 => 7.0])
        );

        $this->assertFalse($result['at_risk']);
        $this->assertSame(1, $result['at_risk_run']);
    }

    /**
     * T3c — Multiple inspections in one calendar month cannot inflate the run.
     * The at-risk builder receives ONE record per month (the caller normalizes
     * to the latest inspection per month_label, mirroring RegressionDataset
     * Builder). Duplicate calendar months are impossible at this boundary, so
     * the run only ever counts distinct months — verified end-to-end in
     * CrossProjectSupplementIntegrationTest::test_b56_multi_inspection_month`.
     */

    /**
     * T4 — No usable class reference → usable=false, no tier, no flag, and
     * the fallback reference build stays quiet (no peer pool, none borrowed).
     */
    public function test_no_usable_reference_returns_empty_standing(): void
    {
        $result = $this->classStanding(
            null,
            3,
            10.0,
            $this->observations([1 => 1.0, 2 => 3.0])
        );

        $this->assertFalse($result['usable']);
        $this->assertNull($result['tier']);
        $this->assertNull($result['tier_label']);
        $this->assertFalse($result['at_risk']);
        $this->assertSame(0, $result['at_risk_run']);
        $this->assertSame(0, $result['similar_count']);
        $this->assertNull($result['lower_band']);
        $this->assertNull($result['median_band']);
        $this->assertNull($result['upper_band']);
    }

    /**
     * T5 — Extended project: a record after the plan's last month clamps to
     * the last band and can contribute to the run.
     */
    public function test_extended_record_clamps_to_last_planned_band(): void
    {
        // 12-month plan. Months 1-11 sit above their band, then months 12/13
        // (which clamp to the last-planned month 12, lower = 24) trail below.
        $monthToProgress = [];
        for ($i = 1; $i <= 11; $i++) {
            $monthToProgress[$i] = (float) ($i * 3.0); // above lower = i*2
        }
        $monthToProgress[12] = 20.0; // month 12 → below 24
        $monthToProgress[13] = 18.0; // month 13 → clamps to month 12, below 24

        $result = $this->classStanding(
            $this->reference(),
            14,
            20.0,
            $this->observations($monthToProgress)
        );

        // Anchor clamps current month 14 → month 12 (lower band 24).
        $this->assertSame(24.0, $result['lower_band']);
        // Months 12-13 below the clamped band → at-risk run 2.
        $this->assertTrue($result['at_risk']);
        $this->assertSame(2, $result['at_risk_run']);
    }

    /**
     * T2b — A single trailing dip is NOT at-risk (requires 2 consecutive).
     */
    public function test_single_trailing_dip_is_not_at_risk(): void
    {
        // Month 1 below (1.0 < 2) → run 1. Month 2 above band (8.0 >= 4)
        // resets the run to 0 → not at-risk.
        $result = $this->classStanding(
            $this->reference(),
            2,
            3.0,
            $this->observations([1 => 1.0, 2 => 8.0])
        );

        $this->assertFalse($result['at_risk']);
        $this->assertSame(0, $result['at_risk_run']);
    }
}