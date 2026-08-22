<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Setting;
use App\Notifications\DeadlineReminder;
use App\Notifications\OverdueInspectionReminder;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Daily notification scan (gaps #12).
 *
 * Generates two kinds of reminders:
 *  - Deadline reminders: Ongoing projects whose planned_end_date falls within
 *    the configured buckets (default 30,14,7 days) or is already overdue.
 *    Escalates naturally because each bucket is a distinct dedupe key.
 *  - Overdue inspection alerts: every baseline month whose planned_date is past
 *    the grace window with no recorded progress for that month_label (one notice
 *    per uninspected month).
 *
 * Safe to run repeatedly — NotificationService dedupes by tracking key.
 */
class SendNotifications extends Command
{
    protected $signature = 'notifications:scan';

    protected $description = 'Send deadline + overdue-inspection notifications from a daily scan';

    public function handle(NotificationService $service)
    {
        if (!Setting::getValue('notification_enabled', true)) {
            $this->info('Notifications disabled (notification_enabled=false). Skipping scan.');
            return 0;
        }

        $deadlineBuckets = $this->parseBuckets(Setting::getValue('notification_deadline_days', '30,14,7'));
        $graceDays = (int) Setting::getValue('notification_overdue_grace_days', 7);

        $this->info('Scanning projects for notifications...');

        // Diagnostic counts so "0 notices" is never ambiguous: show how many
        // projects were scanned vs excluded by the status/end-date filter.
        $statusCounts = Project::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
        $plannedExcluded = $statusCounts['Planned'] ?? 0;
        $noEndDate = Project::whereNull('planned_end_date')->count();

        $projects = Project::whereIn('status', ['Ongoing'])
            ->whereNotNull('planned_end_date')
            ->with(['contractor.user', 'baselineSchedules', 'progressHistory'])
            ->get();

        $deadlineSent = 0;
        $inspectionSent = 0;
        $projectsChecked = 0;

        foreach ($projects as $project) {
            $projectsChecked++;

            $recipients = collect($service->admins());
            $assignedStaff = $service->staffForProject($project);
            if ($assignedStaff) {
                $recipients->push($assignedStaff);
            }
            $contractorUser = $service->contractorUserFor($project);
            if ($contractorUser) {
                $recipients->push($contractorUser);
            }
            $recipients = $recipients->unique('id');

            $sent = $this->sendDeadlineReminders($project, $recipients, $service, $deadlineBuckets);
            $deadlineSent += $sent;

            $sent = $this->sendOverdueInspectionReminders($project, $recipients, $service, $graceDays);
            $inspectionSent += $sent;
        }

        $this->info("Scope: Ongoing w/ end date: {$projectsChecked} | Planned excluded: {$plannedExcluded} | all w/o end date: {$noEndDate}");
        $this->info("Done. Projects checked: {$projectsChecked}, Deadline notices: {$deadlineSent}, Overdue-inspection notices: {$inspectionSent}");

        return 0;
    }

    /**
     * Parse the comma-separated bucket setting into sorted descending integers.
     */
    private function parseBuckets(mixed $raw): array
    {
        $buckets = collect(explode(',', (string) $raw))
            ->map(fn ($v) => (int) trim($v))
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->sortDesc()
            ->values();

        return $buckets->all();
    }

    /**
     * Send deadline reminders for this project to the given recipients.
     * Returns the number of notifications actually delivered.
     */
    private function sendDeadlineReminders(
        Project $project,
        \Illuminate\Support\Collection $recipients,
        NotificationService $service,
        array $buckets
    ): int {
        $daysRemaining = (int) Carbon::now()->startOfDay()->diffInDays(
            $project->planned_end_date->startOfDay(),
            false
        );

        $bucket = $this->resolveDeadlineBucket($daysRemaining, $buckets);
        if ($bucket === null) {
            $this->line("  [Deadline] #{$project->id} '{$project->title}' ends {$project->planned_end_date->format('Y-m-d')} → no bucket ({$daysRemaining} days out)");
            return 0;
        }

        $notification = new DeadlineReminder(
            $project->id,
            $project->title,
            $bucket,
            $daysRemaining,
            $project->planned_end_date->format('Y-m-d')
        );

        $sent = $service->notifyMany($recipients, $notification);
        $this->line("  [Deadline] #{$project->id} '{$project->title}' ends {$project->planned_end_date->format('Y-m-d')} → bucket {$bucket} ({$daysRemaining} days out, {$sent} sent)");

        return $sent;
    }

    /**
     * Resolve which bucket a project falls into:
     *  - 'overdue' when daysRemaining < 0
     *  - otherwise the SMALLEST configured bucket still >= daysRemaining, else null.
     */
    private function resolveDeadlineBucket(int $daysRemaining, array $buckets): ?string
    {
        if ($daysRemaining < 0) {
            return 'overdue';
        }

        sort($buckets);

        foreach ($buckets as $bucketDays) {
            if ($daysRemaining <= $bucketDays) {
                return (string) $bucketDays;
            }
        }

        return null;
    }

    /**
     * Send overdue-inspection alerts for every baseline month past the grace
     * window with no recorded progress for that month (one per uninspected
     * month). Returns the number of notifications actually delivered.
     */
    private function sendOverdueInspectionReminders(
        Project $project,
        \Illuminate\Support\Collection $recipients,
        NotificationService $service,
        int $graceDays
    ): int {
        if ($project->status === 'Completed') {
            return 0;
        }

        $recordedMonths = $project->progressHistory->pluck('month_label');

        $overdueBaselines = $project->baselineSchedules
            ->sortBy('planned_date')
            ->filter(function ($baseline) use ($recordedMonths, $graceDays) {
                if ($recordedMonths->contains($baseline->month_label)) {
                    return false;
                }

                return $baseline->planned_date->lt(Carbon::now()->subDays($graceDays));
            })
            ->values();

        if ($overdueBaselines->isEmpty()) {
            $this->line("  [Inspection] #{$project->id} '{$project->title}' → none overdue past {$graceDays}-day grace");
            return 0;
        }

        $totalSent = 0;
        $overdueMonths = $overdueBaselines->pluck('month_label')->all();

        foreach ($overdueBaselines as $overdueBaseline) {
            $notification = new OverdueInspectionReminder(
                $project->id,
                $project->title,
                $overdueBaseline->month_label,
                $overdueBaseline->month_display,
                $overdueBaseline->planned_date->format('Y-m-d')
            );

            $totalSent += $service->notifyMany($recipients, $notification);
        }

        $this->line("  [Inspection] #{$project->id} '{$project->title}' → overdue months " . implode(', ', $overdueMonths) . " ({$totalSent} sent)");

        return $totalSent;
    }
}
