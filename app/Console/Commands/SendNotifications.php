<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\Setting;
use App\Notifications\DeadlineReminder;
use App\Notifications\MonthEndInspectionReminder;
use App\Notifications\OverdueInspectionReminder;
use App\Notifications\PlannedInspectionReminder;
use App\Notifications\RiskLevelChanged;
use App\Services\NotificationService;
use App\Services\ProjectStatusService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Daily notification scan (gaps #12).
 *
 * Generates two kinds of reminders:
 *  - Deadline reminders: Ongoing projects whose planned_end_date falls within
 *    the configured buckets (default 30,14,7 days) or is already overdue.
 *    Escalates naturally because each bucket is a distinct dedupe key.
 *  - Overdue inspection alerts: baseline months whose planned_date has passed with
 *    no recorded progress — ONE notice per project listing all of them (was one
 *    per uninspected month per person until 2026-09-21).
 *  - Monitoring (in-app only, admins + assigned staff; added 2026-09-21):
 *    warranties expiring / inspection due, Planned projects past their start
 *    date, extensions pending 30+ days, issues open 30+ days.
 *
 * Safe to run repeatedly — NotificationService dedupes by tracking key.
 *
 * In-app notices are written immediately; EMAILS ARE QUEUED on the default
 * queue (Concerns\QueuesMailOnly, 2026-09-21), so the queue worker must run for
 * them to leave. Per-project error handling ensures one failure does not block
 * the entire scan (Bug 7 fix). Plan: textfiles/notification_monitoring_restudy_plan.md
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
            ->where('is_archived', false)
            ->whereNotNull('planned_end_date')
            ->with(['contractor.user', 'baselineSchedules', 'progressHistory'])
            ->get();

        $deadlineSent = 0;
        $inspectionSent = 0;
        $plannedSent = 0;
        $monthEndSent = 0;
        $riskLevelSent = 0;
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
            $internalRecipients = $recipients->filter(fn ($u) => $u->role !== 'contractor');

            try {
                $sent = $this->sendDeadlineReminders($project, $recipients, $service, $deadlineBuckets);
                $deadlineSent += $sent;
            } catch (\Throwable $e) {
                $this->error("  [ERROR] Deadline for #{$project->id}: {$e->getMessage()}");
            }

            try {
                $sent = $this->sendPlannedInspectionReminders($project, $internalRecipients, $service);
                $plannedSent += $sent;
            } catch (\Throwable $e) {
                $this->error("  [ERROR] Planned inspection for #{$project->id}: {$e->getMessage()}");
            }

            try {
                $sent = $this->sendMonthEndInspectionReminders($project, $internalRecipients, $service);
                $monthEndSent += $sent;
            } catch (\Throwable $e) {
                $this->error("  [ERROR] Month-end inspection for #{$project->id}: {$e->getMessage()}");
            }

            try {
                $sent = $this->sendOverdueInspectionReminders($project, $internalRecipients, $service);
                $inspectionSent += $sent;
            } catch (\Throwable $e) {
                $this->error("  [ERROR] Overdue inspection for #{$project->id}: {$e->getMessage()}");
            }

            try {
                $sent = $this->sendRiskLevelChangeNotifications($project, $recipients, $service);
                $riskLevelSent += $sent;
            } catch (\Throwable $e) {
                $this->error("  [ERROR] Risk level for #{$project->id}: {$e->getMessage()}");
            }
        }

        // Monitoring checks added 2026-09-21 (restudy P-7..P-10) — IN-APP ONLY,
        // to admins + the assigned staff. Each in its own guard so one failure
        // never stops the rest.
        $monitoring = [];
        foreach ([
            'Warranty'          => fn () => $this->sendWarrantyReminders($service),
            'Not started'       => fn () => $this->sendNotStartedReminders($service),
            'Pending extension' => fn () => $this->sendPendingExtensionReminders($service),
            'Issue aging'       => fn () => $this->sendIssueAgingReminders($service),
        ] as $label => $check) {
            try {
                $monitoring[$label] = $check();
            } catch (\Throwable $e) {
                $monitoring[$label] = 0;
                $this->error("  [ERROR] {$label}: {$e->getMessage()}");
            }
        }

        $this->info("Scope: Ongoing w/ end date: {$projectsChecked} | Planned excluded: {$plannedExcluded} | all w/o end date: {$noEndDate}");
        $this->info("Done. Projects checked: {$projectsChecked}, Deadline: {$deadlineSent}, Planned inspection: {$plannedSent}, Month-end inspection: {$monthEndSent}, Overdue inspection: {$inspectionSent}, Risk-level: {$riskLevelSent}");
        $this->info('Monitoring: ' . collect($monitoring)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));

        return 0;
    }

    /**
     * Warranties needing attention (App\Services\WarrantyMonitor — the SAME rules
     * the warranty record page shows): expiring within 30 days, expired, or a
     * warranty inspection within 14 days. A passed inspection date sends nothing.
     */
    private function sendWarrantyReminders(NotificationService $service): int
    {
        $monitor = app(\App\Services\WarrantyMonitor::class);
        $sent = 0;

        foreach (\App\Models\Warranty::with('project')->get() as $warranty) {
            if (!$warranty->project) {
                continue;
            }
            $status = $monitor->statusFor($warranty);
            $alerts = [];
            if ($status['expiry'] && in_array($status['expiry']['state'], ['expiring', 'expired'], true)) {
                $alerts[] = [$status['expiry']['state'], $status['expiry']];
            }
            foreach (['mid_year', 'final'] as $kind) {
                if ($status[$kind] && $status[$kind]['state'] === 'due') {
                    $alerts[] = [$kind, $status[$kind]];
                }
            }

            foreach ($alerts as [$kind, $check]) {
                $n = $service->notifyMany(
                    $service->internalRecipientsFor($warranty->project),
                    new \App\Notifications\WarrantyReminder(
                        $warranty->id, $warranty->project->id, $warranty->project->title,
                        $kind, $check['date'], $check['days']
                    )
                );
                $sent += $n;
                $this->line("  [Warranty] #{$warranty->id} '{$warranty->project->title}' {$kind} {$check['date']} ({$n} sent)");
            }
        }

        return $sent;
    }

    /**
     * "Planned" projects whose planned start date has passed — the loop above
     * only scans Ongoing projects, so these were never flagged.
     */
    private function sendNotStartedReminders(NotificationService $service): int
    {
        $today = \App\Support\DateHelper::now()->copy()->startOfDay();
        $sent = 0;

        $projects = Project::where('status', 'Planned')
            ->where('is_archived', false)
            ->whereNotNull('planned_start_date')
            ->whereDate('planned_start_date', '<', $today->toDateString())
            ->get();

        foreach ($projects as $project) {
            $daysLate = (int) $project->planned_start_date->copy()->startOfDay()->diffInDays($today);
            $n = $service->notifyMany(
                $service->internalRecipientsFor($project),
                new \App\Notifications\ProjectNotStartedReminder(
                    $project->id, $project->title, $project->planned_start_date->format('Y-m-d'), $daysLate
                )
            );
            $sent += $n;
            $this->line("  [Not started] #{$project->id} '{$project->title}' start {$project->planned_start_date->format('Y-m-d')} ({$daysLate} days ago, {$n} sent)");
        }

        return $sent;
    }

    /**
     * Extension requests pending review for 30+ days — reminded at 30, 60, …
     * days (the submit-time notice is unchanged; user decision 2026-09-21).
     */
    private function sendPendingExtensionReminders(NotificationService $service): int
    {
        $now = \App\Support\DateHelper::now();
        $sent = 0;

        $extensions = \App\Models\ProjectExtension::with('project')
            ->where('status', 'pending')
            ->where('created_at', '<=', $now->copy()->subDays(30))
            ->get();

        foreach ($extensions as $ext) {
            if (!$ext->project || $ext->project->is_archived) {
                continue;
            }
            $days = (int) $ext->created_at->diffInDays($now);
            $n = $service->notifyMany(
                $service->internalRecipientsFor($ext->project),
                new \App\Notifications\PendingExtensionReminder(
                    $ext->project->id, $ext->project->title, $ext->id, (int) $ext->extension_number,
                    $days, $ext->created_at->format('Y-m-d')
                )
            );
            $sent += $n;
            $this->line("  [Pending extension] #{$ext->id} on '{$ext->project->title}' pending {$days} days ({$n} sent)");
        }

        return $sent;
    }

    /**
     * Issues still Open / In Progress 30+ days after they were reported.
     */
    private function sendIssueAgingReminders(NotificationService $service): int
    {
        $now = \App\Support\DateHelper::now();
        $sent = 0;

        $issues = \App\Models\Issue::with('project')
            ->whereIn('status', ['Open', 'In Progress'])
            ->where('created_at', '<=', $now->copy()->subDays(30))
            ->get();

        foreach ($issues as $issue) {
            if (!$issue->project || $issue->project->is_archived || $issue->project->status === 'Completed') {
                continue;
            }
            $days = (int) $issue->created_at->diffInDays($now);
            $n = $service->notifyMany(
                $service->internalRecipientsFor($issue->project),
                new \App\Notifications\IssueAgingReminder(
                    $issue->project->id, $issue->project->title, $issue->id,
                    (string) $issue->issue_name, (string) ($issue->severity ?? ''), $days
                )
            );
            $sent += $n;
            $this->line("  [Issue aging] #{$issue->id} '{$issue->issue_name}' open {$days} days ({$n} sent)");
        }

        return $sent;
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
     * Send a reminder on the exact day a baseline's planned inspection date arrives.
     * Fires once per (project, month) via dedupe key.
     */
    private function sendPlannedInspectionReminders(
        Project $project,
        \Illuminate\Support\Collection $recipients,
        NotificationService $service,
    ): int {
        if ($project->status === 'Completed') {
            return 0;
        }

        $today = Carbon::now()->toDateString();
        $recordedMonths = $project->progressHistory->pluck('month_label');

        $dueBaselines = $project->baselineSchedules
            ->filter(fn ($b) => $b->planned_date->toDateString() === $today
                && ! $recordedMonths->contains($b->month_label))
            ->values();

        if ($dueBaselines->isEmpty()) {
            return 0;
        }

        $totalSent = 0;

        foreach ($dueBaselines as $baseline) {
            $notification = new PlannedInspectionReminder(
                $project->id,
                $project->title,
                $baseline->month_label,
                $baseline->month_display,
                $baseline->planned_date->format('Y-m-d'),
            );

            $totalSent += $service->notifyMany($recipients, $notification);
        }

        $this->line("  [Planned Inspection] #{$project->id} '{$project->title}' → " . $dueBaselines->pluck('month_label')->implode(', ') . " ({$totalSent} sent)");

        return $totalSent;
    }

    /**
     * Send a month-end nudge when we're in the last 7 days of the month
     * and no progress has been recorded for the current month's baseline.
     * Fires once per (project, month) via dedupe key.
     */
    private function sendMonthEndInspectionReminders(
        Project $project,
        \Illuminate\Support\Collection $recipients,
        NotificationService $service,
    ): int {
        if ($project->status === 'Completed') {
            return 0;
        }

        $now = Carbon::now();
        $daysUntilMonthEnd = (int) abs($now->copy()->endOfMonth()->diffInDays($now));

        if ($daysUntilMonthEnd > 7) {
            return 0;
        }

        $currentMonthLabel = $now->format('Y-m');
        $recordedMonths = $project->progressHistory->pluck('month_label');

        if ($recordedMonths->contains($currentMonthLabel)) {
            return 0;
        }

        $currentMonthBaseline = $project->baselineSchedules
            ->first(fn ($b) => $b->month_label === $currentMonthLabel);

        if (! $currentMonthBaseline) {
            return 0;
        }

        $notification = new MonthEndInspectionReminder(
            $project->id,
            $project->title,
            $currentMonthBaseline->month_label,
            $currentMonthBaseline->month_display,
            $currentMonthBaseline->planned_date->format('Y-m-d'),
        );

        $sent = $service->notifyMany($recipients, $notification);

        $this->line("  [Month-End Inspection] #{$project->id} '{$project->title}' → {$currentMonthLabel} ({$sent} sent)");

        return $sent;
    }

    /**
     * Send ONE overdue-inspection alert per project covering every baseline month
     * whose planned_date has passed with no recorded progress.
     * No grace period — fires immediately when planned_date is past.
     * Returns the number of notifications actually delivered.
     */
    private function sendOverdueInspectionReminders(
        Project $project,
        \Illuminate\Support\Collection $recipients,
        NotificationService $service,
    ): int {
        if ($project->status === 'Completed') {
            return 0;
        }

        $recordedMonths = $project->progressHistory->pluck('month_label');

        $currentMonthLabel = Carbon::now()->format('Y-m');

        $overdueBaselines = $project->baselineSchedules
            ->sortBy('planned_date')
            ->filter(function ($baseline) use ($recordedMonths, $currentMonthLabel) {
                if ($recordedMonths->contains($baseline->month_label)) {
                    return false;
                }

                if ($baseline->month_label === $currentMonthLabel) {
                    return false;
                }

                return $baseline->planned_date->lt(Carbon::now());
            })
            ->values();

        if ($overdueBaselines->isEmpty()) {
            $this->line("  [Overdue Inspection] #{$project->id} '{$project->title}' → none overdue");
            return 0;
        }

        $overdueMonths = $overdueBaselines->pluck('month_label')->all();

        // ONE notice per project listing every uninspected month (restudy P-1,
        // 2026-09-21) — it used to be one per month per person. Identified by the
        // LATEST month, so a newly overdue month re-alerts once with the full list.
        $latest = $overdueBaselines->last();
        $notification = new OverdueInspectionReminder(
            $project->id,
            $project->title,
            $latest->month_label,
            $latest->month_display,
            $latest->planned_date->format('Y-m-d'),
            $overdueBaselines->map(fn ($b) => [
                'label'        => $b->month_label,
                'display'      => $b->month_display,
                'planned_date' => $b->planned_date->format('Y-m-d'),
            ])->all()
        );

        $totalSent = $service->notifyMany($recipients, $notification);

        $this->line("  [Overdue Inspection] #{$project->id} '{$project->title}' → overdue months " . implode(', ', $overdueMonths) . " ({$totalSent} sent)");

        return $totalSent;
    }

    /**
     * Detect risk-level changes that occur when time advances past a baseline
     * month without a new progress recording (Bug 3 fix).
     *
     * Compares the project's stored risk level (set at the last progress
     * recording) with the level that would result from the current month's
     * planned progress vs. the last recorded actual progress. Fires when the
     * level actually changes, using a distinct dedupe key per month+level.
     */
    private function sendRiskLevelChangeNotifications(
        Project $project,
        \Illuminate\Support\Collection $recipients,
        NotificationService $service,
    ): int {
        $now = Carbon::now();

        $lastRecorded = $project->progressHistory()
            ->reorder()
            ->orderBy('month_label', 'desc')
            ->first();

        if (! $lastRecorded) {
            return 0;
        }

        // Find a baseline month that is past grace but has no progress recorded
        // (i.e. time has advanced but nobody recorded progress yet)
        $currentMonthBaseline = ProjectBaselineSchedule::where('project_id', $project->id)
            ->where('planned_date', '<=', $now)
            ->whereNotIn('month_label', $project->progressHistory()->pluck('month_label'))
            ->orderBy('planned_date', 'desc')
            ->first();

        if (! $currentMonthBaseline) {
            return 0;
        }

        $plannedProgress = (float) $currentMonthBaseline->planned_progress_percent;
        $actualProgress  = (float) $lastRecorded->actual_progress_percent;
        $variance        = $actualProgress - $plannedProgress;

        $statusService = app(ProjectStatusService::class);
        $currentRiskLevel = $statusService->getRiskLevel(-$variance)['label'];

        $previousRiskLevel = $project->previous_risk_level;

        if ($previousRiskLevel === null || $currentRiskLevel === $previousRiskLevel) {
            return 0;
        }

        $notification = new RiskLevelChanged(
            $project->id,
            $project->title,
            $previousRiskLevel,
            $currentRiskLevel,
            $currentMonthBaseline->month_label,
            $variance,
            $actualProgress,
            $plannedProgress,
        );

        $sent = $service->notifyMany($recipients, $notification);

        $project->update(['previous_risk_level' => $currentRiskLevel]);

        $this->line("  [Risk Level] #{$project->id} '{$project->title}' {$previousRiskLevel} → {$currentRiskLevel} ({$sent} sent)");

        return $sent;
    }
}
