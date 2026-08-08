@php
    $layout = match(auth()->user()->role) {
        'contractor' => 'layouts.contractorlayouts',
        'staff'      => 'layouts.stafflayouts',
        default      => 'layouts.app'
    };

    // Role-aware destinations — staff must never land on admin-only URLs.
    $projectsIndex = auth()->user()->role === 'staff' ? 'staff.projects' : 'admin.projects';
    $roleLabel     = auth()->user()->role === 'staff' ? 'Staff Overview' : 'Admin Overview';
@endphp
@extends($layout)

@section('content')

{{--
╔══════════════════════════════════════════════════════════════════╗
║  DASHBOARD — REDESIGNED v2                                       ║
║  3-tab layout: Overview · Calendar · Campus Map                  ║
║                                                                  ║
║  Data is hardcoded placeholder.                                  ║
║  TO WIRE BACKEND — replace hardcoded values with:               ║
║    $metrics[], $ongoingProjects, $recentActivities               ║
║    Calendar: inject real events via $calendarEvents              ║
║    Map: replace mockProjects[] with real project coords          ║
╚══════════════════════════════════════════════════════════════════╝
--}}


<div class="db-page">

{{-- ══ HEADER ══ --}}
<div class="db-header">
    <div>
        <h2 class="db-header-title">
            <i class="bi bi-speedometer2"></i> Project Monitoring Dashboard
        </h2>
        <p class="db-header-sub">
            Real-time project overview — CSPC Project Management Unit &mdash;
            {{ $roleLabel }} &mdash;
            {{ now()->format('l, F j, Y') }}
        </p>
    </div>
    <div class="db-header-actions">
        <a href="{{ route($projectsIndex) }}" class="db-header-btn light">
            <i class="bi bi-plus-circle"></i> New Project
        </a>
        <a href="{{ route($projectsIndex) }}" class="db-header-btn outline">
            <i class="bi bi-folder2-open"></i> All Projects
        </a>
    </div>
</div>

{{-- ══ ALERT BAR ══
     Pills filter the dashboard table in-place via AJAX.
     Clicking a pill fetches ALL matching projects from the server.
     Clicking the same pill again restores the default 10 rows.
     Hidden entirely when all counts are zero.
══ --}}
@if($overdueCount > 0 || $atRiskCount > 0 || $pendingUpdateCount > 0 || $overdueInspectionCount > 0 || (auth()->user()->role === 'admin' && $pendingExtensionCount > 0))
<div class="db-alert-bar">
    <span class="db-alert-label">⚠ Needs attention</span>

    @if($overdueCount > 0)
    <button type="button" class="alert-pill danger"
            onclick="filterDashboardTable('overdue', this)">
        <i class="bi bi-x-circle-fill"></i>
        <span>Overdue Projects</span>
        <span class="alert-count">{{ $overdueCount }}</span>
    </button>
    @endif

    @if($atRiskCount > 0)
    <button type="button" class="alert-pill warning"
            onclick="filterDashboardTable('atrisk', this)">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>At Risk</span>
        <span class="alert-count">{{ $atRiskCount }}</span>
    </button>
    @endif

    @if($pendingUpdateCount > 0)
    <button type="button" class="alert-pill info"
            onclick="filterDashboardTable('pending', this)">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Pending Updates</span>
        <span class="alert-count">{{ $pendingUpdateCount }}</span>
    </button>
    @endif

    @if($overdueInspectionCount > 0)
    <button type="button" class="alert-pill warning"
            onclick="filterDashboardTable('inspection_overdue', this)">
        <i class="bi bi-calendar-x"></i>
        <span>Overdue Inspections</span>
        <span class="alert-count">{{ $overdueInspectionCount }}</span>
    </button>
    @endif

    @if(auth()->user()->role === 'admin' && $pendingExtensionCount > 0)
    <button type="button" class="alert-pill info"
            onclick="filterDashboardTable('pending_extensions', this)">
        <i class="bi bi-arrow-repeat"></i>
        <span>Extension Requests</span>
        <span class="alert-count">{{ $pendingExtensionCount }}</span>
    </button>
    @endif

</div>
@endif

{{-- ══ TABS ══ --}}
<div class="db-tab-nav">
    <button class="db-tab-btn active" data-target="tab-overview" onclick="dbSwitchTab(this)">
        <i class="bi bi-speedometer2"></i> Overview
    </button>
    <button class="db-tab-btn" data-target="tab-calendar" onclick="dbSwitchTab(this)">
        <i class="bi bi-calendar3"></i> Calendar
    </button>
    <button class="db-tab-btn" data-target="tab-map" onclick="dbSwitchTab(this)">
        <i class="bi bi-geo-alt"></i> Campus Map
    </button>
</div>


