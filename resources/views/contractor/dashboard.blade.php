@extends('layouts.contractorlayouts')

@section('content')
{{--
╔══════════════════════════════════════════════════════════════════╗
║  CONTRACTOR DASHBOARD — OPTION #3 (enterprise, spacious)         ║
║  Visual-only pass. Untouched: layout, auth, routes, controller   ║
║  variables, table health logic ($healthMap), Chart.js data and   ║
║  canvas IDs (#statusDonut #healthDonut #budgetBar).              ║
║  Shared card/hero/table classes come from                       ║
║  public/css/dashboard/dashboard.css (same file as admin).        ║
╚══════════════════════════════════════════════════════════════════╝
--}}
@php
    $contractorName = auth()->user()->contractor->company_name ?? auth()->user()->email;
@endphp
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<link href="{{ asset('css/dashboard/dashboard.css') }}" rel="stylesheet">
<link href="{{ asset('css/dashboard/dashboard-widgets.css') }}?v=20260921f" rel="stylesheet">

<style>
  /* Contractor-only pieces (deadlines feed + budget bars).
     Tokens (:root) come from dashboard.css loaded above. */
  .ct-stack { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
  .ct-dl-row {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 0; border-bottom: 1px solid var(--bg-2, #F1F5F9);
  }
  .ct-dl-row:last-child { border-bottom: none; padding-bottom: 0; }
  .ct-dl-row:first-of-type { padding-top: 0; }
  .ct-dl-date {
    flex: 0 0 52px; text-align: center; background: var(--bg, #F8FAFC);
    border: 1px solid var(--line, #E2E8F0); border-left: 3px solid var(--brand, #2563EB);
    border-radius: 8px; padding: 7px 0;
  }
  .ct-dl-month { font-size: 10px; color: var(--gray-400, #9ca3af); text-transform: uppercase; letter-spacing: 0.5px; }
  .ct-dl-day { font-size: 17px; font-weight: 600; line-height: 1.2; color: var(--ink, #0F172A); }
  .ct-dl-info { flex: 1; min-width: 0; }
  .ct-dl-proj { font-size: 13px; font-weight: 600; color: var(--ink, #0F172A); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ct-dl-desc { font-size: 12px; color: var(--gray-400, #9ca3af); margin-top: 2px; }
  .ct-pill { display: inline-block; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
  .ct-pill-blue { background: var(--blue-50, #eff6ff); color: var(--brand-dark, #1D4ED8); border: 1px solid var(--blue-100, #dbeafe); }
  .ct-pill-amber { background: var(--amber-50, #fffbeb); color: var(--amber-800, #92400e); border: 1px solid var(--amber-200, #fde68a); }
  .ct-budget-total { font-size: 26px; font-weight: 600; color: var(--ink, #0F172A); letter-spacing: -0.3px; }
  .ct-budget-sub { font-size: 12px; color: var(--gray-500, #6b7280); margin: 2px 0 14px; }
  .ct-bbar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 11px; font-size: 12px; }
  .ct-bbar-row:last-child { margin-bottom: 0; }
  .ct-bbar-name { flex: 0 0 110px; color: var(--gray-500, #6b7280); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ct-bbar-track { flex: 1; height: 7px; background: var(--bg-2, #F1F5F9); border-radius: 4px; overflow: hidden; }
  .ct-bbar-fill { height: 100%; border-radius: 4px; }
  .ct-bbar-amt { flex: 0 0 72px; text-align: right; color: var(--ink, #0F172A); font-weight: 600; }
  .ct-empty { text-align: center; padding: 26px 16px; color: var(--gray-400, #9ca3af); font-size: 13px; }
  .ct-empty i { display: block; font-size: 22px; margin-bottom: 8px; color: var(--gray-300, #d1d5db); }
  .ct-act-row { display: flex; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--bg-2, #F1F5F9); align-items: flex-start; }
  .ct-act-row:last-child { border-bottom: none; padding-bottom: 0; }
  .ct-act-row:first-of-type { padding-top: 2px; }
  .ct-act-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--brand, #2563EB); flex-shrink: 0; margin-top: 5px; }
  .ct-act-text { font-size: 12.5px; color: var(--gray-700, #374151); line-height: 1.45; }
  .ct-act-time { font-size: 11px; color: var(--gray-400, #9ca3af); margin-top: 2px; }
  @media (max-width: 768px) {
    .ct-bbar-name { flex-basis: 84px; }
    .ct-bbar-amt { flex-basis: 60px; }
  }
</style>

<div class="db-page">

  {{-- ══ HERO ══ --}}
  <header class="db-hero">
    <img src="{{ asset('images/CSPC_nabua_WTR.png') }}" alt="" aria-hidden="true" class="db-hero-bg">
    <div class="db-hero-overlay" aria-hidden="true"></div>
    <div class="db-hero-inner">
      <div class="db-hero-left">
        <div class="db-hero-icon"><i class="bi bi-briefcase"></i></div>
        <div style="min-width:0">
          <p class="db-hero-eyebrow">Welcome back,</p>
          <h2 class="db-hero-title">{{ $contractorName }}</h2>
          <p class="db-hero-sub">My Projects Dashboard &mdash; CSPC Project Management Unit</p>
          <div class="db-hero-meta">
            <span class="db-role-badge"><i class="bi bi-person-badge"></i> Contractor Overview</span>
            <span class="db-hero-date"><i class="bi bi-calendar3"></i> {{ now()->format('l, F j, Y') }}</span>
          </div>
        </div>
      </div>
      <div class="db-hero-right">
        <div class="db-hero-search-wrap">
          <label class="db-hero-search" for="ct-hero-search-input">
            <i class="bi bi-search"></i>
            <input id="ct-hero-search-input" type="search"
                   placeholder="Search my projects…"
                   oninput="ctSearchProjects(this.value); ctAutocompleteSearch(this.value)"
                   onkeydown="ctAutocompleteKey(event)"
                   onfocus="ctAutocompleteSearch(this.value)"
                   autocomplete="off" role="combobox" aria-expanded="false"
                   aria-controls="ct-search-results" aria-autocomplete="list">
          </label>
          <div id="ct-search-results" class="db-search-results" hidden></div>
        </div>
        <div class="db-hero-actions">
          <a href="{{ route('contractor.projects') }}" class="db-header-btn light">
            <i class="bi bi-eye"></i> View All Projects
          </a>
        </div>
      </div>
    </div>
  </header>

  {{-- ══ STAT CARDS (same variables as before) ══ --}}
  <div class="db-stats" style="grid-template-columns:repeat(4,1fr)">
    <div class="db-stat-card s-blue">
      <div class="db-stat-icon"><i class="bi bi-folder2"></i></div>
      <div style="min-width:0">
        <div class="db-stat-num">{{ $totalProjects }}</div>
        <div class="db-stat-label">Total assigned</div>
        <div class="db-stat-desc">All projects on record</div>
        <div class="db-stat-sub neu"><i class="bi bi-dash"></i> all time</div>
      </div>
    </div>
    <div class="db-stat-card s-green">
      <div class="db-stat-icon"><i class="bi bi-check-circle-fill"></i></div>
      <div style="min-width:0">
        <div class="db-stat-num">{{ $completedCount }}</div>
        <div class="db-stat-label">Completed</div>
        <div class="db-stat-desc">Finished and turned over</div>
        <div class="db-stat-sub pos"><i class="bi bi-check2-all"></i> {{ $completedPct }}% of total</div>
      </div>
    </div>
    <div class="db-stat-card s-amber">
      <div class="db-stat-icon"><i class="bi bi-arrow-repeat"></i></div>
      <div style="min-width:0">
        <div class="db-stat-num">{{ $ongoingCount }}</div>
        <div class="db-stat-label">In progress</div>
        <div class="db-stat-desc">Actively under construction</div>
        <div class="db-stat-sub neu"><i class="bi bi-dot"></i> {{ $ongoingPct }}% of total</div>
      </div>
    </div>
    <div class="db-stat-card s-violet">
      <div class="db-stat-icon"><i class="bi bi-hourglass-split"></i></div>
      <div style="min-width:0">
        <div class="db-stat-num">{{ $plannedCount }}</div>
        <div class="db-stat-label">Not yet started</div>
        <div class="db-stat-desc">Approved, awaiting rollout</div>
        <div class="db-stat-sub neu"><i class="bi bi-dot"></i> {{ $plannedPct }}% of total</div>
      </div>
    </div>
  </div>
  <style>@media(max-width:1200px){.db-stats{grid-template-columns:repeat(2,1fr)!important}}@media(max-width:480px){.db-stats{grid-template-columns:1fr 1fr!important}}</style>

  {{-- ══ GOOGLE CALENDAR CONNECT BANNER ══ --}}
  @if(!auth()->user()->hasGoogleCalendar())
  <div class="db-panel" style="padding:12px 18px;border:1px dashed #93c5fd;background:#eff6ff;margin-bottom:16px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <span style="font-size:13px;font-weight:700;color:#1D4ED8;"><i class="bi bi-calendar-plus"></i> Google Calendar</span>
      <span style="font-size:12px;color:#334155;">Project start, deadline &amp; inspection dates sync automatically once connected.</span>
      <a href="{{ route('calendar.google.connect') }}" class="ct-pill ct-pill-blue" style="text-decoration:none;">
        <i class="bi bi-google"></i> Connect my calendar
      </a>
    </div>
  </div>
  @endif

  {{-- ══ MAIN GRID ══ --}}
  <div class="db-main-grid">

    {{-- LEFT: projects table + budget --}}
    <div class="ct-stack">

      <div class="db-panel">
        <div class="db-panel-hd">
          <div>
            <div class="db-panel-title"><i class="bi bi-list-task"></i> My Projects</div>
            <p class="db-panel-sub">Monthly planned vs actual with health status</p>
          </div>
          <a href="{{ route('contractor.projects') }}" class="db-view-all">
            View all <i class="bi bi-arrow-right"></i>
          </a>
        </div>
        <div class="db-table-scroll">
          <table class="db-proj-tbl">
            <thead>
              <tr>
                <th style="width:30%">Project</th>
                <th style="width:22%">This Month</th>
                <th style="width:18%">Deadline</th>
                <th style="width:12%">Health</th>
                <th style="width:4%"></th>
              </tr>
            </thead>
            <tbody>
              @forelse($tableProjects as $project)
                @php
                  // ── OVERALL PROGRESS (preloaded — no N+1) ──────────────
                  $progress = isset($latestKnownProgress[$project->id])
                      ? (float) ($latestKnownProgress[$project->id]->actual_progress_percent ?? 0)
                      : 0;

                  // ── THIS MONTH: PLANNED vs ACTUAL ─────────────────────
                  $monthRecord     = $currentMonthProgress[$project->id] ?? null;
                  $monthBaseline   = $currentMonthBaseline[$project->id] ?? null;
                  $latestRecord    = $latestKnownProgress[$project->id] ?? null;

                  $monthActual     = $monthRecord  ? (float) $monthRecord->actual_progress_percent
                                  : ($latestRecord ? (float) $latestRecord->actual_progress_percent
                                  : null);
                  $monthPlanned    = $monthBaseline ? (float) $monthBaseline->planned_progress_percent : null;

                  $hasActual       = $monthActual  !== null;
                  $hasPlanned      = $monthBaseline !== null;
                  $isCurrentMonth  = $monthRecord  !== null;

                  // ── HEALTH (unified ProjectHealthService definition) ────
                  $health        = $healthMap[$project->id] ?? [];
                  $isOverdue     = $health['is_overdue'] ?? false;
                  $isAtRisk      = $health['is_at_risk'] ?? false;
                  $days          = $health['days_remaining']
                      ?? ($project->planned_end_date
                          ? (int) now()->diffInDays($project->planned_end_date, false)
                          : null);

                  $fillClass     = $health['fill_class'] ?? 'fill-healthy';
                  $deadlineClass = $health['deadline_class'] ?? 'ok';
                  $riskLabel     = $health['label'] ?? 'ON TRACK';
                  $riskClass     = $health['risk_class'] ?? 'risk-low';
                  $dotColor      = $health['dot_color'] ?? 'var(--green-600)';
                  $healthTooltip = $health['tooltip'] ?? null;
                @endphp
                <tr>
                  {{-- Project identity: code, title, category --}}
                  <td>
                    <div class="project-code">{{ $project->code }}</div>
                    <div class="project-title">{{ $project->title }}</div>
                    <span class="project-category">{{ $project->category->name ?? '—' }}</span>
                  </td>

                  {{-- This month: planned vs actual, falls back to overall --}}
                  <td>
                    @if($hasActual || $hasPlanned)
                      <div class="this-month-label">
                        @if($hasPlanned)
                          Planned <strong style="color:var(--gray-700)">{{ number_format($monthPlanned, 0) }}%</strong>
                        @else
                          <span style="color:var(--gray-400)">No target set</span>
                        @endif
                        ·
                        @if($hasActual)
                          @if($isCurrentMonth)
                            Actual
                          @else
                            Last
                          @endif
                          @if($monthActual >= ($monthPlanned ?? 0))
                            <strong style="color:var(--green-600)">{{ number_format($monthActual, 0) }}%</strong>
                          @else
                            <strong style="color:var(--red-600)">{{ number_format($monthActual, 0) }}%</strong>
                          @endif
                        @else
                          <span style="color:var(--gray-400);font-style:italic">no record</span>
                        @endif
                      </div>
                      <div class="progress-visual">
                        <div class="progress-bar-mini">
                          <div class="fill {{ $fillClass }}"
                              style="width:{{ $hasActual ? $monthActual : $monthPlanned }}%;
                                      {{ !$isCurrentMonth ? 'opacity:0.6' : '' }}">
                          </div>
                        </div>
                        <span class="progress-percent" style="{{ !$isCurrentMonth ? 'color:var(--gray-400)' : '' }}">
                          {{ $hasActual ? number_format($monthActual, 0) : number_format($monthPlanned, 0) }}%
                        </span>
                      </div>
                      @if(!$isCurrentMonth && $hasActual)
                        <div style="font-size:10px;color:var(--gray-400);margin-top:2px;font-style:italic">
                          No {{ now()->format('M') }} inspection yet
                        </div>
                      @endif
                    @else
                      <div class="this-month-label">
                        Overall <strong style="color:var(--gray-700)">{{ number_format($progress, 0) }}%</strong>
                      </div>
                      <div class="progress-visual">
                        <div class="progress-bar-mini">
                          <div class="fill {{ $fillClass }}" style="width:{{ $progress }}%"></div>
                        </div>
                        <span class="progress-percent">{{ number_format($progress, 0) }}%</span>
                      </div>
                      <div style="font-size:10px;color:var(--gray-400);margin-top:2px">
                        No {{ now()->format('M') }} baseline
                      </div>
                    @endif
                  </td>

                  {{-- Deadline: date + days remaining or overdue count --}}
                  <td>
                    <div class="deadline-cell {{ $deadlineClass }}">
                      {{ $project->planned_end_date?->format('M d, Y') ?? '—' }}
                    </div>
                    @if($isOverdue)
                      <div class="days-label overdue-text">
                        {{ abs($days) }}d overdue
                      </div>
                    @elseif($days !== null)
                      <div class="days-label {{ $days < 30 ? 'days-urgent' : 'days-ok' }}">
                        {{ $days }}d left
                      </div>
                    @endif
                  </td>

                  {{-- Health badge: ON TRACK / AT RISK / OVERDUE / NO DATA --}}
                  <td>
                    <span class="risk-indicator {{ $riskClass }}" @if($healthTooltip) title="{{ $healthTooltip }}" @endif>
                      <span class="risk-dot" style="background:{{ $dotColor }}"></span>
                      {{ $riskLabel }}
                    </span>
                  </td>

                  {{-- Row action: links to the project detail page --}}
                  <td>
                    <a href="{{ route('projects.show', $project->id) }}" class="db-row-link">
                      <i class="bi bi-chevron-right"></i>
                    </a>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="5" class="db-empty">
                    <i class="bi bi-check-circle"></i>
                    <div class="db-empty-title">No ongoing projects at the moment</div>
                    <div>Projects assigned to you that are in progress will appear here.</div>
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>

      {{-- BUDGET --}}
      <div class="db-panel" style="padding:16px 18px">
        <div class="db-panel-title" style="margin-bottom:2px"><i class="bi bi-cash-stack"></i> Budget Allocation</div>
        <p class="db-panel-sub" style="margin-bottom:12px">Across {{ $totalProjects }} assigned {{ Str::plural('project', $totalProjects) }}</p>
        <div class="ct-budget-total">{{ \App\Helpers\FormatHelper::formatCurrency($totalBudget) }}</div>
        <div class="ct-budget-sub">Total approved budget</div>

        @forelse($budgetProjects as $project)
          @php
            $budgetPct = $totalBudget ? round(($project->effectiveContractPrice() / $totalBudget) * 100) : 0;
          @endphp
          <div class="ct-bbar-row">
            <div class="ct-bbar-name" title="{{ $project->title }}">{{ $project->title }}</div>
            <div class="ct-bbar-track"><div class="ct-bbar-fill" style="width:{{ $budgetPct }}%;background:{{ $project->status === 'Completed' ? '#16A34A' : '#2563EB' }}"></div></div>
            <div class="ct-bbar-amt">{{ \App\Helpers\FormatHelper::formatCurrencyShort($project->effectiveContractPrice()) }}</div>
          </div>
        @empty
          <div class="ct-empty">
            <i class="bi bi-cash-stack"></i>
            No budget data yet.
          </div>
        @endforelse
      </div>

    </div>{{-- end left stack --}}

    {{-- RIGHT: deadlines + analytics --}}
    <div class="ct-stack">

      {{-- UPCOMING DEADLINES (same $deadlineProjects logic as before) --}}
      <div class="db-panel" style="padding:16px 18px">
        <div class="db-panel-title" style="margin-bottom:6px"><i class="bi bi-calendar-event"></i> Upcoming Deadlines</div>
        @forelse($deadlineProjects as $project)
          @php
            $dlProgress = (float) ($latestKnownProgress[$project->id]->actual_progress_percent
                        ?? $latestKnownProgress[$project->id]->progress_percentage ?? 0);
            $dlDays     = (int) now()->diffInDays($project->planned_end_date, false);
            $dlOverdue  = $dlDays < 0 && $dlProgress < 100;
            $dlPill     = $dlOverdue ? 'ct-pill-amber' : 'ct-pill-blue';
          @endphp
          <div class="ct-dl-row">
            <div class="ct-dl-date"><div class="ct-dl-month">{{ $project->planned_end_date->format('M') }}</div><div class="ct-dl-day">{{ $project->planned_end_date->format('d') }}</div></div>
            <div class="ct-dl-info">
              <div class="ct-dl-proj">{{ $project->title }}</div>
              <div class="ct-dl-desc">{{ $dlOverdue ? 'Overdue by ' . abs($dlDays) . ' day' . (abs($dlDays) !== 1 ? 's' : '') : ($dlDays >= 0 ? $dlDays . ' days remaining' : 'Completed on schedule') }}</div>
            </div>
            <span class="ct-pill {{ $dlPill }}">{{ $dlOverdue ? 'Overdue' : $project->status }}</span>
          </div>
        @empty
          <div class="ct-empty">
            <i class="bi bi-calendar-check"></i>
            No upcoming deadlines.
          </div>
        @endforelse
      </div>

      {{-- RECENT ACTIVITY (wired from $recentActivities — controller already
           scoped it to this contractor's projects, visibility='all') --}}
      <div class="db-panel" style="padding:16px 18px">
        <div class="db-panel-title" style="margin-bottom:6px"><i class="bi bi-activity"></i> Recent Activity</div>
        @forelse($recentActivities ?? collect() as $activity)
          <div class="ct-act-row">
            <div class="ct-act-dot"></div>
            <div style="min-width:0">
              <div class="ct-act-text">{{ $activity->description }}</div>
              <div class="ct-act-time">{{ $activity->project->title ?? 'General' }} &middot; {{ $activity->created_at?->diffForHumans() }}</div>
            </div>
          </div>
        @empty
          <div class="ct-empty">
            <i class="bi bi-activity"></i>
            No recent activity.
          </div>
        @endforelse
      </div>

      {{-- ANALYTICS (same canvas IDs + data as before) --}}
      <div class="db-chart-card">
        <div class="db-chart-head">
          <div class="db-chart-title"><i class="bi bi-pie-chart"></i> Project Status</div>
        </div>
        <div style="height:200px; position:relative;"><canvas id="statusDonut"></canvas></div>
      </div>

      <div class="db-chart-card">
        <div class="db-chart-head">
          <div class="db-chart-title"><i class="bi bi-heart-pulse"></i> Health Distribution</div>
        </div>
        <div style="height:200px; position:relative;"><canvas id="healthDonut"></canvas></div>
      </div>

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
      </div>

      <div class="db-chart-card">
        <div class="db-chart-head">
          <div class="db-chart-title"><i class="bi bi-bar-chart-line"></i> Budget by Source</div>
        </div>
        <div style="height:160px; position:relative;"><canvas id="budgetBar"></canvas></div>
      </div>

    </div>{{-- end right stack --}}
  </div>{{-- end db-main-grid --}}

  {{-- ══ PROJECT CALENDAR (real start / due / inspection dates) ══ --}}
  <div class="db-panel" style="margin-top:16px;">
    <div class="db-panel-hd">
      <div>
        <div class="db-panel-title"><i class="bi bi-calendar3"></i> Project Calendar</div>
        <p class="db-panel-sub">Start dates, deadlines &amp; monthly inspections for my projects (auto-syncs to Google Calendar when connected)</p>
      </div>
      <a href="{{ route('calendar.google.connect') }}" class="db-view-all" @if(auth()->user()->hasGoogleCalendar()) style="display:none" @endif>
        Connect calendar <i class="bi bi-arrow-right"></i>
      </a>
    </div>
    @include('dashboard.partials.calendar-widget')
  </div>

</div>{{-- end db-page --}}

<script>
  const CT_DASHBOARD_SEARCH_URL = "{{ route('contractor.dashboard.search') }}";

  // Hero search (client-side row filter only).
  function ctSearchProjects(query) {
    const q = (query || '').trim().toLowerCase();
    document.querySelectorAll('.db-proj-tbl tbody tr').forEach(row => {
      if (row.querySelector('.db-empty')) return;
      row.style.display = (!q || row.innerText.toLowerCase().includes(q)) ? '' : 'none';
    });
  }

  // ── Hero autocomplete (search → suggestions → click to open) ──────
  // Contractor-scoped matches from Contractor\DashboardController@search.
  // Clicking (or Enter) navigates to the project's read-only page.
  let ctAcTimer = null;
  let ctAcItems = [];
  let ctAcActive = -1;

  function ctAutocompleteSearch(query) {
    const box = document.getElementById('ct-search-results');
    const q = (query || '').trim();
    clearTimeout(ctAcTimer);
    if (!q) { ctAcClose(); return; }
    ctAcTimer = setTimeout(async () => {
      try {
        const res = await fetch(CT_DASHBOARD_SEARCH_URL + '?q=' + encodeURIComponent(q), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) throw new Error('bad status');
        ctAcItems = await res.json();
        ctAcActive = ctAcItems.length ? 0 : -1;
        ctAcRender(q);
      } catch (e) { ctAcClose(); }
    }, 200);
  }

  function ctAcStatusClass(status) {
    return {
      'Ongoing': 's-ongoing', 'Completed': 's-completed', 'Planned': 's-planned',
      'Suspended': 's-suspended', 'Cancelled': 's-cancelled'
    }[status] || 's-ongoing';
  }

  function ctAcRender(q) {
    const box = document.getElementById('ct-search-results');
    const input = document.getElementById('ct-hero-search-input');
    if (!box) return;
    if (!q || !q.trim()) { ctAcClose(); return; }
    if (!ctAcItems.length) {
      box.innerHTML = '<div class="db-search-empty"><i class="bi bi-search"></i>No projects match "' +
        q.replace(/</g, '&lt;') + '"</div>';
    } else {
      box.innerHTML = ctAcItems.map((p, i) =>
        '<a href="' + p.detail_url + '" class="db-search-item' + (i === ctAcActive ? ' active' : '') + '"' +
        ' data-idx="' + i + '" role="option" aria-selected="' + (i === ctAcActive) + '">' +
        '<span class="db-search-icon"><i class="bi bi-folder2-open"></i></span>' +
        '<span class="db-search-main"><span class="db-search-code">' + (p.code || '').replace(/</g, '&lt;') + '</span>' +
        '<span class="db-search-title">' + (p.title || '').replace(/</g, '&lt;') + '</span>' +
        '</span><span class="db-search-status ' + ctAcStatusClass(p.status) + '">' + (p.status || '') + '</span></a>'
      ).join('');
      box.querySelectorAll('.db-search-item').forEach(el => {
        el.addEventListener('mousemove', () => {
          ctAcActive = parseInt(el.dataset.idx, 10);
          box.querySelectorAll('.db-search-item').forEach(x => x.classList.remove('active'));
          el.classList.add('active');
        });
      });
    }
    box.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function ctAcClose() {
    const box = document.getElementById('ct-search-results');
    const input = document.getElementById('ct-hero-search-input');
    if (box) box.hidden = true;
    if (input) input.setAttribute('aria-expanded', 'false');
    ctAcItems = []; ctAcActive = -1;
  }

  function ctAutocompleteKey(e) {
    const box = document.getElementById('ct-search-results');
    if (!box || box.hidden) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!ctAcItems.length) return;
      ctAcActive = e.key === 'ArrowDown'
        ? (ctAcActive + 1) % ctAcItems.length
        : (ctAcActive - 1 + ctAcItems.length) % ctAcItems.length;
      box.querySelectorAll('.db-search-item').forEach((x, i) =>
        x.classList.toggle('active', i === ctAcActive));
    } else if (e.key === 'Enter') {
      if (ctAcActive >= 0 && ctAcItems[ctAcActive]) {
        e.preventDefault();
        window.location.href = ctAcItems[ctAcActive].detail_url;
      }
    } else if (e.key === 'Escape') { ctAcClose(); }
  }

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.db-hero-search-wrap')) ctAcClose();
  });

  // Status Donut
  new Chart(document.getElementById('statusDonut'), {
    type: 'doughnut',
    data: {
      labels: ['Completed', 'In Progress', 'Not Started', 'Suspended', 'Cancelled'],
      datasets: [{
        data: [{{ $completedCount }}, {{ $ongoingCount }}, {{ $plannedCount }}, {{ $suspendedCount ?? 0 }}, {{ $cancelledCount ?? 0 }}],
        backgroundColor: ['#059669', '#2563eb', '#f59e0b', '#ef4444', '#9ca3af'],
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '65%',
      plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 10, padding: 6, font: { size: 10 } } } }
    }
  });

  // Health Donut
  @php
      $ongoingForHealth = $projects->where('status', 'Ongoing');
      $hAtRisk = $ongoingForHealth->filter(fn ($p) => ($healthMap[$p->id]['level'] ?? '') === 'at_risk')->count();
      $hOverdue = $ongoingForHealth->filter(fn ($p) => ($healthMap[$p->id]['level'] ?? '') === 'overdue')->count();
      $hOnTrack = max(0, $ongoingForHealth->count() - $hAtRisk - $hOverdue);
  @endphp
  new Chart(document.getElementById('healthDonut'), {
    type: 'doughnut',
    data: {
      labels: ['On Track', 'At Risk', 'Overdue'],
      datasets: [{
        data: [{{ $hOnTrack }}, {{ $hAtRisk }}, {{ $hOverdue }}],
        backgroundColor: ['#16a34a', '#f59e0b', '#ef4444'],
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '65%',
      plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 10, padding: 6, font: { size: 10 } } } }
    }
  });

  // Budget by Source (Horizontal Bar)
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
        barThickness: 18
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      indexAxis: 'y',
      scales: {
        x: { display: true, grid: { display: false }, ticks: { font: { size: 9 }, callback: v => v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v } },
        y: { display: true, grid: { display: false }, ticks: { font: { size: 9 } } }
      },
      plugins: { legend: { display: false } }
    }
  });
</script>

@endsection
