<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\BaselineComparisonService;
use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 * BaselineComparisonServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates that BaselineComparisonService correctly computes forecast
 * variance by comparing predicted vs planned progress.
 *
 * Key Behaviour
 * -------------
 * • Computes variance = predicted_progress - planned_progress
 * • Preserves all existing forecast record fields
 * • Returns empty array for empty input
 *
 * Run With
 * --------
 *   php artisan test --filter=BaselineComparisonServiceTest
 */
class BaselineComparisonServiceTest extends TestCase
{
    private BaselineComparisonService $comparisonService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comparisonService = new BaselineComparisonService();
    }

    /**
     * ------------------------------------------------------------------------
     * Build a minimal forecast record
     * ------------------------------------------------------------------------
     */
    private function makeRecord(
        float $planned,
        float $predicted,
        int $monthIndex = 1,
        string $monthLabel = '2026-01'
    ): array {
        return [
            'month_index'        => $monthIndex,
            'month_label'        => $monthLabel,
            'planned_date'       => '2026-01-31',
            'planned_progress'   => $planned,
            'predicted_progress' => $predicted,
        ];
    }

    /*
    |==========================================================================
    | Basic Behaviour
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Compare returns array
     * ------------------------------------------------------------------------
     */
    public function test_compare_returns_array(): void
    {
        $forecast = [$this->makeRecord(50.0, 55.0)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertIsArray($result);
    }

    /**
     * ------------------------------------------------------------------------
     * Each result record has variance key
     * ------------------------------------------------------------------------
     */
    public function test_compare_record_has_variance_key(): void
    {
        $forecast = [$this->makeRecord(50.0, 55.0)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertArrayHasKey('variance', $result[0]);
    }

    /**
     * ------------------------------------------------------------------------
     * Result has same number of records as input
     * ------------------------------------------------------------------------
     */
    public function test_compare_preserves_record_count(): void
    {
        $forecast = [
            $this->makeRecord(50.0, 55.0, monthIndex: 1),
            $this->makeRecord(60.0, 58.0, monthIndex: 2),
            $this->makeRecord(70.0, 72.0, monthIndex: 3),
        ];

        $result = $this->comparisonService->compare($forecast);

        $this->assertCount(3, $result);
    }

    /**
     * ------------------------------------------------------------------------
     * Preserves all original forecast fields
     * ------------------------------------------------------------------------
     */
    public function test_compare_preserves_original_fields(): void
    {
        $record = $this->makeRecord(50.0, 55.0, monthIndex: 7, monthLabel: '2026-07');

        $result = $this->comparisonService->compare([$record]);

        $this->assertEquals(7, $result[0]['month_index']);
        $this->assertEquals('2026-07', $result[0]['month_label']);
        $this->assertEquals('2026-01-31', $result[0]['planned_date']);
        $this->assertEquals(50.0, $result[0]['planned_progress']);
        $this->assertEquals(55.0, $result[0]['predicted_progress']);
    }

    /*
    |==========================================================================
    | Variance Calculation
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Positive variance — ahead of schedule
     * ------------------------------------------------------------------------
     *
     * predicted=55, planned=50 → variance=5
     */
    public function test_compare_positive_variance(): void
    {
        $forecast = [$this->makeRecord(50.0, 55.0)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertEquals(5.0, $result[0]['variance']);
    }

    /**
     * ------------------------------------------------------------------------
     * Negative variance — behind schedule
     * ------------------------------------------------------------------------
     *
     * predicted=45, planned=50 → variance=-5
     */
    public function test_compare_negative_variance(): void
    {
        $forecast = [$this->makeRecord(50.0, 45.0)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertEquals(-5.0, $result[0]['variance']);
    }

    /**
     * ------------------------------------------------------------------------
     * Zero variance — on schedule
     * ------------------------------------------------------------------------
     *
     * predicted=50, planned=50 → variance=0
     */
    public function test_compare_zero_variance(): void
    {
        $forecast = [$this->makeRecord(50.0, 50.0)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertEquals(0.0, $result[0]['variance']);
    }

    /**
     * ------------------------------------------------------------------------
     * Large positive variance
     * ------------------------------------------------------------------------
     *
     * predicted=95, planned=50 → variance=45
     */
    public function test_compare_large_positive_variance(): void
    {
        $forecast = [$this->makeRecord(50.0, 95.0)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertEquals(45.0, $result[0]['variance']);
    }

    /**
     * ------------------------------------------------------------------------
     * Large negative variance
     * ------------------------------------------------------------------------
     *
     * predicted=10, planned=80 → variance=-70
     */
    public function test_compare_large_negative_variance(): void
    {
        $forecast = [$this->makeRecord(80.0, 10.0)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertEquals(-70.0, $result[0]['variance']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance is rounded to 2 decimal places
     * ------------------------------------------------------------------------
     *
     * predicted=55.1234, planned=50 → variance=5.12
     */
    public function test_compare_variance_rounded_to_two_decimals(): void
    {
        $forecast = [$this->makeRecord(50.0, 55.1234)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertEquals(5.12, $result[0]['variance']);
    }

    /**
     * ------------------------------------------------------------------------
     * Variance rounding — third decimal rounds up
     * ------------------------------------------------------------------------
     *
     * predicted=55.125, planned=50 → variance=5.13
     */
    public function test_compare_variance_rounding_half_up(): void
    {
        $forecast = [$this->makeRecord(50.0, 55.125)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertEquals(5.13, $result[0]['variance']);
    }

    /**
     * ------------------------------------------------------------------------
     * Multiple records each have correct variance
     * ------------------------------------------------------------------------
     */
    public function test_compare_multiple_records(): void
    {
        $forecast = [
            $this->makeRecord(40.0, 42.0, monthIndex: 1),   // variance=2
            $this->makeRecord(50.0, 47.0, monthIndex: 2),   // variance=-3
            $this->makeRecord(60.0, 60.0, monthIndex: 3),   // variance=0
            $this->makeRecord(70.0, 80.0, monthIndex: 4),   // variance=10
        ];

        $result = $this->comparisonService->compare($forecast);

        $this->assertCount(4, $result);
        $this->assertEquals(2.0, $result[0]['variance']);
        $this->assertEquals(-3.0, $result[1]['variance']);
        $this->assertEquals(0.0, $result[2]['variance']);
        $this->assertEquals(10.0, $result[3]['variance']);
    }

    /*
    |==========================================================================
    | Edge Cases
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Empty forecast returns empty array
     * ------------------------------------------------------------------------
     */
    public function test_compare_empty_forecast(): void
    {
        $result = $this->comparisonService->compare([]);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * ------------------------------------------------------------------------
     * Single record with decimal values
     * ------------------------------------------------------------------------
     */
    public function test_compare_single_record_decimal(): void
    {
        $forecast = [$this->makeRecord(33.33, 37.77)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertEquals(4.44, $result[0]['variance']);
    }

    /**
     * ------------------------------------------------------------------------
     * Both progress values at boundaries
     * ------------------------------------------------------------------------
     *
     * predicted=0, planned=100 → variance=-100
     */
    public function test_compare_boundary_values(): void
    {
        $forecast = [$this->makeRecord(100.0, 0.0)];

        $result = $this->comparisonService->compare($forecast);

        $this->assertEquals(-100.0, $result[0]['variance']);
    }
}
