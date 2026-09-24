<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\ProgressPaceService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * ProgressPaceService — progress pace in points gained per month (phase-43,
 * textfiles/progress_pace_fix_plan.md). The first case is live project #142,
 * whose card used to read "4%/week" / "26%/week" (averages of cumulative %).
 *
 * Run: php artisan test --filter=ProgressPaceServiceTest
 */
class ProgressPaceServiceTest extends TestCase
{
    /** Build history rows: ['2026-04' => 2.0, …] */
    private function history(array $points): \Illuminate\Support\Collection
    {
        return collect($points)->map(fn ($p, $m) => (object) [
            'month_label' => $m, 'actual_progress_percent' => $p,
        ])->values();
    }

    private function compare(array $points, float $current, ?string $end, string $today = '2026-09-22'): array
    {
        return (new ProgressPaceService())->compare(
            $this->history($points), $current, $end ? Carbon::parse($end) : null, Carbon::parse($today)
        );
    }

    public function test_project_142_reads_real_monthly_pace_not_cumulative_averages(): void
    {
        $r = $this->compare(
            ['2026-04' => 2, '2026-05' => 3, '2026-06' => 7, '2026-07' => 15, '2026-08' => 25, '2026-09' => 38],
            38, '2027-03-31'
        );

        $this->assertTrue($r['has_pace']);
        $this->assertSame(2.5, $r['early_pace'], '2 → 7 over Apr–Jun');
        $this->assertSame(11.5, $r['recent_pace'], '15 → 38 over Jul–Sep');
        $this->assertSame('Apr–Jun 2026', $r['early_window']);
        $this->assertSame('Jul–Sep 2026', $r['recent_window']);
        $this->assertSame('speeding_up', $r['direction']);
        // 62 points left over 190 days (6.24 months) = 9.9 / month; recent 11.5 is enough
        $this->assertSame(9.9, $r['required_pace']);
        $this->assertSame('on_pace', $r['finish_status']);
    }

    public function test_slowing_down_is_detected(): void
    {
        $r = $this->compare(['2026-01' => 10, '2026-02' => 20, '2026-03' => 30, '2026-04' => 32, '2026-05' => 34], 34, '2026-12-31');

        $this->assertSame(10.0, $r['early_pace']);
        $this->assertSame(2.0, $r['recent_pace']);
        $this->assertSame('slowing_down', $r['direction']);
        $this->assertSame('behind_pace', $r['finish_status']);
    }

    public function test_a_tiny_change_is_steady_not_slowing(): void
    {
        // 0.8 → 0.4 points/month halves, but is under the 1-point minimum change
        $r = $this->compare(['2026-01' => 1.0, '2026-02' => 1.8, '2026-03' => 2.6, '2026-04' => 3.0, '2026-05' => 3.4], 3.4, null);

        $this->assertSame('steady', $r['direction']);
        $this->assertSame('no_end_date', $r['finish_status']);
        $this->assertNull($r['required_pace']);
    }

    public function test_a_skipped_month_counts_as_a_longer_gap(): void
    {
        // Jan → Mar is 2 months: +10 over 2 months = 5 / month, not 10
        $r = $this->compare(['2026-01' => 0, '2026-03' => 10, '2026-04' => 15], 15, null);

        $this->assertSame(5.0, $r['early_pace']);
        $this->assertSame(5.0, $r['recent_pace']);
        $this->assertSame('steady', $r['direction']);
    }

    public function test_the_middle_gap_is_left_out_when_the_count_is_odd(): void
    {
        // gaps: +1, +50 (middle), +1 → early 1, recent 1
        $r = $this->compare(['2026-01' => 0, '2026-02' => 1, '2026-03' => 51, '2026-04' => 52], 52, null);

        $this->assertSame(1.0, $r['early_pace']);
        $this->assertSame(1.0, $r['recent_pace']);
    }

    public function test_fewer_than_three_inspections_has_no_pace_but_still_the_required_pace(): void
    {
        $r = $this->compare(['2026-08' => 5, '2026-09' => 10], 10, '2027-09-22');

        $this->assertFalse($r['has_pace']);
        $this->assertSame(2, $r['inspections']);
        $this->assertNull($r['early_pace']);
        $this->assertSame('not_enough_data', $r['direction']);
        $this->assertSame(7.5, $r['required_pace'], '90 points over 12 months');
        $this->assertSame('unknown', $r['finish_status']);
    }

    public function test_two_records_in_one_month_count_once(): void
    {
        $rows = collect([
            (object) ['month_label' => '2026-01', 'actual_progress_percent' => 5],
            (object) ['month_label' => '2026-02', 'actual_progress_percent' => 8],
            (object) ['month_label' => '2026-02', 'actual_progress_percent' => 10], // corrected later
            (object) ['month_label' => '2026-03', 'actual_progress_percent' => 15],
        ]);

        $r = (new ProgressPaceService())->compare($rows, 15, null, Carbon::parse('2026-03-20'));

        $this->assertSame(3, $r['inspections']);
        $this->assertSame(5.0, $r['early_pace']);
        $this->assertSame(5.0, $r['recent_pace']);
    }

    public function test_past_deadline_and_complete(): void
    {
        $past = $this->compare(['2026-01' => 10, '2026-02' => 20, '2026-03' => 30], 30, '2026-06-30');
        $this->assertSame('past_deadline', $past['finish_status']);
        $this->assertNull($past['required_pace']);

        $done = $this->compare(['2026-01' => 60, '2026-02' => 80, '2026-03' => 100], 100, '2026-12-31');
        $this->assertSame('complete', $done['finish_status']);
    }

    public function test_windows_across_a_year_name_both_years(): void
    {
        $r = $this->compare(['2025-11' => 0, '2025-12' => 5, '2026-01' => 10, '2026-02' => 15, '2026-03' => 20], 20, null);

        $this->assertSame('Nov 2025–Jan 2026', $r['early_window']);
        $this->assertSame('Jan–Mar 2026', $r['recent_window']);
    }
}
