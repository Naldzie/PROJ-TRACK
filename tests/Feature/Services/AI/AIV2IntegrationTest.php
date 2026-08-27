<?php

namespace Tests\Feature\Services\AI;

use App\Models\Project;
use App\Services\AI\AIPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * End-to-end integration test for the AI V2 prediction pipeline.
 * Tests the full flow from project creation through prediction generation.
 */
class AIV2IntegrationTest extends TestCase
{
    use RefreshDatabase, CreatesProjectWithHistory;

    private AIPredictionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AIPredictionService::class);
    }

    // ========================================================================
    // End-to-end: create project → record progress → generate prediction
    // ========================================================================

    public function test_full_pipeline_produces_valid_output(): void
    {
        $project = $this->createProjectWithHistory(6);

        $result = $this->service->generatePrediction($project);

        // Verify all major sections are present
        $this->assertArrayHasKey('current_state', $result);
        $this->assertArrayHasKey('next_prediction', $result);
        $this->assertArrayHasKey('forecast', $result);
        $this->assertArrayHasKey('regression', $result);
        $this->assertArrayHasKey('reliability', $result);
        $this->assertArrayHasKey('statistics', $result);
    }

    public function test_pipeline_with_s_curve_data(): void
    {
        // S-curve: slow start, rapid growth, plateau
        $sCurve = [2, 5, 12, 25, 45, 70, 88, 96];
        $project = $this->createProjectWithHistory(8, $sCurve);

        $result = $this->service->generatePrediction($project);

        $this->assertArrayHasKey('regression', $result);
        $this->assertContains($result['regression']['regression_method'], ['slr', 'polynomial']);
        $this->assertGreaterThan(0, $result['next_prediction']['progress']);
    }

    public function test_pipeline_with_minimal_data(): void
    {
        $project = $this->createProjectWithHistory(2);

        $result = $this->service->generatePrediction($project);

        // With only 2 observations, should use SLR
        $this->assertSame('slr', $result['regression']['regression_method']);
    }

    // ========================================================================
    // Cache behavior integration
    // ========================================================================

    public function test_cache_integration(): void
    {
        $project = $this->createProjectWithHistory(5);

        // First call — computes and caches
        $result1 = $this->service->generatePrediction($project);
        $cacheKey = 'ai_prediction:' . $project->id;
        $this->assertTrue(Cache::has($cacheKey));

        // Second call — returns cached
        $result2 = $this->service->generatePrediction($project);
        $this->assertSame(
            $result1['regression']['regression_method'],
            $result2['regression']['regression_method']
        );

        // Invalidate — next call recomputes
        Cache::forget($cacheKey);
        $this->assertFalse(Cache::has($cacheKey));

        $result3 = $this->service->generatePrediction($project);
        $this->assertArrayHasKey('regression', $result3);
    }

    // ========================================================================
    // Regression method selection integration
    // ========================================================================

    public function test_model_selection_with_s_curve(): void
    {
        $sCurve = [2, 5, 12, 25, 45, 70, 88, 96];
        $project = $this->createProjectWithHistory(8, $sCurve);

        $result = $this->service->generatePrediction($project);

        // Both R² values should be present
        $this->assertArrayHasKey('r_squared_slr', $result['regression']);
        $this->assertArrayHasKey('r_squared_poly', $result['regression']);
        $this->assertArrayHasKey('method_reason', $result['regression']);
    }

    public function test_model_selection_with_linear_data(): void
    {
        $linear = [10, 20, 30, 40, 50, 60];
        $project = $this->createProjectWithHistory(6, $linear);

        $result = $this->service->generatePrediction($project);

        // Linear data should result in SLR selection
        $this->assertSame('slr', $result['regression']['regression_method']);
    }

    // ========================================================================
    // Prediction history integration
    // ========================================================================

    public function test_prediction_history_recorded(): void
    {
        $project = $this->createProjectWithHistory(5);

        $this->service->generatePrediction($project);

        $this->assertDatabaseHas('prediction_history', [
            'project_id' => $project->id,
        ]);
    }

    public function test_prediction_history_not_duplicated(): void
    {
        $project = $this->createProjectWithHistory(5);

        $this->service->generatePrediction($project);
        $count1 = \App\Models\PredictionHistory::where('project_id', $project->id)->count();

        Cache::forget('ai_prediction:' . $project->id);
        $this->service->generatePrediction($project);
        $count2 = \App\Models\PredictionHistory::where('project_id', $project->id)->count();

        $this->assertSame($count1, $count2);
    }

    // ========================================================================
    // Reliability integration
    // ========================================================================

    public function test_reliability_block_present(): void
    {
        $project = $this->createProjectWithHistory(5);

        $result = $this->service->generatePrediction($project);

        $this->assertArrayHasKey('reliability', $result);
        $this->assertArrayHasKey('next_prediction', $result['reliability']);
    }

    // ========================================================================
    // Statistics integration
    // ========================================================================

    public function test_statistics_include_observation_count(): void
    {
        $project = $this->createProjectWithHistory(6);

        $result = $this->service->generatePrediction($project);

        $this->assertArrayHasKey('statistics', $result);
        $this->assertSame(6, $result['statistics']['observation_count']);
    }

    // ========================================================================
    // Next prediction values
    // ========================================================================

    public function test_next_prediction_has_valid_range(): void
    {
        $project = $this->createProjectWithHistory(5);

        $result = $this->service->generatePrediction($project);

        $next = $result['next_prediction'];
        $this->assertArrayHasKey('progress', $next);
        $this->assertArrayHasKey('progress_low', $next);
        $this->assertArrayHasKey('progress_high', $next);

        // All values should be numeric and non-negative
        $this->assertIsNumeric($next['progress']);
        $this->assertIsNumeric($next['progress_low']);
        $this->assertIsNumeric($next['progress_high']);
        $this->assertGreaterThanOrEqual(0, $next['progress']);
        $this->assertGreaterThanOrEqual(0, $next['progress_low']);
        $this->assertGreaterThanOrEqual(0, $next['progress_high']);
    }
}
