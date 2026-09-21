<?php

namespace App\Services;

use App\Models\ProjectProgressHistory;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * === DASHBOARD CALENDAR FEED BUILDER ===
 *
 * Replaces the hardcoded `projectsData` demo array in the calendar widget
 * with real project dates: the start date, the current due date
 * (planned_end_date — extensions keep it current), and every monthly
 * baseline inspection date.
 *
 * One flat event per dot so the widget can render start / deadline /
 * inspection markers on their true days. The modal keeps showing the
 * monthly planned-vs-actual context it already showed.
 */
class DashboardCalendarService
{
    /**
     * Build calendar events for the given projects.
     *
     * @param  Collection  $projects  Projects with contractor + baselineSchedules loaded when possible.
     * @param  string  $detailRoute  Route name prefix for the "View Full Project" link ('admin', 'staff', or 'contractor').
     */
    public static function forProjects(Collection $projects, string $detailRoute = 'admin'): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        foreach ($projects as $item) {
            if ($item instanceof \Illuminate\Database\Eloquent\Model) {
                $item->loadMissing(['contractor', 'baselineSchedules']);
            }
        }

        $ids = $projects->pluck('id')->all();
        $monthLabel = now()->format('Y-m');

        // Latest known actual per project (modal "Actual").
        $latestActual = ProjectProgressHistory::whereIn('project_id', $ids)
            ->whereNotNull('actual_progress_percent')
            ->orderBy('inspection_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('project_id')
            ->map(fn ($rows) => (float) ($rows->first()->actual_progress_percent ?? 0));

        // Actual per (project, month_label) for inspection dots.
        $actualByMonth = ProjectProgressHistory::whereIn('project_id', $ids)
            ->whereNotNull('actual_progress_percent')
            ->orderBy('inspection_date', 'desc')
            ->get()
            ->groupBy('project_id')
            ->map(fn ($rows) => $rows->groupBy('month_label')->map(
                fn ($monthRows) => (float) ($monthRows->first()->actual_progress_percent ?? 0)
            ));

        $events = [];

        foreach ($projects as $project) {
            $status = self::statusFor($project);
            $contractor = $project->contractor->company_name
                ?? $project->contractor->contractor_name
                ?? '—';
            $latest = (float) ($latestActual[$project->id] ?? 0);
            $detailUrl = self::detailUrl($detailRoute, (int) $project->id);

            $base = [
                'project_id' => (int) $project->id,
                'code' => (string) ($project->code ?? ''),
                'name' => (string) ($project->title ?? ''),
                'contractor' => (string) $contractor,
                'location' => (string) ($project->location ?? ''),
                'status' => $status,
                'project_status' => (string) ($project->status ?? ''),
                'progress' => $latest,
                'detailUrl' => $detailUrl,
                'startDate' => $project->planned_start_date ? Carbon::parse($project->planned_start_date)->toDateString() : null,
                'dueDate' => $project->planned_end_date ? Carbon::parse($project->planned_end_date)->toDateString() : null,
            ];

            if ($project->planned_start_date) {
                $events[] = array_merge($base, [
                    'id' => "p{$project->id}-start",
                    'kind' => 'start',
                    'kindLabel' => 'Project start',
                    'date' => Carbon::parse($project->planned_start_date)->toDateString(),
                    'deadline' => Carbon::parse($project->planned_start_date)->toDateString(),
                    'planned' => 0,
                    'inspectionDate' => null,
                    'monthLabel' => null,
                ]);
            }

            if ($project->planned_end_date) {
                $events[] = array_merge($base, [
                    'id' => "p{$project->id}-due",
                    'kind' => 'deadline',
                    'kindLabel' => 'Project deadline',
                    'date' => Carbon::parse($project->planned_end_date)->toDateString(),
                    'deadline' => Carbon::parse($project->planned_end_date)->toDateString(),
                    'planned' => 100,
                    'inspectionDate' => null,
                    'monthLabel' => null,
                ]);
            }

            foreach ($project->baselineSchedules ?? collect() as $schedule) {
                if (empty($schedule->planned_date)) {
                    continue;
                }
                $label = $schedule->month_label ?: Carbon::parse($schedule->planned_date)->format('Y-m');
                $planned = (float) ($schedule->planned_progress_percent ?? 0);
                $actual = $actualByMonth[$project->id][$label]
                    ?? $actualByMonth[$project->id][$schedule->month_label]
                    ?? null;

                $events[] = array_merge($base, [
                    'id' => "p{$project->id}-insp-{$schedule->id}",
                    'kind' => 'inspection',
                    'kindLabel' => 'Inspection — ' . $label,
                    'date' => Carbon::parse($schedule->planned_date)->toDateString(),
                    'deadline' => Carbon::parse($schedule->planned_date)->toDateString(),
                    'planned' => $planned,
                    'progress' => $actual ?? $latest,
                    'inspectionDate' => Carbon::parse($schedule->planned_date)->toDateString(),
                    'monthLabel' => $label,
                ]);
            }
        }

        // Keep the current-month context the old modal relied on: for the
        // start/due dots, `planned` is this month's target when one exists.
        // (Inspection dots already carry their own month's target above.)
        if (! empty($events)) {
            $baselinesThisMonth = \App\Models\ProjectBaselineSchedule::whereIn('project_id', $ids)
                ->where('month_label', 'like', $monthLabel . '%')
                ->get()
                ->keyBy('project_id');

            foreach ($events as &$event) {
                if (in_array($event['kind'], ['start', 'deadline'], true)
                    && isset($baselinesThisMonth[$event['project_id']])) {
                    $event['planned'] = (float) $baselinesThisMonth[$event['project_id']]->planned_progress_percent;
                    $event['monthLabel'] = $monthLabel;
                }
            }
            unset($event);
        }

        return array_values($events);
    }

    /**
     * Dot color bucket, matching the widget legend.
     */
    public static function statusFor($project): string
    {
        if (($project->status ?? null) === 'Completed') {
            return 'completed';
        }

        if (! empty($project->planned_end_date)) {
            $end = Carbon::parse($project->planned_end_date)->startOfDay();
            $today = now()->startOfDay();

            if ($end->lt($today)) {
                return 'overdue';
            }

            if ($end->diffInDays($today, false) * -1 <= 7 && $end->gte($today)) {
                return 'at-risk';
            }
        }

        return 'on-track';
    }

    protected static function detailUrl(string $detailRoute, int $projectId): string
    {
        try {
            if ($detailRoute === 'contractor') {
                return route('projects.show', $projectId);
            }
            if ($detailRoute === 'staff') {
                return route('staff.projects.detail', $projectId);
            }

            return route('admin.projects.detail', $projectId);
        } catch (\Throwable $e) {
            return "/admin/projects/{$projectId}";
        }
    }
}
