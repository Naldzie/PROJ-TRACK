<!-- PROJECT HEADER - IMPROVED DESIGN -->
<div class="header-section">
    <div class="header-content">
        <!-- Code and Category -->
        <div class="project-code">{{ $project->code }} • {{ $project->category?->name ?? 'Project' }}</div>
        
        <!-- Title -->
        <h1 class="project-title">{{ $project->title }}</h1>
        
        <!-- Meta Information -->
        <div class="header-meta">
            <div class="meta-item">
                <span class="meta-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                </span>
                <div class="meta-info">
                    <strong>Location</strong>
                    <span>{{ $project->location ?? 'Not specified' }}</span>
                </div>
            </div>
            {{--  
            @if($project->contractor)
            <div class="meta-item">
                <span class="meta-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <div class="meta-info">
                    <strong>Contractor</strong>
                    <span>{{ $project->contractor->company_name }}</span>
                </div>
            </div>
            @endif
            --}}

            @if($project->assigned_project_manager)
            <div class="meta-item">
                <span class="meta-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
            </div>
            @endif
            
            <div class="meta-item">
                <span class="meta-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <div class="meta-info">
                    <strong>Duration</strong>
                    <span>
                        @if($project->status === 'Completed' && $project->actual_start_date && $project->actual_end_date)
                            <!-- Completed: Show actual dates -->
                            {{ $project->actual_start_date->format('M d, Y') }} - {{ $project->actual_end_date->format('M d, Y') }}
                        @elseif(($project->status === 'Ongoing' || $project->status === 'In Progress') && $project->actual_start_date)
                            <!-- Ongoing: Show actual start → planned end -->
                            {{ $project->actual_start_date->format('M d, Y') }} - 
                            @if($project->planned_end_date)
                                {{ $project->planned_end_date->format('M d, Y') }}
                            @else
                                TBA
                            @endif
                        @elseif($project->planned_start_date && $project->planned_end_date)
                            <!-- Not started or planned: Show planned dates -->
                            {{ $project->planned_start_date->format('M d, Y') }} - {{ $project->planned_end_date->format('M d, Y') }}
                        @else
                            Not scheduled
                        @endif
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Badges and Buttons -->
        <div class="header-badges">
            <!-- Left Side: Status & Progress Badges -->
            <div class="header-badges-left">
                <div class="badge">
                    <span class="status-dot"></span>
                    {{ $project->status }}
                </div>
                
                <div class="badge">
                    <span class="badge-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </span>
                    <strong>{{ number_format($data['overview']['progress'], 0) }}%</strong> Complete
                </div>
            </div>

            <!-- Right Side: Action Buttons -->
            <div class="header-badges-right">
                @if($project->proposal)
                <button type="button" class="header-badge-btn" data-bs-toggle="modal" data-bs-target="#proposalDetailModal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Proposal
                </button>
                @endif

                @php($reportRole = auth()->user()->role)
                <div class="dropdown d-inline-block">
                    <button type="button" class="header-badge-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        Reports
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-header">Export</span></li>
                        <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.summary', $project->id) }}">
                            <i class="bi bi-file-earmark-pdf"></i> Project Summary (PDF)</a></li>
                        <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.progress', $project->id) }}">
                            <i class="bi bi-file-earmark-excel"></i> Progress History (Excel)</a></li>
                        <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.baseline', $project->id) }}">
                            <i class="bi bi-file-earmark-excel"></i> Baseline Schedule (Excel)</a></li>
                        <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.extensions', $project->id) }}">
                            <i class="bi bi-file-earmark-excel"></i> Extensions (Excel)</a></li>
                        <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.issues', $project->id) }}">
                            <i class="bi bi-file-earmark-excel"></i> Issues (Excel)</a></li>
                    </ul>
                </div>
                
                @can('update', $project)
                    <button type="button" class="header-badge-btn" 
                            data-bs-toggle="modal" data-bs-target="#addProjectModal" data-project-id="{{ $project->id }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Edit
                    </button>
                @endcan
            </div>
        </div>
    </div>
</div>
