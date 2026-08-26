<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Support\DateHelper;

/**
 * ============================================================================
 * ProjectHealthService
 * ============================================================================
 *
 * Purpose
 * -------
 * Single source of truth for PROJECT HEALTH across the whole app.
 *
 * Before this service, list views (dashboard, projects index, contractor
 * dashboard) judged health with deadline-only heuristics on the Project model
 * (Project::isAtRisk()/isOverdue()), while the AI module judged health from
 * the CURRENT progress gap vs the effective baseline at today
 * (AIPredictionPresenter). The two definitions disagreed: a project far
 * behind plan could show green "ON TRACK" on a list while the AI panel said
 * "AT RISK".
 *
 * This service unifies those definitions:
 *   • classifyGap()        — the SAME threshold ladder the AI panel used
 *                            (gap ≤ 5 Low, < 10 Moderate, < 15 High, else
 *                            Critical). AIPredictionPresenter delegates here
 *                            so the two can never diverge again.
 *   • expectedProgress()   — the effective-baseline lookup at DateHelper::now()
 *                            (current-month progress history → current-month
 *                            baseline → latest history → date-filtered baseline
 *                            → time-based fallback). Accepts a pre-fetched
 *                            context array to avoid N+1 queries in list views.
 *   • determine()          — full badge/fill/dot/tooltip result for a project,
 *                            including OVERDUE, NO DATA, NEUTRAL states and the
 *                            record-based escalation flags (open high/critical
 *                            issues, aging unresolved issues, open high risks,
 *                            pending extensions). AI forecast data is NEVER a
 *                            health input (it shows in tooltips only).
 *
 * Honesty rules (shared with the AI module):
 *   • Ongoing with ZERO progress records → NO DATA (grey), never green.
 *   • Planned / Suspended / Cancelled    → neutral (grey), never green.
 *   • Forecast/prediction data is NOT mixed into health status.
 *
 * Cost/budget health is deliberately absent: the system stores only
 * approved_budget — there is no actual-spend tracking, so a real cost
 * variance (EVM CPI/CV) cannot be computed.
 */
class ProjectHealthService
{
    /**
     * ------------------------------------------------------------------------
     * Classify Risk From Progress Gap
     * ------------------------------------------------------------------------
     *
     * Maps a progress gap (expected − actual, %) to a unified risk level.
     *
     * These thresholds are the SAME ladder used across the whole app
     * (ProjectStatusService, AI module, notifications). Returns one of:
     *   'ahead', 'on_track', 'low_risk', 'medium_risk', 'high_risk'
     *
     * Levels
     * ------
     * gap ≤ -5  → ahead        (ahead of plan)
     * gap ≤  5  → on_track     (within tolerance)
     * gap < 10  → low_risk     (slightly behind)
     * gap < 15  → medium_risk  (noticeably behind)
     * gap ≥ 15  → high_risk    (significantly behind)
     */
    public function classifyGap(float $progressGap): string
    {
        if ($progressGap <= -5) {
            return 'ahead';
        }
        if ($progressGap <= 5) {
            return 'on_track';
        }
        if ($progressGap < 10) {
            return 'low_risk';
        }
        if ($progressGap < 15) {
            return 'medium_risk';
        }
        return 'high_risk';
    }

