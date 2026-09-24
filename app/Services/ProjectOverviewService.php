<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProjectOverviewService
{
    // Build and return the header overview stats for the project detail page
    public function getOverviewStats(Project $project, $progressHistory = null): array
    {
        $activities = $project->activities;
        $totalActivities = $activities->count();
        $completedActivities = $activities->where('is_completed', true)->count();
        $pendingActivities = max(0, $totalActivities - $completedActivities);
        $activityCompletionRate = $totalActivities > 0
            ? round(($completedActivities / $totalActivities) * 100, 1)
            : 0;
        $latestProgress = $progressHistory
            ? $progressHistory->first()
            : $project->progressHistory()
                ->reorder()
                ->orderByDesc('month_label')
                ->orderByDesc('inspection_date')
                ->orderByDesc('created_at')
                ->first();
        $latestProgressValue = null;
        $latestProgressDate = null;

        if ($latestProgress) {
            $latestProgressValue = $latestProgress->actual_progress_percent ?? $latestProgress->progress_percentage ?? null;
            $latestProgressDate = $latestProgress->inspection_date
                ?? $latestProgress->created_at;
        }
        $isCompleted = $project->status === 'Completed';
        
        if ($isCompleted) {
            $timeVariance = $project->getCompletionDelay();
            $timeVarianceLabel = '';
            $timeVarianceColor = 'blue';

            if ($timeVariance !== null) {
                if ($timeVariance === 0) {
                    $timeVarianceLabel = 'On Schedule';
                    $timeVarianceColor = 'green';
                } elseif ($timeVariance > 0) {
                    $timeVarianceLabel = $timeVariance . ' days late';
                    $timeVarianceColor = 'orange';
                } else {
                    $timeVarianceLabel = abs($timeVariance) . ' days early';
                    $timeVarianceColor = 'green';
                }
            } else {
                $timeVarianceLabel = 'N/A';
            }
            
            return [
                'progress' => $project->getProgressPercentage(),
                'totalActivities' => $totalActivities,
                'completedActivities' => $completedActivities,
                'pendingActivities' => $pendingActivities,
                'activityCompletionRate' => $activityCompletionRate,
                'latestProgressValue' => $latestProgressValue,
                'latestProgressDate' => $latestProgressDate,
                'totalBudget' => $project->effectiveContractPrice() ?? 0,
                'isCompleted' => true,
                'timeVariance' => $timeVariance,
                'timeVarianceLabel' => $timeVarianceLabel,
                'timeVarianceColor' => $timeVarianceColor,
            ];
        } else {
            $daysRemaining = $this->calculateDaysRemaining($project);
            $endDateFormatted = $project->planned_end_date 
                ? $project->planned_end_date->format('M d, Y')
                : 'Not set';
            
            return [
                'progress' => $project->getProgressPercentage(),
                'totalActivities' => $totalActivities,
                'completedActivities' => $completedActivities,
                'pendingActivities' => $pendingActivities,
                'activityCompletionRate' => $activityCompletionRate,
                'latestProgressValue' => $latestProgressValue,
                'latestProgressDate' => $latestProgressDate,
                'totalBudget' => $project->effectiveContractPrice() ?? 0,
                'isCompleted' => false,
                'daysRemaining' => $daysRemaining,
                'endDateFormatted' => $endDateFormatted,
            ];
        }
    }

    // Return the progress metrics (percent, status label, color)
    public function getProgressMetrics(Project $project): array
    {
        return [
            'percentComplete' => $project->getProgressPercentage(),
            'statusLabel' => $project->status,
            'statusColor' => $this->getStatusColor($project->status),
        ];
    }

    // Fetch all activities for a project, ordered by month
    public function getActivities($projectId)
    {
        return ProjectActivity::where('project_id', $projectId)
            ->orderBy('month_label')
            ->get();
    }

    // Fetch activities for a specific month
    public function getActivitiesByMonth($projectId, $monthLabel)
    {
        return ProjectActivity::where('project_id', $projectId)
            ->where('month_label', $monthLabel)
            ->orderBy('activity_name')
            ->get();
    }

    // Calculate total activities and completion rate
    public function getActivitiesStatistics($projectId)
    {
        $activities = ProjectActivity::where('project_id', $projectId)->get();
        
        $total = $activities->count();
        $completed = $activities->where('is_completed', true)->count();
        $pending = $total - $completed;
        $completionRate = $total > 0 ? ($completed / $total) * 100 : 0;

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'completion_rate' => round($completionRate, 2),
        ];
    }

    // Format a Y-m or Y-m-d label into "Mon YYYY" display format
    public function formatMonthLabelForDisplay(?string $monthLabel): string
    {
        if (empty($monthLabel)) {
            return 'N/A';
        }

        foreach (['Y-m', 'Y-m-d'] as $format) {
            try {
                return Carbon::createFromFormat($format, $monthLabel)->format('M Y');
            } catch (\Throwable $e) {
            }
        }

        try {
            return Carbon::parse($monthLabel)->format('M Y');
        } catch (\Throwable $e) {
            return $monthLabel;
        }
    }

    // Calculate remaining days until project end date
    public function calculateDaysRemaining(Project $project): int
    {
        if (!$project->planned_end_date) return 0;
        return (int) max(0, floor(now()->diffInDays($project->planned_end_date, false)));
    }

    // Map project status to a display color
    public function getStatusColor(string $status): string
    {
        return match ($status) {
            'Completed' => 'green',
            'Ongoing' => 'blue',
            'Planned' => 'gray',
            'Suspended' => 'orange',
            'Cancelled' => 'red',
            default => 'gray',
        };
    }

    // ==================== PHASE B: OVERVIEW REAL DATA ====================

    // Severity → weight used to rank the Issues & Risks card and the worst
    // severity surfaced in the Alerts card.
    public function severityOrder(string $severity): int
    {
        return match ($severity) {
            'Critical' => 4,
            'High'     => 3,
            'Medium'   => 2,
            'Low'      => 1,
            default    => 0,
        };
    }

    // Severity → badge/text color (CSS var, matches the mockup palette)
    public function severityColor(string $severity): string
    {
        return match ($severity) {
            'Critical' => 'var(--red)',
            'High'     => 'var(--orange)',
            'Medium'   => 'var(--blue)',
            'Low'      => 'var(--green)',
            default    => 'var(--text-muted)',
        };
    }

    /**
     * Top 3 unresolved issues (by severity desc, newest first) plus totals.
     *
     * @return array{total_open: int, worst_severity: ?string, aging_count: int, items: array}
     */
    public function getOpenIssues(Project $project): array
    {
        $issues = $project->issues()
            ->whereIn('status', ['Open', 'In Progress'])
            ->get()
            ->sortByDesc(fn ($issue) => [
                $this->severityOrder($issue->severity),
                $issue->created_at?->timestamp ?? 0,
            ])
            ->values();

        $totalOpen    = $issues->count();
        $worstSeverity = $issues->first()?->severity ?? null;
        $agingCount   = $issues->filter(
            fn ($issue) => $issue->created_at && $issue->created_at->lte(now()->subDays(config('ai.notifications.aging_days')))
        )->count();

        return [
            'total_open'     => $totalOpen,
            'worst_severity' => $worstSeverity,
            'aging_count'    => $agingCount,
            'items'          => $issues->take(3)->map(fn ($issue) => [
                'id'          => $issue->id,
                'issue_name'  => $issue->issue_name,
                'severity'    => $issue->severity,
                'status'      => $issue->status,
                'days_open'   => $issue->created_at ? (int) $issue->created_at->diffInDays(now()) : 0,
                'badge_color' => $this->severityColor($issue->severity),
            ])->all(),
        ];
    }

    /**
     * Derived alerts for the Notifications & Alerts card. No new table — all
     * signals are computed from existing data:
     *   - inspection_pending  → first baseline month with no progress record
     *   - open_issues         → unresolved count + worst severity (+ aging)
     *   - pending_extensions  → project_extensions.status = 'pending'
     *   - document_coverage   → shown when the project has zero documents
     *
     * @return array<int, array{type: string, title: string, meta: string, color: string}>
     */
    public function getAlerts(Project $project): array
    {
        $alerts = [];

        // 0. Planned project whose start date has passed (restudy F-9, 2026-09-21 —
        //    the same condition the daily scan's ProjectNotStartedReminder uses)
        if ($project->status === 'Planned' && $project->planned_start_date) {
            $today = \App\Support\DateHelper::now()->copy()->startOfDay();
            $start = $project->planned_start_date->copy()->startOfDay();
            if ($start->lt($today)) {
                $daysLate = (int) $start->diffInDays($today);
                $alerts[] = [
                    'type'  => 'not_started',
                    'title' => 'Not yet started — planned start passed ' . $daysLate . ' ' . ($daysLate === 1 ? 'day' : 'days') . ' ago',
                    'meta'  => 'Planned start ' . $start->format('M d, Y') . ' · status is still Planned',
                    'color' => 'var(--orange)',
                ];
            }
        }

        // 0b. Warranty needing attention (App\Services\WarrantyMonitor — same rules
        //     as the warranty record page and the daily WarrantyReminder)
        $monitor = app(\App\Services\WarrantyMonitor::class);
        foreach (\App\Models\Warranty::where('project_id', $project->id)->get() as $warranty) {
            $status = $monitor->statusFor($warranty);
            foreach (['expiry', 'mid_year', 'final'] as $check) {
                $c = $status[$check];
                if ($c && in_array($c['state'], ['expired', 'expiring', 'due'], true)) {
                    $what = $check === 'expiry' ? '' : ($check === 'mid_year' ? 'Mid-year warranty ' : 'Final warranty ');
                    $alerts[] = [
                        'type'  => 'warranty_' . $check,
                        'title' => $what . ($what ? lcfirst($c['label']) : $c['label']),
                        'meta'  => 'Warranty · ' . \Carbon\Carbon::parse($c['date'])->format('M d, Y'),
                        'color' => $c['state'] === 'expired' ? 'var(--red)' : 'var(--orange)',
                    ];
                }
            }
        }

        // 1. Next pending inspection month
        if ($project->status !== 'Completed') {
            $recordedMonths = $project->progressHistory()->pluck('month_label');
            $nextPending = $project->baselineSchedules()
                ->orderBy('planned_date')
                ->get()
                ->first(fn ($baseline) => !$recordedMonths->contains($baseline->month_label));

            if ($nextPending) {
                $alerts[] = [
                    'type'  => 'inspection_pending',
                    'title' => 'Inspection pending for ' . $this->formatMonthLabelForDisplay($nextPending->month_label),
                    'meta'  => 'Reminder · Next unrecorded baseline month',
                    'color' => 'var(--orange)',
                ];
            }
        }

        // 2. Open / aging issues
        $open = $this->getOpenIssues($project);
        if ($open['total_open'] > 0) {
            $severity = $open['worst_severity'] ?? 'Low';
            $meta = $open['total_open'] . ' open issue' . ($open['total_open'] > 1 ? 's' : '');
            if ($open['aging_count'] > 0) {
                $meta .= ' · ' . $open['aging_count'] . ' aging';
            }

            $alerts[] = [
                'type'  => 'open_issues',
                'title' => $open['total_open'] . ' open issue' . ($open['total_open'] > 1 ? 's' : '')
                    . ' · ' . $severity,
                'meta'  => $meta,
                'color' => in_array($severity, ['Critical', 'High']) ? 'var(--red)' : 'var(--orange)',
            ];
        }

        // 3. Pending extension requests
        $pendingExtensions = $project->extensions()->where('status', 'pending')->count();
        if ($pendingExtensions > 0) {
            // Oldest request waiting 30+ days is named (same threshold as the
            // PendingExtensionReminder in the daily scan, restudy F-10)
            $oldest = $project->extensions()->where('status', 'pending')->min('created_at');
            $oldestDays = $oldest ? (int) Carbon::parse($oldest)->diffInDays(\App\Support\DateHelper::now()) : 0;
            $alerts[] = [
                'type'  => 'pending_extensions',
                'title' => $pendingExtensions . ' extension request'
                    . ($pendingExtensions > 1 ? 's' : '') . ' awaiting approval',
                'meta'  => $oldestDays >= 30
                    ? 'Pending · oldest waiting ' . $oldestDays . ' days'
                    : 'Pending · Action required',
                'color' => 'var(--blue)',
            ];
        }

        // 4. Document-coverage reminder
        if ($project->documents()->count() === 0) {
            $alerts[] = [
                'type'  => 'document_coverage',
                'title' => 'No documents uploaded yet',
                'meta'  => 'Documentation pending',
                'color' => 'var(--purple)',
            ];
        }

        return $alerts;
    }

    /**
     * Latest ~6 project-scoped activity logs (newest first), joined with the
     * acting user. Respects the visibility scope so non-admins never see
     * admin_only entries.
     *
     * @return \Illuminate\Support\Collection<int, ActivityLog>
     */
    public function getRecentActivity(Project $project): Collection
    {
        return ActivityLog::with('user')
            ->where('project_id', $project->id)
            ->visibleTo(auth()->user()?->role ?? 'admin')
            ->latest('created_at')
            ->take(6)
            ->get();
    }

    /**
     * Resolve the baseline month to auto-select when a NEW issue is added:
     *   1. the monthly target matching the current calendar month, else
     *   2. the monthly target of the most recently recorded actual progress,
     *   3. otherwise the earliest baseline (no progress yet).
     *
     * Returns null only when the project has no baselines at all.
     */
    public function getDefaultIssueBaseline(Project $project)
    {
        $baselines = $project->baselineSchedules()->orderBy('planned_date')->get();

        if ($baselines->isEmpty()) {
            return null;
        }

        // Priority 1: monthly target matching today's month
        $currentMonth = Carbon::now()->format('Y-m');
        $dateMatch = $baselines->firstWhere('month_label', $currentMonth);
        if ($dateMatch) {
            return $dateMatch;
        }

        // Priority 2: monthly target of the latest recorded actual progress
        $latestProgress = $project->progressHistory()
            ->reorder('month_label', 'desc')
            ->orderBy('inspection_date', 'desc')
            ->first();
        if ($latestProgress) {
            if ($latestProgress->baseline_schedule_id) {
                $byFk = $baselines->firstWhere('id', $latestProgress->baseline_schedule_id);
                if ($byFk) {
                    return $byFk;
                }
            }
            $byLabel = $baselines->firstWhere('month_label', $latestProgress->month_label);
            if ($byLabel) {
                return $byLabel;
            }
        }

        // Priority 3: earliest baseline (no recorded progress yet)
        return $baselines->first();
    }

    /**
     * Resolve the baseline month to auto-select when a NEW risk is added.
     * Uses the same priority as issues (current month → latest progress →
     * earliest baseline), since a risk most often threatens the phase the
     * project is currently in.
     */
    public function getDefaultRiskBaseline(Project $project)
    {
        return $this->getDefaultIssueBaseline($project);
    }
}
