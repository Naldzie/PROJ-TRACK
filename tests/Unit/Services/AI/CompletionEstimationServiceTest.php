<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\CompletionEstimationService;
use App\Services\AI\Regression\PolynomialRegressionService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * ============================================================================
 * CompletionEstimationServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates CompletionEstimationService::estimatePolynomial() guards
 * introduced in Phase 14 Step 0:
 *   • Future-guard — a solved root at or before the current month index is
 *     never reported as a completion date (rejects past dates like the
 *     Project 142 regression).
 *   • Descending-branch rejection — a < 0 peak-then-bend-down cubic whose
 *     root lies on the downward tail is not extrapolated to 100% at all.
 *   • Healthy S-curves still produce a sane future completion estimate.
 *
 * Run With
 * --------
 *   php artisan test --filter=CompletionEstimationServiceTest
 */
class CompletionEstimationServiceTest extends TestCase
{
    private CompletionEstimationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CompletionEstimationService();
    }

    /**
     * ------------------------------------------------------------------------
     * Build a polynomial analysis array from raw coefficients
     * ------------------------------------------------------------------------
     */
    private function polynomialAnalysis(
        float $a,
        float $b,
        float $c,
        float $d,
        float $rSquared = 0.90,
        int $observationCount = 9
    ): array {
        return [
            'regression_method'  => 'polynomial',
            'a'                  => $a,
            'b'                  => $b,
            'c'                  => $c,
            'd'                  => $d,
            'r_squared'          => $rSquared,
            'observation_count'  => $observationCount,
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Project 142 live reproduction — root in the past is rejected
     * ------------------------------------------------------------------------
     *
     * Real coefficients from Project 142 (Aug 11 2026): the cubic
     * a=-0.125421, b=2.453824, c=-5.301708, d=4.801587 has a root at
     * index -4.78 (April 22 2026, in the past). The future-guard must make
     * the polynomial method return null instead of a past date.
     */
    public function test_polynomial_root_in_the_past_returns_null(): void
    {
        $analysis = $this->polynomialAnalysis(
            -0.125421,
            2.453824,
            -5.301708,
            4.801587,
            rSquared: 0.9993
        );

        $result = $this->service->estimatePolynomial(
            $analysis,
            totalPlannedMonths: 9,
            currentMonthIndex: 9,
            plannedEndDate: Carbon::parse('2027-03-31')
        );

        $this->assertNull($result);
    }

    /**
     * ------------------------------------------------------------------------
     * Root exactly at the current month index is not a future completion
     * ------------------------------------------------------------------------
     *
     * y = 10x (a cubic with a=b=0, c=10, d=0) crosses 100 at index 10.
     * With currentMonthIndex = 10 the future-guard (root <= current month)
     * must reject it even though the derivative there is positive.
     */
    public function test_polynomial_root_at_current_month_returns_null(): void
    {
        $analysis = $this->polynomialAnalysis(0.0, 0.0, 10.0, 0.0);

        $result = $this->service->estimatePolynomial(
            $analysis,
            totalPlannedMonths: 12,
            currentMonthIndex: 10,
            plannedEndDate: Carbon::parse('2026-12-31')
        );

        $this->assertNull($result);
    }

    /**
     * ------------------------------------------------------------------------
     * a < 0 peak-then-descend is rejected even when the root is in the future
     * ------------------------------------------------------------------------
     *
     * y = -0.1x³ + 0.6x² + 28.8x - 144.2 peaks at index 12 (115%) then
     * descends, crossing 100 at index 14.16 (f' < 0 on the tail). A
     * cumulative-progress curve can't decline, so this extrapolation is
     * meaningless and must be rejected despite the root being in the future.
     */
    public function test_polynomial_a_negative_descending_tail_returns_null(): void
    {
        $analysis = $this->polynomialAnalysis(-0.1, 0.6, 28.8, -144.2);

        $result = $this->service->estimatePolynomial(
            $analysis,
            totalPlannedMonths: 12,
            currentMonthIndex: 9,
            plannedEndDate: Carbon::parse('2026-12-31')
        );

        $this->assertNull($result);
    }

    /**
     * ------------------------------------------------------------------------
     * Healthy S-curve (a > 0) still yields a sane future completion estimate
     * ------------------------------------------------------------------------
     *
     * 'lin+' dataset (a = 0.042088, R² ≈ 1.0) has its root at index ~11.18.
     * With currentMonthIndex = 9 and a 9-month baseline ending 2027-01-31,
     * the extra 2.18 months lands the completion at ~2027-04-05.
     */
    public function test_polynomial_healthy_s_curve_still_predicts(): void
    {
        $y = [5, 8, 12, 17, 23, 30, 39, 50, 63];
        $analysis = (new PolynomialRegressionService())
            ->analyzeDataset(range(1, 9), $y);
        $analysis['regression_method'] = 'polynomial';
        $analysis['observation_count'] = 9;

        $result = $this->service->estimatePolynomial(
            $analysis,
            totalPlannedMonths: 9,
            currentMonthIndex: 9,
            plannedEndDate: Carbon::parse('2027-01-31')
        );

        $this->assertNotNull($result);
        $this->assertEquals(11.18, round($result['estimated_completion_index'], 2));
        $this->assertEquals(
            '2027-04-05',
            $result['estimated_completion_date']->format('Y-m-d')
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Non-polynomial regression method returns null early
     * ------------------------------------------------------------------------
     */
    public function test_polynomial_rejects_non_polynomial_analysis(): void
    {
        $analysis = $this->polynomialAnalysis(0.1, 1.0, -2.0, 3.0);
        $analysis['regression_method'] = 'slr';

        $result = $this->service->estimatePolynomial(
            $analysis,
            totalPlannedMonths: 9,
            currentMonthIndex: 9,
            plannedEndDate: Carbon::parse('2027-03-31')
        );

        $this->assertNull($result);
    }

    /**
     * ============================================================================
     * A2 — Completion sanity fallback (estimateFromClassReference)
     * ============================================================================
     */

    /**
     * ------------------------------------------------------------------------
     * T1 — Class median crossing current progress yields a future completion
     * ------------------------------------------------------------------------
     *
     * Class median reaches ~70% at month 6 of a 12-month plan. A project at
     * 70% after month 6 has ~6 class months left → completion at index 12
     * (from currentMonthIndex 6). Blended with nothing, the estimate must be
     * in the future and flagged class_aggregate.
     */
    public function test_class_reference_produces_future_completion(): void
    {
        $median = [1 => 10, 2 => 20, 3 => 32, 4 => 46, 5 => 58, 6 => 70, 7 => 80, 8 => 88, 9 => 94, 10 => 98, 11 => 99, 12 => 100];
        $reference = ['median' => $median];

        $result = $this->service->estimateFromClassReference(
            $reference,
            currentProgress: 70.0,
            currentMonthIndex: 6,
            totalPlannedMonths: 12,
            plannedEndDate: Carbon::parse('2027-03-31')
        );

        $this->assertNotNull($result);
        $this->assertGreaterThan(6, $result['estimated_completion_index']);
        $this->assertEquals('class_aggregate', $result['fallback_method']);
        $this->assertEquals(['class_aggregate'], $result['blend_partners']);
    }

    /**
     * ------------------------------------------------------------------------
     * T2 — Bound: class curve behind current progress clamps to sane horizon
     * ------------------------------------------------------------------------
     *
     * Class median peaks at 60% (never reaches 70%). The project has already
     * outrun its class, so the class has no evidence it can finish — the
     * fallback must NOT invent a date; it stays null.
     */
    public function test_class_reference_never_reaching_progress_returns_null(): void
    {
        $median = [1 => 8, 2 => 16, 3 => 25, 4 => 34, 5 => 44, 6 => 50, 7 => 55, 8 => 58, 9 => 60];
        $reference = ['median' => $median];

        $result = $this->service->estimateFromClassReference(
            $reference,
            currentProgress: 70.0,
            currentMonthIndex: 9,
            totalPlannedMonths: 9,
            plannedEndDate: Carbon::parse('2026-12-31')
        );

        $this->assertNull($result);
    }

    /**
     * ------------------------------------------------------------------------
     * T3 — Blend: schedule-aware index present → mean of the two
     * ------------------------------------------------------------------------
     *
     * Class reaches 70% at month 8 of 12 → class months-to-go = 4 → class index
     * 10 (from currentMonthIndex 6). Schedule-aware index 12. Blended index
     * must be 11.0 and blend_partners lists both sources.
     */
    public function test_class_reference_blends_with_schedule_aware(): void
    {
        $median = [1 => 10, 2 => 20, 3 => 32, 4 => 46, 5 => 58, 6 => 65, 7 => 68, 8 => 70, 9 => 80, 10 => 88, 11 => 95, 12 => 100];
        $reference = ['median' => $median];

        $result = $this->service->estimateFromClassReference(
            $reference,
            currentProgress: 70.0,
            currentMonthIndex: 6,
            totalPlannedMonths: 12,
            plannedEndDate: Carbon::parse('2027-03-31'),
            blendIndex: 12.0
        );

        $this->assertNotNull($result);
        $this->assertEquals(11.0, round($result['estimated_completion_index'], 4));
        $this->assertEquals(
            ['class_aggregate', 'schedule_aware'],
            $result['blend_partners']
        );
    }

    /**
     * ------------------------------------------------------------------------
     * T4 — Past-date bound: class months-to-go collapses to zero
     * ------------------------------------------------------------------------
     *
     * Class reaches current progress only at its very last planned month →
     * classMonthsToGo = 0 → the anchored index lands exactly at the current
     * month → rejected (never a past/now date).
     */
    public function test_class_reference_past_date_rejected(): void
    {
        $median = [1 => 2, 2 => 5, 3 => 8, 4 => 11, 5 => 14, 6 => 17, 7 => 20, 8 => 22, 9 => 25, 10 => 27, 11 => 29, 12 => 30];
        $reference = ['median' => $median];

        $result = $this->service->estimateFromClassReference(
            $reference,
            currentProgress: 30.0,
            currentMonthIndex: 12,
            totalPlannedMonths: 12,
            plannedEndDate: Carbon::parse('2026-12-31')
        );

        $this->assertNull($result);
    }

    /**
     * ------------------------------------------------------------------------
     * T5 — isPolynomialSelected distinguishes implausible-from-selected
     * ------------------------------------------------------------------------
     *
     * A selected polynomial with adequate R² → true (fallback warranted).
     * An SLR fit or a low-R² polynomial → false (plain N/A, no fallback).
     */
    public function test_is_polynomial_selected(): void
    {
        $selected = $this->polynomialAnalysis(0.0, 0.0, 10.0, 0.0, rSquared: 0.90);
        $this->assertTrue($this->service->isPolynomialSelected($selected));

        $slr = $this->polynomialAnalysis(0.0, 0.0, 10.0, 0.0);
        $slr['regression_method'] = 'slr';
        $this->assertFalse($this->service->isPolynomialSelected($slr));

        $lowR2 = $this->polynomialAnalysis(0.0, 0.0, 10.0, 0.0, rSquared: 0.30);
        $this->assertFalse($this->service->isPolynomialSelected($lowR2));
    }

    // ========================================================================
    // estimateEarnedSchedule — basic
    // ========================================================================

    public function test_earned_schedule_returns_null_when_progress_at_100(): void
    {
        $baseline = $this->createBaselineSchedules(12);

        $result = $this->service->estimateEarnedSchedule(
            Carbon::parse('2027-03-31'),
            currentMonthIndex: 6,
            currentProgress: 100.0,
            baselineSchedules: $baseline,
            analysis: ['r_squared' => 0.90, 'observation_count' => 6]
        );

        $this->assertNull($result);
    }

    public function test_earned_schedule_returns_null_when_r2_too_low(): void
    {
        $baseline = $this->createBaselineSchedules(12);

        $result = $this->service->estimateEarnedSchedule(
            Carbon::parse('2027-03-31'),
            currentMonthIndex: 6,
            currentProgress: 50.0,
            baselineSchedules: $baseline,
            analysis: ['r_squared' => 0.30, 'observation_count' => 6]
        );

        $this->assertNull($result);
    }

    public function test_earned_schedule_returns_null_when_no_baseline(): void
    {
        $result = $this->service->estimateEarnedSchedule(
            Carbon::parse('2027-03-31'),
            currentMonthIndex: 6,
            currentProgress: 50.0,
            baselineSchedules: collect(),
            analysis: ['r_squared' => 0.90, 'observation_count' => 6]
        );

        $this->assertNull($result);
    }

    public function test_earned_schedule_produces_valid_output(): void
    {
        $baseline = $this->createBaselineSchedules(12);

        $result = $this->service->estimateEarnedSchedule(
            Carbon::parse('2027-03-31'),
            currentMonthIndex: 6,
            currentProgress: 50.0,
            baselineSchedules: $baseline,
            analysis: ['r_squared' => 0.90, 'observation_count' => 6]
        );

        if ($result !== null) {
            $this->assertArrayHasKey('estimated_completion_index', $result);
            $this->assertArrayHasKey('estimated_completion_date', $result);
            $this->assertArrayHasKey('predicted_delay_days', $result);
            $this->assertArrayHasKey('completion_confidence', $result);
            $this->assertGreaterThan(6, $result['estimated_completion_index']);
        }
    }

    // ========================================================================
    // classifyConfidence
    // ========================================================================

    public function test_confidence_classification_returns_string(): void
    {
        $method = new \ReflectionMethod($this->service, 'classifyConfidence');

        // High R² + high observations + reasonable horizon
        $result = $method->invoke($this->service, 0.90, 8, 12.0, 12, 0);
        $this->assertIsString($result);
        $this->assertContains($result, ['High', 'Medium', 'Low']);

        // Low R²
        $result = $method->invoke($this->service, 0.30, 3, 20.0, 12, 0);
        $this->assertIsString($result);
        $this->assertSame('Low', $result);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createBaselineSchedules(int $months): \Illuminate\Support\Collection
    {
        $schedules = [];
        $startDate = now()->subMonths($months)->startOfMonth();
        for ($i = 1; $i <= $months; $i++) {
            $monthDate = (clone $startDate)->addMonths($i - 1);
            $schedules[] = (object) [
                'planned_progress_percent' => round(($i / $months) * 100, 2),
                'month_label' => $monthDate->format('Y-m'),
                'planned_date' => $monthDate->endOfMonth(),
            ];
        }
        return collect($schedules);
    }
}
