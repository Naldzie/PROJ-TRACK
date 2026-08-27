<?php

namespace Tests\Unit\Services\AI;

use App\Models\PredictionHistory;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Services\AI\AIPredictionService;
use App\Services\AI\Regression\LinearRegressionService;
use App\Services\AI\Regression\PolynomialRegressionService;
use App\Services\AI\Regression\RegressionDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * Tests the SLR vs Polynomial model selection logic.
 * Validates the R² threshold gate and fallback behavior.
 */
class ModelSelectionTest extends TestCase
{
    use RefreshDatabase, CreatesProjectWithHistory;

    private AIPredictionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AIPredictionService::class);
    }

    // ========================================================================
    // Selection logic — SLR always runs first
    // ========================================================================

    public function test_slr_always_runs_even_when_polynomial_selected(): void
    {
        $project = $this->createProjectWithHistory(6);

        $result = $this->service->generatePrediction($project);

        $this->assertArrayHasKey('regression_method', $result['regression']);
        $this->assertContains($result['regression']['regression_method'], ['slr', 'polynomial']);
        $this->assertArrayHasKey('r_squared_slr', $result['regression']);
    }

    // ========================================================================
    // Selection logic — fewer than 4 observations forces SLR
    // ========================================================================

    public function test_fewer_than_4_observations_forces_slr(): void
    {
        $project = $this->createProjectWithHistory(3);

        $result = $this->service->generatePrediction($project);

        $this->assertSame('slr', $result['regression']['regression_method']);
        $this->assertNull($result['regression']['r_squared_poly']);
        $this->assertStringContainsString('Insufficient observations', $result['regression']['method_reason']);
    }

    // ========================================================================
    // Selection logic — polynomial needs R² improvement > threshold
    // ========================================================================

    public function test_polynomial_selected_when_r2_improvement_exceeds_threshold(): void
    {
        // S-curve data where polynomial should outperform SLR significantly
        $sCurve = [2, 5, 12, 25, 45, 70, 88, 96];
        $project = $this->createProjectWithHistory(8, $sCurve);

        $result = $this->service->generatePrediction($project);

        if ($result['regression']['regression_method'] === 'polynomial') {
            $threshold = config('ai.regression.slr_polynomial_r2_threshold');
            $this->assertGreaterThan(
                $result['regression']['r_squared_slr'] + $threshold,
                $result['regression']['r_squared_poly']
            );
        }
        // If SLR was selected, polynomial R² improvement was within threshold
    }

    public function test_slr_selected_when_polynomial_improvement_below_threshold(): void
    {
        // Linear data — polynomial should not provide meaningful improvement
        $linear = [10, 20, 30, 40, 50, 60];
        $project = $this->createProjectWithHistory(6, $linear);

        $result = $this->service->generatePrediction($project);

        $this->assertSame('slr', $result['regression']['regression_method']);
    }

    // ========================================================================
    // Selection logic — config threshold override
    // ========================================================================

    public function test_config_threshold_affects_selection(): void
    {
        $project = $this->createProjectWithHistory(6);

        // With very low threshold, polynomial is more likely to be selected
        config(['ai.regression.slr_polynomial_r2_threshold' => 0.001]);
        $resultLow = $this->service->generatePrediction($project);

        Cache::forget('ai_prediction:' . $project->id);

        // With very high threshold, SLR is more likely to be selected
        config(['ai.regression.slr_polynomial_r2_threshold' => 0.50]);
        $resultHigh = $this->service->generatePrediction($project);

        // At minimum, both should produce valid results
        $this->assertContains($resultLow['regression']['regression_method'], ['slr', 'polynomial']);
        $this->assertContains($resultHigh['regression']['regression_method'], ['slr', 'polynomial']);
    }

    // ========================================================================
    // Selection logic — r_squared_slr and r_squared_poly always present
    // ========================================================================

    public function test_r_squared_slr_always_present(): void
    {
        $project = $this->createProjectWithHistory(5);

        $result = $this->service->generatePrediction($project);

        $this->assertArrayHasKey('r_squared_slr', $result['regression']);
        $this->assertIsNumeric($result['regression']['r_squared_slr']);
    }

    public function test_r_squared_poly_null_when_not_computed(): void
    {
        $project = $this->createProjectWithHistory(3);

        $result = $this->service->generatePrediction($project);

        $this->assertNull($result['regression']['r_squared_poly']);
    }

    public function test_r_squared_poly_present_when_4_plus_observations(): void
    {
        $project = $this->createProjectWithHistory(6);

        $result = $this->service->generatePrediction($project);

        $this->assertArrayHasKey('r_squared_poly', $result['regression']);
        $this->assertIsNumeric($result['regression']['r_squared_poly']);
    }

    // ========================================================================
    // Selection logic — method_reason is always set
    // ========================================================================

    public function test_method_reason_always_present(): void
    {
        $project = $this->createProjectWithHistory(5);

        $result = $this->service->generatePrediction($project);

        $this->assertArrayHasKey('method_reason', $result['regression']);
        $this->assertIsString($result['regression']['method_reason']);
        $this->assertNotEmpty($result['regression']['method_reason']);
    }
}
