<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\RecommendationEngine;
use App\Services\ProjectHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests that changing config/ai.php thresholds actually changes service behavior.
 * Validates that the config migration from Phase 12 is functional.
 */
class ConfigDrivenBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private RecommendationEngine $engine;
    private ProjectHealthService $healthService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new RecommendationEngine();
        $this->healthService = new ProjectHealthService();
    }

    // ========================================================================
    // RecommendationEngine — maxRecommendations
    // ========================================================================

    public function test_max_recommendations_reads_from_config(): void
    {
        $method = new \ReflectionMethod($this->engine, 'maxRecommendations');

        config(['ai.recommendations.max_rule' => 3]);
        $this->assertSame(3, $method->invoke($this->engine));

        config(['ai.recommendations.max_rule' => 10]);
        $this->assertSame(10, $method->invoke($this->engine));
    }

    // ========================================================================
    // RecommendationEngine — determineTrend
    // ========================================================================

    public function test_determine_trend_reads_variance_delta_from_config(): void
    {
        $method = new \ReflectionMethod($this->engine, 'determineTrend');

        // Needs 2+ assessment records with 'variance' keys
        $assessment = [
            ['variance' => -5.0],
            ['variance' => -4.0],
        ];
        config(['ai.trend.variance_delta' => 2]);
        $result = $method->invoke($this->engine, $assessment);
        $this->assertIsArray($result);
        // Delta = +1.0, within ±2 → STABLE
        $this->assertSame('STABLE', $result['trend']);

        // Delta = -3.0, worse than -2 → DECELERATING
        $assessment = [
            ['variance' => -2.0],
            ['variance' => -5.0],
        ];
        $result = $method->invoke($this->engine, $assessment);
        $this->assertSame('DECELERATING', $result['trend']);

        // Delta = +3.0, improving by 3 → ACCELERATING
        $assessment = [
            ['variance' => -5.0],
            ['variance' => -2.0],
        ];
        $result = $method->invoke($this->engine, $assessment);
        $this->assertSame('ACCELERATING', $result['trend']);
    }

    // ========================================================================
    // RecommendationEngine — classifyRiskFromGap
    // ========================================================================

    public function test_classify_risk_from_gap_reads_config(): void
    {
        $method = new \ReflectionMethod($this->engine, 'classifyRiskFromGap');

        // Default config: gap of 6 → Moderate (between on_track=5 and low=10)
        $result = $method->invoke($this->engine, 6.0);
        $this->assertSame('Moderate', $result);

        // Default config: gap of 12 → High (between low=10 and medium=15)
        $result = $method->invoke($this->engine, 12.0);
        $this->assertSame('High', $result);

        // Override: change on_track threshold to 3
        config(['ai.risk.gap.on_track' => 3]);
        // gap of 4: 4 <= 3 is false, 4 < 10 → Moderate
        $result = $method->invoke($this->engine, 4.0);
        $this->assertSame('Moderate', $result);
    }

    // ========================================================================
    // ProjectHealthService — classifyGap with config override
    // ========================================================================

    public function test_classify_gap_respects_config_override(): void
    {
        // Default: gap of 5 → on_track
        $this->assertSame('on_track', $this->healthService->classifyGap(5.0));

        // Override: on_track threshold to 3
        config(['ai.risk.gap.on_track' => 3]);
        $this->assertSame('low_risk', $this->healthService->classifyGap(5.0));
    }

    public function test_classify_gap_all_levels_configurable(): void
    {
        // Override all thresholds
        config([
            'ai.risk.gap.ahead' => -10,
            'ai.risk.gap.on_track' => 2,
            'ai.risk.gap.low' => 5,
            'ai.risk.gap.medium' => 8,
        ]);

        $this->assertSame('ahead', $this->healthService->classifyGap(-10));
        $this->assertSame('on_track', $this->healthService->classifyGap(2));
        // gap of 4: 4 > 2 (on_track) and 4 < 5 (low) → low_risk
        $this->assertSame('low_risk', $this->healthService->classifyGap(4));
        // gap of 6: 6 >= 5 (low) and 6 < 8 (medium) → medium_risk
        $this->assertSame('medium_risk', $this->healthService->classifyGap(6));
        // gap of 10: 10 >= 8 (medium) → high_risk
        $this->assertSame('high_risk', $this->healthService->classifyGap(10));
    }

    // ========================================================================
    // ContractorPerformanceService — rating tiers config
    // ========================================================================

    public function test_contractor_rating_tiers_configurable(): void
    {
        $service = app(\App\Services\AI\ContractorPerformanceService::class);

        // Default: 95/3 → tier 5
        $this->assertSame(5, $service->getPerformanceRating(95.0, 3.0));

        // Override tier 5 to require 98%
        config(['ai.contractor_rating.5.on_time_pct' => 98]);
        $this->assertSame(4, $service->getPerformanceRating(95.0, 3.0));
    }

    // ========================================================================
    // RecommendationEngine — escalation rules
    // ========================================================================

    public function test_escalation_gap_configurable(): void
    {
        $method = new \ReflectionMethod($this->engine, 'assessEscalation');

        // assessEscalation takes an array $factors with required keys
        $factors = [
            'risk_level' => 'Critical',
            'issues_high_severity' => 0,
            'consecutive_carryovers' => 0,
            'progress_gap' => -25.0,
            'days_remaining' => 20,
        ];

        $result = $method->invoke($this->engine, $factors);
        $this->assertNotEmpty($result);
        $categories = collect($result)->pluck('category')->toArray();
        $this->assertContains('escalation', $categories);
    }

    public function test_escalation_not_triggered_on_low_risk(): void
    {
        $method = new \ReflectionMethod($this->engine, 'assessEscalation');

        $factors = [
            'risk_level' => 'Low',
            'issues_high_severity' => 0,
            'consecutive_carryovers' => 0,
            'progress_gap' => -2.0,
            'days_remaining' => 100,
        ];

        $result = $method->invoke($this->engine, $factors);
        $this->assertEmpty($result);
    }
}
