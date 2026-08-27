<?php

namespace Tests\Unit\Services;

use App\Services\ProjectStatusService;
use Tests\TestCase;

/**
 * Tests ProjectStatusService::getRiskLevel() — config-driven risk classification.
 */
class ProjectStatusServiceTest extends TestCase
{
    private ProjectStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectStatusService();
    }

    // ========================================================================
    // getRiskLevel — boundary values
    // ========================================================================

    public function test_ahead_of_schedule(): void
    {
        $result = $this->service->getRiskLevel(-6.0);
        $this->assertSame('ahead', $result['level']);
        $this->assertSame('Ahead of Schedule', $result['label']);
        $this->assertSame('green', $result['color']);
    }

    public function test_on_track_zero_variance(): void
    {
        $result = $this->service->getRiskLevel(0.0);
        $this->assertSame('on_track', $result['level']);
        $this->assertSame('On Track', $result['label']);
        $this->assertSame('green', $result['color']);
    }

    public function test_on_track_positive_variance(): void
    {
        $result = $this->service->getRiskLevel(3.0);
        $this->assertSame('on_track', $result['level']);
    }

    public function test_on_track_boundary(): void
    {
        $result = $this->service->getRiskLevel(5.0);
        $this->assertSame('on_track', $result['level']);
    }

    public function test_low_risk(): void
    {
        $result = $this->service->getRiskLevel(7.5);
        $this->assertSame('low_risk', $result['level']);
        $this->assertSame('Low Risk', $result['label']);
        $this->assertSame('yellow', $result['color']);
    }

    public function test_medium_risk(): void
    {
        $result = $this->service->getRiskLevel(12.0);
        $this->assertSame('medium_risk', $result['level']);
        $this->assertSame('Medium Risk', $result['label']);
        $this->assertSame('orange', $result['color']);
    }

    public function test_high_risk(): void
    {
        $result = $this->service->getRiskLevel(20.0);
        $this->assertSame('high_risk', $result['level']);
        $this->assertSame('High Risk', $result['label']);
        $this->assertSame('red', $result['color']);
    }

    public function test_high_risk_boundary(): void
    {
        $result = $this->service->getRiskLevel(15.0);
        $this->assertSame('high_risk', $result['level']);
    }

    // ========================================================================
    // getRiskLevel — config override
    // ========================================================================

    public function test_config_override_changes_boundaries(): void
    {
        // Default: 5.0 is on_track
        $result = $this->service->getRiskLevel(5.0);
        $this->assertSame('on_track', $result['level']);

        // Override: on_track threshold to 3
        config(['ai.risk.gap.on_track' => 3]);
        $result = $this->service->getRiskLevel(5.0);
        $this->assertSame('low_risk', $result['level']);
    }

    public function test_config_override_ahead_threshold(): void
    {
        // Default: -5 is ahead (because -5 <= -5)
        $result = $this->service->getRiskLevel(-5.0);
        $this->assertSame('ahead', $result['level']);

        // Override: ahead threshold to -3
        config(['ai.risk.gap.ahead' => -3]);
        // -5 <= -3 is true, so it's still ahead
        $result = $this->service->getRiskLevel(-5.0);
        $this->assertSame('ahead', $result['level']);

        // -2 > -3, so it should be on_track
        $result = $this->service->getRiskLevel(-2.0);
        $this->assertSame('on_track', $result['level']);
    }

    public function test_config_override_medium_threshold(): void
    {
        // Default: 15 is high_risk
        $result = $this->service->getRiskLevel(15.0);
        $this->assertSame('high_risk', $result['level']);

        // Override: medium threshold to 20
        config(['ai.risk.gap.medium' => 20]);
        $result = $this->service->getRiskLevel(15.0);
        $this->assertSame('medium_risk', $result['level']);
    }

    // ========================================================================
    // getRiskLevel — all return correct structure
    // ========================================================================

    public function test_all_levels_return_required_keys(): void
    {
        $values = [-10, 0, 5, 7.5, 12, 20];

        foreach ($values as $value) {
            $result = $this->service->getRiskLevel($value);
            $this->assertArrayHasKey('level', $result);
            $this->assertArrayHasKey('label', $result);
            $this->assertArrayHasKey('color', $result);
        }
    }

    // ========================================================================
    // Note: getRiskLevel(behindPercent) uses positive = behind schedule
    // ========================================================================

    public function test_positive_percent_means_behind_schedule(): void
    {
        // 10% behind → should be at least low_risk
        $result = $this->service->getRiskLevel(10.0);
        $this->assertContains($result['level'], ['low_risk', 'medium_risk', 'high_risk']);
    }
}
