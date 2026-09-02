@extends('layouts.contractorlayouts')

@section('content')
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<link href="{{ asset('css/dashboard/dashboard.css') }}" rel="stylesheet">

<style>
  :root {
    --primary-color: #2563eb;
    --primary-dark: #1e40af;
    --success-color: #059669;
    --warning-color: #f59e0b;
    --danger-color: #dc2626;
    --text-main: #1f2937;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --bg-light: #f3f4f6;
    --bg-lighter: #f9fafb;
    --border: #e5e7eb;
  }

  /* ===== DASHBOARD LAYOUT & ZONES ===== */
  .dashboard-page {
    display: flex;
    flex-direction: column;
    gap: 30px;
  }

  .dashboard-zone {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .zone-header {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
    color: var(--text-main);
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border);
    margin-bottom: 5px;
  }

  .zone-header i {
    font-size: 22px;
    color: var(--primary-color);
  }

  /* ===== PAGE HEADER ===== */
  .dashboard-header {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
    border-radius: 15px;
    padding: 30px;
    color: white;
    margin-bottom: 10px;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.15);
  }

  .dashboard-header h2 {
    margin: 0;
    font-weight: 600;
    font-size: 28px;
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .dashboard-header p {
    margin: 8px 0 0;
    opacity: 0.9;
    font-size: 14px;
  }

  .dashboard-header-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
  }

  .quick-action-btn {
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
  }

  /* ===== STAT CARDS ===== */
  .stat-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 10px;
  }

  .stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.2s;
    border: 1px solid var(--border);
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
  }

  .stat-label {
    font-size: 12px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .stat-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .stat-val {
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    color: var(--text-main);
    margin-bottom: 8px;
  }

  .stat-sub {
    font-size: 12px;
    color: var(--text-light);
  }

  /* ===== BODY GRID ===== */
  .body-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
    gap: 20px;
  }

  .left-col {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .right-col {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  /* ===== CARDS ===== */
  .d-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.2s;
  }

  .d-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  }

  .d-card-title {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
  }

  .d-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--bg-light);
  }

  .status-pill {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }

  .pill-completed {
    background: #d1fae5;
    color: #065f46;
  }

  .pill-inprogress {
    background: #dbeafe;
    color: #1e40af;
  }

  .pill-notstarted {
    background: #fed7aa;
    color: #92400e;
  }

  /* ===== BUDGET ===== */
  .budget-total {
    font-size: 28px;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 4px;
  }

  .budget-sub {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 16px;
  }

  .bbar-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    font-size: 12px;
  }

  .bbar-row:last-child {
    margin-bottom: 0;
  }

  .bbar-name {
    flex: 0 0 100px;
    color: var(--text-muted);
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .bbar-track {
    flex: 1;
    height: 8px;
    background: var(--bg-light);
    border-radius: 4px;
    overflow: hidden;
  }

  .bbar-fill {
    height: 8px;
    border-radius: 4px;
  }

  .bbar-amt {
    flex: 0 0 70px;
    text-align: right;
    color: var(--text-main);
    font-weight: 600;
  }

  /* ===== DONUT CHART ===== */
  .donut-wrap {
    display: flex;
    align-items: center;
    gap: 20px;
  }

  .leg-row {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 8px;
    font-weight: 500;
  }

  .leg-row:last-child {
    margin-bottom: 0;
  }

  .leg-sq {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    flex-shrink: 0;
  }

  .leg-val {
    margin-left: auto;
    font-weight: 700;
    color: var(--text-main);
    min-width: 30px;
    text-align: right;
  }

  /* ===== DEADLINES ===== */
  .dl-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--bg-light);
  }

  .dl-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
  }

  .dl-row:first-child {
    padding-top: 0;
  }

  .dl-date {
    flex: 0 0 56px;
    text-align: center;
    background: var(--bg-light);
    border-radius: 8px;
    padding: 8px 0;
    border-left: 3px solid var(--primary-color);
  }

  .dl-month {
    font-size: 10px;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .dl-day {
    font-size: 18px;
    font-weight: 700;
    line-height: 1;
    color: var(--text-main);
    margin-top: 2px;
  }

  .dl-info {
    flex: 1;
    min-width: 0;
  }

  .dl-proj {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .dl-desc {
    font-size: 12px;
    color: var(--text-light);
    margin-top: 2px;
  }

  /* ===== ACTIVITY ===== */
  .act-row {
    display: flex;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--bg-light);
    align-items: flex-start;
  }

  .act-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
  }

  .act-row:first-child {
    padding-top: 0;
  }

  .act-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 4px;
  }

  .act-body {
    flex: 1;
    min-width: 0;
  }

  .act-proj {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .act-text {
    font-size: 12px;
    color: var(--text-light);
    margin-top: 2px;
  }

  .act-time {
    font-size: 11px;
    color: var(--text-light);
    white-space: nowrap;
    flex-shrink: 0;
    padding-top: 2px;
  }

  /* ===== RESPONSIVE ===== */
  @media (max-width: 1024px) {
    .body-grid {
      grid-template-columns: 1fr;
    }

    .stat-cards {
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
  }

  @media (max-width: 768px) {
    .dashboard-header {
      padding: 20px;
    }

    .dashboard-header h2 {
      font-size: 22px;
    }

    .stat-cards {
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
    }

    .stat-card {
      padding: 16px;
    }

    .d-card {
      padding: 16px;
    }

    .donut-wrap {
      flex-direction: column;
      gap: 16px;
    }
  }

  @media (max-width: 480px) {
    .stat-cards {
      grid-template-columns: 1fr;
    }

    .dashboard-header h2 {
      font-size: 18px;
      flex-direction: column;
      align-items: flex-start;
    }
  }
</style>

<div class="dashboard-page" style="padding: 2rem;">

  <!-- ===== PAGE HEADER ===== -->
  <div class="dashboard-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
      <div>
        <h2><i class="bi bi-briefcase me-2"></i>My Projects Dashboard</h2>
        <p>View your assigned projects, track progress, and manage project activities.</p>
      </div>
      <div class="dashboard-header-actions">
        <a href="{{ route('contractor.projects') }}" class="btn btn-light quick-action-btn">
          <i class="bi bi-eye me-1"></i> View All Projects
        </a>
      </div>
    </div>
  </div>

  <!-- ===== STAT CARDS ===== -->
  <div class="stat-cards">
    <div class="stat-card">
      <div class="stat-label"><span class="stat-dot" style="background:#888780"></span>Total assigned</div>
      <div class="stat-val">{{ $totalProjects }}</div>
      <div class="stat-sub">All projects</div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><span class="stat-dot" style="background:#059669"></span>Completed</div>
      <div class="stat-val" style="color:#059669">{{ $completedCount }}</div>
      <div class="stat-sub">{{ $completedPct }}% of total</div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><span class="stat-dot" style="background:#2563eb"></span>In progress</div>
      <div class="stat-val" style="color:#2563eb">{{ $ongoingCount }}</div>
      <div class="stat-sub">{{ $ongoingPct }}% of total</div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><span class="stat-dot" style="background:#f59e0b"></span>Not yet started</div>
      <div class="stat-val" style="color:#f59e0b">{{ $plannedCount }}</div>
      <div class="stat-sub">{{ $plannedPct }}% of total</div>
    </div>
  </div>

  {{-- MAIN BODY --}}
  <div class="body-grid">

    {{-- LEFT COLUMN --}}
    <div class="left-col">

      {{-- PROJECTS TABLE (mirrors admin/staff dashboard) --}}
      <div class="d-card">
        <div class="d-card-head">
          <span class="d-card-title">My Projects</span>
          <a href="{{ route('contractor.projects') }}" class="db-view-all">
            View all <i class="bi bi-arrow-right"></i>
          </a>
        </div>
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

      {{-- BUDGET --}}
      <div class="d-card">
        <div class="d-card-head">
          <span class="d-card-title">Total budget allocation</span>
        </div>
        <div class="budget-total">{{ \App\Helpers\FormatHelper::formatCurrency($totalBudget) }}</div>
        <div class="budget-sub">Across {{ $totalProjects }} assigned {{ Str::plural('project', $totalProjects) }}</div>

        @forelse($budgetProjects as $project)
          @php
            $budgetPct = $totalBudget ? round(($project->approved_budget / $totalBudget) * 100) : 0;
          @endphp
          <div class="bbar-row">
            <div class="bbar-name" title="{{ $project->title }}">{{ $project->title }}</div>
            <div class="bbar-track"><div class="bbar-fill" style="width:{{ $budgetPct }}%;background:{{ $project->status === 'Completed' ? '#059669' : '#2563eb' }}"></div></div>
            <div class="bbar-amt">{{ \App\Helpers\FormatHelper::formatCurrencyShort($project->approved_budget) }}</div>
          </div>
        @empty
          <div style="text-align:center;padding:24px;color:var(--text-light)">
            <i class="bi bi-cash-stack" style="font-size:20px;display:block;margin-bottom:8px"></i>
            No budget data yet.
          </div>
        @endforelse
      </div>

    </div>{{-- end left-col --}}

    {{-- RIGHT COLUMN --}}
    <div class="right-col">

      {{-- UPCOMING DEADLINES --}}
      <div class="d-card">
        <div class="d-card-head"><span class="d-card-title">Upcoming deadlines</span></div>

        @forelse($deadlineProjects as $project)
          @php
            $dlProgress = (float) ($latestKnownProgress[$project->id]->actual_progress_percent
                        ?? $latestKnownProgress[$project->id]->progress_percentage ?? 0);
            $dlDays     = (int) now()->diffInDays($project->planned_end_date, false);
            $dlOverdue  = $dlDays < 0 && $dlProgress < 100;
            $dlPill     = $dlOverdue ? 'pill-notstarted'
                : match ($project->status) {
                    'Ongoing' => 'pill-inprogress',
                    default   => 'pill-notstarted',
                };
          @endphp
          <div class="dl-row">
            <div class="dl-date"><div class="dl-month">{{ $project->planned_end_date->format('M') }}</div><div class="dl-day">{{ $project->planned_end_date->format('d') }}</div></div>
            <div class="dl-info">
              <div class="dl-proj">{{ $project->title }}</div>
              <div class="dl-desc">{{ $dlOverdue ? 'Overdue by ' . abs($dlDays) . ' day' . (abs($dlDays) !== 1 ? 's' : '') : ($dlDays >= 0 ? $dlDays . ' days remaining' : 'Completed on schedule') }}</div>
            </div>
            <span class="status-pill {{ $dlPill }}">{{ $dlOverdue ? 'Overdue' : $project->status }}</span>
          </div>
        @empty
          <div style="text-align:center;padding:24px;color:var(--text-light)">
            <i class="bi bi-calendar-check" style="font-size:20px;display:block;margin-bottom:8px"></i>
            No upcoming deadlines.
          </div>
        @endforelse
      </div>

      {{-- ANALYTICS GRID --}}
      <div style="display:flex; flex-direction:column; gap:14px;">

        {{-- Status Donut --}}
        <div class="d-card">
          <div class="d-card-head"><span class="d-card-title"><i class="bi bi-pie-chart"></i> Project Status</span></div>
          <div style="height:200px; position:relative;"><canvas id="statusDonut"></canvas></div>
        </div>

        {{-- Health Donut --}}
        <div class="d-card">
          <div class="d-card-head"><span class="d-card-title"><i class="bi bi-heart-pulse"></i> Health Distribution</span></div>
          <div style="height:200px; position:relative;"><canvas id="healthDonut"></canvas></div>
        </div>

        {{-- Extension Summary --}}
        <div class="d-card">
          <div class="d-card-head"><span class="d-card-title"><i class="bi bi-calendar2-plus"></i> Extensions</span></div>
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 10px;">
            <div style="text-align:center; padding:8px; background:#f9fafb; border-radius:6px;">
              <div style="font-size:18px; font-weight:700; color:#2563eb;">{{ $extensionSummary['approved'] }}</div>
              <div style="font-size:11px; color:#6b7280;">Granted</div>
            </div>
            <div style="text-align:center; padding:8px; background:#f9fafb; border-radius:6px;">
              <div style="font-size:18px; font-weight:700; color:#f59e0b;">{{ $extensionSummary['pending'] }}</div>
              <div style="font-size:11px; color:#6b7280;">Pending</div>
            </div>
            <div style="text-align:center; padding:8px; background:#f9fafb; border-radius:6px;">
              <div style="font-size:18px; font-weight:700; color:#ef4444;">{{ $extensionSummary['total_days'] }}</div>
              <div style="font-size:11px; color:#6b7280;">Days Added</div>
            </div>
          </div>
        </div>

        {{-- Budget by Source --}}
        <div class="d-card">
          <div class="d-card-head"><span class="d-card-title"><i class="bi bi-bar-chart-line"></i> Budget by Source</span></div>
          <div style="height:160px; position:relative;"><canvas id="budgetBar"></canvas></div>
        </div>

      </div>

    </div>{{-- end right-col --}}
  </div>{{-- end body-grid --}}

</div>{{-- end dashboard-page --}}

<script>
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