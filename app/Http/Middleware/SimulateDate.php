<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;

/**
 * App-wide date simulation for the dev Date Simulator widget.
 *
 * Applies session('simulated_date') as Carbon's test-now for the duration of the
 * request, so deadline, overdue and month-boundary logic can be exercised without
 * waiting for the calendar. Non-production only.
 *
 * P3 FIX (2026-09-20) — RESTORE, DO NOT CLEAR.
 * This used to call Carbon::setTestNow() unconditionally after every request, which
 * clears test-now whether or not this middleware had set it. Carbon's test-now is
 * PROCESS-WIDE global state, and a feature test's $this->travelTo() is exactly that
 * state — so the first HTTP call in a test silently reset the clock and every request
 * after it ran at REAL time. That is what made
 * DashboardOverdueInspectionTest::count_badge_matches_ajax_filter_list fail: the
 * dashboard request computed its badge at the travelled-to date (correctly, 2), then
 * this middleware wiped the clock, and the follow-up filter request ran at real time
 * where all three fixtures were overdue.
 * Saving and restoring the previous value undoes only what this middleware did.
 */
class SimulateDate
{
    public function handle($request, Closure $next)
    {
        // Whatever the caller already had — a test's travelTo(), or nothing at all.
        $previous = Carbon::getTestNow();

        $simulated = session('simulated_date');
        if ($simulated && !app()->environment('production')) {
            Carbon::setTestNow(Carbon::parse($simulated));
        }

        $response = $next($request);

        // Put the clock back exactly as it was found. Passing null clears it, which is
        // the old behaviour for the case where nothing was set to begin with.
        Carbon::setTestNow($previous);

        return $response;
    }
}
