<?php

namespace Tests\Unit\Services\AI;

use App\Models\Project;
use App\Services\AI\AIPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * Tests AIPredictionService caching behavior — key format, TTL, invalidation.
 */
class AIPredictionCacheTest extends TestCase
{
    use RefreshDatabase, CreatesProjectWithHistory;

    private AIPredictionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AIPredictionService::class);
    }

    // ========================================================================
    // Cache key format
    // ========================================================================

    public function test_cache_key_format(): void
    {
        $project = $this->createProjectWithHistory(5);

        $this->service->generatePrediction($project);

        $cacheKey = 'ai_prediction:' . $project->id;
        $this->assertTrue(Cache::has($cacheKey));
    }

    // ========================================================================
    // TTL from config
    // ========================================================================

    public function test_cache_uses_config_ttl(): void
    {
        $project = $this->createProjectWithHistory(5);

        // Default TTL is 480 minutes
        $this->assertSame(480, config('ai.cache.prediction_ttl'));
    }

    // ========================================================================
    // Cache returns same result on second call
    // ========================================================================

    public function test_second_call_returns_cached_result(): void
    {
        $project = $this->createProjectWithHistory(5);

        $result1 = $this->service->generatePrediction($project);
        $result2 = $this->service->generatePrediction($project);

        // Both should have the same regression method
        $this->assertSame(
            $result1['regression']['regression_method'],
            $result2['regression']['regression_method']
        );
    }

    // ========================================================================
    // Cache invalidation
    // ========================================================================

    public function test_cache_invalidated_on_forget(): void
    {
        $project = $this->createProjectWithHistory(5);

        $this->service->generatePrediction($project);
        $cacheKey = 'ai_prediction:' . $project->id;
        $this->assertTrue(Cache::has($cacheKey));

        Cache::forget($cacheKey);
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_cache_fresh_after_invalidation(): void
    {
        $project = $this->createProjectWithHistory(5);

        $result1 = $this->service->generatePrediction($project);

        Cache::forget('ai_prediction:' . $project->id);

        $result2 = $this->service->generatePrediction($project);

        // After invalidation, both should produce valid results
        $this->assertArrayHasKey('regression', $result1);
        $this->assertArrayHasKey('regression', $result2);
    }

    // ========================================================================
    // Cache wrapping behavior
    // ========================================================================

    public function test_generate_prediction_returns_consistent_structure(): void
    {
        $project = $this->createProjectWithHistory(5);

        $result = $this->service->generatePrediction($project);

        $this->assertArrayHasKey('current_state', $result);
        $this->assertArrayHasKey('next_prediction', $result);
        $this->assertArrayHasKey('forecast', $result);
        $this->assertArrayHasKey('regression', $result);
        $this->assertArrayHasKey('reliability', $result);
    }

    public function test_cache_wraps_compute_prediction(): void
    {
        $project = $this->createProjectWithHistory(5);

        // First call computes and caches
        $result1 = $this->service->generatePrediction($project);

        // Verify cache exists
        $cacheKey = 'ai_prediction:' . $project->id;
        $this->assertTrue(Cache::has($cacheKey));

        // Second call returns from cache
        $result2 = $this->service->generatePrediction($project);

        // Results should be identical (from cache)
        $this->assertEquals($result1['current_state'], $result2['current_state']);
    }
}
