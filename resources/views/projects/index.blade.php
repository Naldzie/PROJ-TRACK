@php
    $layout = match(auth()->user()->role) {
        'contractor' => 'layouts.contractorlayouts',
        'staff' => 'layouts.stafflayouts',
        default => 'layouts.app'
    };

    
    $showRoute = match(auth()->user()->role) {
        'contractor' => 'projects.show',
        'staff' => 'staff.projects.detail',
        default => 'admin.projects.detail', // admin
    };

    $indexRoute = match(auth()->user()->role) {
        'contractor' => 'contractor.projects',
        'staff'  => 'staff.projects',
        default  => 'admin.projects',
    };

    $archivedRoute = match(auth()->user()->role) {
        'staff'  => 'staff.projects.archived',
        default  => 'admin.projects.archived',
    };

    $archiveRouteName = auth()->user()->role === 'admin'
        ? 'admin.projects.archive'
        : 'staff.projects.archive';

    $activeFilterLabel = match(true) {
        request('status') === 'Ongoing'   => 'Ongoing Projects',
        request('status') === 'Completed' => 'Completed Projects',
        request('status') === 'Planned'   => 'Planned Projects',
        request('status') === 'Suspended' => 'Suspended Projects',
        request('status') === 'Cancelled' => 'Cancelled Projects',
        request('filter') === 'overdue'   => 'Overdue Projects',
        request('filter') === 'atrisk'    => 'At Risk Projects',
        request('filter') === 'pending'   => 'Projects Pending Updates',
        request('filter') === 'inspection_overdue' => 'Overdue Inspections',
        default => null,
    };
@endphp
@extends($layout)

@section('content')

{{-- Critical first-paint styles (inline so refresh never flashes unstyled).
     Full styling still lives in public/css/admin/projects/projects.css. --}}
<style>
.pm-header{display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;margin-bottom:18px}
.pm-title-block h1{font-size:26px;font-weight:700;letter-spacing:-.02em;color:#0B1F4B;margin:0}
.pm-subtitle{margin:4px 0 0;font-size:13px;color:#64748B;font-weight:500}
.pm-header-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.pm-toolbar-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px 16px;margin-bottom:18px}
.pm-filters{display:flex;gap:15px;flex-wrap:wrap;align-items:center;justify-content:space-between}
.projects-table-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;margin-bottom:24px}
.projects-table-wrap{overflow-x:auto;border-bottom:1px solid #E2E8F0}
#projectsTable{display:none;width:100%;border-collapse:collapse;background:#fff}
#projectsTable.show{display:table}
#projectsTable thead th{position:sticky;top:0;background:#1e40af;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:12px 14px;text-align:left;white-space:nowrap}
#projectsTable td{padding:14px;font-size:13px;border-bottom:1px solid #F1F5F9;vertical-align:middle}
.projects-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(400px,1fr));gap:25px;margin-bottom:40px}
.project-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
</style>

@php
    // Sort URL builder — preserves existing query params (status, filter, year, per_page)
    // and toggles sort direction on repeated clicks.
    $sortUrl = function ($column) use ($currentSort, $currentSortDir) {
        $params = request()->query();
        if ($currentSort === $column) {
            $params['sort_dir'] = $currentSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $params['sort_by'] = $column;
            $params['sort_dir'] = 'asc';
        }
        unset($params['page']); // Reset to page 1 on sort change
        return route(request()->route()->getName(), $params);
    };
@endphp
@if($activeFilterLabel && in_array(auth()->user()->role, ['admin', 'staff']))
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 16px;margin-bottom:16px;font-size:13px;color:#1e40af;">
        <i class="bi bi-funnel-fill" style="color:#2563eb;"></i>
        <span>Showing: <strong>{{ $activeFilterLabel }}</strong></span>
        <a href="{{ route($indexRoute) }}"
           style="margin-left:auto;font-weight:600;color:#2563eb;text-decoration:none;font-size:12px;">
            <i class="bi bi-x-lg"></i> Clear filter
        </a>
    </div>
@endif

<div class="pm-header">
    <div class="pm-title-block">
        <h1>Project Monitor</h1>
        <p class="pm-subtitle">Track schedules, progress, and health across all projects</p>
    </div>
    <div class="pm-header-actions">
    @if(in_array(auth()->user()->role, ['admin', 'staff']))
        <a href="{{ route($archivedRoute) }}" class="btn-secondary pm-btn-archived" style="text-decoration: none;">
            <i class="bi bi-archive"></i> Archived Projects
        </a>
    @endif
    @can('create', App\Models\Project::class)
        <button class="btn-primary pm-btn-new" data-bs-toggle="modal" data-bs-target="#addProjectModal">
            <i class="bi bi-plus-circle"></i> New Project
        </button>
    @endcan
    </div>
</div>

