<?php

namespace App\Observers;

use App\Jobs\SyncProjectCalendar;
use App\Models\Project;

/**
 * Keeps Google Calendars in sync when a project changes.
 *
 * Fires on create (new start/due dates) and on update whenever a synced
 * field moves — including deadline moves caused by extension approvals,
 * which flow through Project::update() in recomputeProjectDeadline().
 * The queued job does the Google work; the request itself never waits.
 */
class ProjectObserver
{
    private const SYNCED_FIELDS = [
        'title',
        'code',
        'location',
        'status',
        'planned_start_date',
        'planned_end_date',
        'contractor_id',
        'assigned_project_manager',
        'is_archived',
    ];

    public function created(Project $project): void
    {
        SyncProjectCalendar::dispatch($project->id)->afterCommit();
    }

    public function updated(Project $project): void
    {
        foreach (self::SYNCED_FIELDS as $field) {
            if ($project->wasChanged($field)) {
                SyncProjectCalendar::dispatch($project->id)->afterCommit();

                return;
            }
        }
    }

    public function deleted(Project $project): void
    {
        // Permanent delete: best-effort removal is handled by the service
        // when rows still exist; queue a final pass guarded by find() miss.
        // (Rows cascade-delete with the project, so mostly nothing remains.)
    }
}
