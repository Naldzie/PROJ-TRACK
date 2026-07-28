<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\CompletionComparisonService;
use App\Services\AI\CompletionEstimationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CompletionComparisonServiceTest extends TestCase
{
    private CompletionComparisonService $service;
    private CompletionEstimationService $estimationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimationService = new CompletionEstimationService();
        $this->service = new CompletionComparisonService($this->estimationService);
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
        int $obs = 8
    ): array {
        return [
            'r_squared' => $rSquared,
            'observation_count' => $obs,
            'regression_method' => 'slr',
            'equation' => 'y = 5.0x + 2.0',
            'slope' => 5.0,
            'intercept' => 2.0,
        ];
    }

    public function test_estimate_all_returns_methods_and_comparison_keys(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = $this->makeAnalysis(0.95, 8);
        $plannedEndDate = Carbon::now()->addMonths(25);

        $result = $this->service->estimateAll(
            $plannedEndDate,
            $analysis,
            6,
            25.0,
            $schedules
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('methods', $result);
        $this->assertArrayHasKey('comparison', $result);
        $this->assertArrayHasKey('polynomial', $result['methods']);
        $this->assertArrayHasKey('schedule_aware', $result['methods']);
        $this->assertArrayHasKey('earned_schedule', $result['methods']);
    }

    public function test_estimate_all_polynomial_method_returns_completion_data(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = array_merge($this->makeAnalysis(0.95, 8), [
            'regression_method' => 'polynomial',
            'a' => 0.000105,
            'b' => 0.0042,
            'c' => 4.1667,
            'd' => -2.5,
        ]);
        $plannedEndDate = Carbon::now()->addMonths(25);

        $result = $this->service->estimateAll(
            $plannedEndDate,
            $analysis,
            6,
            25.0,
            $schedules
        );

        $poly = $result['methods']['polynomial'];
        $this->assertNotNull($poly);
        $this->assertArrayHasKey('estimated_completion_index', $poly);
        $this->assertArrayHasKey('estimated_completion_date', $poly);
        $this->assertArrayHasKey('predicted_delay_days', $poly);
        $this->assertArrayHasKey('completion_confidence', $poly);
        $this->assertArrayHasKey('label', $poly);
        $this->assertArrayHasKey('label_short', $poly);
    }

    public function test_estimate_all_schedule_aware_method_returns_completion_data(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = $this->makeAnalysis(0.95, 8);
        $plannedEndDate = Carbon::now()->addMonths(25);

        $result = $this->service->estimateAll(
            $plannedEndDate,
            $analysis,
            6,
            25.0,
            $schedules
        );

        $sa = $result['methods']['schedule_aware'];
        $this->assertNotNull($sa);
        $this->assertArrayHasKey('estimated_completion_index', $sa);
        $this->assertArrayHasKey('estimated_completion_date', $sa);
        $this->assertArrayHasKey('predicted_delay_days', $sa);
        $this->assertArrayHasKey('completion_confidence', $sa);
    }

    public function test_estimate_all_earned_schedule_method_returns_completion_data(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = $this->makeAnalysis(0.95, 8);
        $plannedEndDate = Carbon::now()->addMonths(25);

        $result = $this->service->estimateAll(
            $plannedEndDate,
            $analysis,
            6,
            25.0,
            $schedules
        );

        $es = $result['methods']['earned_schedule'];
        $this->assertNotNull($es);
        $this->assertArrayHasKey('estimated_completion_index', $es);
        $this->assertArrayHasKey('estimated_completion_date', $es);
        $this->assertArrayHasKey('predicted_delay_days', $es);
        $this->assertArrayHasKey('completion_confidence', $es);
    }

    public function test_comparison_summary_with_all_three_methods_valid(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = $this->makeAnalysis(0.95, 8);
        $plannedEndDate = Carbon::now()->addMonths(25);

        $result = $this->service->estimateAll(
            $plannedEndDate,
            $analysis,
            6,
            25.0,
            $schedules
        );

        $comparison = $result['comparison'];
        $this->assertArrayHasKey('earliest_date', $comparison);
        $this->assertArrayHasKey('latest_date', $comparison);
        $this->assertArrayHasKey('spread_days', $comparison);
        $this->assertArrayHasKey('agreement', $comparison);
        $this->assertArrayHasKey('valid_count', $comparison);
        $this->assertGreaterThanOrEqual(2, $comparison['valid_count']);
        $this->assertNotNull($comparison['earliest_date']);
        $this->assertNotNull($comparison['latest_date']);
    }

    public function test_comparison_returns_high_agreement_when_spread_is_small(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = $this->makeAnalysis(0.98, 10);
        $plannedEndDate = Carbon::now()->addMonths(25);

        $result = $this->service->estimateAll(
            $plannedEndDate,
            $analysis,
            6,
            25.0,
            $schedules
        );

        $comparison = $result['comparison'];
        $this->assertEquals('High', $comparison['agreement']);
    }

    public function test_returns_null_methods_when_estimation_not_possible(): void
    {
        $schedules = new Collection();
        $analysis = $this->makeAnalysis(0.95, 8);
        $plannedEndDate = Carbon::now()->addMonths(25);

        $result = $this->service->estimateAll(
            $plannedEndDate,
            $analysis,
            6,
            25.0,
            $schedules
        );

        $this->assertNull($result['methods']['polynomial']);
        $this->assertNull($result['methods']['schedule_aware']);
        $this->assertNull($result['methods']['earned_schedule']);
        $this->assertEquals(0, $result['comparison']['valid_count']);
        $this->assertEquals('N/A', $result['comparison']['agreement']);
    }

    public function test_labels_are_present_on_all_methods(): void
    {
        $plannedProgress = [];
        for ($i = 1; $i <= 24; $i++) {
            $plannedProgress[] = round(100 * $i / 24, 1);
        }
        $schedules = $this->makeSchedulesWithPlanned(24, $plannedProgress);
        $analysis = array_merge($this->makeAnalysis(0.95, 8), [
            'regression_method' => 'polynomial',
            'a' => 0.000105,
            'b' => 0.0042,
            'c' => 4.1667,
            'd' => -2.5,
        ]);
        $plannedEndDate = Carbon::now()->addMonths(25);

        $result = $this->service->estimateAll(
            $plannedEndDate,
            $analysis,
            6,
            25.0,
            $schedules
        );

        $this->assertEquals('Polynomial (Trend-Based)', $result['methods']['polynomial']['label']);
        $this->assertEquals('Polynomial', $result['methods']['polynomial']['label_short']);
        $this->assertEquals('Schedule-Aware (Conservative)', $result['methods']['schedule_aware']['label']);
        $this->assertEquals('Schedule-Aware', $result['methods']['schedule_aware']['label_short']);
        $this->assertEquals('Earned Schedule EAC_time', $result['methods']['earned_schedule']['label']);
        $this->assertEquals('EAC_time', $result['methods']['earned_schedule']['label_short']);
    }
}
