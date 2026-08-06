<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectProgressHistory;
use App\Models\ProjectBaselineSchedule;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        // ── ONGOING PROJECTS (table — capped at 10 rows) ──────────────────
        // Overdue projects float to the top, then sorted by soonest deadline.
        // Limit 10 keeps the dashboard summary concise with minimal scrolling.
        $ongoingProjects = Project::where('status', 'Ongoing')
            ->where('is_archived', false)
            ->with(['contractor', 'category'])
            ->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
            ->orderBy('planned_end_date', 'asc')
            ->limit(10)
            ->get();

        // ── PROGRESS DATA (via shared helper) ─────────────────────────────
        // Extracts 3 progress queries into a reusable helper.
        // Same helper is used by filter() to keep logic consistent.
        $progressData             = $this->getProgressData($ongoingProjects);
        $currentMonthProgress     = $progressData['currentMonthProgress'];
        $currentMonthBaseline     = $progressData['currentMonthBaseline'];
        $latestKnownProgress      = $progressData['latestKnownProgress'];

        // ── STATS CARD COUNTS ─────────────────────────────────────────────
        // Separate COUNT queries covering ALL projects — not the table's 10 rows.
        $totalProjects  = Project::where('is_archived', false)->count();
        $ongoingCount   = Project::where('status', 'Ongoing')
                            ->where('is_archived', false)->count();
        $completedCount = Project::where('status', 'Completed')->count();

        // ── AT RISK COUNT (DB-level, ALL ongoing) ─────────────────────────
        // Mirrors isAtRisk() deadline condition: planned_end_date within 14 days.
        // Slight overcount possible (includes >=90% progress near deadline) —
        // acceptable, safer to overcount than undercount.
        $atRiskCount = Project::where('status', 'Ongoing')
            ->where('is_archived', false)
            ->whereBetween('planned_end_date', [now(), now()->addDays(14)])
            ->count();

        // ── OVERDUE COUNT (DB-level, ALL ongoing) ─────────────────────────
        // planned_end_date is always the current effective deadline (updated by
        // extensions), so no special extension handling needed.
        $overdueCount = Project::where('status', 'Ongoing')
            ->where('is_archived', false)
            ->where('planned_end_date', '<', now())
            ->count();

        // ── PENDING UPDATES COUNT (DB-level, ALL ongoing) ─────────────────
        // Ongoing projects with NO progress record for the current month.
        // Uses DB-level query — not limited to the table's 10 rows.
        $currentMonthLabel = now()->format('Y-m');

        $allOngoingIds = Project::where('status', 'Ongoing')
            ->where('is_archived', false)
            ->pluck('id');

        $projectsWithThisMonthRecord = ProjectProgressHistory
            ::whereIn('project_id', $allOngoingIds)
            ->where('month_label', 'like', $currentMonthLabel . '%')
            ->distinct()
            ->pluck('project_id');

        $pendingUpdateCount = $allOngoingIds->diff($projectsWithThisMonthRecord)->count();

        // ── OVERDUE INSPECTION COUNT (DB-level, ALL ongoing) ───────────────
        // Ongoing projects with ≥1 baseline month whose planned target date is
        // past the grace window and has no recorded progress for that month.
        // Mirrors SendNotifications@sendOverdueInspectionReminders exactly so
        // the dashboard pill and the notification alerts stay in sync.
        $graceDays = (int) Setting::getValue('notification_overdue_grace_days', 7);
        $overdueInspectionCount = $this->overdueInspectionQuery($graceDays)->count();

        // ── RECENT ACTIVITY FEED ──────────────────────────────────────────────
        // Latest 6 activity log entries. Admins see everything. Staff see
        // visibility='all' entries minus routine deletions and admin-housekeeping
        // (Edit User, Password Change); Delete Project is still shown. Deletions
        // stay visible on the project-scoped Overview feed.
        $recentActivities = ActivityLog::with('user')
            ->forDashboard(auth()->user()->role)
            ->latest()
            ->limit(6)
            ->get();

        return view('admin.dashboard', compact(
            'ongoingProjects',
            'currentMonthProgress',
            'currentMonthBaseline',
            'latestKnownProgress',
            'totalProjects',
            'ongoingCount',
            'completedCount',
            'atRiskCount',
            'overdueCount',
            'pendingUpdateCount',
            'overdueInspectionCount',
            'recentActivities'
        ));
    }

    public function filter(Request $request)
    {
        // ── VALIDATE TYPE PARAMETER ───────────────────────────────────────
        // Whitelist check — invalid types fall back to 'default' safely.
        $type = in_array($request->get('type'), ['default', 'overdue', 'atrisk', 'pending', 'inspection_overdue'])
            ? $request->get('type')
            : 'default';

        $currentMonthLabel = now()->format('Y-m');
        $graceDays = (int) Setting::getValue('notification_overdue_grace_days', 7);

        // ── BUILD PROJECT COLLECTION BASED ON FILTER TYPE ─────────────────
        if ($type === 'overdue') {
            // All ongoing projects past their current effective deadline.
            // planned_end_date already reflects any approved extensions.
            $projects = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->where('planned_end_date', '<', now())
                ->with(['contractor', 'category'])
                ->orderBy('planned_end_date', 'asc') // most overdue first
                ->get();

        } elseif ($type === 'atrisk') {
            // All ongoing projects with deadline within the next 14 days.
            $projects = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->whereBetween('planned_end_date', [now(), now()->addDays(14)])
                ->with(['contractor', 'category'])
                ->orderBy('planned_end_date', 'asc') // soonest deadline first
                ->get();

        } elseif ($type === 'pending') {
            // All ongoing projects with NO progress record for the current month.
            // distinct() prevents duplicate project IDs from multiple records.
            $projectsWithRecord = ProjectProgressHistory
                ::where('month_label', 'like', $currentMonthLabel . '%')
                ->distinct()
                ->pluck('project_id');

            $projects = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->whereNotIn('id', $projectsWithRecord)
                ->with(['contractor', 'category'])
                ->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
                ->orderBy('planned_end_date', 'asc')
                ->get();

        } elseif ($type === 'inspection_overdue') {
            // Ongoing projects with an uninspected baseline month past the grace
            // window — same definition as the overdue-inspection notification scan.
            $projects = $this->overdueInspectionQuery($graceDays)
                ->with(['contractor', 'category'])
                ->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
                ->orderBy('planned_end_date', 'asc')
                ->get();

        } else {
            // 'default' — restore original 10-row view (also used for clear).
            $projects = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->with(['contractor', 'category'])
                ->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
                ->orderBy('planned_end_date', 'asc')
                ->limit(10)
                ->get();
        }

        // ── FETCH PROGRESS DATA FOR THE FILTERED COLLECTION ───────────────
        // Uses the same shared helper as index() — consistent behavior.
        $progressData = $this->getProgressData($projects);

        // ── RETURN PARTIAL VIEW (HTML rows only, no layout) ───────────────
        return view('dashboard.partials.dashboard-table-rows', array_merge(
            ['ongoingProjects' => $projects],
            $progressData
        ));
    }

    // ── PRIVATE HELPER ────────────────────────────────────────────────────
    // Fetches the 3 progress data collections needed by both index() and filter().
    // Keeps logic in one place — no duplication between the two methods.
    private function getProgressData($projects): array
    {
        $currentMonthLabel = now()->format('Y-m');
        $ids = $projects->pluck('id');

        // Current month actual progress — most recent inspection wins per project
        $currentMonthProgress = ProjectProgressHistory::whereIn('project_id', $ids)
            ->where('month_label', 'like', $currentMonthLabel . '%')
            ->get()
            ->sortByDesc('inspection_date')
            ->keyBy('project_id');

        // Latest known actual progress from ANY month — fallback when no current month record
        $latestKnownProgress = ProjectProgressHistory::whereIn('project_id', $ids)
            ->whereNotNull('actual_progress_percent')
            ->orderBy('inspection_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('project_id')
            ->map(fn($records) => $records->first());

        // Current month baseline (planned %) — uses LIKE for month_label format safety
        $currentMonthBaseline = ProjectBaselineSchedule::whereIn('project_id', $ids)
            ->where('month_label', 'like', $currentMonthLabel . '%')
            ->get()
            ->keyBy('project_id');

        return compact('currentMonthProgress', 'latestKnownProgress', 'currentMonthBaseline');
    }

    // ── PRIVATE HELPER ────────────────────────────────────────────────────
    // Base query for the "Overdue Inspections" signal: ongoing, non-archived
    // projects that have ≥1 baseline month whose planned target date is past
    // the grace window with no recorded progress for that month_label. Used by
    // both index() (count badge) and filter() (AJAX list) so they never drift.
    private function overdueInspectionQuery(int $graceDays)
    {
        $cutoff = now()->subDays($graceDays);

        return Project::where('status', 'Ongoing')
            ->where('is_archived', false)
            ->whereHas('baselineSchedules', function ($q) use ($cutoff) {
                $q->whereNotNull('month_label')
                    ->where('planned_date', '<', $cutoff)
                    ->whereNotIn('month_label', function ($sub) {
                        $sub->select('month_label')
                            ->from('project_progress_history')
                            ->whereColumn('project_id', 'project_baseline_schedules.project_id')
                            ->whereNotNull('month_label');
                    });
            });
    }
}