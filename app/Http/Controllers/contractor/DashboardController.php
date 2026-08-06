<?php

namespace App\Http\Controllers\Contractor;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use Illuminate\Support\Facades\Auth;

/**
 * Contractor dashboard (Phase A of the UI polish).
 *
 * Feeds the contractor/dashboard view with real data scoped to the
 * logged-in contractor's own projects — replaces the previous fully
 * hardcoded placeholder layout.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $contractor = $user->contractor;

        // ── ASSIGNED PROJECTS (all statuses, archived excluded) ─────────────
        // A contractor's projects are those where project.contractor_id points
        // to the contractor record linked to this user (see ProjectController).
        $projects = $contractor
            ? $contractor->projects()->where('is_archived', false)->with('category')->get()
            : collect();

        // ── STAT CARD COUNTS ─────────────────────────────────────────────────
        $totalProjects  = $projects->count();
        $completedCount = $projects->where('status', 'Completed')->count();
        $ongoingCount   = $projects->where('status', 'Ongoing')->count();
        $plannedCount   = $projects->where('status', 'Planned')->count();

        $completedPct = $totalProjects ? round($completedCount / $totalProjects * 100) : 0;
        $ongoingPct   = $totalProjects ? round($ongoingCount / $totalProjects * 100) : 0;
        $plannedPct   = $totalProjects ? round($plannedCount / $totalProjects * 100) : 0;

        // ── LATEST KNOWN PROGRESS (preloaded to avoid N+1) ───────────────────
        // Mirrors DashboardController::getProgressData() — latest actual from
        // any month, keyed by project_id.
        $projectIds = $projects->pluck('id');

        $latestKnownProgress = ProjectProgressHistory::whereIn('project_id', $projectIds)
            ->whereNotNull('actual_progress_percent')
            ->orderBy('inspection_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('project_id')
            ->map(fn ($records) => $records->first());

        // ── PROJECTS TABLE (ongoing only — mirrors the admin/staff dashboard) ──
        // Overdue projects float to the top, then sorted by soonest deadline.
        $tableProjects = $projects
            ->where('status', 'Ongoing')
            ->sortBy(fn ($p) => $p->planned_end_date?->timestamp ?? PHP_INT_MAX)
            ->values()
            ->sortBy(fn ($p) => $p->planned_end_date && $p->planned_end_date->lt(now()) ? 0 : 1)
            ->values();

        // ── THIS MONTH: PLANNED vs ACTUAL (for the projects table) ─────────────
        // Mirrors DashboardController::getProgressData() so the "This Month"
        // column behaves exactly like the admin/staff dashboard.
        $currentMonthLabel = now()->format('Y-m');

        $currentMonthProgress = ProjectProgressHistory::whereIn('project_id', $projectIds)
            ->where('month_label', 'like', $currentMonthLabel . '%')
            ->get()
            ->sortByDesc('inspection_date')
            ->keyBy('project_id');

        $currentMonthBaseline = ProjectBaselineSchedule::whereIn('project_id', $projectIds)
            ->where('month_label', 'like', $currentMonthLabel . '%')
            ->get()
            ->keyBy('project_id');

        // ── BUDGET ───────────────────────────────────────────────────────────
        $totalBudget = $projects->sum('approved_budget');
        $budgetProjects = $projects->sortByDesc('approved_budget');

        // ── UPCOMING DEADLINES ───────────────────────────────────────────────
        // Non-completed/cancelled projects with a deadline, most urgent first.
        // Overdue projects float to the top (mirrors the admin dashboard feed).
        $deadlineProjects = $projects
            ->reject(fn ($p) => in_array($p->status, ['Completed', 'Cancelled']))
            ->filter(fn ($p) => $p->planned_end_date !== null)
            ->sortBy('planned_end_date')
            ->values()
            ->sortBy(fn ($p) => $p->planned_end_date->lt(now()) ? 0 : 1)
            ->take(5);

        // ── RECENT ACTIVITY ──────────────────────────────────────────────────
        // Latest activity log entries scoped to this contractor's projects.
        // Contractors only ever see visibility='all' entries (scope visibleTo).
        $recentActivities = ActivityLog::with(['user', 'project'])
            ->whereIn('project_id', $projectIds)
            ->visibleTo('contractor')
            ->latest()
            ->limit(6)
            ->get();

        return view('contractor.dashboard', compact(
            'projects',
            'totalProjects',
            'completedCount',
            'ongoingCount',
            'plannedCount',
            'completedPct',
            'ongoingPct',
            'plannedPct',
            'tableProjects',
            'latestKnownProgress',
            'currentMonthProgress',
            'currentMonthBaseline',
            'totalBudget',
            'budgetProjects',
            'deadlineProjects',
            'recentActivities'
        ));
    }
}
