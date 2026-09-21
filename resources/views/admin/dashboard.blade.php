@php
    $layout = match(auth()->user()->role) {
        'contractor' => 'layouts.contractorlayouts',
        'staff'      => 'layouts.stafflayouts',
        default      => 'layouts.app'
    };

    // Role-aware destinations — staff must never land on admin-only URLs.
    $projectsIndex = auth()->user()->role === 'staff' ? 'staff.projects' : 'admin.projects';
    $roleLabel     = auth()->user()->role === 'staff' ? 'Staff Overview' : 'Admin Overview';
    // Timeline-first for admins (full-picture triage); staff keep Overview first.
    $timelineFirst = auth()->user()->role === 'admin';

    // Hero identity — prefers the role profile's full name, falls back to email.
    $heroName = auth()->user()->admin->full_name
        ?? auth()->user()->staff->full_name
        ?? auth()->user()->contractor->full_name
        ?? auth()->user()->email;
@endphp
@extends($layout)

@section('content')

{{--
╔══════════════════════════════════════════════════════════════════╗
║  DASHBOARD — OPTION #3 REDESIGN (enterprise, spacious, minimal)  ║
║  Scope: visual presentation of the content area ONLY.            ║
║  Untouched: global layout/sidebar/topbar/auth, routes,           ║
║  controllers, models, partials, Chart.js data, AJAX filtering    ║
║  (filterDashboardTable), tab switching (dbSwitchTab), calendar   ║
║  + campus map widgets and their JS.                              ║
║  Preserved IDs/classes: .db-tab-btn/.db-tab-pane, .alert-pill,   ║
║  .db-proj-tbl tbody, #db-filter-badge/#db-filter-label,          ║
║  #statusDonut #healthDonut #budgetBar #progressBar               ║
║  #extensionDonut, DASHBOARD_FILTER_URL.                          ║
╚══════════════════════════════════════════════════════════════════╝
--}}

<div class="db-page">

{{-- ══ HERO / WELCOME BANNER ══
     CSPC_nabua_WTR.png is a decorative backdrop (aria-hidden); the welcome
     copy, role badge, date, search and route buttons sit above it.  --}}
<header class="db-hero">
    <img src="{{ asset('images/CSPC_nabua_WTR.png') }}" alt="" aria-hidden="true" class="db-hero-bg">
    <div class="db-hero-overlay" aria-hidden="true"></div>
    <div class="db-hero-inner">
        <div class="db-hero-left">
            <div class="db-hero-icon"><i class="bi bi-kanban"></i></div>
            <div style="min-width:0">
                <p class="db-hero-eyebrow">Welcome back,</p>
                <h2 class="db-hero-title">{{ $heroName }}</h2>
                <p class="db-hero-sub">Project Monitoring Dashboard &mdash; CSPC Project Management Unit</p>
                <div class="db-hero-meta">
                    <span class="db-role-badge"><i class="bi bi-person-badge"></i> {{ $roleLabel }}</span>
                    <span class="db-hero-date"><i class="bi bi-calendar3"></i> {{ now()->format('l, F j, Y') }}</span>
                </div>
            </div>
        </div>
        <div class="db-hero-right">
            {{-- Hero search: filters the table in-place AND offers click-to-open
                 suggestions (dropdown navigates to the project detail page). --}}
            <div class="db-hero-search-wrap">
                <label class="db-hero-search" for="db-hero-search-input">
                    <i class="bi bi-search"></i>
                    <input id="db-hero-search-input" type="search"
                           placeholder="Search projects…"
                           oninput="dbSearchProjects(this.value); dbAutocompleteSearch(this.value)"
                           onkeydown="dbAutocompleteKey(event)"
                           onfocus="dbAutocompleteSearch(this.value)"
                           autocomplete="off" role="combobox" aria-expanded="false"
                           aria-controls="db-search-results" aria-autocomplete="list">
                </label>
                <div id="db-search-results" class="db-search-results" hidden></div>
            </div>
            <div class="db-hero-actions">
                <a href="{{ route($projectsIndex) }}" class="db-header-btn light">
                    <i class="bi bi-plus-circle"></i> New Project
                </a>
                <a href="{{ route($projectsIndex) }}" class="db-header-btn outline">
                    <i class="bi bi-folder2-open"></i> All Projects
                </a>
            </div>
        </div>
    </div>
