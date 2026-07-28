<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\ProjectAssessmentService;
use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 * ProjectAssessmentServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates that ProjectAssessmentService correctly interprets variance
 * values into schedule status and risk level classifications.
 *
 * Thresholds
 * ----------
 *   Variance ≥ 0    → On Track,   Low
 *   Variance ≥ -10  → Behind,     Moderate
 *   Variance ≥ -25  → Behind,     High
 *   Variance < -25  → Behind,     Critical
 *
 * Run With
 * --------
 *   php artisan test --filter=ProjectAssessmentServiceTest
 */
class ProjectAssessmentServiceTest extends TestCase
{
    private ProjectAssessmentService $assessmentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assessmentService = new ProjectAssessmentService();
    }

    /**
     * ------------------------------------------------------------------------
     * Build a minimal comparison record
     * ------------------------------------------------------------------------
     */
    private function makeRecord(
        float $variance,
        int $monthIndex = 1,
        float $planned = 50.0,
        float $predicted = 50.0
    ): array {
        return [
            'month_index'        => $monthIndex,
            'month_label'        => '2026-01',
            'planned_date'       => '2026-01-31',
            'planned_progress'   => $planned,
            'predicted_progress' => $predicted,
            'variance'           => $variance,
        ];
    }

    /*
    |==========================================================================
    | Basic Behaviour
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Assess returns array
     * ------------------------------------------------------------------------
     */
    public function test_assess_returns_array(): void
    {
        $comparison = [$this->makeRecord(5.0)];

        $result = $this->assessmentService->assess($comparison);

        $this->assertIsArray($result);
    }

    /**
     * ------------------------------------------------------------------------
     * Each record has status and risk_level keys
     * ------------------------------------------------------------------------
     */
    public function test_assess_record_has_status_and_risk(): void
    {
        $comparison = [$this->makeRecord(5.0)];

        $result = $this->assessmentService->assess($comparison);

        $this->assertArrayHasKey('status', $result[0]);
        $this->assertArrayHasKey('risk_level', $result[0]);
    }

    /**
     * ------------------------------------------------------------------------
     * Preserves original comparison fields
     * ------------------------------------------------------------------------
     */
    public function test_assess_preserves_original_fields(): void
    {
        $comparison = [$this->makeRecord(5.0, monthIndex: 7, planned: 40.0, predicted: 45.0)];

        $result = $this->assessmentService->assess($comparison);

        $this->assertEquals(7, $result[0]['month_index']);
        $this->assertEquals(40.0, $result[0]['planned_progress']);
        $this->assertEquals(45.0, $result[0]['predicted_progress']);
        $this->assertEquals(5.0, $result[0]['variance']);
    }

    /**
     * ------------------------------------------------------------------------
     * Result has same record count as input
     * ------------------------------------------------------------------------
     */
    public function test_assess_preserves_record_count(): void
    {
        $comparison = [
            $this->makeRecord(5.0),
            $this->makeRecord(-5.0),
            $this->makeRecord(-15.0),
        ];

        $result = $this->assessmentService->assess($comparison);

        $this->assertCount(3, $result);
    }

    /*
    |==========================================================================
    | Schedule Status
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Positive variance → On Track
     * ------------------------------------------------------------------------
     */
    public function test_assess_positive_variance_on_track(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(15.0)]
        );

        $this->assertEquals('On Track', $result[0]['status']);
    }

    /**
     * ------------------------------------------------------------------------
     * Zero variance → On Track
     * ------------------------------------------------------------------------
     */
    public function test_assess_zero_variance_on_track(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(0.0)]
        );

        $this->assertEquals('On Track', $result[0]['status']);
    }

    /**
     * ------------------------------------------------------------------------
     * Negative variance → Behind Schedule
     * ------------------------------------------------------------------------
     */
    public function test_assess_negative_variance_behind(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-5.0)]
        );

        $this->assertEquals('Behind Schedule', $result[0]['status']);
    }

    /*
    |==========================================================================
    | Risk Level Classification
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Variance ≥ 0 → Low risk
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_low_when_on_track(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(10.0)]
        );

        $this->assertEquals('Low', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = 0 → Low risk (boundary)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_low_at_zero_boundary(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(0.0)]
        );

        $this->assertEquals('Low', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = -5 → Moderate risk (between 0 and -10)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_moderate(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-5.0)]
        );

        $this->assertEquals('Behind Schedule', $result[0]['status']);
        $this->assertEquals('Moderate', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = -10 → Moderate risk (boundary: >= -10)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_moderate_at_minus_ten_boundary(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-10.0)]
        );

        $this->assertEquals('Moderate', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = -9.99 → Moderate risk (just above -10)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_moderate_just_above_minus_ten(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-9.99)]
        );

        $this->assertEquals('Moderate', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = -15 → High risk (between -10 and -25)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_high(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-15.0)]
        );

        $this->assertEquals('Behind Schedule', $result[0]['status']);
        $this->assertEquals('High', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = -25 → High risk (boundary: >= -25)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_high_at_minus_twenty_five_boundary(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-25.0)]
        );

        $this->assertEquals('High', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = -24.99 → High risk (just above -25)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_high_just_above_minus_twenty_five(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-24.99)]
        );

        $this->assertEquals('High', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = -30 → Critical risk (< -25)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_critical(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-30.0)]
        );

        $this->assertEquals('Behind Schedule', $result[0]['status']);
        $this->assertEquals('Critical', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = -25.01 → Critical risk (just below -25)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_critical_just_below_minus_twenty_five(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-25.01)]
        );

        $this->assertEquals('Critical', $result[0]['risk_level']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance = -100 → Critical risk (extreme)
     * ------------------------------------------------------------------------
     */
    public function test_assess_risk_critical_extreme(): void
    {
        $result = $this->assessmentService->assess(
            [$this->makeRecord(-100.0)]
        );

        $this->assertEquals('Critical', $result[0]['risk_level']);
    }

    /*
    |==========================================================================
    | Multiple Records
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Multiple records each assessed independently
     * ------------------------------------------------------------------------
     */
    public function test_assess_multiple_records(): void
    {
        $comparison = [
            $this->makeRecord(10.0, monthIndex: 1),   // On Track,    Low
            $this->makeRecord(-5.0, monthIndex: 2),   // Behind,      Moderate
            $this->makeRecord(-15.0, monthIndex: 3),  // Behind,      High
            $this->makeRecord(-30.0, monthIndex: 4),  // Behind,      Critical
        ];

        $result = $this->assessmentService->assess($comparison);

        $this->assertCount(4, $result);

        // Record 1
        $this->assertEquals('On Track', $result[0]['status']);
        $this->assertEquals('Low', $result[0]['risk_level']);

        // Record 2
        $this->assertEquals('Behind Schedule', $result[1]['status']);
        $this->assertEquals('Moderate', $result[1]['risk_level']);

        // Record 3
        $this->assertEquals('Behind Schedule', $result[2]['status']);
        $this->assertEquals('High', $result[2]['risk_level']);

        // Record 4
        $this->assertEquals('Behind Schedule', $result[3]['status']);
        $this->assertEquals('Critical', $result[3]['risk_level']);
    }

    /*
    |==========================================================================
    | Edge Cases
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Empty comparison returns empty array
     * ------------------------------------------------------------------------
     */
    public function test_assess_empty_comparison(): void
    {
        $result = $this->assessmentService->assess([]);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }
}
