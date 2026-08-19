<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectProgressHistory;
use App\Models\ProjectBaselineSchedule;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\ProjectHealthService;
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
        $baselineSchedules        = $progressData['baselineSchedules'];
        $openHighIssueCounts      = $progressData['openHighIssueCounts'];
        $agingIssueCounts         = $progressData['agingIssueCounts'];
        $openHighRiskCounts       = $progressData['openHighRiskCounts'];
        $pendingExtensionCounts   = $progressData['pendingExtensionCounts'];

        // ── STATS CARD COUNTS ─────────────────────────────────────────────
        // Separate COUNT queries covering ALL projects — not the table's 10 rows.
        $totalProjects  = Project::where('is_archived', false)->count();
        $ongoingCount   = Project::where('status', 'Ongoing')
                            ->where('is_archived', false)->count();
        $completedCount = Project::where('status', 'Completed')->count();

        // ── AT RISK COUNT (gap-based, ALL ongoing) ────────────────────────
        // Same ProjectHealthService definition as the table badges: expected
        // progress (baseline) minus actual progress, gap ≥ 10 → AT RISK.
        // Overdue projects are NOT counted here (they have their own bucket).
        $atRiskCount = count($this->atRiskProjectIds());

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

        // ── PENDING EXTENSION COUNT (DB-level, ALL ongoing) ────────────────
        // Ongoing, non-archived projects with ≥1 extension request awaiting
        // admin review. Mirrors ProjectExtension::scopePending() so the pill
        // and the review workflow stay in sync. Admin-only pill (staff cannot
        // review/approve extensions).
        $pendingExtensionCount = Project::where('status', 'Ongoing')
            ->where('is_archived', false)
            ->whereHas('extensions', fn ($q) => $q->pending())
            ->count();

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
            'baselineSchedules',
            'openHighIssueCounts',
            'agingIssueCounts',
            'openHighRiskCounts',
            'pendingExtensionCounts',
            'totalProjects',
            'ongoingCount',
            'completedCount',
            'atRiskCount',
            'overdueCount',
            'pendingUpdateCount',
            'overdueInspectionCount',
            'pendingExtensionCount',
            'recentActivities'
        ));
    }

    public function filter(Request $request)
    {
        // ── VALIDATE TYPE PARAMETER ───────────────────────────────────────
        // Whitelist check — invalid types fall back to 'default' safely.
        $type = in_array($request->get('type'), ['default', 'overdue', 'atrisk', 'pending', 'inspection_overdue', 'pending_extensions'])
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
            // All ongoing projects classified AT RISK by ProjectHealthService
            // (gap-based — same definition as the table badges and the index
            // metric card). Overdue projects are excluded (their own bucket).
            $projects = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->whereIn('id', $this->atRiskProjectIds())
                ->with(['contractor', 'category'])
                ->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
                ->orderBy('planned_end_date', 'asc')
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

        } elseif ($type === 'pending_extensions') {
            // Ongoing projects with ≥1 extension request awaiting admin review.
            // Admin-only pill — staff cannot review/approve extensions.
            $projects = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->whereHas('extensions', fn ($q) => $q->pending())
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
    // Gap-based AT RISK classification across ALL non-archived ongoing
    // projects. Shared by index() (metric card count) and filter() (the
    // "At Risk" AJAX list + deep link) so the number always matches the list.
    // Uses ProjectHealthService — the exact same definition as the table
    // badges. Overdue projects are never counted here.
    private function atRiskProjectIds(): array
    {
        $projects = Project::with(['progressHistory', 'baselineSchedules'])
            ->where('status', 'Ongoing')
            ->where('is_archived', false)
            ->get();

        $projects->loadCount([
            'issues as open_high_issue_count'  => fn ($q) => $q->unresolved()->whereIn('severity', ['High', 'Critical']),
            'issues as aging_issue_count'      => fn ($q) => $q->aging(30),
            'risks as open_high_risk_count'    => fn ($q) => $q->open()->highRisk(),
            'extensions as pending_extension_count' => fn ($q) => $q->pending(),
        ]);

        $service   = app(ProjectHealthService::class);
        $ids       = [];
        $month     = now()->format('Y-m');

        foreach ($projects as $project) {
            $history  = $project->progressHistory; // asc by inspection_date
            $latest   = $history->last();
            $monthHistory = $history->filter(fn ($r) => $r->month_label === $month)->last();

            $baselines = $project->baselineSchedules; // asc by planned_date
            $monthBaseline = $baselines->first(fn ($b) =>
                ($b->month_label && $b->month_label === $month)
                || ($b->planned_date && $b->planned_date->format('Y-m') === $month)
            );

            $health = $service->determine($project, [
                'latest_progress'        => $latest,
                'current_month_history'  => $monthHistory,
                'current_month_baseline' => $monthBaseline,
                'baseline_schedules'     => $baselines,
                'open_high_issues'       => (int) ($project->open_high_issue_count ?? 0) > 0,
                'aging_issue_count'      => (int) ($project->aging_issue_count ?? 0),
                'open_high_risks'        => (int) ($project->open_high_risk_count ?? 0) > 0,
                'pending_extension'      => (int) ($project->pending_extension_count ?? 0) > 0,
            ]);

            if ($health['level'] === 'at_risk') {
                $ids[] = $project->id;
            }
        }

        return $ids;
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

        // ALL baseline schedules (asc by planned_date), keyed by project_id.
        // Feeds ProjectHealthService::expectedProgress() branch 4 (last baseline
        // ≤ today) so the health badge is computed WITHOUT any per-row query.
        $baselineSchedules = ProjectBaselineSchedule::whereIn('project_id', $ids)
            ->orderBy('planned_date', 'asc')
            ->get()
            ->groupBy('project_id');

        // ── ESCALATION SIGNALS (grouped counts — one query each, no N+1) ─────
        // Feed ProjectHealthService's record-based escalation overlay: open
        // High/Critical issues, unresolved issues aging > 30 days, open
        // high-priority risks (score ≥ 7), and pending extension requests.
        $openHighIssueCounts = \App\Models\Issue::whereIn('project_id', $ids)
            ->unresolved()
            ->whereIn('severity', ['High', 'Critical'])
            ->selectRaw('project_id, count(*) as cnt')
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        $agingIssueCounts = \App\Models\Issue::whereIn('project_id', $ids)
            ->aging(30)
            ->selectRaw('project_id, count(*) as cnt')
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        $openHighRiskCounts = \App\Models\Risk::whereIn('project_id', $ids)
            ->open()
            ->highRisk()
            ->selectRaw('project_id, count(*) as cnt')
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        $pendingExtensionCounts = \App\Models\ProjectExtension::whereIn('project_id', $ids)
            ->pending()
            ->selectRaw('project_id, count(*) as cnt')
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        return compact(
            'currentMonthProgress',
            'latestKnownProgress',
            'currentMonthBaseline',
            'baselineSchedules',
            'openHighIssueCounts',
            'agingIssueCounts',
            'openHighRiskCounts',
            'pendingExtensionCounts'
        );
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