</header>

{{-- ══ GOOGLE CALENDAR CONNECT BANNER ══
     Project start / deadline / inspection dates auto-sync to the Google
     Calendars of connected users (all admins + assigned staff + the
     contractor). Shown only until this user connects. --}}
@if(!auth()->user()->hasGoogleCalendar())
<div class="db-alert-bar" style="border:1px dashed var(--blue-300,#93c5fd);background:var(--blue-50,#eff6ff);">
    <span class="db-alert-label" style="color:var(--brand-dark,#1D4ED8);"><i class="bi bi-calendar-plus"></i> Google Calendar</span>
    <span style="font-size:12px;color:#334155;">Project start, deadline &amp; inspection dates sync automatically once connected.</span>
    <a href="{{ route('calendar.google.connect') }}" class="alert-pill info" style="text-decoration:none;">
        <i class="bi bi-google"></i><span>Connect my calendar</span>
    </a>
</div>
@endif

{{-- ══ ALERT BAR ══
     Pills filter the dashboard table in-place via AJAX.
     Clicking a pill fetches ALL matching projects from the server.
     Clicking the same pill again restores the default 10 rows.
     Hidden entirely when all counts are zero.
     NOTE: the global notification bell + profile controls live in the
     layout topbar and are intentionally not duplicated here.
══ --}}
@if($overdueCount > 0 || $atRiskCount > 0 || $pendingUpdateCount > 0 || $overdueInspectionCount > 0 || (auth()->user()->role === 'admin' && $pendingExtensionCount > 0))
<div class="db-alert-bar">
    <span class="db-alert-label"><i class="bi bi-exclamation-triangle"></i> Needs attention</span>

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
    <button type="button" class="alert-pill yellow"
            onclick="filterDashboardTable('inspection_overdue', this)">
        <i class="bi bi-calendar-x"></i>
        <span>Overdue Inspections</span>
        <span class="alert-count">{{ $overdueInspectionCount }}</span>
    </button>
    @endif

    @if(auth()->user()->role === 'admin' && $pendingExtensionCount > 0)
    <button type="button" class="alert-pill purple"
            onclick="filterDashboardTable('pending_extensions', this)">
        <i class="bi bi-arrow-repeat"></i>
        <span>Extension Requests</span>
        <span class="alert-count">{{ $pendingExtensionCount }}</span>
    </button>
    @endif

</div>
@endif

{{-- ══ TABS ══
     Same tab-switch logic (dbSwitchTab) and data-target panes as
     before — only the visual style changed to a segmented control.
══ --}}
<div class="db-tab-nav" role="tablist">
    <button class="db-tab-btn {{ $timelineFirst ? 'active' : '' }}" data-target="tab-timeline" onclick="dbSwitchTab(this)">
        <i class="bi bi-bar-chart-steps"></i> Timeline
    </button>
    <button class="db-tab-btn {{ $timelineFirst ? '' : 'active' }}" data-target="tab-overview" onclick="dbSwitchTab(this)">
        <i class="bi bi-speedometer2"></i> Overview
    </button>
    <button class="db-tab-btn" data-target="tab-calendar" onclick="dbSwitchTab(this)">
        <i class="bi bi-calendar3"></i> Calendar
    </button>
    {{-- Campus Map tab HIDDEN for now (2026-09-21, per user request) — restore by uncommenting this button + the tab-map pane below.
    <button class="db-tab-btn" data-target="tab-map" onclick="dbSwitchTab(this)">
        <i class="bi bi-geo-alt"></i> Campus Map
    </button>
    --}}
</div>


{{-- ════════════════════════════════
     TAB 1 — TIMELINE (deadline-first hero)
     Source: dashboard/partials/timeline-widget.blade.php
     Data: $timelineProjects + $timelineProgress (DashboardController@index)
════════════════════════════════ --}}
<div id="tab-timeline" class="db-tab-pane {{ $timelineFirst ? 'active' : '' }}">
    <div class="db-timeline-shell">
        @include('dashboard.partials.timeline-widget')
    </div>
</div>

