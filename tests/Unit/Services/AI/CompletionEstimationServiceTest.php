<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\CompletionEstimationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CompletionEstimationServiceTest extends TestCase
{
    private CompletionEstimationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CompletionEstimationService();
    }

    private function makeSchedulesWithPlanned(
        int $count,
        array $plannedProgress,
        ?Carbon $startDate = null
    ): Collection {
        $start = $startDate ?? Carbon::now()->subMonth();
        $schedules = [];

        for ($i = 0; $i < $count; $i++) {
            $schedule = new \stdClass();
            $schedule->planned_date = $start->copy()->addMonths($i);
            $schedule->planned_progress_percent = $plannedProgress[$i] ?? 100.0;
            $schedules[] = $schedule;
        }

        return new Collection($schedules);
    }

    private function makeAnalysis(
        float $rSquared = 0.95,
        int $obs = 6
    ): array {
        return [
            'r_squared' => $rSquared,
            'observation_count' => $obs,
        ];
    }

    public function test_on_schedule_project_estimates_at_planned_end(): void
    {
        $plannedProgress = [1, 3, 6, 10, 15, 22, 30, 40, 50, 56.22, 63.74, 70.89, 76.78, 82.22, 87.22, 91.85, 100];
        $schedules = $this->makeSchedulesWithPlanned(17, $plannedProgress);
        $analysis = $this->makeAnalysis(0.97, 8);

        // Month 9 = 50%, progress matches → on schedule
        $result = $this->service->estimate(
            Carbon::now()->addMonths(18),
            $analysis,
            9,
            $schedules,
            50.0
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(17.0, $result['estimated_completion_index'], 0.001);
    }

    public function test_behind_schedule_increases_estimated_index(): void
    {
        $plannedProgress = [1, 3, 6, 10, 15, 22, 30, 40, 50, 56.22, 63.74, 70.89, 76.78, 82.22, 87.22, 91.85, 100];
        $schedules = $this->makeSchedulesWithPlanned(17, $plannedProgress);
        $analysis = $this->makeAnalysis(0.97, 8);

        // Month 9 = 50%, but actual = 42% → 42% is at position 8.2 on schedule
        // Lag = 9 - 8.2 = 0.8, est = 17 + 0.8 = 17.8
        $result = $this->service->estimate(
            Carbon::now()->addMonths(18),
            $analysis,
            9,
            $schedules,
            42.0
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(17.8, $result['estimated_completion_index'], 0.01);
    }

    public function test_ahead_of_schedule_decreases_estimated_index(): void
    {
        $plannedProgress = [1, 3, 6, 10, 15, 22, 30, 40, 50, 56.22, 63.74, 70.89, 76.78, 82.22, 87.22, 91.85, 100];
        $schedules = $this->makeSchedulesWithPlanned(17, $plannedProgress);
        $analysis = $this->makeAnalysis(0.97, 8);

        // Month 9 = 50%, actual = 55% → 55% is between month 9 (50%) and 10 (56.22%)
        // Effective = 9 + (55-50)/(56.22-50) = 9 + 5/6.22 = 9.80
        // Lag = 9 - 9.80 = -0.80, est = 17 + (-0.80) = 16.20
        $result = $this->service->estimate(
            Carbon::now()->addMonths(18),
            $analysis,
            9,
            $schedules,
            55.0
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(16.20, $result['estimated_completion_index'], 0.02);
    }

    public function test_returns_null_when_already_at_one_hundred_percent(): void
    {
        $schedules = $this->makeSchedulesWithPlanned(12, [5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 75, 100]);
        $analysis = $this->makeAnalysis(0.97, 8);

        $result = $this->service->estimate(
            Carbon::now()->addMonths(12),
            $analysis,
            8,
            $schedules,
            100.0
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_r_squared_below_threshold(): void
    {
        $schedules = $this->makeSchedulesWithPlanned(12, [5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 75, 100]);
        $analysis = $this->makeAnalysis(0.30, 8);

        $result = $this->service->estimate(
            Carbon::now()->addMonths(12),
            $analysis,
            8,
            $schedules,
            45.0
        );

        $this->assertNull($result);
    }

    public function test_returns_null_with_empty_schedules(): void
    {
        $analysis = $this->makeAnalysis(0.95, 6);
        $emptySchedules = new Collection();

        $result = $this->service->estimate(
            Carbon::now()->addMonths(12),
            $analysis,
            5,
            $emptySchedules,
            30.0
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_estimated_index_le_current(): void
    {
        // 10 baselines, last planned = 90%, currentMonthIndex = 10, progress = 92%
        // Effective = count = 10 (progress >= last), lag = 10 - 10 = 0, est = 10 + 0 = 10
        // 10 <= 10 → null
        $plannedProgress = [9, 18, 27, 36, 45, 54, 63, 72, 81, 90];
        $schedules = $this->makeSchedulesWithPlanned(10, $plannedProgress);
        $analysis = $this->makeAnalysis(0.90, 6);

        $result = $this->service->estimate(
            Carbon::now()->addMonths(6),
            $analysis,
            10,
            $schedules,
            92.0
        );

        $this->assertNull($result);
    }

    public function test_delay_positive_when_behind_schedule(): void
    {
        $plannedProgress = [1, 3, 6, 10, 15, 22, 30, 40, 50, 56.22, 63.74, 70.89, 76.78, 82.22, 87.22, 91.85, 100];
        $startDate = Carbon::now()->subMonths(9);
        $schedules = $this->makeSchedulesWithPlanned(17, $plannedProgress, $startDate);

        // plannedEndDate matches the last baseline's date
        $lastBaselineDate = $schedules->last()->planned_date;
        $analysis = $this->makeAnalysis(0.95, 8);

        $result = $this->service->estimate(
            $lastBaselineDate,
            $analysis,
            9,
            $schedules,
            42.0
        );

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result['predicted_delay_days']);
    }

    public function test_delay_negative_when_ahead_of_schedule(): void
    {
        $plannedProgress = [1, 3, 6, 10, 15, 22, 30, 40, 50, 56.22, 63.74, 70.89, 76.78, 82.22, 87.22, 91.85, 100];
        $startDate = Carbon::now()->subMonths(9);
        $schedules = $this->makeSchedulesWithPlanned(17, $plannedProgress, $startDate);

        $lastBaselineDate = $schedules->last()->planned_date;
        $analysis = $this->makeAnalysis(0.98, 8);

        $result = $this->service->estimate(
            $lastBaselineDate,
            $analysis,
            9,
            $schedules,
            55.0
        );

        $this->assertNotNull($result);
        $this->assertLessThan(0, $result['predicted_delay_days']);
    }

    public function test_confidence_high(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = $this->makeAnalysis(0.95, 8);

        // Month 6 = 25%, on schedule → est = 24, obs = 8 ≥ 6, R² = 0.95 ≥ 0.85, index = 24 ≤ 1.5*24 = 36
        $result = $this->service->estimate(
            Carbon::now()->addMonths(25),
            $analysis,
            6,
            $schedules,
            25.0
        );

        $this->assertNotNull($result);
        $this->assertEquals('High', $result['completion_confidence']);
    }

    public function test_confidence_medium(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 10; $i++) {
            $plannedProgress[] = round(100 * $i / 10, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(10, $plannedProgress);
        $analysis = $this->makeAnalysis(0.80, 5);

        $result = $this->service->estimate(
            Carbon::now()->addMonths(11),
            $analysis,
            4,
            $schedules,
            40.0
        );

        $this->assertNotNull($result);
        $this->assertEquals('Medium', $result['completion_confidence']);
    }

    public function test_confidence_low_due_to_low_r_squared(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = $this->makeAnalysis(0.55, 5);

        $result = $this->service->estimate(
            Carbon::now()->addMonths(25),
            $analysis,
            6,
            $schedules,
            25.0
        );

        $this->assertNotNull($result);
        $this->assertEquals('Low', $result['completion_confidence']);
    }

    public function test_confidence_low_due_to_excessive_index(): void
    {
        $plannedProgress = [5, 10, 15, 20, 25, 30, 35, 40];
        $schedules = $this->makeSchedulesWithPlanned(8, $plannedProgress);
        $analysis = $this->makeAnalysis(0.90, 6);

        // Month 3, progress = 5% → effective = 1.0, lag = 2, est = 8 + 2 = 10
        // LOW_CONFIDENCE_INDEX_RATIO = 2.0, plannedEndIndex = 8
        // 10 > 2.0 * 8 = 16? No, 10 < 16. So it's not "excessive"
        // Need a case where estIndex > 2 * plannedEndIndex
        
        // Let me use: month 3, progress = 1% (below first baseline of 5%)
        // Effective = 1.0, lag = 3 - 1 = 2, est = 8 + 2 = 10
        // Still 10 < 16.
        
        // month 9, progress = 1% (but there are only 8 baselines)
        // Hmm, currentMonthIndex is the dataset count, not the actual month
        // If we set currentMonthIndex > count. Let me check what currentMonthIndex CAN be.
        // currentMonthIndex = count of x in the dataset = observation count?
        // No, it's the count of sequential months. If we have 9 observations,
        // currentMonthIndex could be 9 but total baselines = 8.
        
        // Let me use: month 12, progress = 5%, 8 baselines (last at 40%)
        // Effective = 1.0, lag = 12 - 1 = 11, est = 8 + 11 = 19
        // 19 > 2 * 8 = 16 → LOW!
        $result = $this->service->estimate(
            Carbon::now()->addMonths(6),
            $analysis,
            12,
            $schedules,
            5.0
        );

        $this->assertNotNull($result);
        $this->assertEquals('Low', $result['completion_confidence']);
    }

    public function test_high_confidence_requires_sufficient_observations(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 20; $i++) {
            $plannedProgress[] = round(100 * $i / 20, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(20, $plannedProgress);
        $analysis = $this->makeAnalysis(0.95, 4);

        $result = $this->service->estimate(
            Carbon::now()->addMonths(21),
            $analysis,
            5,
            $schedules,
            25.0
        );

        $this->assertNotNull($result);
        $this->assertNotEquals('High', $result['completion_confidence']);
    }

    public function test_estimates_completion_for_early_project(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = $this->makeAnalysis(0.93, 5);

        $result = $this->service->estimate(
            Carbon::now()->addMonths(25),
            $analysis,
            3,
            $schedules,
            8.0
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result['estimated_completion_date']);
    }

    public function test_progress_below_first_baseline_uses_month_one(): void
    {
        $plannedProgress = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
        $schedules = $this->makeSchedulesWithPlanned(10, $plannedProgress);
        $analysis = $this->makeAnalysis(0.90, 6);

        // Month 3, progress = 3% (below first baseline of 10%)
        // Effective = 1.0, lag = 3 - 1 = 2, est = 10 + 2 = 12
        $result = $this->service->estimate(
            Carbon::now()->addMonths(12),
            $analysis,
            3,
            $schedules,
            3.0
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(12.0, $result['estimated_completion_index'], 0.001);
    }
}
