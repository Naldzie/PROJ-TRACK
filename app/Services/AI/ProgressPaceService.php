<?php

namespace App\Services\AI;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ============================================================================
 * ProgressPaceService
 * ============================================================================
 *
 * Purpose
 * -------
 * How FAST a project is moving: percentage points of actual progress gained
 * per month, early in its recorded history vs recently, and the pace it needs
 * from now on to finish by its planned end date.
 *
 * Replaces calculateSpeedComparison() in AIPredictionPresenter and
 * RecommendationEngine (phase-43, 2026-09-22 — textfiles/progress_pace_fix_plan.md).
 * Both copies averaged the CUMULATIVE progress of each half of the history and
 * labelled it "%/week": for 2,3,7,15,25,38 % they showed "4%/week" and
 * "26%/week" (= the averages of 2,3,7 and 15,25,38). That is not a speed, the
 * data is monthly, and because cumulative progress never falls the "recent
 * slower than early" recommendation rule could never fire.
 *
 * How pace is measured
 * --------------------
 *   · One inspection per month_label (the last record of a month wins).
 *   · Gaps between consecutive inspections; each gap's length is the number
 *     of months between their month_labels (a skipped month = a 2-month gap).
 *   · Early window = the first half of the gaps, recent window = the last
 *     half (the middle gap is left out when the count is odd, so the two
 *     windows never share a gap).
 *   · Window pace = points gained in the window ÷ months the window spans.
 *   · Needs 3+ inspections (2+ gaps); otherwise has_pace = false.
 *
 * Pure: no database access — callers pass the history.
 *
 * Called by: AIPredictionPresenter::presentNormal(),
 *            RecommendationEngine::extractFactors()
 */
class ProgressPaceService
{
    /** Minimum inspections before a pace comparison is shown. */
    public const MIN_INSPECTIONS = 3;

    /** Recent pace this far below early pace (ratio) counts as slowing down… */
    public const SLOWING_RATIO = 0.8;

    /** …or this far above it as speeding up. */
    public const SPEEDING_RATIO = 1.2;

    /** …and the change must also be at least this many points per month. */
    public const MIN_CHANGE_POINTS = 1.0;

    /** Average days in a month, for the months-remaining calculation. */
    private const DAYS_PER_MONTH = 30.44;

    /**
     * ------------------------------------------------------------------------
     * Compare early vs recent pace, and the pace needed to finish on time
     * ------------------------------------------------------------------------
     *
     * @param  Collection  $history          progress-history rows with
     *                                        month_label + actual_progress_percent
     * @param  float       $currentProgress  latest actual progress (0-100)
     * @param  Carbon|null $plannedEnd       current planned end date
     * @param  Carbon      $today            "now" (DateHelper::now() — simulator aware)
     *
     * @return array{
     *   has_pace: bool, inspections: int,
     *   early_pace: ?float, recent_pace: ?float,
     *   early_window: ?string, recent_window: ?string,
     *   direction: string,           // speeding_up|slowing_down|steady|not_enough_data
     *   required_pace: ?float,       // points/month still needed to reach 100%
     *   months_remaining: ?float,
     *   finish_status: string,       // on_pace|behind_pace|past_deadline|complete|no_end_date|unknown
     * }
     */
    public function compare(Collection $history, float $currentProgress, ?Carbon $plannedEnd, Carbon $today): array
    {
        $points = $this->monthlyPoints($history);
        $required = $this->requiredPace($currentProgress, $plannedEnd, $today);

        $result = [
            'has_pace'         => false,
            'inspections'      => count($points),
            'early_pace'       => null,
            'recent_pace'      => null,
            'early_window'     => null,
            'recent_window'    => null,
            'direction'        => 'not_enough_data',
            'required_pace'    => $required['pace'],
            'months_remaining' => $required['months'],
            'finish_status'    => $required['status'],
        ];

        if (count($points) < self::MIN_INSPECTIONS) {
            return $result;
        }

        // Gaps between consecutive inspections
        $gaps = [];
        for ($i = 1; $i < count($points); $i++) {
            $months = max(1, $this->monthsBetween($points[$i - 1]['month'], $points[$i]['month']));
            $gaps[] = [
                'from'   => $points[$i - 1]['month'],
                'to'     => $points[$i]['month'],
                'gain'   => $points[$i]['progress'] - $points[$i - 1]['progress'],
                'months' => $months,
            ];
        }

        // Equal-size windows from each end (the middle gap is dropped when odd)
        $size = intdiv(count($gaps), 2);
        $early = array_slice($gaps, 0, $size);
        $recent = array_slice($gaps, -$size);

        $earlyPace = $this->windowPace($early);
        $recentPace = $this->windowPace($recent);

        $result['has_pace'] = true;
        $result['early_pace'] = round($earlyPace, 1);
        $result['recent_pace'] = round($recentPace, 1);
        $result['early_window'] = $this->windowLabel($early);
        $result['recent_window'] = $this->windowLabel($recent);
        $result['direction'] = $this->direction($earlyPace, $recentPace);

        // With a pace known, say whether it is enough to finish on time
        if ($result['finish_status'] === 'unknown' && $required['pace'] !== null) {
            $result['finish_status'] = $recentPace >= $required['pace'] ? 'on_pace' : 'behind_pace';
        }

        return $result;
    }