{{-- ════════════════════════════════
     TAB 2 — OVERVIEW
════════════════════════════════ --}}
<div id="tab-overview" class="db-tab-pane {{ $timelineFirst ? '' : 'active' }}">

    {{-- ── STATS CARDS ──────────────────────────────────────────────────────
        Data source: DashboardController@index
        Variables:   $totalProjects, $ongoingCount, $overdueCount,
                    $atRiskCount, $completedCount, $totalSavings
    ── --}}
    <div class="db-stats">

        {{-- Total Projects --}}
        <a href="{{ route($projectsIndex) }}" class="db-stat-card s-blue">
            <div class="db-stat-icon"><i class="bi bi-folder2"></i></div>
            <div style="min-width:0">
                <div class="db-stat-num">{{ $totalProjects }}</div>
                <div class="db-stat-label">Total Projects</div>
                <div class="db-stat-desc">All projects on record</div>
                <div class="db-stat-sub neu"><i class="bi bi-dash"></i> all time</div>
            </div>
        </a>

        {{-- Completed --}}
        <a href="{{ route($projectsIndex, ['status' => 'Completed']) }}" class="db-stat-card s-green"
           title="Total Budget: {{ \App\Helpers\FormatHelper::formatCurrency($completedBudget) }}">
            <div class="db-stat-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div style="min-width:0">
                <div class="db-stat-num">{{ $completedCount }}</div>
                <div class="db-stat-label">Completed</div>
                <div class="db-stat-desc">Finished and turned over</div>
                <div class="db-stat-sub pos"><i class="bi bi-check2-all"></i> finished projects</div>
            </div>
        </a>

        {{-- Ongoing --}}
        <a href="{{ route($projectsIndex, ['status' => 'Ongoing']) }}" class="db-stat-card s-amber">
            <div class="db-stat-icon"><i class="bi bi-arrow-repeat"></i></div>
            <div style="min-width:0">
                <div class="db-stat-num">{{ $ongoingCount }}</div>
                <div class="db-stat-label">Ongoing</div>
                <div class="db-stat-desc">Actively under construction</div>
                <div class="db-stat-sub neu"><i class="bi bi-dot"></i> active projects</div>
            </div>
        </a>

        {{-- Overdue — ongoing projects past their current planned_end_date --}}
        <a href="{{ route($projectsIndex, ['filter' => 'overdue']) }}" class="db-stat-card s-red">
            <div class="db-stat-icon"><i class="bi bi-alarm-fill"></i></div>
            <div style="min-width:0">
                <div class="db-stat-num">{{ $overdueCount }}</div>
                <div class="db-stat-label">Overdue</div>
                <div class="db-stat-desc">Past the current deadline</div>
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
            <div style="min-width:0">
                <div class="db-stat-num">{{ $atRiskCount }}</div>
                <div class="db-stat-label">At Risk</div>
                <div class="db-stat-desc">Falling behind baseline</div>
                <div class="db-stat-sub {{ $atRiskCount > 0 ? 'neg' : 'neu' }}">
                    @if($atRiskCount > 0)
                        <i class="bi bi-clock"></i> behind baseline plan
                    @else
                        <i class="bi bi-check-circle"></i> all on track
                    @endif
                </div>
            </div>
        </a>

        {{-- Total Savings --}}
        <div class="db-stat-card s-teal" title="Total government savings across all projects (ABC − Contract Price)">
            <div class="db-stat-icon"><i class="bi bi-piggy-bank-fill"></i></div>
            <div style="min-width:0">
                <div class="db-stat-num">{{ \App\Helpers\FormatHelper::formatCurrency($totalSavings) }}</div>
                <div class="db-stat-label">Total Savings</div>
                <div class="db-stat-desc">ABC vs contract price</div>
                <div class="db-stat-sub {{ $totalSavings >= 0 ? 'pos' : 'neg' }}">
                    @if($totalSavings >= 0)
                        <i class="bi bi-arrow-down-right"></i> from ABC
                    @else
                        <i class="bi bi-arrow-up-right"></i> exceeded ABC
                    @endif
                </div>
            </div>
        </div>

    </div>

    <div class="db-main-grid">

        {{-- Projects table --}}
        <div class="db-panel">
            <div class="db-panel-hd">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-width:0">
                    <div>
                        <div class="db-panel-title">
                            <i class="bi bi-list-task"></i> Ongoing Projects
                        </div>
                        <p class="db-panel-sub">Monthly planned vs actual with health status</p>
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
            <div class="db-table-scroll">
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
        </div>

        {{-- ── ANALYTICS (right column — 1-col vertical stack) ──────── --}}
        <div class="db-analytics-grid">

            {{-- 1. Project Status Donut --}}
            <div class="db-chart-card">
                <div class="db-chart-head">
                    <div class="db-chart-title"><i class="bi bi-pie-chart"></i> Project Status</div>
                    <a href="{{ route($projectsIndex) }}" class="db-chart-link">View details <i class="bi bi-arrow-right"></i></a>
                </div>
                <div style="height:200px; position:relative;"><canvas id="statusDonut"></canvas></div>
            </div>

            {{-- 2. Health Distribution Donut --}}
            <div class="db-chart-card">
                <div class="db-chart-head">
                    <div class="db-chart-title"><i class="bi bi-heart-pulse"></i> Health Distribution</div>
                    <a href="{{ route($projectsIndex, ['filter' => 'atrisk']) }}" class="db-chart-link">View details <i class="bi bi-arrow-right"></i></a>
                </div>
                <div style="height:200px; position:relative;"><canvas id="healthDonut"></canvas></div>
            </div>

            {{-- 3. Budget by Source --}}
            <div class="db-chart-card">
                <div class="db-chart-head">
                    <div class="db-chart-title"><i class="bi bi-bar-chart-line"></i> Budget by Source</div>
                    <a href="{{ route($projectsIndex) }}" class="db-chart-link">View details <i class="bi bi-arrow-right"></i></a>
                </div>
                <div style="height:200px; position:relative;"><canvas id="budgetBar"></canvas></div>
            </div>

            {{-- 4. Progress Performance --}}
            <div class="db-chart-card">
                <div class="db-chart-head">
                    <div class="db-chart-title"><i class="bi bi-graph-up"></i> Progress: Planned vs Actual</div>
                    <a href="{{ route($projectsIndex, ['status' => 'Ongoing']) }}" class="db-chart-link">View details <i class="bi bi-arrow-right"></i></a>
                </div>
                <div style="height:200px; position:relative;"><canvas id="progressBar"></canvas></div>
            </div>

            {{-- 5. Extension Summary --}}
            <div class="db-chart-card">
                <div class="db-chart-head">
                    <div class="db-chart-title"><i class="bi bi-calendar2-plus"></i> Extensions</div>
                </div>
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
     TAB 3 — CALENDAR
     Source: dashboard/partials/calendar-widget.blade.php
     Includes: tab HTML + modal HTML + calendar JS
