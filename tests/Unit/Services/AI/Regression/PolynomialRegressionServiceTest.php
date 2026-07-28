<?php

namespace Tests\Unit\Services\AI\Regression;

use App\Services\AI\Regression\PolynomialRegressionService;
use PHPUnit\Framework\TestCase;

class PolynomialRegressionServiceTest extends TestCase
{
    private PolynomialRegressionService $regressionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->regressionService = new PolynomialRegressionService();
    }

    /*
    |==========================================================================
    | Dataset A — Perfect Cubic (y = x³)
    |==========================================================================
    |
    | The textbook cubic: y = 1·x³ + 0·x² + 0·x + 0
    | Every output value can be verified by hand.
    |
    */

    public function test_observation_count_for_perfect_cubic(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $this->assertEquals(5, $result['observation_count']);
    }

    public function test_coefficients_for_perfect_cubic(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $this->assertEqualsWithDelta(1.0, $result['a'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['b'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['c'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['d'], 1e-10);
    }

    public function test_coefficients_array_for_perfect_cubic(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $this->assertCount(4, $result['coefficients']);
        $this->assertEqualsWithDelta(1.0, $result['coefficients'][0], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['coefficients'][1], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['coefficients'][2], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['coefficients'][3], 1e-10);
    }

    public function test_r_squared_for_perfect_cubic(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $this->assertEqualsWithDelta(1.0, $result['r_squared'], 1e-10);
    }

    public function test_fit_quality_for_perfect_cubic(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $this->assertEquals('Excellent', $result['fit_quality']);
    }

    public function test_equation_format_for_perfect_cubic(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $this->assertStringContainsString('y = ', $result['equation']);
        $this->assertStringContainsString('1.0000x³', $result['equation']);
    }

    public function test_analysis_has_all_expected_keys(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $expectedKeys = [
            'observation_count', 'coefficients',
            'a', 'b', 'c', 'd',
            'equation', 'r_squared', 'fit_quality',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }

        $this->assertCount(count($expectedKeys), $result);
    }

    /*
    |==========================================================================
    | Dataset D — Linear (y = 2x + 1)
    |==========================================================================
    |
    | Pure linear data — polynomial should produce a≈0, b≈0, c≈2, d≈1.
    | Tests that polynomial correctly handles degenerate cases where
    | higher-order coefficients are zero.
    |
    */

    public function test_coefficients_for_linear_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [3, 5, 7, 9, 11]
        );

        $this->assertEqualsWithDelta(0.0, $result['a'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['b'], 1e-10);
        $this->assertEqualsWithDelta(2.0, $result['c'], 1e-10);
        $this->assertEqualsWithDelta(1.0, $result['d'], 1e-10);
        $this->assertEqualsWithDelta(1.0, $result['r_squared'], 1e-10);
    }

    /*
    |==========================================================================
    | Dataset E — Quadratic (y = x² + x)
    |==========================================================================
    |
    | Pure quadratic (degree 2) data, minimum 4 observations.
    | Polynomial (degree 3) should produce a≈0, b≈1, c≈1, d≈0.
    |
    */

    public function test_coefficients_for_quadratic_dataset(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4],
            [2, 6, 12, 20]
        );

        $this->assertEqualsWithDelta(0.0, $result['a'], 1e-10);
        $this->assertEqualsWithDelta(1.0, $result['b'], 1e-10);
        $this->assertEqualsWithDelta(1.0, $result['c'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['d'], 1e-10);
        $this->assertEqualsWithDelta(1.0, $result['r_squared'], 1e-10);
    }

    /*
    |==========================================================================
    | Dataset F — Negative Quadratic (y = -x² + x + 20)
    |==========================================================================
    |
    | Tests handling of negative higher-order coefficients.
    |
    */

    public function test_coefficients_for_negative_quadratic(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [20, 18, 14, 8, 0]
        );

        $this->assertEqualsWithDelta(0.0, $result['a'], 1e-10);
        $this->assertEqualsWithDelta(-1.0, $result['b'], 1e-10);
        $this->assertEqualsWithDelta(1.0, $result['c'], 1e-10);
        $this->assertEqualsWithDelta(20.0, $result['d'], 1e-10);
        $this->assertEqualsWithDelta(1.0, $result['r_squared'], 1e-10);
    }

    /*
    |==========================================================================
    | Dataset B — S-Curve Shape
    |==========================================================================
    |
    | 8 observations with S-curve characteristics (slow start, rapid middle).
    | Polynomial should produce an Excellent fit.
    |
    */

    public function test_s_curve_dataset_produces_excellent_fit(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5, 6, 7, 8],
            [2, 5, 10, 18, 28, 40, 55, 70]
        );

        $this->assertEquals(8, $result['observation_count']);
        $this->assertEquals('Excellent', $result['fit_quality']);
        $this->assertGreaterThanOrEqual(0.99, $result['r_squared']);
        $this->assertStringContainsString('x³', $result['equation']);
    }

    /*
    |==========================================================================
    | Dataset C — Saturating (Plateau)
    |==========================================================================
    |
    | 8 observations with a plateau at the end, mimicking real construction
    | S-curves. Polynomial should capture the flattening better than SLR.
    |
    */

    public function test_saturating_dataset_produces_excellent_fit(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5, 6, 7, 8],
            [2, 5, 10, 16, 19, 20, 21, 21]
        );

        $this->assertEquals(8, $result['observation_count']);
        $this->assertEquals('Excellent', $result['fit_quality']);
        $this->assertGreaterThan(0.90, $result['r_squared']);
        $this->assertStringContainsString('x³', $result['equation']);
    }

    /*
    |==========================================================================
    | Minimum Observations (n=4)
    |==========================================================================
    |
    | Exactly 4 observations — the minimum required for cubic regression.
    | Should produce a perfect fit (R² = 1.0).
    |
    */

    public function test_regression_with_four_observations(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4],
            [10, 20, 30, 45]
        );

        $this->assertEquals(4, $result['observation_count']);
        $this->assertEqualsWithDelta(1.0, $result['r_squared'], 1e-10);
        $this->assertEquals('Excellent', $result['fit_quality']);
    }

    /*
    |==========================================================================
    | predict() Method Tests
    |==========================================================================
    */

    public function test_predict_cubic_y_equals_x_cubed(): void
    {
        $result = $this->regressionService->predict(3.0, 1.0, 0.0, 0.0, 0.0);

        $this->assertEqualsWithDelta(27.0, $result, 1e-10);
    }

    public function test_predict_linear_y_equals_2x_plus_1(): void
    {
        $result = $this->regressionService->predict(7.0, 0.0, 0.0, 2.0, 1.0);

        $this->assertEqualsWithDelta(15.0, $result, 1e-10);
    }

    public function test_predict_quadratic_y_equals_x_squared_plus_x(): void
    {
        $result = $this->regressionService->predict(5.0, 0.0, 1.0, 1.0, 0.0);

        $this->assertEqualsWithDelta(30.0, $result, 1e-10);
    }

    public function test_predict_with_float_month_index(): void
    {
        $result = $this->regressionService->predict(3.5, 0.0, 1.0, 1.0, 0.0);

        $expected = 3.5 * 3.5 + 3.5;
        $this->assertEqualsWithDelta($expected, $result, 1e-10);
    }

    public function test_predict_at_index_zero_returns_intercept(): void
    {
        $result = $this->regressionService->predict(0.0, 5.0, 3.0, 2.0, 7.0);

        $this->assertEqualsWithDelta(7.0, $result, 1e-10);
    }

    /*
    |==========================================================================
    | predictUsingAnalysis() Method Tests
    |==========================================================================
    */

    public function test_predict_using_analysis_array(): void
    {
        $analysis = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $predicted = $this->regressionService->predictUsingAnalysis($analysis, 6.0);

        $this->assertEqualsWithDelta(216.0, $predicted, 1e-10);
    }

    public function test_predict_using_analysis_matches_direct_predict(): void
    {
        $analysis = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $directResult = $this->regressionService->predict(10.0, 1.0, 0.0, 0.0, 0.0);
        $analysisResult = $this->regressionService->predictUsingAnalysis($analysis, 10.0);

        $this->assertSame($directResult, $analysisResult);
    }

    /*
    |==========================================================================
    | Validation & Error Handling
    |==========================================================================
    */

    public function test_count_mismatch_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same number of observations');

        $this->regressionService->analyzeDataset(
            [1, 2, 3, 4],
            [10, 20]
        );
    }

    public function test_three_observations_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least four observations');

        $this->regressionService->analyzeDataset(
            [1, 2, 3],
            [5, 10, 15]
        );
    }

    public function test_two_observations_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least four observations');

        $this->regressionService->analyzeDataset(
            [1, 2],
            [5, 10]
        );
    }

    public function test_single_observation_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least four observations');

        $this->regressionService->analyzeDataset(
            [1],
            [5]
        );
    }

    public function test_empty_dataset_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least four observations');

        $this->regressionService->analyzeDataset([], []);
    }

    public function test_identical_x_values_throws_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('singular');

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
    | Thresholds:
    |   R² ≥ 0.90  → Excellent
    |   R² ≥ 0.80  → Very Good
    |   R² ≥ 0.70  → Good
    |   R² ≥ 0.50  → Moderate
    |   R² < 0.50  → Weak
    |
    */

    public function test_fit_quality_excellent(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5, 6],
            [1, 8, 27, 64, 125, 216]
        );

        $this->assertEquals('Excellent', $result['fit_quality']);
        $this->assertGreaterThanOrEqual(0.90, $result['r_squared']);
    }

    public function test_fit_quality_very_good(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Verified: polynomial R² ≈ 0.816 (May 6, 2026)
        |--------------------------------------------------------------------------
        */

        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            [15, 35, 25, 55, 40, 75, 55, 90, 70, 100]
        );

        $this->assertEquals('Very Good', $result['fit_quality']);
        $this->assertGreaterThanOrEqual(0.80, $result['r_squared']);
        $this->assertLessThan(0.90, $result['r_squared']);
    }

    public function test_fit_quality_good(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Verified: polynomial R² ≈ 0.770 (May 6, 2026)
        |--------------------------------------------------------------------------
        */

        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
            [20, 25, 18, 30, 22, 35, 28, 40, 32, 45, 38, 50]
        );

        $this->assertEquals('Good', $result['fit_quality']);
        $this->assertGreaterThanOrEqual(0.70, $result['r_squared']);
        $this->assertLessThan(0.80, $result['r_squared']);
    }

    public function test_fit_quality_moderate(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Verified: polynomial R² ≈ 0.543 (May 6, 2026)
        |--------------------------------------------------------------------------
        */

        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            [10, 30, 15, 35, 20, 40, 25, 45, 30, 50]
        );

        $this->assertEquals('Moderate', $result['fit_quality']);
        $this->assertGreaterThanOrEqual(0.50, $result['r_squared']);
        $this->assertLessThan(0.70, $result['r_squared']);
    }

    public function test_fit_quality_weak(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Verified: polynomial R² ≈ 0.032 (May 6, 2026)
        |--------------------------------------------------------------------------
        */

        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            [15, 8, 12, 20, 5, 18, 10, 22, 8, 14]
        );

        $this->assertEquals('Weak', $result['fit_quality']);
        $this->assertLessThan(0.50, $result['r_squared']);
    }

    /*
    |==========================================================================
    | Equation Format
    |==========================================================================
    */

    public function test_equation_format_with_negative_coefficients(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [20, 18, 14, 8, 0]
        );

        $this->assertStringContainsString('y = ', $result['equation']);
        $this->assertStringContainsString('0.0000x³', $result['equation']);
        $this->assertStringContainsString('- 1.0000x²', $result['equation']);
        $this->assertStringContainsString('+ 1.0000x', $result['equation']);
        $this->assertStringContainsString('+ 20.0000', $result['equation']);
    }

    public function test_equation_contains_cubic_term(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [1, 8, 27, 64, 125]
        );

        $this->assertStringContainsString('x³', $result['equation']);
        $this->assertStringContainsString('x²', $result['equation']);
        $this->assertStringContainsString('x', $result['equation']);
    }

    /*
    |==========================================================================
    | Predict Consistency
    |==========================================================================
    */

    public function test_predict_next_month_from_analysis(): void
    {
        $x = [1, 2, 3, 4, 5];
        $y = [3, 5, 7, 9, 11];

        $analysis = $this->regressionService->analyzeDataset($x, $y);

        $currentMonthIndex = count($x);
        $nextMonthIndex = $currentMonthIndex + 1;

        $predicted = $this->regressionService->predict(
            $nextMonthIndex,
            $analysis['a'],
            $analysis['b'],
            $analysis['c'],
            $analysis['d']
        );

        $this->assertEqualsWithDelta(13.0, $predicted, 1e-10);
    }

    public function test_predict_next_month_using_analysis(): void
    {
        $x = [1, 2, 3, 4, 5];
        $y = [1, 8, 27, 64, 125];

        $analysis = $this->regressionService->analyzeDataset($x, $y);

        $predicted = $this->regressionService->predictUsingAnalysis($analysis, 6.0);

        $this->assertEqualsWithDelta(216.0, $predicted, 1e-10);
    }

    /*
    |==========================================================================
    | Flat Line (All Y Identical)
    |==========================================================================
    |
    | When all Y values are identical, polynomial should still work correctly
    | (this tests edge case where SS_total = 0 -> R² = 1.0).
    |
    */

    public function test_regression_with_flat_line(): void
    {
        $result = $this->regressionService->analyzeDataset(
            [1, 2, 3, 4, 5],
            [5, 5, 5, 5, 5]
        );

        $this->assertEqualsWithDelta(0.0, $result['a'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['b'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $result['c'], 1e-10);
        $this->assertEqualsWithDelta(5.0, $result['d'], 1e-10);
        $this->assertEqualsWithDelta(1.0, $result['r_squared'], 1e-10);
        $this->assertEquals('Excellent', $result['fit_quality']);
    }
}
