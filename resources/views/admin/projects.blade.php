@extends('layouts.app')

@section('content')

<div class="page-header">
    <h1> Project Monitor(admin)</h1>
    <button class="btn-primary" data-bs-toggle="modal" data-bs-target="#addProjectModal">
        <i class="bi bi-plus-circle"></i> New Project
    </button>
</div>

<div class="dashboard-metrics">
    <div class="metric-card success">
        <div class="metric-label"><i class="bi bi-bar-chart"></i> Active Projects</div>
        <div class="metric-value">{{ $metrics['active_projects'] ?? 0 }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-label"><i class="bi bi-check-circle"></i> Completed</div>
        <div class="metric-value">{{ $metrics['completed_projects'] ?? 0 }}</div>
    </div>
    <div class="metric-card warning">
        <div class="metric-label"><i class="bi bi-exclamation-triangle"></i> At Risk</div>
        <div class="metric-value">{{ $projects->filter(fn($p) => $p->isAtRisk())->count() }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-label"><i class="bi bi-cash-stack"></i> Total Budget</div>
        <div class="metric-value">{{ \App\Helpers\FormatHelper::formatCurrencyShort($metrics['total_budget'] ?? 0) }}</div>
    </div>
</div>

<div class="filters-section">
    <div style="display: flex; gap: 12px; align-items: center; flex: 1;">
        <button class="filter-btn active" onclick="filterProjects('all')">All</button>
        <button class="filter-btn" onclick="filterProjects('ongoing')"><i class="bi bi-arrow-repeat"></i> Ongoing</button>
        <button class="filter-btn" onclick="filterProjects('planned')"><i class="bi bi-clipboard-check"></i> Planned</button>
        <button class="filter-btn" onclick="filterProjects('completed')"><i class="bi bi-check-circle"></i> Completed</button>
    </div>
    
    <div style="display: flex; gap: 12px; align-items: center;">
        <div style="position: relative; flex-shrink: 0;">
            <input type="text" id="projectSearch" placeholder="🔍 Search by code, title, or contractor" 
                   style="padding: 10px 14px 10px 40px; border: 2px solid var(--primary); background: rgba(37,99,235,0.05); border-radius: 8px; width: 280px; font-size: 13px; transition: 0.3s; font-weight: 500;" 
                   oninput="performSearch(this.value)"
                   onfocus="this.style.background='rgba(37,99,235,0.08)'; this.style.borderColor='#2563eb'; this.style.boxShadow='0 0 0 3px rgba(37,99,235,0.1)';"
                   onblur="this.style.background='rgba(37,99,235,0.05)'; this.style.borderColor='var(--primary)'; this.style.boxShadow='none';">
            <i class="bi bi-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--primary); font-size: 16px; pointer-events: none; font-weight: 700;"></i>
        </div>
        <div class="view-toggle-group">
            <button class="view-toggle-btn active" id="gridViewBtn" onclick="toggleViewMode('grid')">
                <i class="bi bi-grid-3x3-gap"></i> Grid
            </button>
            <button class="view-toggle-btn" id="tableViewBtn" onclick="toggleViewMode('table')">
                <i class="bi bi-table"></i> Table
            </button>
        </div>
    </div>
</div>

<div class="projects-grid" id="projectsGrid">
    @if($projects->count() > 0)
        @foreach($projects as $project)
            @php
                $daysRemaining = $project->getDaysRemaining();
                $isOverdue = $project->isOverdue();
                $isAtRisk = $project->isAtRisk();
                $progress = $project->getProgressPercentage();
                $progressHealth = $progress >= 80 ? 'healthy' : ($progress >= 50 ? 'warning' : 'danger');
                $budgetUtilization = 65; // Hardcoded for now
                $budgetHealth = $budgetUtilization >= 75 ? 'success' : ($budgetUtilization >= 50 ? 'warning' : 'danger');
                $riskStatus = $isOverdue ? 'danger' : ($isAtRisk ? 'warning' : 'success');
                
                // For completed projects
                $isCompleted = $project->status === 'Completed';
                $completionDelay = $project->getCompletionDelay();
                $completionStatusLabel = $project->getCompletionStatusLabel();
                $completionStatusColor = $project->getCompletionStatusColor();
            @endphp
            <div class="project-card {{ $isOverdue ? 'overdue' : ($isAtRisk ? 'at-risk' : 'on-track') }}" 
                 data-status="{{ strtolower($project->status) }}"
                 data-id="{{ $project->id }}">
                
                <!-- HEADER: Code, Title, Status Badges -->
                <div class="project-header">
                    <div class="project-header-left">
                        <div class="project-code">{{ $project->code }}</div>
                        <div class="project-title">{{ $project->title }}</div>
                        
                        <!-- Status Badge Row -->
                        <div class="status-badge-row">
                            <!-- Project Status Badge -->
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

                            <!-- Schedule Health Badge -->
                            @if($isCompleted)
                                <!-- For Completed Projects -->
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
                                <!-- For Ongoing/Planned Projects -->
                                <span class="health-badge {{ $isOverdue ? 'overdue' : ($isAtRisk ? 'at-risk' : 'on-track') }}">
                                    @if($isOverdue)
                                        <i class="bi bi-exclamation-triangle-fill"></i> Overdue
                                    @elseif($isAtRisk)
                                        <i class="bi bi-exclamation-diamond-fill"></i> At Risk
                                    @else
                                        <i class="bi bi-check-circle-fill"></i> On Track
                                    @endif
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- BODY: Main Content -->
                <div class="project-body">
                    
                    <!-- Contractor Section -->
                    @if($project->contractor)
                        <div class="contractor-section">
                            <div class="contractor-avatar"><i class="bi bi-building"></i></div>
                            <div class="contractor-details">
                                <div class="contractor-label"><i class="bi bi-briefcase"></i>CONTRACTOR</div>
                                <div class="contractor-name">{{ $project->contractor->company_name ?: ($project->contractor->contractor_name ?: ($project->contractor->user?->name ?? 'Unknown')) }}</div>
                            </div>
                        </div>
                    @endif

                    <!-- Overall Progress -->
                    <div class="progress-section">
                        <div class="progress-label">
                            <span class="progress-title"><i class="bi bi-bar-chart-line"></i>OVERALL PROGRESS</span>
                            <span class="progress-percent">{{ $progress }}%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-fill {{ $progressHealth }}" style="width: {{ $progress }}%"></div>
                        </div>
                    </div>

                    <!-- Schedule Info - ONGOING PROJECTS -->
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
                        <!-- Schedule Info - COMPLETED PROJECTS -->
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

                    <!-- Budget Info -->
                    <div class="budget-info">
                        <div class="budget-row">
                            <span class="budget-label"><i class="bi bi-cash-coin"></i>Approved Budget:</span>
                            <span class="budget-value">{{ \App\Helpers\FormatHelper::formatCurrency($project->approved_budget ?? 0) }}</span>
                        </div>
                    </div>

                    <!-- Expandable: Activities -->
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

                    <!-- Expandable: Details -->
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
                                    <div class="detail-value">
                                        <span class="status-badge-inline {{ strtolower($project->status) === 'ongoing' ? 'success' : (strtolower($project->status) === 'suspended' ? 'warning' : '') }}">
                                            @if($project->status === 'Ongoing')
                                                <i class="bi bi-play-circle-fill"></i>
                                            @elseif($project->status === 'Completed')
                                                <i class="bi bi-check-circle-fill"></i>
                                            @elseif($project->status === 'Planned')
                                                <i class="bi bi-calendar-event"></i>
                                            @elseif($project->status === 'Suspended')
                                                <i class="bi bi-pause-circle-fill"></i>
                                            @endif
                            {{ $project->status }}
                                        </span>
                                    </div>
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
                                @if($project->planned_handing_over_date)
                                    <div class="detail-item">
                                        <div class="detail-label"><i class="bi bi-hand-index"></i>Handover Date</div>
                                        <div class="detail-value">{{ $project->planned_handing_over_date->format('M d, Y') }}</div>
                                    </div>
                                @endif
                                @if($project->planned_duration_days)
                                    <div class="detail-item">
                                        <div class="detail-label"><i class="bi bi-calendar-range"></i>Planned Duration</div>
                                        <div class="detail-value">{{ $project->planned_duration_days }} days</div>
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

                    <!-- Action Buttons -->
                    <div class="project-actions">
                        <a href="{{ route('admin.projects.detail', $project->id) }}" class="action-btn">
                            <i class="bi bi-eye"></i>VIEW
                        </a>
                        <a href="#" class="action-btn" 
                           data-bs-toggle="modal" data-bs-target="#addProjectModal" data-project-id="{{ $project->id }}">
                            <i class="bi bi-pencil-square"></i>EDIT
                        </a>
                        <button class="action-btn danger" onclick="deleteProject({{ $project->id }}, '{{ $project->title }}')">
                            <i class="bi bi-trash"></i>DELETE
                        </button>
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

<!-- TABLE VIEW -->
<table class="projects-table" id="projectsTable">
    <thead>
        <tr>
            <th>Code</th>
            <th>Title</th>
            <th>Contractor</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Deadline</th>
            <th>Days Remaining</th>
            <th>Budget</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @if($projects->count() > 0)
            @foreach($projects as $project)
                @php
                    $daysRemaining = $project->getDaysRemaining();
                    $isOverdue = $project->isOverdue();
                    $isAtRisk = $project->isAtRisk();
                    $progress = $project->getProgressPercentage();
                    $progressHealth = $progress >= 80 ? 'healthy' : ($progress >= 50 ? 'warning' : 'danger');
                    $budgetUtilization = 65;
                    $riskStatus = $isOverdue ? 'danger' : ($isAtRisk ? 'warning' : 'success');
                    $isCompleted = $project->status === 'Completed';
                @endphp
                <tr data-status="{{ strtolower($project->status) }}" data-id="{{ $project->id }}">
                    <td>
                        <span class="table-project-code">{{ $project->code }}</span>
                    </td>
                    <td>
                        <span class="table-project-title" title="{{ $project->title }}">{{ $project->title }}</span>
                    </td>
                    <td>
                        @if($project->contractor)
                            <span class="table-contractor-name">{{ $project->contractor->company_name ?: ($project->contractor->contractor_name ?: ($project->contractor->user?->name ?? 'Unknown')) }}</span>
                        @else
                            <span class="table-contractor-empty">N/A</span>
                        @endif
                    </td>
                    <td>
                        <span class="table-status-badge {{ strtolower($project->status) }}">
                            @if($project->status === 'Ongoing')
                                <i class="bi bi-play-circle-fill"></i>
                            @elseif($project->status === 'Completed')
                                <i class="bi bi-check-circle-fill"></i>
                            @elseif($project->status === 'Planned')
                                <i class="bi bi-calendar-event"></i>
                            @elseif($project->status === 'Suspended')
                                <i class="bi bi-pause-circle-fill"></i>
                            @endif
                            {{ $project->status }}
                        </span>
                    </td>
                    <td>
                        <div class="table-progress-cell">
                            <div class="table-progress-bar">
                                <div class="table-progress-fill {{ $progressHealth }}" style="width: {{ $progress }}%"></div>
                            </div>
                            <div class="table-progress-text">{{ $progress }}%</div>
                        </div>
                    </td>
                    <td>
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
                    <td>
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
                    <td>
                        <span class="table-budget">{{ \App\Helpers\FormatHelper::formatCurrency($project->approved_budget ?? 0) }}</span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <a href="{{ route('admin.projects.detail', $project->id) }}" class="table-action-btn" title="View">
                                <i class="bi bi-eye"></i>VIEW
                            </a>
                            <a href="#" class="table-action-btn" data-bs-toggle="modal" data-bs-target="#addProjectModal" data-project-id="{{ $project->id }}" title="Edit">
                                <i class="bi bi-pencil-square"></i>EDIT
                            </a>
                            <button class="table-action-btn danger" onclick="deleteProject({{ $project->id }}, '{{ $project->title }}')" title="Delete Permanently">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            @endforeach
        @else
            <tr>
                <td colspan="9" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    No projects found
                </td>
            </tr>
        @endif
    </tbody>
</table>

@endsection

@include('admin._create_project_modal')
@include('admin._manage_categories_modal')
@include('components.delete-confirmation-modal')
@include('projects.project_detail.components.modals._setup_baseline_modal')

{{-- Chart.js for S-Curve preview in Set Up Baseline modal --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
{{-- S-Curve chart rendering --}}
<script src="{{ asset('js/admin/projects/s-curve-chart.js') }}"></script>
{{-- Baseline schedule utilities --}}
<script src="{{ asset('js/admin/projects/baseline-schedule-utils.js') }}"></script>
{{-- Set Up Baseline modal logic --}}
<script src="{{ asset('js/admin/projects/baseline-schedule-setup-modal.js') }}"></script>

<script>
let currentSearchTerm = '';
let currentStatusFilter = 'all';

function filterProjects(status) {
    const buttons = document.querySelectorAll('.filter-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    currentStatusFilter = status;
    applyFilters();
}

function performSearch(searchTerm) {
    currentSearchTerm = searchTerm.toLowerCase();
    applyFilters();
}

function applyFilters() {
    const cards = document.querySelectorAll('.project-card');
    cards.forEach(card => {
        const status = card.dataset.status;
        const code = card.querySelector('.project-code')?.textContent.toLowerCase() || '';
        const title = card.querySelector('.project-title')?.textContent.toLowerCase() || '';
        const contractor = card.querySelector('.contractor-name')?.textContent.toLowerCase() || '';
        
        const statusMatch = currentStatusFilter === 'all' || status === currentStatusFilter;
        const searchMatch = currentSearchTerm === '' || code.includes(currentSearchTerm) || title.includes(currentSearchTerm) || contractor.includes(currentSearchTerm);
        
        card.style.display = (statusMatch && searchMatch) ? 'flex' : 'none';
    });

    const rows = document.querySelectorAll('.projects-table tbody tr');
    rows.forEach(row => {
        const status = row.dataset.status;
        const code = row.querySelector('.table-project-code')?.textContent.toLowerCase() || '';
        const title = row.querySelector('.table-project-title')?.textContent.toLowerCase() || '';
        const contractor = row.querySelector('.table-contractor-name')?.textContent.toLowerCase() || row.querySelector('.table-contractor-empty')?.textContent.toLowerCase() || '';
        
        const statusMatch = currentStatusFilter === 'all' || status === currentStatusFilter;
        const searchMatch = currentSearchTerm === '' || code.includes(currentSearchTerm) || title.includes(currentSearchTerm) || contractor.includes(currentSearchTerm);
        
        row.style.display = (statusMatch && searchMatch) ? 'table-row' : 'none';
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
    const gridBtn = document.getElementById('gridViewBtn');
    const tableBtn = document.getElementById('tableViewBtn');

    if (mode === 'grid') {
        grid.style.display = 'grid';
        table.classList.remove('show');
        gridBtn.classList.add('active');
        tableBtn.classList.remove('active');
    } else if (mode === 'table') {
        grid.style.display = 'none';
        table.classList.add('show');
        gridBtn.classList.remove('active');
        tableBtn.classList.add('active');
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

function deleteProject(projectId, projectTitle) {
    showDeleteConfirmation(
        'Delete Project',
        `<strong style="color: #ef4444;"><i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px;"></i>Warning:</strong> Are you sure you want to permanently delete <strong>"${projectTitle}"</strong> and all associated data? This action cannot be undone.`,
        function() {
            fetch(`/admin/projects/${projectId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast('success', data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('error', 'Failed to delete project');
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                showToast('error', 'Error deleting project. Please try again.');
            });
        }
    );
}

function showToast(type, message) {
    const icons = { success: 'bi-check-circle', error: 'bi-exclamation-circle' };
    const colors = { success: '#10b981', error: '#ef4444' };
    const bg = { success: '#f0fdf4', error: '#fef2f2' };
    
    const toast = document.createElement('div');
    toast.style.cssText = `
        background: ${bg[type]};
        color: ${colors[type]};
        padding: 16px 20px;
        border-radius: 8px;
        border-left: 4px solid ${colors[type]};
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 600;
        animation: slideIn 0.3s ease;
    `;
    toast.innerHTML = `<i class="bi ${icons[type]}"></i><span>${message}</span>`;
    
    let toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toastContainer';
        toastContainer.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 400px;';
        document.body.appendChild(toastContainer);
    }
    
    toastContainer.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

// Initialize view preference on page load
document.addEventListener('DOMContentLoaded', function() {
    loadViewPreference();
});
</script>