<div class="pm-toolbar-card">
<div class="filters-section pm-filters">
    <div class="filters-buttons pm-segmented" role="tablist" aria-label="Project status filter" style="display: flex; gap: 0; align-items: center; flex: 1;">
        <button class="filter-btn active" onclick="filterProjects('all')" role="tab">All</button>
        <button class="filter-btn" onclick="filterProjects('ongoing')" role="tab"><i class="bi bi-arrow-repeat"></i> Ongoing</button>
        <button class="filter-btn" onclick="filterProjects('planned')" role="tab"><i class="bi bi-clipboard-check"></i> Planned</button>
        <button class="filter-btn" onclick="filterProjects('completed')" role="tab"><i class="bi bi-check-circle"></i> Completed</button>
    </div>

    <div class="filters-actions pm-toolbar" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <select id="filterStartYear" class="pm-select" onchange="applyFilters()" aria-label="Filter by start year">
            <option value="">Start Year — All</option>
            @foreach($startYears as $y)
                <option value="{{ $y }}" {{ (string)$y === (string)($selectedStartYear ?? '') ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
        </select>
        <select id="filterDeadlineYear" class="pm-select" onchange="applyFilters()" aria-label="Filter by deadline year">
            <option value="">Deadline Year — All</option>
            @foreach($deadlineYears as $y)
                <option value="{{ $y }}" {{ (string)$y === (string)($selectedDeadlineYear ?? '') ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
        </select>
        <div class="project-search pm-search" style="position: relative;">
            <input type="text" id="projectSearch" class="project-search-input" placeholder="Search by code, title, or contractor"
                   oninput="performSearch(this.value)">
            <i class="bi bi-search pm-search-icon"></i>
        </div>
        {{-- Column visibility control (frontend only — no backend change) --}}
        <div class="pm-columns-wrap">
            <button type="button" class="pm-columns-btn" id="pmColumnsBtn" aria-haspopup="true" aria-expanded="false" title="Show / hide table columns">
                <i class="bi bi-gear"></i> Columns <i class="bi bi-chevron-down pm-caret"></i>
            </button>
            <div class="pm-columns-pop" id="pmColumnsPop" role="menu" aria-label="Column visibility" hidden>
                <div class="pm-columns-head">Columns</div>
                <div class="pm-columns-list" id="pmColumnsList">
                    {{-- Checkboxes injected by project-monitor-columns.js from table headers --}}
                </div>
                <div class="pm-columns-foot">
                    <button type="button" id="pmColumnsShowAll">Show all</button>
                    <span class="pm-columns-sep">·</span>
                    <button type="button" id="pmColumnsHideAll">Hide all</button>
                    <span class="pm-columns-sep">·</span>
                    <button type="button" id="pmColumnsReset">Reset</button>
                </div>
            </div>
        </div>
        <div class="view-toggle-group pm-view-toggle">
            <button class="view-toggle-btn active" id="gridViewBtn" onclick="toggleViewMode('grid')">
                <i class="bi bi-grid-3x3-gap"></i> Grid
            </button>
            <button class="view-toggle-btn" id="tableViewBtn" onclick="toggleViewMode('table')">
                <i class="bi bi-table"></i> Table
            </button>
        </div>
    </div>
</div>
</div>

<div class="projects-grid" id="projectsGrid">
    @if($projects->count() > 0)
        @foreach($projects as $project)
            @php
                // Health comes from ProjectHealthService (computed in the
                // controller via $healthMap) — same definition as the dashboard.
                $health = $healthMap[$project->id] ?? [];
                $daysRemaining = $health['days_remaining'] ?? $project->getDaysRemaining();
                $isOverdue = $health['is_overdue'] ?? $project->isOverdue();
                $isAtRisk = $health['is_at_risk'] ?? $project->isAtRisk();
                $progress = $health['progress'] ?? $project->getProgressPercentage();
                $healthLevel = $health['level'] ?? null;
                $progressHealth = $isOverdue ? 'danger' : ($isAtRisk ? 'warning' : ($progress >= 80 ? 'healthy' : 'normal'));
                $riskStatus = $isOverdue ? 'danger' : ($isAtRisk ? 'warning' : 'success');
                $isCompleted = $project->status === 'Completed';
                $completionDelay = $project->getCompletionDelay();
                $completionStatusLabel = $project->getCompletionStatusLabel();
                $completionStatusColor = $project->getCompletionStatusColor();
            @endphp
            <div class="project-card {{ $isOverdue ? 'overdue' : ($isAtRisk ? 'at-risk' : (in_array($healthLevel, ['no_data', 'neutral']) ? 'no-data' : 'on-track')) }}" 
                 data-status="{{ strtolower($project->status) }}"
                 data-id="{{ $project->id }}"
                 data-start-year="{{ $project->planned_start_date ? $project->planned_start_date->format('Y') : '' }}"
                 data-deadline-year="{{ $project->planned_end_date ? $project->planned_end_date->format('Y') : '' }}">

                <div class="project-header has-cover-bg" style="background: linear-gradient(rgba(11,31,75,0.62), rgba(20,43,99,0.62)), url('{{ asset('images/login-image.png') }}') center/cover no-repeat;">
                    <div class="project-header-left">
                        @if(($unreadByProject->get($project->id, 0) ?? 0) > 0)
                            <span style="position:absolute;top:10px;right:10px;width:10px;height:10px;border-radius:50%;background:#ef4444;box-shadow:0 0 0 2px rgba(255,255,255,0.8);" title="Unread notifications"></span>
                        @endif
                        <div class="project-code">{{ $project->code }}</div>
                        <div class="project-title">{{ $project->title }}</div>
                        
                        <div class="status-badge-row">
                            <span class="status-badge {{ strtolower($project->status) }}">
                                @if($project->status === 'Ongoing')
                                    <i class="bi bi-arrow-repeat"></i> Ongoing
                                @elseif($project->status === 'Completed')
                                    <i class="bi bi-check-circle"></i> Completed
                                @elseif($project->status === 'Planned')
                                    <i class="bi bi-calendar-check"></i> Planned
                                @elseif($project->status === 'Suspended')
                                    <i class="bi bi-pause-circle"></i> Suspended
                                @else
                                    {{ $project->status }}
                                @endif
                            </span>

                            {{-- Planned but its start date has passed (restudy F-9, 2026-09-21; same
                                 condition as the daily ProjectNotStartedReminder) --}}
                            @if($project->status === 'Planned' && $project->planned_start_date
                                && $project->planned_start_date->copy()->startOfDay()->lt(\App\Support\DateHelper::now()->copy()->startOfDay()))
                                <span class="health-badge at-risk" title="Planned start {{ $project->planned_start_date->format('M d, Y') }} has passed — status is still Planned">
                                    <i class="bi bi-hourglass-top"></i> Not yet started
                                </span>
                            @endif

                            @if($isCompleted)
                                <span class="health-badge {{ $completionDelay === 0 ? 'on-schedule' : ($completionDelay > 0 ? 'late' : 'early') }}">
                                    @if($completionDelay === 0)
                                        <i class="bi bi-check-lg"></i> On Schedule
                                    @elseif($completionDelay > 0)
                                        <i class="bi bi-exclamation-lg"></i> {{ $completionDelay }} days late
                                    @else
                                        <i class="bi bi-lightning-fill"></i> {{ abs($completionDelay) }} days early
                                    @endif
                                </span>
                            @else
                                <span class="health-badge {{ $isOverdue ? 'overdue' : ($isAtRisk ? 'at-risk' : ($healthLevel === 'no_data' || $healthLevel === 'neutral' ? 'no-data' : 'on-track')) }}"
                                      @if(!empty($health['tooltip'])) title="{{ $health['tooltip'] }}" @endif>
                                    @if($isOverdue)
                                        <i class="bi bi-exclamation-triangle-fill"></i> Overdue
                                    @elseif($isAtRisk)
                                        <i class="bi bi-exclamation-diamond-fill"></i> At Risk
                                    @elseif($healthLevel === 'no_data')
                                        <i class="bi bi-hourglass-split"></i> No Data
                                    @elseif($healthLevel === 'neutral')
                                        <i class="bi bi-pause-circle-fill"></i> {{ $health['label'] ?? 'Planned' }}
                                    @else
                                        <i class="bi bi-check-circle-fill"></i> On Track
                                    @endif
                                </span>
                                @if($health['escalated'] ?? false)
                                <span style="margin-left: 4px; color: #d97706; cursor: help;" title="{{ $health['escalation_reason'] }}">
                                    <i class="bi bi-info-circle" style="font-size: 12px;"></i>
                                </span>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>

                <div class="project-body">
                    
                    @if($project->contractor)
                        <div class="contractor-section">
                            <div class="contractor-avatar"><i class="bi bi-building"></i></div>
                            <div class="contractor-details">
                                <div class="contractor-label"><i class="bi bi-briefcase"></i>CONTRACTOR</div>
                                <div class="contractor-name">{{ $project->contractor->company_name ?: ($project->contractor->contractor_name ?: ($project->contractor->user?->name ?? 'Unknown')) }}</div>
                            </div>
                        </div>
                    @endif

                    <div class="progress-section">
                        <div class="progress-label">
                            <span class="progress-title"><i class="bi bi-bar-chart-line"></i>OVERALL PROGRESS</span>
                            <span class="progress-percent">{{ $progress }}%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-fill {{ $progressHealth }}" style="width: {{ $progress }}%"></div>
                        </div>
                    </div>

                    @if(!$isCompleted)
                        <div class="schedule-info">
                            <div class="schedule-row">
                                <span class="schedule-label"><i class="bi bi-calendar-check"></i>Deadline:</span>
                                <span class="schedule-value {{ $riskStatus }}">
                                    {{ $project->planned_end_date ? $project->planned_end_date->format('M d, Y') : 'TBD' }}
                                </span>
                            </div>
                            <div class="schedule-row">
                                <span class="schedule-label"><i class="bi bi-hourglass-split"></i>Days Remaining:</span>
                                <span class="schedule-value {{ $riskStatus }}">
                                    @if($daysRemaining !== null)
                                        @if($daysRemaining < 0)
                                            OVERDUE ({{ abs($daysRemaining) }} days)
                                        @else
                                            {{ $daysRemaining }} days
                                        @endif
                                    @else
                                        N/A
                                    @endif
                                </span>
                            </div>
                            <div class="schedule-row">
                                <span class="schedule-label"><i class="bi bi-circle"></i>Status:</span>
                                <span class="schedule-value">{{ $project->status }}</span>
                            </div>
                        </div>
                    @else
                        <div class="schedule-info" style="background: #f0fdf4; border-color: #dcfce7;">
                            <div class="schedule-row">
                                <span class="schedule-label"><i class="bi bi-calendar-check"></i>Planned End Date:</span>
                                <span class="schedule-value">
                                    {{ $project->planned_end_date ? $project->planned_end_date->format('M d, Y') : 'N/A' }}
                                </span>
                            </div>
                            <div class="schedule-row">
                                <span class="schedule-label"><i class="bi bi-calendar-check-fill"></i>Actual End Date:</span>
                                <span class="schedule-value success">
                                    {{ $project->actual_end_date ? $project->actual_end_date->format('M d, Y') : 'N/A' }}
                                </span>
                            </div>
                            <div class="schedule-row">
                                <span class="schedule-label"><i class="bi bi-speedometer2"></i>Completion Status:</span>
                                <span class="schedule-value {{ $completionStatusColor }}">
                                    {{ $completionStatusLabel }}
                                </span>
                            </div>
                            @if($completionDelay !== null)
                                <div class="schedule-row" style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #dcfce7;">
                                    <span class="schedule-label"><i class="bi bi-info-circle"></i>Time Variance:</span>
                                    <span class="schedule-value {{ $completionDelay > 0 ? 'warning' : 'success' }}">
                                        @if($completionDelay > 0)
                                            +{{ $completionDelay }} days (Delayed)
                                        @elseif($completionDelay < 0)
                                            {{ $completionDelay }} days (Early)
                                        @else
                                            On Schedule
                                        @endif
                                    </span>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="budget-info">
                        <div class="budget-row">
                            <span class="budget-label"><i class="bi bi-cash-coin"></i>Contract Price:</span>
                            <span class="budget-value">{{ \App\Helpers\FormatHelper::formatCurrency($project->approved_budget ?? 0) }}</span>
                        </div>
                    </div>

                    @if($project->activities()->count() > 0)
                        <div class="expandable-section">
                            <div class="expandable-header" onclick="toggleExpand(this)">
                                <span><i class="bi bi-checklist"></i>MONTHLY ACTIVITIES ({{ $project->activities()->count() }})</span>
                                <i class="bi bi-chevron-right expand-icon"></i>
                            </div>
                            <div class="expandable-content">
                                <div style="color: var(--text-muted); font-size: 12px; padding: 8px;">
                                    <i class="bi bi-info-circle"></i> {{ $project->activities()->where('is_completed', true)->count() }} of {{ $project->activities()->count() }} completed
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="expandable-section">
                        <div class="expandable-header" onclick="toggleExpand(this)">
                            <span><i class="bi bi-info-circle"></i>PROJECT DETAILS</span>
                            <i class="bi bi-chevron-right expand-icon"></i>
                        </div>
                        <div class="expandable-content">
                            <div class="details-grid">
                                <div class="detail-item">
                                    <div class="detail-label"><i class="bi bi-geo-alt"></i>Location</div>
                                    <div class="detail-value">{{ $project->location ?? 'N/A' }}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="bi bi-tag"></i>Category</div>
                                    <div class="detail-value">{{ $project->category?->name ?? 'N/A' }}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="bi bi-circle"></i>Status</div>
                                    <div class="detail-value">{{ $project->status }}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><i class="bi bi-people"></i>Est. Workers</div>
                                    <div class="detail-value">{{ $project->estimated_workers ?? 'N/A' }}</div>
                                </div>
                                @if($project->assigned_project_manager)
                                    <div class="detail-item">
                                        <div class="detail-label"><i class="bi bi-person-badge"></i>Project Manager</div>
                                        <div class="detail-value">{{ $project->getProjectManagerName() }}</div>
                                    </div>
                                @endif
                                @if($project->project_manager_contact)
                                    <div class="detail-item">
                                        <div class="detail-label"><i class="bi bi-telephone"></i>PM Contact</div>
                                        <div class="detail-value">{{ $project->project_manager_contact }}</div>
                                    </div>
                                @endif
                            </div>
                            @if($project->description)
                                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border);">
                                    <div class="detail-label" style="margin-bottom: 8px;"><i class="bi bi-file-text"></i>Description</div>
                                    <div style="font-size: 12px; color: var(--text-main); line-height: 1.5;">{{ $project->description }}</div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Expandable: Completion Analytics (For Completed Projects) -->
                    @if($isCompleted)
                        <div class="expandable-section">
                            <div class="expandable-header" onclick="toggleExpand(this)">
                                <span><i class="bi bi-graph-up"></i>COMPLETION ANALYTICS</span>
                                <i class="bi bi-chevron-right expand-icon"></i>
                            </div>
                            <div class="expandable-content">
                                <div class="details-grid">
                                    @if($project->planned_duration_days)
                                        <div class="detail-item">
                                            <div class="detail-label"><i class="bi bi-hourglass"></i>Planned Duration</div>
                                            <div class="detail-value">{{ $project->planned_duration_days }} days</div>
                                        </div>
                                    @endif
                                    @if($project->actual_duration_days)
                                        <div class="detail-item">
                                            <div class="detail-label"><i class="bi bi-clock-history"></i>Actual Duration</div>
                                            <div class="detail-value">{{ $project->actual_duration_days }} days</div>
                                        </div>
                                    @endif
                                    @if($project->planned_start_date)
                                        <div class="detail-item">
                                            <div class="detail-label"><i class="bi bi-calendar2-event"></i>Started</div>
                                            <div class="detail-value">{{ $project->planned_start_date->format('M d, Y') }}</div>
                                        </div>
                                    @endif
                                    @if($project->actual_start_date && $project->actual_end_date)
                                        <div class="detail-item">
                                            <div class="detail-label"><i class="bi bi-lightning-charge"></i>Execution Days</div>
                                            <div class="detail-value">{{ $project->actual_start_date->diffInDays($project->actual_end_date) }} days</div>
                                        </div>
                                    @endif
                                </div>
                                @if($completionDelay !== null)
                                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border);">
                                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                                            <div class="detail-label" style="margin-bottom: 0;"><i class="bi bi-speedometer2"></i>Schedule Performance</div>
                                            <div style="padding: 6px 12px; background: {{ $completionDelay > 0 ? 'rgba(245,158,11,0.1)' : 'rgba(16,185,129,0.1)' }}; border-radius: 4px; font-size: 12px; font-weight: 600; color: {{ $completionDelay > 0 ? '#b45309' : '#047857' }};">
                                                @if($completionDelay > 0)
                                                    -{{ $completionDelay }} days variance
                                                @elseif($completionDelay < 0)
                                                    +{{ abs($completionDelay) }} days ahead
                                                @else
                                                    100% On Schedule
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="project-actions">
                        <a href="{{ route($showRoute, $project->id) }}" class="action-btn">
                            <i class="bi bi-eye"></i>VIEW
                        </a>
                        @can('update', $project)
                            <a href="#" class="action-btn" 
                               data-bs-toggle="modal" data-bs-target="#addProjectModal" data-project-id="{{ $project->id }}">
                                <i class="bi bi-pencil-square"></i>EDIT
                            </a>
                        @endcan
                        @can('archive', $project)
                            <button class="action-btn" onclick="archiveProject('{{ route($archiveRouteName, $project->id) }}', '{{ $project->title }}')">
                                <i class="bi bi-archive"></i>ARCHIVE
                            </button>
                        @endcan
                    </div>
                </div>
            </div>
        @endforeach
    @else
        <div class="empty-state">
            <div class="empty-state-icon"><i class="bi bi-inbox"></i></div>
            <div class="empty-state-title">No Projects Found</div>
            <div>Create a new project to get started</div>
        </div>
    @endif
</div>

{{-- GRID VIEW PAGINATION --}}
<div class="dt-info-bar grid-pagination" style="display:none;">
    <div class="dt-info-text">
        @if($projects->total() > 0)
            Showing <strong>{{ $projects->firstItem() }}</strong>–<strong>{{ $projects->lastItem() }}</strong> of <strong>{{ $projects->total() }}</strong> projects
        @endif
    </div>
    <div class="dt-per-page">
        <label for="perPageSelectGrid">Show</label>
        <select id="perPageSelectGrid" onchange="changePerPage(this.value)">
            @foreach([10, 15, 25, 50] as $size)
                <option value="{{ $size }}" {{ $perPage === $size ? 'selected' : '' }}>{{ $size }}</option>
            @endforeach
        </select>
        <span>per page</span>
    </div>
</div>
@if($projects->hasPages())
    <div class="dt-pagination-wrap grid-pagination" style="display:none;">
        {{ $projects->links('vendor.pagination.bootstrap-5') }}
    </div>
@endif

<!-- TABLE VIEW -->
<div class="projects-table-card">
<div class="projects-table-wrap">
    <table class="projects-table" id="projectsTable">
    <thead>
        <tr>
            <th data-col="code"><a href="{{ $sortUrl('code') }}" class="sortable-th">Code {!! $currentSort === 'code' ? ($currentSortDir === 'asc' ? '<span class="sort-arrow active">&#9650;</span>' : '<span class="sort-arrow active">&#9660;</span>') : '<span class="sort-arrow">&#9650;&#9660;</span>' !!}</a></th>
            <th data-col="title"><a href="{{ $sortUrl('title') }}" class="sortable-th">Title {!! $currentSort === 'title' ? ($currentSortDir === 'asc' ? '<span class="sort-arrow active">&#9650;</span>' : '<span class="sort-arrow active">&#9660;</span>') : '<span class="sort-arrow">&#9650;&#9660;</span>' !!}</a></th>
            <th data-col="category"><a href="{{ $sortUrl('category') }}" class="sortable-th">Category {!! $currentSort === 'category' ? ($currentSortDir === 'asc' ? '<span class="sort-arrow active">&#9650;</span>' : '<span class="sort-arrow active">&#9660;</span>') : '<span class="sort-arrow">&#9650;&#9660;</span>' !!}</a></th>
            <th data-col="status"><a href="{{ $sortUrl('status') }}" class="sortable-th">Status {!! $currentSort === 'status' ? ($currentSortDir === 'asc' ? '<span class="sort-arrow active">&#9650;</span>' : '<span class="sort-arrow active">&#9660;</span>') : '<span class="sort-arrow">&#9650;&#9660;</span>' !!}</a></th>
            <th data-col="health">Health</th>
            <th data-col="progress">Progress</th>
            <th data-col="start"><a href="{{ $sortUrl('planned_start_date') }}" class="sortable-th">Start Date {!! $currentSort === 'planned_start_date' ? ($currentSortDir === 'asc' ? '<span class="sort-arrow active">&#9650;</span>' : '<span class="sort-arrow active">&#9660;</span>') : '<span class="sort-arrow">&#9650;&#9660;</span>' !!}</a></th>
            <th data-col="duration">Duration</th>
            <th data-col="deadline"><a href="{{ $sortUrl('planned_end_date') }}" class="sortable-th">Deadline {!! $currentSort === 'planned_end_date' ? ($currentSortDir === 'asc' ? '<span class="sort-arrow active">&#9650;</span>' : '<span class="sort-arrow active">&#9660;</span>') : '<span class="sort-arrow">&#9650;&#9660;</span>' !!}</a></th>
            <th data-col="days">Days Left</th>
            <th data-col="manager">Manager</th>
            <th data-col="cost"><a href="{{ $sortUrl('approved_budget') }}" class="sortable-th">Contract Price {!! $currentSort === 'approved_budget' ? ($currentSortDir === 'asc' ? '<span class="sort-arrow active">&#9650;</span>' : '<span class="sort-arrow active">&#9660;</span>') : '<span class="sort-arrow">&#9650;&#9660;</span>' !!}</a></th>
            <th data-col="actions" data-locked="1">Actions</th>
        </tr>
    </thead>
    <tbody>
        @if($projects->count() > 0)
            @foreach($projects as $project)
                @php
                    $health = $healthMap[$project->id] ?? [];
                    $daysRemaining = $health['days_remaining'] ?? $project->getDaysRemaining();
                    $isOverdue = $health['is_overdue'] ?? $project->isOverdue();
                    $isAtRisk = $health['is_at_risk'] ?? $project->isAtRisk();
                    $progress = $health['progress'] ?? $project->getProgressPercentage();
                    $progressHealth = $isOverdue ? 'danger' : ($isAtRisk ? 'warning' : ($progress >= 80 ? 'healthy' : 'normal'));
                    $riskStatus = $isOverdue ? 'danger' : ($isAtRisk ? 'warning' : 'success');
                    $healthLevel = $health['level'] ?? null;
                    $isCompleted = $project->status === 'Completed';
                @endphp
                <tr data-status="{{ strtolower($project->status) }}" data-id="{{ $project->id }}"
                    data-start-year="{{ $project->planned_start_date ? $project->planned_start_date->format('Y') : '' }}"
                    data-deadline-year="{{ $project->planned_end_date ? $project->planned_end_date->format('Y') : '' }}">
                    <td data-col="code">
                        <span class="table-project-code">{{ $project->code }}</span>
                        @if(($unreadByProject->get($project->id, 0) ?? 0) > 0)
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;margin-left:4px;vertical-align:middle;" title="Unread notifications"></span>
                        @endif
                    </td>
                    <td data-col="title"><span class="table-project-title" title="{{ $project->title }}">{{ $project->title }}</span></td>
                    <td data-col="category"><span class="table-category">{{ $project->category?->name ?? 'N/A' }}</span></td>
                    <td data-col="status">
                        <span class="table-status-badge {{ strtolower($project->status) }}" title="{{ $isCompleted && isset($completionDelay) ? ($completionDelay === 0 ? 'On Time' : ($completionDelay > 0 ? 'Late' : 'Early')) : $project->status }}">
                            @if($project->status === 'Ongoing')
                                <i class="bi bi-play-circle-fill"></i>
                            @elseif($project->status === 'Completed')
                                @php
                                    $completionDelay = $project->getCompletionDelay();
                                @endphp
                                @if($completionDelay === 0)
                                    <i class="bi bi-check-lg"></i>
                                @elseif($completionDelay > 0)
                                    <i class="bi bi-exclamation-lg"></i>
                                @else
                                    <i class="bi bi-lightning-fill"></i>
                                @endif
                            @elseif($project->status === 'Planned')
                                <i class="bi bi-calendar-event"></i>
                            @elseif($project->status === 'Suspended')
                                <i class="bi bi-pause-circle-fill"></i>
                            @endif
                            <span class="status-text">{{ $project->status }}</span>
                        </span>
                    </td>
                    <td data-col="health">
                        @php
                            // Planned but its start date has passed (restudy F-9, 2026-09-21; same
                            // condition as the card view badge and the daily ProjectNotStartedReminder)
                            $tableNotStarted = $project->status === 'Planned' && $project->planned_start_date
                                && $project->planned_start_date->copy()->startOfDay()->lt(\App\Support\DateHelper::now()->copy()->startOfDay());
                        @endphp
                        @if($tableNotStarted)
                        <span class="table-health-badge at-risk" title="Planned start {{ $project->planned_start_date->format('M d, Y') }} has passed — status is still Planned">
                            <i class="bi bi-hourglass-top"></i> Not yet started
                        </span>
                        @else
                        <span class="table-health-badge {{ $isOverdue ? 'overdue' : ($isAtRisk ? 'at-risk' : ($healthLevel === 'no_data' || $healthLevel === 'neutral' ? 'no-data' : 'on-track')) }}"
                              @if(!empty($health['tooltip'])) title="{{ $health['tooltip'] }}" @endif>
                            @if($isOverdue)
                                <i class="bi bi-exclamation-triangle-fill"></i> Overdue
                            @elseif($isAtRisk)
                                <i class="bi bi-exclamation-diamond-fill"></i> At Risk
                            @elseif($healthLevel === 'no_data')
                                <i class="bi bi-hourglass-split"></i> No Data
                            @elseif($healthLevel === 'neutral')
                                <i class="bi bi-pause-circle-fill"></i> {{ $health['label'] ?? 'Planned' }}
                            @else
                                <i class="bi bi-check-circle-fill"></i> On Track
                            @endif
                        </span>
                        @endif
                        @if($health['escalated'] ?? false)
                        <span style="margin-left: 4px; color: #d97706; cursor: help;" title="{{ $health['escalation_reason'] }}">
                            <i class="bi bi-info-circle" style="font-size: 12px;"></i>
                        </span>
                        @endif
                    </td>
                    <td data-col="progress">
                        <div class="table-progress-cell">
                            <div class="table-progress-bar">
                                <div class="table-progress-fill {{ $progressHealth }}" style="width: {{ $progress }}%"></div>
                            </div>
                            <div class="table-progress-text">{{ $progress }}%</div>
                        </div>
                    </td>
                    <td data-col="start"><span class="table-start-date">{{ $project->planned_start_date ? $project->planned_start_date->format('M d, Y') : 'TBD' }}</span></td>
                    <td data-col="duration"><span class="table-duration">{{ $project->planned_duration_days ? $project->planned_duration_days . ' days' : 'N/A' }}</span></td>
                    <td data-col="deadline">
                        @if(!$isCompleted)
                            <span class="table-deadline {{ $riskStatus }}">
                                {{ $project->planned_end_date ? $project->planned_end_date->format('M d, Y') : 'TBD' }}
                            </span>
                        @else
                            <span class="table-deadline">
                                {{ $project->actual_end_date ? $project->actual_end_date->format('M d, Y') : 'N/A' }}
                            </span>
                        @endif
                    </td>
                    <td data-col="days">
                        @if(!$isCompleted)
                            @if($daysRemaining !== null)
                                <span class="table-days-remaining {{ $riskStatus }}">
                                    @if($daysRemaining < 0)
                                        OVERDUE ({{ abs($daysRemaining) }})
                                    @else
                                        {{ $daysRemaining }} days
                                    @endif
                                </span>
                            @else
                                <span class="table-days-remaining">N/A</span>
                            @endif
                        @else
                            <span class="table-days-remaining">—</span>
                        @endif
                    </td>
                    <td data-col="manager">
                        @if($project->assigned_project_manager)
                            <span class="table-manager">{{ $project->getProjectManagerName() }}</span>
                        @else
                            <span class="table-manager-empty">Unassigned</span>
                        @endif
                    </td>
                    <td data-col="cost"><span class="table-budget">{{ \App\Helpers\FormatHelper::formatCurrency($project->approved_budget ?? 0) }}</span></td>
                    <td data-col="actions">
                        <button type="button" class="pm-kebab" aria-haspopup="true" aria-expanded="false" title="Row actions"
                            data-view-url="{{ route($showRoute, $project->id) }}"
                            @can('update', $project) data-edit-id="{{ $project->id }}" @endcan
                            @can('archive', $project) data-archive-url="{{ route($archiveRouteName, $project->id) }}" data-archive-title="{{ $project->title }}" @endcan>
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                    </td>
                </tr>
            @endforeach
        @else
            <tr>
                <td colspan="13" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    No projects found
                </td>
            </tr>
        @endif
    </tbody>
    </table>
</div>

{{-- PAGINATION CONTROLS + INFO BAR --}}
<div class="pm-card-foot">
<div class="dt-info-bar">
    <div class="dt-info-text">
        @if($projects->total() > 0)
            Showing <strong>{{ $projects->firstItem() }}</strong>–<strong>{{ $projects->lastItem() }}</strong> of <strong>{{ $projects->total() }}</strong> projects
        @else
            No projects found
        @endif
    </div>
    <div class="dt-per-page">
        <label for="perPageSelect">Show</label>
        <select id="perPageSelect" onchange="changePerPage(this.value)">
            @foreach([10, 15, 25, 50] as $size)
                <option value="{{ $size }}" {{ $perPage === $size ? 'selected' : '' }}>{{ $size }}</option>
            @endforeach
        </select>
        <span>per page</span>
    </div>
</div>

@if($projects->hasPages())
    <div class="dt-pagination-wrap">
        {{ $projects->links('vendor.pagination.bootstrap-5') }}
    </div>
@endif
</div>
</div>

{{-- Single floating row-action menu (body-level, positioned via JS so it is
     never clipped by the table scroll container or covered by other rows) --}}
<div class="pm-row-menu-pop pm-row-menu-floating" id="pmRowMenu" hidden>
    <a href="#" class="pm-row-menu-item" id="pmRowMenuView">
        <i class="bi bi-eye"></i> View Project
    </a>
    <a href="#" class="pm-row-menu-item" id="pmRowMenuEdit" data-bs-toggle="modal" data-bs-target="#addProjectModal">
        <i class="bi bi-pencil-square"></i> Edit Project
    </a>
    <button type="button" class="pm-row-menu-item pm-row-menu-danger" id="pmRowMenuArchive">
        <i class="bi bi-archive"></i> Archive
    </button>
</div>

{{-- Apply the saved Grid/Table mode synchronously (before first paint) so a
     refresh never flashes the wrong view while waiting for the main script. --}}
<script>
(function(){try{
var mode=localStorage.getItem('pmu_projects_view_mode')||'grid';
var grid=document.getElementById('projectsGrid');
var tbl=document.getElementById('projectsTable');
var card=document.querySelector('.projects-table-card');
var gb=document.getElementById('gridViewBtn'),tb=document.getElementById('tableViewBtn');
var gp=document.querySelectorAll('.grid-pagination');
var feet=document.querySelectorAll('.pm-card-foot');
if(mode==='table'){
if(grid)grid.style.display='none';
if(tbl)tbl.classList.add('show');
if(card)card.style.display='block';
if(gb)gb.classList.remove('active');if(tb)tb.classList.add('active');
gp.forEach(function(e){e.style.display='none';});
feet.forEach(function(e){e.style.display='';});
}else{
if(grid)grid.style.display='grid';
if(tbl)tbl.classList.remove('show');
if(card)card.style.display='none';
if(gb)gb.classList.add('active');if(tb)tb.classList.remove('active');
gp.forEach(function(e){e.style.display='';});
feet.forEach(function(e){e.style.display='none';});
}}catch(e){}})();
</script>

@include('components.delete-confirmation-modal')
@include('components.warning-confirm-modal')
@include('components.loading-overlay', ['id' => 'archiveProjectLoadingOverlay', 'label' => 'Working…'])

{{-- Show the success toast AFTER the post-archive page reload (flash set by
     ProjectController@archive → session('toast_success')). --}}
@if(session('toast_success'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.showToast(@json(session('toast_success')), 'success');
    });
</script>
@endif

<script>
function archiveProject(url, projectTitle) {
    showDeleteConfirmation(
        'Archive Project',
        `Are you sure you want to archive <strong>"${projectTitle}"</strong>? ` +
        `Archived projects are hidden from the active list but can be restored at any time. ` +
        `They are <strong>not</strong> permanently deleted.`,
        function() {
            showLoadingOverlay('archiveProjectLoadingOverlay', 'Archiving project…');
            fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Content-Type': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Keep the overlay visible through the reload: re-label it and
                    // let location.reload() tear the page down (no gap between the
                    // overlay hiding and the reload painting). The success toast is
                    // flashed server-side and rendered after this reload.
                    showLoadingOverlay('archiveProjectLoadingOverlay', 'Almost done…');
                    location.reload();
                } else {
                    hideLoadingOverlay('archiveProjectLoadingOverlay');
                    showToast(data.message || 'Failed to archive project', 'error');
                }
            })
            .catch(err => {
                console.error('Archive error:', err);
                hideLoadingOverlay('archiveProjectLoadingOverlay');
                showToast('Error archiving project. Please try again.', 'error');
            });
        }
    );
}
</script>

