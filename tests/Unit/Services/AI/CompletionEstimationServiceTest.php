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
}
