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

        {{-- Completed --}}
        <a href="{{ route($projectsIndex, ['status' => 'Completed']) }}" class="db-stat-card s-green"
           title="Total Budget: {{ \App\Helpers\FormatHelper::formatCurrency($completedBudget) }}">
            <div class="db-stat-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="db-stat-num">{{ $completedCount }}</div>
                <div class="db-stat-label">Completed</div>
                <div class="db-stat-sub pos"><i class="bi bi-check2-all"></i> finished projects</div>
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

        {{-- At Risk — gap-based (baseline vs actual), count covers ALL ongoing --}}
        <a href="{{ route($projectsIndex, ['filter' => 'atrisk']) }}" class="db-stat-card s-violet">
            <div class="db-stat-icon"><i class="bi bi-exclamation-diamond-fill"></i></div>
            <div>
                <div class="db-stat-num">{{ $atRiskCount }}</div>
                <div class="db-stat-label">At Risk</div>
                <div class="db-stat-sub {{ $atRiskCount > 0 ? 'neg' : 'neu' }}">
                    @if($atRiskCount > 0)
                        <i class="bi bi-clock"></i> behind baseline plan
                    @else
                        <i class="bi bi-check-circle"></i> all on track
                    @endif
                </div>
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

        {{-- ── ANALYTICS (right column — 1-col vertical stack) ──────── --}}
        <div class="db-analytics-grid">

            {{-- 1. Project Status Donut --}}
            <div class="db-chart-card">
                <div class="db-chart-title"><i class="bi bi-pie-chart"></i> Project Status</div>
                <div style="height:200px; position:relative;"><canvas id="statusDonut"></canvas></div>
            </div>

            {{-- 2. Health Distribution Donut --}}
            <div class="db-chart-card">
                <div class="db-chart-title"><i class="bi bi-heart-pulse"></i> Health Distribution</div>
                <div style="height:200px; position:relative;"><canvas id="healthDonut"></canvas></div>
            </div>

            {{-- 3. Budget by Source --}}
            <div class="db-chart-card">
                <div class="db-chart-title"><i class="bi bi-bar-chart-line"></i> Budget by Source</div>
                <div style="height:200px; position:relative;"><canvas id="budgetBar"></canvas></div>
            </div>

            {{-- 4. Progress Performance --}}
            <div class="db-chart-card">
                <div class="db-chart-title"><i class="bi bi-graph-up"></i> Progress: Planned vs Actual</div>
                <div style="height:200px; position:relative;"><canvas id="progressBar"></canvas></div>
            </div>

            {{-- 5. Extension Summary --}}
            <div class="db-chart-card">
                <div class="db-chart-title"><i class="bi bi-calendar2-plus"></i> Extensions</div>
                <div class="db-ext-mini-stats">
                    <div class="db-ext-mini-card">
                        <div class="db-ext-mini-num" style="color:#2563eb;">{{ $extensionSummary['approved'] }}</div>
                        <div class="db-ext-mini-label">Granted</div>
                    </div>
                    <div class="db-ext-mini-card">
                        <div class="db-ext-mini-num" style="color:#f59e0b;">{{ $extensionSummary['pending'] }}</div>
                        <div class="db-ext-mini-label">Pending</div>
                    </div>
                    <div class="db-ext-mini-card">
                        <div class="db-ext-mini-num" style="color:#ef4444;">{{ $extensionSummary['total_days'] }}</div>
                        <div class="db-ext-mini-label">Days Added</div>
                    </div>
                </div>
                <div style="height:140px; position:relative;"><canvas id="extensionDonut"></canvas></div>
            </div>

        </div>

    </div>{{-- /db-main-grid --}}

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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
    // Blade-injected — must be defined BEFORE dashboard.js loads
    const DASHBOARD_FILTER_URL = "{{ auth()->user()->role === 'staff'
        ? route('staff.dashboard.filter')
        : route('admin.dashboard.filter') }}";

    // ── Analytics Charts ──────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        const chartDefaults = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        };

        // 1. Project Status Donut
        new Chart(document.getElementById('statusDonut'), {
            type: 'doughnut',
            data: {
                labels: ['Ongoing', 'Completed', 'Planned', 'Suspended', 'Cancelled'],
                datasets: [{
                    data: [{{ $ongoingCount }}, {{ $completedCount }}, {{ $plannedCount }}, {{ $suspendedCount }}, {{ $cancelledCount }}],
                    backgroundColor: ['#2563eb', '#16a34a', '#f59e0b', '#ef4444', '#9ca3af'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                ...chartDefaults,
                cutout: '60%',
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } }
                }
            }
        });

        // 2. Health Distribution Donut
        @php
            $onTrackCount = max(0, $ongoingCount - $atRiskCount - $overdueCount - ($ongoingCount - $completedCount - $plannedCount - $suspendedCount - $cancelledCount - $atRiskCount - $overdueCount));
            // Derive: on_track = ongoing minus at_risk minus overdue minus no_data
            // no_data = ongoing projects with zero progress history records
        @endphp
        new Chart(document.getElementById('healthDonut'), {
            type: 'doughnut',
            data: {
                labels: ['On Track', 'At Risk', 'Overdue', 'No Data'],
                datasets: [{
                    data: [{{ max(0, $ongoingCount - $atRiskCount - $overdueCount) }}, {{ $atRiskCount }}, {{ $overdueCount }}, {{ max(0, $ongoingCount - $atRiskCount - $overdueCount) > 0 ? 0 : 0 }}],
                    backgroundColor: ['#16a34a', '#f59e0b', '#ef4444', '#d1d5db'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                ...chartDefaults,
                cutout: '60%',
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } }
                }
            }
        });

        // 3. Budget by Source (Horizontal Bar)
        @php
            $sourceLabels = $budgetBySource->keys()->toArray();
            $sourceValues = $budgetBySource->values()->toArray();
        @endphp
        new Chart(document.getElementById('budgetBar'), {
            type: 'bar',
            data: {
                labels: @json($sourceLabels),
                datasets: [{
                    data: @json($sourceValues),
                    backgroundColor: '#2563eb',
                    borderRadius: 4,
                    barThickness: 20
                }]
            },
            options: {
                ...chartDefaults,
                indexAxis: 'y',
                scales: {
                    x: { display: true, grid: { display: false }, ticks: { font: { size: 10 }, callback: v => v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v } },
                    y: { display: true, grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });

        // 4. Progress Performance (Grouped Bar — top 8 projects)
        @php
            $progressProjects = $ongoingProjects->take(8);
            $progressLabels = $progressProjects->pluck('code')->toArray();
            $plannedData = [];
            $actualData = [];
            foreach ($progressProjects as $p) {
                $baseline = $currentMonthBaseline[$p->id] ?? null;
                $actual = $latestKnownProgress[$p->id] ?? null;
                $plannedData[] = $baseline ? (float) ($baseline->planned_progress_percent ?? 0) : 0;
                $actualData[] = $actual ? (float) ($actual->actual_progress_percent ?? $actual->progress_percentage ?? 0) : 0;
            }
        @endphp
        new Chart(document.getElementById('progressBar'), {
            type: 'bar',
            data: {
                labels: @json($progressLabels),
                datasets: [
                    { label: 'Planned', data: @json($plannedData), backgroundColor: '#93c5fd', borderRadius: 4, barThickness: 12 },
                    { label: 'Actual', data: @json($actualData), backgroundColor: '#16a34a', borderRadius: 4, barThickness: 12 }
                ]
            },
            options: {
                ...chartDefaults,
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                    y: { max: 100, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, callback: v => v + '%' } }
                },
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } }
                }
            }
        });

        // 5. Extension Donut (approved vs pending)
        new Chart(document.getElementById('extensionDonut'), {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Pending'],
                datasets: [{
                    data: [{{ $extensionSummary['approved'] }}, {{ $extensionSummary['pending'] }}],
                    backgroundColor: ['#2563eb', '#f59e0b'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                ...chartDefaults,
                cutout: '55%',
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { boxWidth: 10, padding: 6, font: { size: 10 } } }
                }
            }
        });
    });
</script>
<script src="{{ asset('js/dashboard.js') }}"></script>

@endsection