{{-- ════════════════════════════════
     TAB 1 — OVERVIEW
════════════════════════════════ --}}
<div id="tab-overview" class="db-tab-pane active">

    {{-- ── STATS CARDS ──────────────────────────────────────────────────────
        Data source: DashboardController@index
        Variables:   $totalProjects, $ongoingCount, $overdueCount,
                    $atRiskCount, $completedCount
        NOTE: "Planned" card removed — client does not handle pre-proposal projects.
        NOTE: Overdue uses planned_end_date (includes extended deadlines — correct).
        NOTE: Sub-labels are static descriptions; delta computation is future scope.
    ── --}}
    <div class="db-stats">

        {{-- Total Projects --}}
        <a href="{{ route($projectsIndex) }}" class="db-stat-card s-blue">
            <div class="db-stat-icon"><i class="bi bi-folder2"></i></div>
            <div>
                <div class="db-stat-num">{{ $totalProjects }}</div>
                <div class="db-stat-label">Total Projects</div>
                <div class="db-stat-sub neu"><i class="bi bi-dash"></i> all time</div>
            </div>
        </a>

        {{-- Ongoing --}}
        <a href="{{ route($projectsIndex, ['status' => 'Ongoing']) }}" class="db-stat-card s-amber">
            <div class="db-stat-icon"><i class="bi bi-arrow-repeat"></i></div>
            <div>
                <div class="db-stat-num">{{ $ongoingCount }}</div>
                <div class="db-stat-label">Ongoing</div>
                <div class="db-stat-sub neu"><i class="bi bi-dot"></i> active projects</div>
            </div>
        </a>

        {{-- Overdue — ongoing projects past their current planned_end_date --}}
        <a href="{{ route($projectsIndex, ['filter' => 'overdue']) }}" class="db-stat-card s-red">
            <div class="db-stat-icon"><i class="bi bi-alarm-fill"></i></div>
            <div>
                <div class="db-stat-num">{{ $overdueCount }}</div>
                <div class="db-stat-label">Overdue</div>
                <div class="db-stat-sub {{ $overdueCount > 0 ? 'neg' : 'neu' }}">
                    @if($overdueCount > 0)
                        <i class="bi bi-exclamation-circle"></i> past deadline
                    @else
                        <i class="bi bi-check-circle"></i> none overdue
                    @endif
                </div>
            </div>
        </a>

        {{-- At Risk — deadline within 14 days, DB-level count covers ALL ongoing --}}
        <a href="{{ route($projectsIndex, ['filter' => 'atrisk']) }}" class="db-stat-card s-violet">
            <div class="db-stat-icon"><i class="bi bi-exclamation-diamond-fill"></i></div>
            <div>
                <div class="db-stat-num">{{ $atRiskCount }}</div>
                <div class="db-stat-label">At Risk</div>
                <div class="db-stat-sub {{ $atRiskCount > 0 ? 'neg' : 'neu' }}">
                    @if($atRiskCount > 0)
                        <i class="bi bi-clock"></i> within 14 days
                    @else
                        <i class="bi bi-check-circle"></i> all on track
                    @endif
                </div>
            </div>
        </a>

        {{-- Completed --}}
        <a href="{{ route($projectsIndex, ['status' => 'Completed']) }}" class="db-stat-card s-green">
            <div class="db-stat-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="db-stat-num">{{ $completedCount }}</div>
                <div class="db-stat-label">Completed</div>
                <div class="db-stat-sub pos"><i class="bi bi-check2-all"></i> finished projects</div>
            </div>
        </a>

    </div>

    <div class="db-main-grid">

        {{-- Projects table --}}
        <div class="db-panel">
            <div class="db-panel-hd">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <div class="db-panel-title">
                        <i class="bi bi-list-task"></i> Ongoing Projects
                    </div>
                    {{-- Filter active badge — hidden by default, shown by JS when filter is active --}}
                    <span id="db-filter-badge" style="display:none;font-size:11px;font-weight:600;
                        padding:3px 10px;border-radius:20px;background:var(--amber-100);
                        color:var(--amber-700);border:1px solid var(--amber-300);
                        align-items:center;gap:4px">
                        <i class="bi bi-funnel-fill"></i>
                        <span id="db-filter-label">Filtered</span>
                        &nbsp;·&nbsp;
                        <span style="cursor:pointer;text-decoration:underline"
                            onclick="filterDashboardTable('default', null)">Clear</span>
                    </span>
                </div>
                <a href="{{ route($projectsIndex) }}" class="db-view-all">
                    View all <i class="bi bi-arrow-right"></i>
                </a>
            </div>
            <table class="db-proj-tbl">
                <thead>
                    <tr>
                        <th style="width:30%">Project</th>
                        <th style="width:17%">Contractor</th>
                        <th style="width:19%">This Month</th>
                        <th style="width:18%">Deadline</th>
                        <th style="width:12%">Health</th>
                        <th style="width:4%"></th>
                    </tr>
                </thead>
                <tbody>
                    @include('dashboard.partials.dashboard-table-rows', [
                        'ongoingProjects'      => $ongoingProjects,
                        'currentMonthProgress' => $currentMonthProgress,
                        'currentMonthBaseline' => $currentMonthBaseline,
                        'latestKnownProgress'  => $latestKnownProgress,
                    ])
                </tbody>
            </table>
        </div>

        {{-- Activity feed --}}
        <div class="db-panel">
            <div class="db-panel-hd">
                <div class="db-panel-title"><i class="bi bi-clock-history"></i> Recent Activity</div>
                <a href="{{ route('admin.activity.logs') }}" class="db-view-all">All logs <i class="bi bi-arrow-right"></i></a>
            </div>

            {{-- ── RECENT ACTIVITY LOOP ──────────────────────────────────────────
                Data source: DashboardController@index → $recentActivities
                Already filtered via scopeForDashboard() — staff see visibility
                'all' entries minus routine deletions (Delete Project still
                shown) and admin-housekeeping (Edit User, Password Change).
            ── --}}
            @forelse($recentActivities as $activity)
                @php
                    $action = $activity->action ?? '';
                    $actionLower = strtolower($action);

                    // Fallback description for login/logout entries that have no
                    // stored description (these only log user_id + action + timestamp).
                    $description = $activity->description;
                    if (empty($description)) {
                        $actorName = $activity->user->name ?? 'A user';
                        $description = match(true) {
                            str_contains($actionLower, 'login')  => "{$actorName} logged in",
                            str_contains($actionLower, 'logout') => "{$actorName} logged out",
                            default => $action,
                        };
                    }

                    $iconClass = match(true) {
                        str_contains($actionLower, 'delete')  => 'delete',
                        str_contains($actionLower, 'login') || str_contains($actionLower, 'logout') => 'register',
                        str_contains($actionLower, 'record monthly progress') => 'progress',
                        str_contains($actionLower, 'baseline schedule') && str_contains($actionLower, 'create') => 'progress',
                        str_contains($actionLower, 'checklist item') && str_contains($actionLower, 'update') => 'complete',
                        str_contains($actionLower, 'create') => 'create',
                        str_contains($actionLower, 'edit') || str_contains($actionLower, 'update') => 'update',
                        default => 'update',
                    };

                    $iconBs = match($iconClass) {
                        'progress' => 'bi-graph-up-arrow',
                        'complete' => 'bi-check-circle',
                        'update'   => 'bi-pencil-square',
                        'create'   => 'bi-plus-circle',
                        'delete'   => 'bi-trash',
                        'register' => 'bi-box-arrow-in-right',
                        default    => 'bi-info-circle',
                    };
                @endphp
                <div class="activity-item">
                    <div class="activity-icon {{ $iconClass }}"><i class="bi {{ $iconBs }}"></i></div>
                    <div>
                        <div class="activity-text">{{ $description }}</div>
                        <div class="activity-time">
                            {{ $activity->created_at->diffForHumans() }}
                            @if($activity->user) · {{ $activity->user->name }} @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="db-empty">
                    <i class="bi bi-info-circle"></i>
                    <div class="db-empty-title">No recent activity yet</div>
                    <div>Project updates and notifications will show up here.</div>
                </div>
            @endforelse

        </div>

    </div>
