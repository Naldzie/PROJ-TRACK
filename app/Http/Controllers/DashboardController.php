<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectProgressHistory;
use App\Models\ProjectBaselineSchedule;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\DashboardCalendarService;
use App\Services\ProjectHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * Scope a project query to only show assigned projects for staff.
     * Admins see all projects unchanged.
     */
    private function scopeToAssigned($query): void
    {
        if (Auth::user()->role === 'staff') {
            $query->where('assigned_project_manager', Auth::id());
        }
    }

    /**
     * Numeric priority for health-based sorting (lower = higher priority).
     * Used as a tertiary sort after overdue float and deadline proximity.
     */
    private function healthSortValue(string $healthLevel): int
    {
        return match ($healthLevel) {
            'overdue'  => 0,
            'at_risk'  => 1,
            'on_track' => 2,
            'no_data'  => 3,
            default    => 4,
        };
    }
    public function index()
    {
        // ── ONGOING PROJECTS (table — capped at 10 rows) ──────────────────
        // Overdue projects float to the top, then sorted by soonest deadline.
        // Limit 10 keeps the dashboard summary concise with minimal scrolling.
        $ongoingProjects = Project::where('status', 'Ongoing')
            ->where('is_archived', false)
            ->with(['contractor', 'category', 'progressHistory', 'baselineSchedules']);
        $this->scopeToAssigned($ongoingProjects);
        $ongoingProjects = $ongoingProjects
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

        // ── HEALTH-BASED TERTIARY SORT ───────────────────────────────────
        // After overdue float + deadline, sort by health priority:
        // OVERDUE (0) > AT RISK (1) > ON TRACK (2) > NO DATA (3) > neutral (4).
        $healthService = app(ProjectHealthService::class);
        $currentMonthLabel = now()->format('Y-m');
        $ongoingProjects = $ongoingProjects->sortBy(function ($project) use ($healthService, $currentMonthLabel) {
            $history = $project->progressHistory ?? collect();
            $latest = $history->last();
            $monthHistory = $history->filter(fn ($r) => $r->month_label === $currentMonthLabel)->last();
            $baselines = $project->baselineSchedules ?? collect();
            $monthBaseline = $baselines->first(fn ($b) =>
                ($b->month_label && $b->month_label === $currentMonthLabel)
                || ($b->planned_date && $b->planned_date->format('Y-m') === $currentMonthLabel)
            );

            $health = $healthService->determine($project, [
                'latest_progress'        => $latest,
                'current_month_history'  => $monthHistory,
                'current_month_baseline' => $monthBaseline,
                'baseline_schedules'     => $baselines,
                'open_high_issues'       => (int) ($openHighIssueCounts[$project->id] ?? 0) > 0,
                'aging_issue_count'      => (int) ($agingIssueCounts[$project->id] ?? 0),
                'open_high_risks'        => (int) ($openHighRiskCounts[$project->id] ?? 0) > 0,
                'pending_extension'      => (int) ($pendingExtensionCounts[$project->id] ?? 0) > 0,
            ]);

            return $this->healthSortValue($health['level']);
        })->values();

        // ── STATS CARD COUNTS ─────────────────────────────────────────────
        // Separate COUNT queries covering projects visible to the current user.
        $baseQuery = Project::where('is_archived', false);
        $this->scopeToAssigned($baseQuery);
        $totalProjects = (clone $baseQuery)->count();
        $ongoingCount = (clone $baseQuery)->where('status', 'Ongoing')->count();
        $completedCount = (clone $baseQuery)->where('status', 'Completed')->count();
        $completedBudget = (clone $baseQuery)->where('status', 'Completed')->sum('approved_budget');

        // ── TOTAL SAVINGS (ABC - contract price) across all projects ─────
        // Only counts projects where both abc and approved_budget are set.
        $totalSavings = (clone $baseQuery)
            ->whereNotNull('abc')
            ->whereNotNull('approved_budget')
            ->selectRaw('SUM(abc - approved_budget) as total_savings')
            ->value('total_savings') ?? 0;

        // ── AT RISK COUNT (gap-based, ALL ongoing) ────────────────────────
        // Same ProjectHealthService definition as the table badges: expected
        // progress (baseline) minus actual progress, gap ≥ 10 → AT RISK.
        // Overdue projects are NOT counted here (they have their own bucket).
        $atRiskCount = count($this->atRiskProjectIds());

        // ── OVERDUE COUNT (DB-level, ALL ongoing) ─────────────────────────
        // planned_end_date is always the current effective deadline (updated by
        // extensions), so no special extension handling needed.
        $overdueCount = (clone $baseQuery)->where('status', 'Ongoing')
            ->where('planned_end_date', '<', now())
            ->count();

        // ── PENDING UPDATES COUNT (DB-level, ALL ongoing) ─────────────────
        // Ongoing projects with NO progress record for the current month.
        // Uses DB-level query — not limited to the table's 10 rows.
        $currentMonthLabel = now()->format('Y-m');

        $allOngoingIds = (clone $baseQuery)->where('status', 'Ongoing')
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
        $pendingExtensionCount = (clone $baseQuery)->where('status', 'Ongoing')
            ->whereHas('extensions', fn ($q) => $q->pending())
            ->count();

        // ── ANALYTICS DATA ──────────────────────────────────────────────
        // Additional status counts for the Status Donut chart.
        $plannedCount   = (clone $baseQuery)->where('status', 'Planned')->count();
        $suspendedCount = (clone $baseQuery)->where('status', 'Suspended')->count();
        $cancelledCount = (clone $baseQuery)->where('status', 'Cancelled')->count();

        // Budget grouped by source for the horizontal bar chart.
        // LEFT JOIN ensures projects without a budget source appear as "Unspecified".
        $budgetBySource = Project::where('is_archived', false)
            ->selectRaw("COALESCE(budget_sources.name, 'Unspecified') as source_name, SUM(approved_budget) as total_budget")
            ->leftJoin('budget_sources', 'projects.budget_source_id', '=', 'budget_sources.id')
            ->groupBy('source_name')
            ->pluck('total_budget', 'source_name');

        // Extension summary for mini stat cards + donut.
        $extensionSummary = [
            'approved'   => \App\Models\ProjectExtension::where('status', 'approved')->count(),
            'pending'    => \App\Models\ProjectExtension::where('status', 'pending')->count(),
            'total_days' => \App\Models\ProjectExtension::where('status', 'approved')->sum('duration_days'),
        ];

        // ── TIMELINE DATASET (hero Gantt strip — all active projects) ─────
        // Timeline-first admin view: every ongoing, non-archived project the
        // user can see, sorted by deadline soonest-first. No 10-row cap here
        // (the cap above is only for the Overview table). Reuses the shared
        // getProgressData() helper so planned-vs-actual tooltips and health
        // badges match the table exactly (same ProjectHealthService input).
        $timelineQuery = Project::where('status', 'Ongoing')
            ->where('is_archived', false)
            ->with(['contractor', 'category', 'progressHistory', 'baselineSchedules']);
        $this->scopeToAssigned($timelineQuery);
        $timelineProjects = $timelineQuery
            ->orderBy('planned_end_date', 'asc')
            ->get();
        $timelineProgress = $this->getProgressData($timelineProjects);

        // ── TIMELINE RANGES (quarter / year toggle targets) ───────────────
        // Quarter: calendar quarter containing today. Year: Jan 1 – Dec 31.
        // Passed as Y-m-d strings so Blade + JS share one source of truth.
        $now = now();
        $timelineQuarter = [
            'start' => $now->copy()->firstOfQuarter()->startOfDay()->toDateString(),
            'end'   => $now->copy()->lastOfQuarter()->endOfDay()->toDateString(),
        ];
        $timelineYear = [
            'start' => $now->copy()->startOfYear()->toDateString(),
            'end'   => $now->copy()->endOfYear()->toDateString(),
        ];

        // ── CALENDAR FEED (real start / due / inspection dates) ──────────
        // Replaces the widget's hardcoded demo array. Respects the same
        // staff scoping as everything else on this page.
        $calendarQuery = Project::where('is_archived', false)
            ->whereNotIn('status', ['Cancelled'])
            ->with(['contractor', 'baselineSchedules']);
        $this->scopeToAssigned($calendarQuery);
        $calendarSource = $calendarQuery->orderBy('planned_end_date', 'asc')->get();
        $calendarProjects = DashboardCalendarService::forProjects(
            $calendarSource,
            Auth::user()->role === 'staff' ? 'staff' : 'admin'
        );

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
            'completedBudget',
            'totalSavings',
            'atRiskCount',
            'overdueCount',
            'pendingUpdateCount',
            'overdueInspectionCount',
            'pendingExtensionCount',
            'plannedCount',
            'suspendedCount',
            'cancelledCount',
            'budgetBySource',
            'extensionSummary',
            'timelineProjects',
            'timelineProgress',
            'timelineQuarter',
            'timelineYear',
            'calendarProjects',
        ));
    }

    // ── DASHBOARD HERO SEARCH (autocomplete → click to open) ─────────────
    // Role-scoped project lookup for the dashboard search bar. Staff only
    // see their assigned projects (scopeToAssigned); admins see everything.
    // Returns up to 8 matches as JSON: { id, code, title, status,
    // contractor, detail_url }. The Blade dropdown navigates to detail_url
    // on click / Enter — the "directory" of the project.
    public function search(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        $query = Project::where('is_archived', false)
            ->with(['contractor:id,company_name']);
        $this->scopeToAssigned($query);

        $query->where(function ($inner) use ($q) {
            $inner->where('code', 'like', "%{$q}%")
                ->orWhere('title', 'like', "%{$q}%")
                ->orWhereHas('contractor', fn ($c) => $c->where('company_name', 'like', "%{$q}%"));
        });

        $projects = $query
            ->orderByRaw("CASE WHEN status = 'Ongoing' THEN 0 ELSE 1 END")
            ->orderBy('title', 'asc')
            ->limit(8)
            ->get(['id', 'code', 'title', 'status', 'contractor_id']);

        $isStaff = Auth::user()->role === 'staff';

        return response()->json($projects->map(fn ($p) => [
            'id'         => $p->id,
            'code'       => $p->code,
            'title'      => $p->title,
            'status'     => $p->status,
            'contractor' => $p->contractor->company_name ?? null,
            'detail_url' => $isStaff
                ? route('staff.projects.detail', $p->id)
                : route('admin.projects.detail', $p->id),
        ])->values());
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
            $query = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->where('planned_end_date', '<', now())
                ->with(['contractor', 'category', 'progressHistory', 'baselineSchedules']);
            $this->scopeToAssigned($query);
            $projects = $query->orderBy('planned_end_date', 'asc')->get();

        } elseif ($type === 'atrisk') {
            $query = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->whereIn('id', $this->atRiskProjectIds())
                ->with(['contractor', 'category', 'progressHistory', 'baselineSchedules']);
            $this->scopeToAssigned($query);
            $projects = $query->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
                ->orderBy('planned_end_date', 'asc')
                ->get();

        } elseif ($type === 'pending') {
            $projectsWithRecord = ProjectProgressHistory
                ::where('month_label', 'like', $currentMonthLabel . '%')
                ->distinct()
                ->pluck('project_id');

            $query = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->whereNotIn('id', $projectsWithRecord)
                ->with(['contractor', 'category', 'progressHistory', 'baselineSchedules']);
            $this->scopeToAssigned($query);
            $projects = $query->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
                ->orderBy('planned_end_date', 'asc')
                ->get();

        } elseif ($type === 'inspection_overdue') {
            // Ongoing projects with an uninspected baseline month past the grace
            // window — same definition as the overdue-inspection notification scan.
            $projects = $this->overdueInspectionQuery($graceDays)
                ->with(['contractor', 'category', 'progressHistory', 'baselineSchedules'])
                ->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
                ->orderBy('planned_end_date', 'asc')
                ->get();

        } elseif ($type === 'pending_extensions') {
            $query = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->whereHas('extensions', fn ($q) => $q->pending())
                ->with(['contractor', 'category', 'progressHistory', 'baselineSchedules']);
            $this->scopeToAssigned($query);
            $projects = $query->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
                ->orderBy('planned_end_date', 'asc')
                ->get();

        } else {
            $query = Project::where('status', 'Ongoing')
                ->where('is_archived', false)
                ->with(['contractor', 'category', 'progressHistory', 'baselineSchedules']);
            $this->scopeToAssigned($query);
            $projects = $query->orderByRaw("CASE WHEN planned_end_date < ? THEN 0 ELSE 1 END", [now()])
                ->orderBy('planned_end_date', 'asc')
                ->limit(10)
                ->get();
        }

        // ── FETCH PROGRESS DATA FOR THE FILTERED COLLECTION ───────────────
        // Uses the same shared helper as index() — consistent behavior.
        $progressData = $this->getProgressData($projects);

        // ── HEALTH-BASED TERTIARY SORT ───────────────────────────────────
        $healthService = app(ProjectHealthService::class);
        $currentMonthLabel = now()->format('Y-m');
        $projects = $projects->sortBy(function ($project) use ($healthService, $currentMonthLabel, $progressData) {
            $history = $project->progressHistory ?? collect();
            $latest = $history->last();
            $monthHistory = $history->filter(fn ($r) => $r->month_label === $currentMonthLabel)->last();
            $baselines = $project->baselineSchedules ?? collect();
            $monthBaseline = $baselines->first(fn ($b) =>
                ($b->month_label && $b->month_label === $currentMonthLabel)
                || ($b->planned_date && $b->planned_date->format('Y-m') === $currentMonthLabel)
            );

            $health = $healthService->determine($project, [
                'latest_progress'        => $latest,
                'current_month_history'  => $monthHistory,
                'current_month_baseline' => $monthBaseline,
                'baseline_schedules'     => $baselines,
                'open_high_issues'       => (int) ($progressData['openHighIssueCounts'][$project->id] ?? 0) > 0,
                'aging_issue_count'      => (int) ($progressData['agingIssueCounts'][$project->id] ?? 0),
                'open_high_risks'        => (int) ($progressData['openHighRiskCounts'][$project->id] ?? 0) > 0,
                'pending_extension'      => (int) ($progressData['pendingExtensionCounts'][$project->id] ?? 0) > 0,
            ]);

            return $this->healthSortValue($health['level']);
        })->values();

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
        $query = Project::with(['progressHistory', 'baselineSchedules'])
            ->where('status', 'Ongoing')
            ->where('is_archived', false);
        $this->scopeToAssigned($query);
        $projects = $query->get();

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

        $query = Project::where('status', 'Ongoing')
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
        $this->scopeToAssigned($query);

        return $query;
    }
}