@endsection
{{-- CREATE/EDIT PROJECT MODAL --}}
@if(in_array(auth()->user()->role, ['admin', 'staff']))
    @php
        $routePrefix = auth()->user()->role === 'admin' ? 'admin.projects' : 'staff.projects';
    @endphp
    @include('admin._create_project_modal', ['admins' => $admins, 'staff' => $staff, 'routePrefix' => $routePrefix, 'documentTypes' => $documentTypes ?? []])
    @include('projects.project_detail.components.modals._setup_baseline_modal')
    @if(auth()->user()->role === 'admin')
        @include('admin._manage_categories_modal')
        @include('admin._manage_budget_sources_modal')
        @include('admin._manage_document_types_modal')
    @endif
@endif

{{-- Chart.js for S-Curve preview in Set Up Baseline modal --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
{{-- S-Curve chart rendering --}}
<script src="{{ asset('js/admin/projects/s-curve-chart.js') }}"></script>
{{-- Baseline schedule utilities --}}
<script src="{{ asset('js/admin/projects/baseline-schedule-utils.js') }}"></script>
{{-- Set Up Baseline modal logic (filemtime cache-bust: same $jsv convention as _create_project_modal) --}}
@php $jsvSetupModal = fn($p) => asset($p) . '?v=' . filemtime(public_path($p)); @endphp
<script src="{{ $jsvSetupModal('js/admin/projects/baseline-schedule-setup-modal.js') }}"></script>
{{-- Project Monitor UI: column visibility + row menus (frontend only, no backend change) --}}
<script src="{{ asset('js/admin/projects/project-monitor-columns.js') }}"></script>

<script>
let currentSearchTerm = '';
let currentStatusFilter = 'all';

function changePerPage(value) {
    const params = new URLSearchParams(window.location.search);
    params.set('per_page', value);
    params.delete('page');
    window.location.search = params.toString();
}

function filterProjects(status) {
    // Use closest() so clicks on icons inside the button still activate the right tab.
    const btn = (typeof event !== 'undefined' && event.target)
        ? (event.target.closest ? (event.target.closest('.filter-btn') || event.target) : event.target)
        : null;
    const buttons = document.querySelectorAll('.filter-btn');
    buttons.forEach(b => b.classList.remove('active'));
    if (btn && btn.classList) btn.classList.add('active');
    currentStatusFilter = status;
    applyFilters();
}

function performSearch(searchTerm) {
    currentSearchTerm = searchTerm.toLowerCase();
    applyFilters();
}

function applyFilters() {
    const filterStartYear = document.getElementById('filterStartYear')?.value || '';
    const filterDeadlineYear = document.getElementById('filterDeadlineYear')?.value || '';

    const cards = document.querySelectorAll('.project-card');
    cards.forEach(card => {
        const status = card.dataset.status;
        const code = card.querySelector('.project-code')?.textContent.toLowerCase() || '';
        const title = card.querySelector('.project-title')?.textContent.toLowerCase() || '';
        const contractor = card.querySelector('.contractor-name')?.textContent.toLowerCase() || '';
        const sy = card.dataset.startYear || '';
        const dy = card.dataset.deadlineYear || '';

        const statusMatch = currentStatusFilter === 'all' || status === currentStatusFilter;
        const searchMatch = currentSearchTerm === '' || code.includes(currentSearchTerm) || title.includes(currentSearchTerm) || contractor.includes(currentSearchTerm);
        const startYearMatch = filterStartYear === '' || sy === filterStartYear;
        const deadlineYearMatch = filterDeadlineYear === '' || dy === filterDeadlineYear;

        card.style.display = (statusMatch && searchMatch && startYearMatch && deadlineYearMatch) ? 'flex' : 'none';
    });

    const rows = document.querySelectorAll('.projects-table tbody tr');
    rows.forEach(row => {
        const status = row.dataset.status;
        const code = row.querySelector('.table-project-code')?.textContent.toLowerCase() || '';
        const title = row.querySelector('.table-project-title')?.textContent.toLowerCase() || '';
        const contractor = row.querySelector('.table-contractor-name')?.textContent.toLowerCase() || row.querySelector('.table-contractor-empty')?.textContent.toLowerCase() || '';
        const sy = row.dataset.startYear || '';
        const dy = row.dataset.deadlineYear || '';

        const statusMatch = currentStatusFilter === 'all' || status === currentStatusFilter;
        const searchMatch = currentSearchTerm === '' || code.includes(currentSearchTerm) || title.includes(currentSearchTerm) || contractor.includes(currentSearchTerm);
        const startYearMatch = filterStartYear === '' || sy === filterStartYear;
        const deadlineYearMatch = filterDeadlineYear === '' || dy === filterDeadlineYear;

        row.style.display = (statusMatch && searchMatch && startYearMatch && deadlineYearMatch) ? 'table-row' : 'none';
    });
}

function toggleExpand(header) {
    header.classList.toggle('active');
    const content = header.nextElementSibling;
    if (content && content.classList.contains('expandable-content')) {
        content.classList.toggle('show');
    }
}

function toggleViewMode(mode) {
    const grid = document.getElementById('projectsGrid');
    const table = document.getElementById('projectsTable');
    const card = document.querySelector('.projects-table-card');
    const gridBtn = document.getElementById('gridViewBtn');
    const tableBtn = document.getElementById('tableViewBtn');
    const gridPaginations = document.querySelectorAll('.grid-pagination');
    const tablePaginations = document.querySelectorAll('.projects-table-card .dt-info-bar, .projects-table-card .dt-pagination-wrap, .pm-card-foot');

    if (mode === 'grid') {
        grid.style.setProperty('display', 'grid', 'important');
        table.classList.remove('show');
        if (card) card.style.setProperty('display', 'none', 'important');
        gridBtn.classList.add('active');
        tableBtn.classList.remove('active');
        gridPaginations.forEach(el => el.style.display = '');
        tablePaginations.forEach(el => el.style.display = 'none');
    } else if (mode === 'table') {
        grid.style.setProperty('display', 'none', 'important');
        table.classList.add('show');
        if (card) card.style.setProperty('display', 'block', 'important');
        gridBtn.classList.remove('active');
        tableBtn.classList.add('active');
        gridPaginations.forEach(el => el.style.display = 'none');
        tablePaginations.forEach(el => el.style.display = '');
    }

    saveViewPreference(mode);
}

function saveViewPreference(mode) {
    localStorage.setItem('pmu_projects_view_mode', mode);
}

function loadViewPreference() {
    const savedMode = localStorage.getItem('pmu_projects_view_mode') || 'grid';
    toggleViewMode(savedMode);
}

document.addEventListener('DOMContentLoaded', function() {
    loadViewPreference();
});
</script>
