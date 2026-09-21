<?php

namespace App\Observers;

use App\Jobs\SyncProjectCalendar;
use App\Models\ProjectBaselineSchedule;

/**
 * Keeps Google Calendars in sync when inspection months change.
 * Covers baseline create / edit / delete and S-curve imports (which write
 * through the same model), so inspection dots never go stale.
 */
class BaselineScheduleObserver
{
    public function saved(ProjectBaselineSchedule $schedule): void
    {
        if ($schedule->project_id) {
            SyncProjectCalendar::dispatch((int) $schedule->project_id)->afterCommit();
        }
    }

    public function deleted(ProjectBaselineSchedule $schedule): void
    {
        if ($schedule->project_id) {
            SyncProjectCalendar::dispatch((int) $schedule->project_id)->afterCommit();
        }
    }
}