════════════════════════════════ --}}
<div id="tab-calendar" class="db-tab-pane">
    <div class="db-widget-shell">
        @include('dashboard.partials.calendar-widget')
    </div>
</div>

{{-- ════════════════════════════════
     TAB 4 — CAMPUS MAP — HIDDEN for now (2026-09-21, per user request).
     Restore by uncommenting.
     Source: dashboard/partials/campus-map-widget.blade.php
════════════════════════════════
<div id="tab-map" class="db-tab-pane">
    <div class="db-widget-shell">
        @include('dashboard.partials.campus-map-widget')
    </div>
</div>
════════════════════════════════ --}}

</div>{{-- /db-page --}}


{{-- ════════════════════════════════════════════════════════
     JAVASCRIPT
     Blade-injected variables defined here first.
     Pure JS loaded from public/js/dashboard.js after.
     (Chart definitions + data below are UNCHANGED — only the
     surrounding card markup was restyled.)
════════════════════════════════════════════════════════ --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
    // Blade-injected — must be defined BEFORE dashboard.js loads
    const DASHBOARD_FILTER_URL = "{{ auth()->user()->role === 'staff'
        ? route('staff.dashboard.filter')
        : route('admin.dashboard.filter') }}";
    const DASHBOARD_SEARCH_URL = "{{ auth()->user()->role === 'staff'
        ? route('staff.dashboard.search')
        : route('admin.dashboard.search') }}";

    // ── Hero search (additive, client-side only) ──────────────────────
    // Filters the currently loaded table rows by text. Does NOT touch
    // the alert-pill AJAX filter (filterDashboardTable in dashboard.js).
    function dbSearchProjects(query) {
        const q = (query || '').trim().toLowerCase();
        document.querySelectorAll('.db-proj-tbl tbody tr').forEach(row => {
            if (row.querySelector('.db-empty')) return;
            row.style.display = (!q || row.innerText.toLowerCase().includes(q)) ? '' : 'none';
        });
    }

    // ── Hero autocomplete (search → suggestions → click to open) ──────
    // Fetches role-scoped matches from DashboardController@search and shows
    // them in a dropdown. Clicking (or Enter) navigates to detail_url.
    let dbAcTimer = null;
    let dbAcItems = [];
    let dbAcActive = -1;

    function dbAutocompleteSearch(query) {
        const box = document.getElementById('db-search-results');
        const input = document.getElementById('db-hero-search-input');
        const q = (query || '').trim();
        clearTimeout(dbAcTimer);
        if (!q) { dbAcClose(); return; }
        dbAcTimer = setTimeout(async () => {
            try {
                const res = await fetch(DASHBOARD_SEARCH_URL + '?q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!res.ok) throw new Error('bad status');
                dbAcItems = await res.json();
                dbAcActive = dbAcItems.length ? 0 : -1;
                dbAcRender(q);
            } catch (e) { dbAcClose(); }
        }, 200);
    }

    function dbAcStatusClass(status) {
        return {
            'Ongoing': 's-ongoing', 'Completed': 's-completed', 'Planned': 's-planned',
            'Suspended': 's-suspended', 'Cancelled': 's-cancelled'
        }[status] || 's-ongoing';
    }

    function dbAcRender(q) {
        const box = document.getElementById('db-search-results');
        const input = document.getElementById('db-hero-search-input');
        if (!box) return;
        if (!q || !q.trim()) { dbAcClose(); return; }
        if (!dbAcItems.length) {
            box.innerHTML = '<div class="db-search-empty"><i class="bi bi-search"></i>No projects match "' +
                q.replace(/</g, '&lt;') + '"</div>';
        } else {
            box.innerHTML = dbAcItems.map((p, i) =>
                '<a href="' + p.detail_url + '" class="db-search-item' + (i === dbAcActive ? ' active' : '') + '"' +
                ' data-idx="' + i + '" role="option" aria-selected="' + (i === dbAcActive) + '">' +
                '<span class="db-search-icon"><i class="bi bi-folder2-open"></i></span>' +
                '<span class="db-search-main"><span class="db-search-code">' + (p.code || '').replace(/</g, '&lt;') + '</span>' +
                '<span class="db-search-title">' + (p.title || '').replace(/</g, '&lt;') + '</span>' +
                (p.contractor ? '<span class="db-search-sub">' + p.contractor.replace(/</g, '&lt;') + '</span>' : '') +
                '</span><span class="db-search-status ' + dbAcStatusClass(p.status) + '">' + (p.status || '') + '</span></a>'
            ).join('');
            box.querySelectorAll('.db-search-item').forEach(el => {
                el.addEventListener('mousemove', () => {
                    dbAcActive = parseInt(el.dataset.idx, 10);
                    box.querySelectorAll('.db-search-item').forEach(x => x.classList.remove('active'));
                    el.classList.add('active');
                });
            });
        }
        box.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function dbAcClose() {
        const box = document.getElementById('db-search-results');
        const input = document.getElementById('db-hero-search-input');
        if (box) box.hidden = true;
        if (input) input.setAttribute('aria-expanded', 'false');
        dbAcItems = []; dbAcActive = -1;
    }

    function dbAutocompleteKey(e) {
        const box = document.getElementById('db-search-results');
        if (!box || box.hidden) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!dbAcItems.length) return;
            dbAcActive = e.key === 'ArrowDown'
                ? (dbAcActive + 1) % dbAcItems.length
                : (dbAcActive - 1 + dbAcItems.length) % dbAcItems.length;
            box.querySelectorAll('.db-search-item').forEach((x, i) =>
                x.classList.toggle('active', i === dbAcActive));
        } else if (e.key === 'Enter') {
            if (dbAcActive >= 0 && dbAcItems[dbAcActive]) {
                e.preventDefault();
                window.location.href = dbAcItems[dbAcActive].detail_url;
            }
        } else if (e.key === 'Escape') { dbAcClose(); }
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.db-hero-search-wrap')) dbAcClose();
    });

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
<script src="{{ asset('js/dashboard-timeline.js') }}"></script>

@endsection