    /**
     * One point per month_label (last record of the month wins), oldest first.
     *
     * @return array<int, array{month: string, progress: float}>
     */
    private function monthlyPoints(Collection $history): array
    {
        $byMonth = [];
        foreach ($history as $row) {
            $month = (string) ($row->month_label ?? '');
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                continue;
            }
            $byMonth[$month] = (float) ($row->actual_progress_percent ?? 0);
        }
        ksort($byMonth);

        $points = [];
        foreach ($byMonth as $month => $progress) {
            $points[] = ['month' => $month, 'progress' => $progress];
        }

        return $points;
    }

    /** Whole months from one Y-m label to another. */
    private function monthsBetween(string $from, string $to): int
    {
        [$fy, $fm] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));

        return ($ty - $fy) * 12 + ($tm - $fm);
    }

    /** Points gained ÷ months spanned, over a window of gaps. */
    private function windowPace(array $gaps): float
    {
        $months = array_sum(array_column($gaps, 'months'));

        return $months > 0 ? array_sum(array_column($gaps, 'gain')) / $months : 0.0;
    }

    /** "Apr–Jun 2026" / "Nov 2025–Jan 2026" for a window of gaps. */
    private function windowLabel(array $gaps): string
    {
        $from = Carbon::createFromFormat('Y-m-d', $gaps[0]['from'] . '-01');
        $to = Carbon::createFromFormat('Y-m-d', end($gaps)['to'] . '-01');

        return $from->year === $to->year
            ? $from->format('M') . '–' . $to->format('M Y')
            : $from->format('M Y') . '–' . $to->format('M Y');
    }

    /**
     * Speeding up / slowing down / steady. Both a ratio AND an absolute change
     * are required, so 0.4 → 0.2 points/month is not called "slowing down".
     */
    private function direction(float $early, float $recent): string
    {
        $change = $recent - $early;

        if ($change <= -self::MIN_CHANGE_POINTS && $recent < $early * self::SLOWING_RATIO) {
            return 'slowing_down';
        }
        if ($change >= self::MIN_CHANGE_POINTS && ($early <= 0 || $recent > $early * self::SPEEDING_RATIO)) {
            return 'speeding_up';
        }

        return 'steady';
    }

    /**
     * Points per month still needed to reach 100% by the planned end date.
     *
     * @return array{pace: ?float, months: ?float, status: string}
     *         status is final for complete / past_deadline / no_end_date;
     *         'unknown' means compare() decides on_pace vs behind_pace.
     */
    private function requiredPace(float $current, ?Carbon $plannedEnd, Carbon $today): array
    {
        if ($current >= 100) {
            return ['pace' => null, 'months' => null, 'status' => 'complete'];
        }
        if (!$plannedEnd) {
            return ['pace' => null, 'months' => null, 'status' => 'no_end_date'];
        }

        $days = $today->copy()->startOfDay()->diffInDays($plannedEnd->copy()->startOfDay(), false);
        if ($days <= 0) {
            return ['pace' => null, 'months' => 0.0, 'status' => 'past_deadline'];
        }

        $months = $days / self::DAYS_PER_MONTH;

        return [
            'pace'   => round((100 - $current) / $months, 1),
            'months' => round($months, 1),
            'status' => 'unknown',
        ];
    }
}
