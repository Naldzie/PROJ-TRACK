<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * The two pace rules (phase-43, textfiles/progress_pace_fix_plan.md):
 *   · monitoring — pace slowing down (the old "recent < early" rule compared
 *     averages of CUMULATIVE progress, which can never fall, so it never fired);
 *   · corrective — the recent pace is clearly below the pace needed to finish
 *     by the planned end date.
 *
 * Run: php artisan test --filter=RecommendationPaceRuleTest
 */
class RecommendationPaceRuleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    /** Minimal prediction that reaches the rule assessors (≥ 2 observations). */
    private function prediction(float $progress, int $observations): array
    {
        return [
            'current_state' => ['progress' => $progress, 'month_index' => $observations],
            'assessment'    => [['risk_level' => 'Low', 'trend' => 'STABLE', 'progress' => $progress]],
            'regression'    => ['observation_count' => $observations, 'fit_quality' => 'Good'],
        ];
    }

    private function recs(array $progress, string $plannedEnd): array
    {
        $project = $this->createProjectWithHistory(
            months: count($progress),
            actualProgress: $progress,
            overrides: ['status' => 'Ongoing', 'planned_end_date' => $plannedEnd]
        );

        return (new RecommendationEngine())->generateRuleBased($project, $this->prediction(end($progress), count($progress)));
    }

    private function withReason(array $recs, string $needle): ?array
    {
        return collect($recs)->first(fn ($r) => str_contains($r['reason'] ?? '', $needle));
    }

    public function test_slowing_pace_fires_the_monitoring_recommendation(): void
    {
        $recs = $this->recs([10, 20, 30, 32, 34], now()->addYears(2)->toDateString());

        $rec = $this->withReason($recs, 'Pace fell from');
        $this->assertNotNull($rec, 'the slowing-pace rule must fire');
        $this->assertSame('monitoring', $rec['category']);
        $this->assertStringContainsString('+10% per month', $rec['reason']);
        $this->assertStringContainsString('+2% per month', $rec['reason']);
    }

    public function test_steady_pace_does_not_fire_it(): void
    {
        $recs = $this->recs([5, 10, 15, 20, 25], now()->addYears(2)->toDateString());

        $this->assertNull($this->withReason($recs, 'Pace fell from'));
    }

    public function test_recent_pace_too_slow_to_finish_fires_the_catch_up_recommendation(): void
    {
        // 66 points left in ~6 months needs ~11 / month; the recent pace is 2
        $recs = $this->recs([10, 20, 30, 32, 34], now()->addMonths(6)->toDateString());

        $rec = $this->withReason($recs, 'to finish by the planned end date');
        $this->assertNotNull($rec, 'the catch-up rule must fire');
        $this->assertSame('corrective', $rec['category']);
        $this->assertSame(2, $rec['priority']);
        $this->assertStringContainsString('recent pace is +2% per month', $rec['reason']);
    }

    public function test_a_pace_that_is_enough_does_not_fire_the_catch_up_recommendation(): void
    {
        // Recent pace 10 / month; 50 points left over 2 years needs ~2 / month
        $recs = $this->recs([10, 20, 30, 40, 50], now()->addYears(2)->toDateString());

        $this->assertNull($this->withReason($recs, 'to finish by the planned end date'));
    }
}
