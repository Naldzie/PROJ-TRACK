<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push a project's start / due / inspection dates to Google Calendars
 * in the background so project saves never wait on the Google API.
 */
class SyncProjectCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public int $projectId)
    {
    }

    public function handle(GoogleCalendarService $service): void
    {
        $project = Project::with(['contractor.user', 'baselineSchedules'])->find($this->projectId);

        if (! $project) {
            return;
        }

        try {
            $summary = $service->syncProject($project);
            Log::info('Project calendar synced', [
                'project_id' => $project->id,
                'synced' => $summary['synced'] ?? 0,
                'skipped' => $summary['skipped'] ?? 0,
                'failed' => $summary['failed'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Project calendar sync job failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
