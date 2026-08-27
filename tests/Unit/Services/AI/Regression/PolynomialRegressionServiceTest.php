<?php

namespace Tests\Unit\Services\AI\Regression;

use App\Services\AI\Regression\PolynomialRegressionService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PolynomialRegressionService — cubic (degree-3) regression.
 * Pure unit tests (no Laravel container).
 */
class PolynomialRegressionServiceTest extends TestCase
{
    private PolynomialRegressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PolynomialRegressionService();
    }

    // ========================================================================
    // analyzeDataset — basic functionality
    // ========================================================================

    public function test_analyze_returns_required_keys(): void
    {
        $x = [1, 2, 3, 4, 5];
        $y = [10, 20, 30, 40, 50];

        $result = $this->service->analyzeDataset($x, $y);

        $this->assertArrayHasKey('observation_count', $result);
        $this->assertArrayHasKey('coefficients', $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertArrayHasKey('d', $result);
        $this->assertArrayHasKey('equation', $result);
        $this->assertArrayHasKey('r_squared', $result);
        $this->assertArrayHasKey('fit_quality', $result);
    }

    public function test_analyze_returns_correct_observation_count(): void
    {
        $x = [1, 2, 3, 4];
        $y = [10, 20, 30, 40];

        $result = $this->service->analyzeDataset($x, $y);

        $this->assertSame(4, $result['observation_count']);
    }

    public function test_analyze_coefficients_array_matches_individual_values(): void
    {
        $x = [1, 2, 3, 4, 5];
        $y = [10, 20, 30, 40, 50];

        $result = $this->service->analyzeDataset($x, $y);

        $this->assertSame([$result['a'], $result['b'], $result['c'], $result['d']], $result['coefficients']);
    }

    // ========================================================================
    // R-squared
    // ========================================================================

    public function test_r_squared_perfect_fit(): void
    {
        // Exact cubic: y = 1*x^3 + 0*x^2 + 0*x + 0
        $x = [1, 2, 3, 4];
        $y = [1, 8, 27, 64];

        $result = $this->service->analyzeDataset($x, $y);

        $this->assertEqualsWithDelta(1.0, $result['r_squared'], 0.001);
    }

    public function test_r_squared_imperfect_fit(): void
    {
        // Linear data with some noise — cubic should still fit well
        $x = [1, 2, 3, 4, 5];
        $y = [10, 21, 29, 41, 50];

        $result = $this->service->analyzeDataset($x, $y);

        $this->assertGreaterThan(0.99, $result['r_squared']);
    }

    public function test_r_squared_all_same_values(): void
    {
        $x = [1, 2, 3, 4];
        $y = [50, 50, 50, 50];

        $result = $this->service->analyzeDataset($x, $y);

        // Perfect fit when all values are the same
        $this->assertEqualsWithDelta(1.0, $result['r_squared'], 0.001);
    }

    // ========================================================================
    // predict
    // ========================================================================

    public function test_predict_known_coefficients(): void
    {
        // y = 1*x^3 + 0*x^2 + 0*x + 0
        $this->assertEqualsWithDelta(8.0, $this->service->predict(2, 1, 0, 0, 0), 0.001);
        $this->assertEqualsWithDelta(27.0, $this->service->predict(3, 1, 0, 0, 0), 0.001);
    }

    public function test_predict_negative_coefficients(): void
    {
        // y = -1*x^3 + 2*x^2 + 0*x + 0
        // At x=1: -1 + 2 = 1
        $this->assertEqualsWithDelta(1.0, $this->service->predict(1, -1, 2, 0, 0), 0.001);
        // At x=2: -8 + 8 = 0
        $this->assertEqualsWithDelta(0.0, $this->service->predict(2, -1, 2, 0, 0), 0.001);
    }

    public function test_predict_zero_month(): void
    {
        // y = a*0 + b*0 + c*0 + d = d
        $this->assertEqualsWithDelta(5.0, $this->service->predict(0, 1, 2, 3, 5), 0.001);
    }

    // ========================================================================
    // predictUsingAnalysis
    // ========================================================================

    public function test_predict_using_analysis_matches_predict(): void
    {
        $x = [1, 2, 3, 4, 5];
        $y = [10, 20, 30, 40, 50];

        $analysis = $this->service->analyzeDataset($x, $y);

        $direct = $this->service->predict(3, $analysis['a'], $analysis['b'], $analysis['c'], $analysis['d']);
        $fromAnalysis = $this->service->predictUsingAnalysis($analysis, 3);

        $this->assertEqualsWithDelta($direct, $fromAnalysis, 0.0001);
    }

    // ========================================================================
    // Fit quality classification
    // ========================================================================

    public function test_fit_quality_excellent(): void
    {
        $x = [1, 2, 3, 4, 5, 6];
        $y = [1, 8, 27, 64, 125, 216];

        $result = $this->service->analyzeDataset($x, $y);

        $this->assertSame('Excellent', $result['fit_quality']);
    }

    public function test_fit_quality_very_good(): void
    {
        // Slightly noisy cubic data — should still be very good
        $x = [1, 2, 3, 4, 5, 6];
        $y = [1, 8, 27, 64, 125, 210]; // slight deviation at end

        $result = $this->service->analyzeDataset($x, $y);

        $this->assertContains($result['fit_quality'], ['Excellent', 'Very Good']);
    }

    // ========================================================================
    // Edge cases — validation
    // ========================================================================

    public function test_throws_on_mismatched_lengths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->analyzeDataset([1, 2, 3], [10, 20]);
    }

    public function test_throws_on_fewer_than_4_observations(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->analyzeDataset([1, 2, 3], [10, 20, 30]);
    }

    public function test_exactly_4_observations_succeeds(): void
    {
        $result = $this->service->analyzeDataset([1, 2, 3, 4], [10, 20, 30, 40]);

        $this->assertSame(4, $result['observation_count']);
    }

    // ========================================================================
    // Edge cases — S-curve shape
    // ========================================================================

    public function test_s_curve_data(): void
    {
        // Typical S-curve: slow start, rapid growth, plateau
        $x = [1, 2, 3, 4, 5, 6, 7, 8];
        $y = [2, 5, 12, 30, 55, 78, 90, 96];

        $result = $this->service->analyzeDataset($x, $y);

        $this->assertGreaterThan(0.95, $result['r_squared']);
        // Predictions should be monotonically increasing for this data
        $pred1 = $this->service->predictUsingAnalysis($result, 5);
        $pred2 = $this->service->predictUsingAnalysis($result, 6);
        $this->assertGreaterThan($pred1, $pred2);
    }

    public function test_linear_data_still_fits(): void
    {
        // Pure linear data — cubic should degenerate to near-linear
        $x = [1, 2, 3, 4, 5];
        $y = [10, 20, 30, 40, 50];

        $result = $this->service->analyzeDataset($x, $y);

        // a should be near 0 for linear data
        $this->assertEqualsWithDelta(0.0, $result['a'], 0.01);
        $this->assertGreaterThan(0.99, $result['r_squared']);
    }

    // ========================================================================
    // Equation format
    // ========================================================================

    public function test_equation_format(): void
    {
        $x = [1, 2, 3, 4];
        $y = [1, 8, 27, 64];

        $result = $this->service->analyzeDataset($x, $y);

        $this->assertIsString($result['equation']);
        $this->assertStringContainsString('x³', $result['equation']);
        $this->assertStringContainsString('x²', $result['equation']);
        $this->assertStringContainsString('x', $result['equation']);
    }
}
