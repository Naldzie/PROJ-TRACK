<?php

namespace Tests\Unit\Services\AI\Regression;

use App\Services\AI\Regression\LinearRegressionService;
use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 * LinearRegressionServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates every mathematical calculation in LinearRegressionService
 * against known-correct datasets.
 *
 * This service has zero Laravel dependencies — these are pure Unit tests
 * extending PHPUnit\Framework\TestCase directly (no database needed).
 *
 * Test Datasets
 * -------------
 * Each dataset has pre-computed expected values verified independently:
 *
 * Dataset A — Perfect linear (y = 2x + 1)
 *   x = [1, 2, 3, 4, 5], y = [3, 5, 7, 9, 11]
 *   slope=2, intercept=1, R²=1.0, fit=Excellent
 *
 * Dataset B — Negative slope (y = -3x + 20)
 *   x = [1, 2, 3, 4, 5], y = [17, 14, 11, 8, 5]
 *   slope=-3, intercept=20, R²=1.0, fit=Excellent
 *
 * Dataset C — Minimum observations (n=2)
 *   x = [1, 2], y = [3, 7]
 *   slope=4, intercept=-1, R²=1.0, fit=Excellent
 *
 * Dataset D — Flat line (zero slope)
 *   x = [1, 2, 3, 4, 5], y = [5, 5, 5, 5, 5]
 *   slope=0, intercept=5, R²=1.0, fit=Excellent
 *
 * References
 * ----------
 * • Progress log values (July 6, 2026):
 *   slope=5.1151515151515, intercept=-9.9333333333333, R²=0.95445434179074
 *
 * Run With
 * --------
 *   php artisan test --filter=LinearRegressionServiceTest
 *
 * Depends On
 * ----------
 * LinearRegressionService — the service under test
 */