    /**
     * ------------------------------------------------------------------------
     * Determine Project Health
     * ------------------------------------------------------------------------
     *
     * Main entry point for list views. Returns a blade-friendly result with
     * the badge text, display classes, gap math, and (for AT RISK) the reason.
     *
     * When $context is fully populated the service performs ZERO database
     * queries (important for the dashboard/projects tables where it is called
     * once per row). When keys are missing it falls back to the same queries
     * the AI presenter used, so single-project callers stay simple.
     *
     * Context keys (all optional — omitting them triggers a query):
     *   latest_progress        ProjectProgressHistory|null — latest record
     *                          (actual_progress_percent / planned_progress_percent)
     *   current_month_history  ProjectProgressHistory|null — record in current month
     *   current_month_baseline ProjectBaselineSchedule|null — planned in current month
     *   baseline_schedules     Collection|ProjectBaselineSchedule[] — all, asc by planned_date
     *   open_high_issues       bool — ≥1 unresolved High/Critical issue
     *   aging_issue_count      int  — unresolved issues older than 30 days
     *   open_high_risks        bool — ≥1 open risk-register entry with score ≥ 7
     *   pending_extension      bool — ≥1 pending extension request
     *
     * Returns (levels):
     *   completed → 'COMPLETED'  (neutral grey; views special-case completed badges)
     *   neutral   → 'PLANNED' / 'SUSPENDED' / 'CANCELLED' (grey)
     *   no_data   → 'NO DATA'    (ongoing, zero progress records — grey)
     *   overdue   → 'OVERDUE'    (past planned_end_date and progress < 100)
     *   on_track  → 'ON TRACK'   (gap ≤ 5 — green)
     *   at_risk   → 'AT RISK'    (gap > 5, or escalated via records — amber/red)
     */
    public function determine(Project $project, array $context = []): array
    {
        $status = $project->status;

        if ($status === 'Completed') {
            return $this->result('completed', 'COMPLETED', 'unknown', $project, [
                'fill_class' => 'fill-normal',
                'risk_class' => 'risk-neutral',
                'dot_color'  => 'var(--gray-400)',
                'tooltip'    => 'Completed project',
            ]);
        }

        if (in_array($status, ['Planned', 'Suspended', 'Cancelled'], true)) {
            return $this->result('neutral', strtoupper($status), 'unknown', $project, [
                'fill_class' => 'fill-normal',
                'risk_class' => 'risk-neutral',
                'dot_color'  => 'var(--gray-400)',
                'tooltip'    => 'Project is ' . strtolower($status),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ongoing Project — gap-based health
        |--------------------------------------------------------------------------
        */

        $latest = $this->latestProgress($project, $context);

        $actual = 0.0;
        if ($latest) {
            $actual = (float) ($latest->actual_progress_percent
                ?? $latest->progress_percentage
                ?? 0);
        }

        $expected = $this->expectedProgress($project, $context);
        $gap      = $expected - $actual;

        $days   = $this->daysRemaining($project);
        $now    = DateHelper::now();
        $overdue = $project->planned_end_date
            && $project->planned_end_date->lt($now)
            && $actual < 100;

        if (!$latest) {
            return $this->result('no_data', 'NO DATA', 'unknown', $project, [
                'fill_class' => 'fill-normal',
                'risk_class' => 'risk-neutral',
                'dot_color'  => 'var(--gray-400)',
                'tooltip'    => 'No progress records yet — start recording monthly inspections',
                'days_remaining' => $days,
            ]);
        }

        if ($overdue) {
            return $this->result('overdue', 'OVERDUE', 'Critical', $project, [
                'fill_class' => 'fill-danger',
                'risk_class' => 'risk-high',
                'dot_color'  => 'var(--red-600)',
                'tooltip'    => $days !== null
                    ? abs($days) . 'd overdue'
                    : 'Past planned completion date',
                'days_remaining' => $days,
            ]);
        }

        $risk = $this->classifyGap($gap);
        $level = in_array($risk, ['ahead', 'on_track']) ? 'on_track' : 'at_risk';

        /*
        |----------------------------------------------------------------------
        | Record-based escalation (worst signal wins)
        |----------------------------------------------------------------------
        |
        | A project that is on-track by schedule is still escalated to AT RISK
        | when it carries hard, record-based warning signs. AI forecast data is
        | deliberately NOT an input here — these are facts about open records.
        |
        */

        $escalated = false;
        $escalationReason = null;

        if ($level === 'on_track') {
            $reason = $this->escalationReason($context);
            if ($reason !== null) {
                $level = 'at_risk';
                $risk  = 'high_risk';
                $escalated = true;
                $escalationReason = $reason;
            }
        }

        $severity = $risk === 'high_risk';

        $base = [
            'fill_class' => $level === 'at_risk' ? 'fill-warning' : 'fill-healthy',
            'risk_class' => match ($level) {
                'on_track' => 'risk-low',
                'at_risk'  => $severity ? 'risk-high' : 'risk-medium',
                default    => 'risk-neutral',
            },
            'dot_color'  => match ($level) {
                'on_track' => 'var(--green-600)',
                'at_risk'  => $severity ? 'var(--red-600)' : 'var(--amber-600)',
                default    => 'var(--gray-400)',
            },
            'tooltip'    => $level === 'on_track'
                ? 'On track against the baseline plan'
                : ($escalated
                    ? $escalationReason
                    : 'Behind plan by ' . round($gap, 1) . '%'),
            'gap'        => $gap,
            'expected'   => $expected,
            'actual'     => $actual,
            'has_actual' => true,
            'escalated'  => $escalated,
            'escalation_reason' => $escalationReason,
            'days_remaining' => $days,
        ];

        return $this->result(
            $level,
            $level === 'on_track' ? 'ON TRACK' : 'AT RISK',
            $risk,
            $project,
            $base
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Expected Progress
     * ------------------------------------------------------------------------
     *
     * Determines the expected cumulative progress from the project's
     * Effective Baseline at DateHelper::now(). Identical to the AI presenter's
     * original lookup (now delegated here so list views and the AI module
     * share ONE implementation).
     *
     * Logic
     * -----
     * 1. Current-month progress history planned_progress_percent
     * 2. Current-month baseline schedule planned_progress_percent
     * 3. Latest progress history planned_progress_percent
     * 4. Last baseline schedule with planned_date ≤ today (else first one)
     * 5. Time-based fallback (days elapsed / total days) × 100
     *
     * Context keys consumed: current_month_history, current_month_baseline,
     * latest_progress, baseline_schedules, days_elapsed, days_total.
     */
    public function expectedProgress(Project $project, array $context = []): float
    {
        $effectiveNow = DateHelper::now();
        $currentMonthStart = $effectiveNow->copy()->startOfMonth()->format('Y-m-d');
        $currentMonthEnd   = $effectiveNow->copy()->endOfMonth()->format('Y-m-d');

        /*
        |----------------------------------------------------------------------
        | 1. Current-month progress history
        |----------------------------------------------------------------------
        */

        $currentMonthHistory = $context['current_month_history'] ?? null;
        if ($currentMonthHistory === null && !array_key_exists('current_month_history', $context)) {
            $currentMonthHistory = ProjectProgressHistory::where('project_id', $project->id)
                ->where('month_label', DateHelper::now()->format('Y-m'))
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($currentMonthHistory && $currentMonthHistory->planned_progress_percent !== null) {
            return (float) $currentMonthHistory->planned_progress_percent;
        }

        /*
        |----------------------------------------------------------------------
        | 2. Current-month baseline schedule
        |----------------------------------------------------------------------
        */

        $currentMonthBaseline = $context['current_month_baseline'] ?? null;
        if ($currentMonthBaseline === null && !array_key_exists('current_month_baseline', $context)) {
            $currentMonthBaseline = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('planned_date', '>=', $currentMonthStart)
                ->where('planned_date', '<=', $currentMonthEnd)
                ->orderBy('planned_date', 'asc')
                ->first();
        }

        if ($currentMonthBaseline) {
            return (float) $currentMonthBaseline->planned_progress_percent;
        }

        /*
        |----------------------------------------------------------------------
        | 3. Latest progress history record
        |----------------------------------------------------------------------
        */

        $latestHistory = $context['latest_progress'] ?? null;
        if ($latestHistory === null && !array_key_exists('latest_progress', $context)) {
            $latestHistory = ProjectProgressHistory::where('project_id', $project->id)
                ->orderBy('month_label', 'desc')
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($latestHistory && $latestHistory->planned_progress_percent !== null) {
            return (float) $latestHistory->planned_progress_percent;
        }

        /*
        |----------------------------------------------------------------------
        | 4. Date-filtered baseline
        |----------------------------------------------------------------------
        */

        $baselineSchedules = $context['baseline_schedules'] ?? null;
        if ($baselineSchedules === null && !array_key_exists('baseline_schedules', $context)) {
            $baselineSchedules = $project->baselineSchedules
                ?? ProjectBaselineSchedule::where('project_id', $project->id)
                    ->orderBy('planned_date', 'asc')
                    ->get();
        }

        if ($baselineSchedules && $baselineSchedules->isNotEmpty()) {
            $currentMonthTarget = $baselineSchedules
                ->filter(fn ($schedule) =>
                    $schedule->planned_date && $schedule->planned_date->lte($effectiveNow)
                )
                ->last();

            if ($currentMonthTarget) {
                return (float) $currentMonthTarget->planned_progress_percent;
            }

            return (float) $baselineSchedules->first()->planned_progress_percent;
        }

        /*
        |----------------------------------------------------------------------
        | 5. Time-based fallback
        |----------------------------------------------------------------------
        */

        $daysTotal = $context['days_total'] ?? $this->daysTotal($project);
        if ($daysTotal > 0) {
            $daysElapsed = $context['days_elapsed'] ?? $this->daysElapsed($project);
            return ($daysElapsed / $daysTotal) * 100;
        }

        return 0;
    }

    /*
    |==========================================================================
    | Private Helpers
    |==========================================================================
    */

    /**
     * Latest progress record — from context when provided, else query.
     */
    private function latestProgress(Project $project, array $context): ?ProjectProgressHistory
    {
        if (array_key_exists('latest_progress', $context)) {
            return $context['latest_progress'];
        }

        return ProjectProgressHistory::where('project_id', $project->id)
            ->orderBy('month_label', 'desc')
            ->orderBy('inspection_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Record-based escalation reason (first matching flag wins), or null.
     * Hard, facts-only signals — AI forecast is never an input here.
     */
    private function escalationReason(array $context): ?string
    {
        if (!empty($context['open_high_issues'])) {
            return 'Open High/Critical issue';
        }
        if (!empty($context['aging_issue_count'])) {
            return 'Unresolved issue aging > 30 days';
        }
        if (!empty($context['open_high_risks'])) {
            return 'Open high-priority risk';
        }
        if (!empty($context['pending_extension'])) {
            return 'Pending extension request';
        }

        return null;
    }

    /**
     * Build a normalized result array with shared keys.
     */
    private function result(
        string $level,
        string $label,
        string $risk,
        Project $project,
        array $overrides
    ): array {
        $days = $overrides['days_remaining'] ?? $this->daysRemaining($project);

        return array_merge([
            'level'        => $level,
            'label'        => $label,
            'risk'         => $risk,
            'gap'          => 0.0,
            'expected'     => 0.0,
            'actual'       => 0.0,
            'has_actual'   => false,
            'fill_class'   => 'fill-normal',
            'risk_class'   => 'risk-neutral',
            'dot_color'    => 'var(--gray-400)',
            'tooltip'      => '',
            'escalated'    => false,
            'escalation_reason' => null,
            'days_remaining' => $days,
            'deadline_class' => $this->deadlineClass($level, $days),
            'is_overdue'   => $level === 'overdue',
            'is_at_risk'   => $level === 'at_risk',
        ], $overrides);
    }

    /**
     * Deadline display class (overdue / urgent < 30d / ok).
     */
    private function deadlineClass(string $level, ?int $days): string
    {
        if ($level === 'overdue') {
            return 'overdue';
        }
        if ($days !== null && $days < 30) {
            return 'urgent';
        }
        return 'ok';
    }

    private function daysElapsed(Project $project): int
    {
        if (!$project->planned_start_date) {
            return 0;
        }
        return (int) $project->planned_start_date->diffInDays(DateHelper::now(), false);
    }

    private function daysTotal(Project $project): int
    {
        if (!$project->planned_start_date || !$project->planned_end_date) {
            return 0;
        }
        return (int) $project->planned_start_date->diffInDays($project->planned_end_date, false);
    }

    /**
     * Signed days remaining until deadline (negative = past it). null if no end date.
     */
    private function daysRemaining(Project $project): ?int
    {
        if (!$project->planned_end_date) {
            return null;
        }
        return (int) DateHelper::now()->diffInDays($project->planned_end_date, false);
    }
}