</div>{{-- /tab-overview --}}

{{-- ════════════════════════════════
     TAB 2 — CALENDAR
     Source: dashboard/partials/calendar-widget.blade.php
     Includes: tab HTML + modal HTML + calendar JS
════════════════════════════════ --}}
<div id="tab-calendar" class="db-tab-pane">
    @include('dashboard.partials.calendar-widget')
</div>

{{-- ════════════════════════════════
     TAB 3 — CAMPUS MAP
     Source: dashboard/partials/campus-map-widget.blade.php
     Includes: map HTML + map JS
════════════════════════════════ --}}
<div id="tab-map" class="db-tab-pane">
    @include('dashboard.partials.campus-map-widget')
</div>

</div>{{-- /db-page --}}


{{-- ════════════════════════════════════════════════════════
     JAVASCRIPT
     Blade-injected variables defined here first.
     Pure JS loaded from public/js/dashboard.js after.
════════════════════════════════════════════════════════ --}}
<script>
    // Blade-injected — must be defined BEFORE dashboard.js loads
    const DASHBOARD_FILTER_URL = "{{ auth()->user()->role === 'staff'
        ? route('staff.dashboard.filter')
        : route('admin.dashboard.filter') }}";
</script>
<script src="{{ asset('js/dashboard.js') }}"></script>

@endsection