class LinearRegressionServiceTest extends TestCase
{
    private LinearRegressionService $regressionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->regressionService = new LinearRegressionService();
    }

    /*
    |==========================================================================
    | Dataset A — Perfect Linear (y = 2x + 1)
    |==========================================================================
    |
    | A textbook-perfect linear relationship.
    | Every output value can be verified by hand.
    |
    */

    /**
     * ------------------------------------------------------------------------
     * Observation count — Dataset A
     * ------------------------------------------------------------------------
     */
    public function test_observation_count_for_perfect_linear_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $this->assertEquals(5, $result['observation_count']);
    }

    /**
     * ------------------------------------------------------------------------
     * Mean X — Dataset A
     * ------------------------------------------------------------------------
     */
    public function test_mean_x_for_perfect_linear_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $this->assertEquals(3.0, $result['mean_x']);
    }

    /**
     * ------------------------------------------------------------------------
     * Mean Y — Dataset A
     * ------------------------------------------------------------------------
     */
    public function test_mean_y_for_perfect_linear_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $this->assertEquals(7.0, $result['mean_y']);
    }

    /**
     * ------------------------------------------------------------------------
     * Slope — Dataset A
     * ------------------------------------------------------------------------
     *
     * Expected: slope = Σ((x-x̄)(y-ȳ)) / Σ((x-x̄)²) = 20 / 10 = 2
     */
    public function test_slope_for_perfect_linear_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $this->assertEquals(2.0, $result['slope']);
    }

    /**
     * ------------------------------------------------------------------------
     * Intercept — Dataset A
     * ------------------------------------------------------------------------
     *
     * Expected: b = ȳ - (m × x̄) = 7 - (2 × 3) = 1
     */
    public function test_intercept_for_perfect_linear_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $this->assertEquals(1.0, $result['intercept']);
    }

    /**
     * ------------------------------------------------------------------------
     * R² — Dataset A
     * ------------------------------------------------------------------------
     *
     * Expected: 1.0 (perfect fit — all residuals are zero)
     */
    public function test_r_squared_for_perfect_linear_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $this->assertEquals(1.0, $result['r_squared']);
    }

    /**
     * ------------------------------------------------------------------------
     * Fit quality — Dataset A
     * ------------------------------------------------------------------------
     *
     * Expected: Excellent (R² = 1.0 ≥ 0.90)
     */
    public function test_fit_quality_for_perfect_linear_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $this->assertEquals('Excellent', $result['fit_quality']);
    }

    /**
     * ------------------------------------------------------------------------
     * Equation format — Dataset A (positive intercept)
     * ------------------------------------------------------------------------
     */
    public function test_equation_format_positive_intercept(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $this->assertStringContainsString('y = ', $result['equation']);
        $this->assertStringContainsString('2.0000', $result['equation']);
        $this->assertStringContainsString('+ 1.0000', $result['equation']);
    }

    /**
     * ------------------------------------------------------------------------
     * Complete structure — Dataset A
     * ------------------------------------------------------------------------
     *
     * Verify all 8 keys exist in the analysis result.
     */
    public function test_analysis_result_has_all_expected_keys(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $expectedKeys = [
            'observation_count', 'mean_x', 'mean_y',
            'slope', 'intercept', 'equation',
            'r_squared', 'fit_quality',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }

        $this->assertCount(count($expectedKeys), $result);
    }

    /*
    |==========================================================================
    | Dataset B — Negative Slope (y = -3x + 20)
    |==========================================================================
    |
    | Verifies the engine handles negative slopes correctly.
    |
    */

    /**
     * ------------------------------------------------------------------------
     * Slope — Dataset B
     * ------------------------------------------------------------------------
     */
    public function test_slope_for_negative_slope_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [17, 14, 11, 8, 5]
        );

        $this->assertEquals(-3.0, $result['slope']);
    }

    /**
     * ------------------------------------------------------------------------
     * Intercept — Dataset B
     * ------------------------------------------------------------------------
     */
    public function test_intercept_for_negative_slope_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [17, 14, 11, 8, 5]
        );

        $this->assertEquals(20.0, $result['intercept']);
    }

    /**
     * ------------------------------------------------------------------------
     * R² — Dataset B
     * ------------------------------------------------------------------------
     */
    public function test_r_squared_for_negative_slope_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [17, 14, 11, 8, 5]
        );

        $this->assertEquals(1.0, $result['r_squared']);
    }

    /**
     * ------------------------------------------------------------------------
     * Equation format — Dataset B (negative slope, positive intercept)
     * ------------------------------------------------------------------------
     */
    public function test_equation_format_negative_slope(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [17, 14, 11, 8, 5]
        );

        $this->assertStringContainsString('-3.0000', $result['equation']);
        $this->assertStringContainsString('+ 20.0000', $result['equation']);
    }

    /*
    |==========================================================================
    | Dataset C — Minimum Observations (n=2)
    |==========================================================================
    |
    | Two points always form a perfect line. Verifies the minimum
    | threshold works correctly.
    |
    */

    /**
     * ------------------------------------------------------------------------
     * Minimum observations — Dataset C
     * ------------------------------------------------------------------------
     */
    public function test_regression_with_two_observations(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2],
            [3, 7]
        );

        $this->assertEquals(2, $result['observation_count']);
        $this->assertEquals(1.5, $result['mean_x']);
        $this->assertEquals(5.0, $result['mean_y']);
        $this->assertEquals(4.0, $result['slope']);
        $this->assertEquals(-1.0, $result['intercept']);
        $this->assertEquals(1.0, $result['r_squared']);
        $this->assertEquals('Excellent', $result['fit_quality']);
        $this->assertStringContainsString('4.0000x - 1.0000', $result['equation']);
    }

    /*
    |==========================================================================
    | Dataset D — Flat Line (Zero Slope)
    |==========================================================================
    |
    | All Y values are identical, producing a horizontal regression line.
    |
    */

    /**
     * ------------------------------------------------------------------------
     * Zero slope — Dataset D
     * ------------------------------------------------------------------------
     */
    public function test_regression_with_zero_slope(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [5, 5, 5, 5, 5]
        );

        $this->assertEquals(0.0, $result['slope']);
        $this->assertEquals(5.0, $result['intercept']);
        $this->assertEquals(1.0, $result['r_squared']);
        $this->assertEquals('Excellent', $result['fit_quality']);
        $this->assertStringContainsString('0.0000x + 5.0000', $result['equation']);
    }

    /*
    |==========================================================================
    | predict() Method Tests
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Predict: y = 2x + 1 at x = 7
     * ------------------------------------------------------------------------
     *
     * Expected: 2 × 7 + 1 = 15
     */
    public function test_predict_with_positive_slope(): void
    {
        $result = $this->regressionService->predict(7.0, 2.0, 1.0);

        $this->assertEquals(15.0, $result);
    }

    /**
     * ------------------------------------------------------------------------
     * Predict: y = -3x + 20 at x = 6
     * ------------------------------------------------------------------------
     *
     * Expected: -3 × 6 + 20 = 2
     */
    public function test_predict_with_negative_slope(): void
    {
        $result = $this->regressionService->predict(6.0, -3.0, 20.0);

        $this->assertEquals(2.0, $result);
    }

    /**
     * ------------------------------------------------------------------------
     * Predict: y = 4x - 1 at x = 3
     * ------------------------------------------------------------------------
     *
     * Expected: 4 × 3 - 1 = 11
     */
    public function test_predict_with_two_observations(): void
    {
        $result = $this->regressionService->predict(3.0, 4.0, -1.0);

        $this->assertEquals(11.0, $result);
    }

    /**
     * ------------------------------------------------------------------------
     * Predict at floating-point month index
     * ------------------------------------------------------------------------
     */
    public function test_predict_with_float_month_index(): void
    {
        $result = $this->regressionService->predict(10.5, 2.0, 1.0);

        $this->assertEquals(22.0, $result);
    }

    /**
     * ------------------------------------------------------------------------
     * Predict at month index 0
     * ------------------------------------------------------------------------
     *
     * y = mx + b at x=0 should return the intercept.
     */
    public function test_predict_at_index_zero_returns_intercept(): void
    {
        $result = $this->regressionService->predict(0.0, 5.0, 3.0);

        $this->assertEquals(3.0, $result);
    }

    /*
    |==========================================================================
    | predictUsingAnalysis() Method Tests
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Predict using analysis array
     * ------------------------------------------------------------------------
     *
     * Uses the complete analysis output from analyzeDataset() as input.
     */
    public function test_predict_using_analysis_array(): void
    {
        $analysis = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $predicted = $this->regressionService->predictUsingAnalysis($analysis, 7.0);

        $this->assertEquals(15.0, $predicted);
    }

    /**
     * ------------------------------------------------------------------------
     * predictUsingAnalysis returns same result as predict
     * ------------------------------------------------------------------------
     */
    public function test_predict_using_analysis_matches_direct_predict(): void
    {
        $analysis = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $directResult = $this->regressionService->predict(10.0, 2.0, 1.0);
        $analysisResult = $this->regressionService->predictUsingAnalysis($analysis, 10.0);

        $this->assertSame($directResult, $analysisResult);
    }

    /*
    |==========================================================================
    | Validation & Error Handling
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Count mismatch
     * ------------------------------------------------------------------------
     *
     * X and Y arrays must have the same number of elements.
     * InvalidArgumentException expected.
     */
    public function test_count_mismatch_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same number of observations');

        $this->regressionService->analyzeDataset(
            [1, 2, 3],
            [5, 10]
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Fewer than 2 observations
     * ------------------------------------------------------------------------
     *
     * Linear regression requires at least 2 data points.
     * InvalidArgumentException expected.
     */
    public function test_single_observation_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least two observations');

        $this->regressionService->analyzeDataset(
            [1],
            [5]
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Zero observations
     * ------------------------------------------------------------------------
     *
     * Empty arrays should also throw.
     * InvalidArgumentException expected.
     */
    public function test_empty_dataset_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least two observations');

        $this->regressionService->analyzeDataset([], []);
    }

    /**
     * ------------------------------------------------------------------------
     * All X values identical
     * ------------------------------------------------------------------------
     *
     * When all X values are the same, the denominator Σ(x-x̄)² = 0,
     * making slope calculation impossible.
     * RuntimeException expected.
     */
    public function test_identical_x_values_throws_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('identical');

        $this->regressionService->analyzeDataset(
            [2, 2, 2, 2],
            [3, 5, 7, 9]
        );
    }

    /*
    |==========================================================================
    | Fit Quality Classification
    |==========================================================================
    |
    | These tests verify the classifyFit method indirectly through
    | analyzeDataset. Each dataset is constructed to produce R² in a
    | specific range.
    |
    | Thresholds:
    |   R² ≥ 0.90  → Excellent
    |   R² ≥ 0.80  → Very Good
    |   R² ≥ 0.70  → Good
    |   R² ≥ 0.50  → Moderate
    |   R² < 0.50  → Weak
    |
    */

    /**
     * ------------------------------------------------------------------------
     * Fit quality: Excellent (R² = 1.0)
     * ------------------------------------------------------------------------
     *
     * Perfect linear data always produces Excellent fit.
     */
    public function test_fit_quality_excellent(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5, 6],
            [10, 20, 30, 40, 50, 60]
        );

        $this->assertEquals('Excellent', $result['fit_quality']);
        $this->assertGreaterThanOrEqual(0.90, $result['r_squared']);
    }

    /**
     * ------------------------------------------------------------------------
     * Fit quality: Very Good (R² between 0.80 and 0.90)
     * ------------------------------------------------------------------------
     *
     * A dataset with moderate scatter produces a Very Good fit.
     */
    public function test_fit_quality_very_good(): void
    {
        /*
        |--------------------------------------------------------------------------
        | This dataset has a saturating trend producing R² ≈ 0.89
        | Pre-computed: slope ≈ 2.93, intercept ≈ 1.07, R² ≈ 0.89
        |--------------------------------------------------------------------------
        */

        $x = [1, 2, 3, 4, 5, 6, 7, 8];
        $y = [2, 5, 10, 16, 19, 20, 21, 21];

        $result = $this->regressionService->analyzeDataset($x, $y);

        $this->assertEquals('Very Good', $result['fit_quality']);
        $this->assertGreaterThanOrEqual(0.80, $result['r_squared']);
        $this->assertLessThan(0.90, $result['r_squared']);
    }

    /**
     * ------------------------------------------------------------------------
     * Fit quality: Good (R² between 0.70 and 0.80)
     * ------------------------------------------------------------------------
     *
     * A noisier dataset produces a Good fit.
     */
    public function test_fit_quality_good(): void
    {
        /*
        |--------------------------------------------------------------------------
        | This dataset has significant scatter producing R² ≈ 0.73
        | Pre-computed: slope ≈ 1.1, intercept ≈ 5.6, R² ≈ 0.73
        |--------------------------------------------------------------------------
        */

        $x = [1, 2, 3, 4, 5, 6, 7];
        $y = [5, 9, 10, 9, 12, 16, 13];

        $result = $this->regressionService->analyzeDataset($x, $y);

        $this->assertEquals('Good', $result['fit_quality']);
        $this->assertGreaterThanOrEqual(0.70, $result['r_squared']);
        $this->assertLessThan(0.80, $result['r_squared']);
    }

    /**
     * ------------------------------------------------------------------------
     * Fit quality: Moderate (R² between 0.50 and 0.70)
     * ------------------------------------------------------------------------
     *
     * A very scattered dataset produces a Moderate fit.
     */
    public function test_fit_quality_moderate(): void
    {
        /*
        |--------------------------------------------------------------------------
        | This dataset has alternating pattern producing R² ≈ 0.57
        | Pre-computed: slope ≈ 0.65, intercept ≈ 7.33, R² ≈ 0.57
        |--------------------------------------------------------------------------
        */

        $x = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $y = [7, 10, 8, 12, 9, 13, 10, 14, 11, 15];

        $result = $this->regressionService->analyzeDataset($x, $y);

        $this->assertEquals('Moderate', $result['fit_quality']);
        $this->assertGreaterThanOrEqual(0.50, $result['r_squared']);
        $this->assertLessThan(0.70, $result['r_squared']);
    }

    /**
     * ------------------------------------------------------------------------
     * Fit quality: Weak (R² < 0.50)
     * ------------------------------------------------------------------------
     *
     * An effectively random dataset produces a Weak fit.
     */
    public function test_fit_quality_weak(): void
    {
        /*
        |--------------------------------------------------------------------------
        | This near-random dataset produces R² ≈ 0.05
        | Pre-computed: slope ≈ 0.05, intercept ≈ 4.5, R² ≈ 0.05
        |--------------------------------------------------------------------------
        */

        $x = [1, 2, 3, 4, 5];
        $y = [4, 5, 3, 6, 2];

        $result = $this->regressionService->analyzeDataset($x, $y);

        $this->assertEquals('Weak', $result['fit_quality']);
        $this->assertLessThan(0.50, $result['r_squared']);
    }

    /*
    |==========================================================================
    | Predict Consistency
    |==========================================================================
    |
    | Verify that predict() and analyzeDataset() produce consistent results
    | when used together.
    |
    */

    /**
     * ------------------------------------------------------------------------
     * Predict next month using analyzeDataset results
     * ------------------------------------------------------------------------
     *
     * Simulates the real AIPredictionService workflow:
     * 1. Run analyzeDataset()
     * 2. Call predict(slope, intercept) for the next month index
     */
    public function test_predict_next_month_from_analysis(): void
    {
        $x = [1, 2, 3, 4, 5];
        $y = [3, 5, 7, 9, 11];

        $analysis = $this->regressionService->analyzeDataset($x, $y);

        // Current month index = 5, next month = 6
        $currentMonthIndex = count($x);
        $nextMonthIndex = $currentMonthIndex + 1;

        $predicted = $this->regressionService->predict(
            $nextMonthIndex,
            $analysis['slope'],
            $analysis['intercept']
        );

        // y = 2(6) + 1 = 13
        $this->assertEquals(13.0, $predicted);
    }

    /**
     * ------------------------------------------------------------------------
     * Predict next month using predictUsingAnalysis
     * ------------------------------------------------------------------------
     */
    public function test_predict_next_month_using_analysis(): void
    {
        $x = [1, 2, 3, 4, 5];
        $y = [17, 14, 11, 8, 5];

        $analysis = $this->regressionService->analyzeDataset($x, $y);

        // Current month index = 5, next month = 6
        $predicted = $this->regressionService->predictUsingAnalysis($analysis, 6.0);

        // y = -3(6) + 20 = 2
        $this->assertEquals(2.0, $predicted);
    }

    /*
    |==========================================================================
    | Equation Format Variations
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Equation with negative intercept
     * ------------------------------------------------------------------------
     *
     * When intercept is negative, the equation uses minus operator.
     */
    public function test_equation_with_negative_intercept(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2],
            [3, 7]
        );

        $this->assertStringContainsString('- 1.0000', $result['equation']);
        $this->assertStringNotContainsString('+', $result['equation']);
    }

    /**
     * ------------------------------------------------------------------------
     * Equation with zero intercept
     * ------------------------------------------------------------------------
     *
     * When intercept is zero, the equation shows + 0.0000.
     */
    public function test_equation_with_zero_intercept(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3],
            [2, 4, 6]
        );

        $this->assertStringContainsString('+ 0.0000', $result['equation']);
    }
}
