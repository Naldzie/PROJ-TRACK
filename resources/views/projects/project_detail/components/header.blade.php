<!-- PROJECT HEADER — Project Monitor card-cover language (same look as the
     project list cards: navy photo cover, white code/title, ghost
     status/health pills, contractor row, ghost action buttons).
     Badge state is attribute-only (no extra queries): overdue/at-risk are
     derived from planned_end_date + the precomputed overview progress. -->
@php
    $headerProgress  = (float) ($data['overview']['progress'] ?? 0);
    $headerCompleted = $project->status === 'Completed';
    $headerOverdue   = !$headerCompleted
        && $project->planned_end_date
        && now()->greaterThan($project->planned_end_date)
        && $headerProgress < 100;
    $headerDaysLeft  = $project->planned_end_date
        ? (int) now()->diffInDays($project->planned_end_date, false)
        : null;
    $headerAtRisk    = !$headerCompleted
        && $project->status !== 'Cancelled'
        && $headerDaysLeft !== null
        && $headerDaysLeft < 15
        && $headerProgress < 90;
    $headerDelay     = $project->getCompletionDelay();
    $headerContractor = $project->contractor->company_name
        ?? $project->contractor->contractor_name
        ?? $project->contractor->user?->name
        ?? null;
@endphp
<div class="header-section has-cover-bg" style="background: linear-gradient(rgba(11,31,75,0.62), rgba(20,43,99,0.62)), url('{{ asset('images/login-image.png') }}') center/cover no-repeat;">
    <div class="header-content">
        <!-- Code and Category -->
        <div class="project-code">{{ $project->code }} • {{ $project->category?->name ?? 'Project' }}</div>

        <!-- Title -->
        <h1 class="project-title">{{ $project->title }}</h1>

        <!-- Contractor (same avatar row as the list cards) -->
        @if($headerContractor)
        <div class="contractor-section">
            <div class="contractor-avatar"><i class="bi bi-building"></i></div>
            <div class="contractor-details">
                <div class="contractor-label"><i class="bi bi-briefcase"></i>Contractor</div>
                <div class="contractor-name">{{ $headerContractor }}</div>
            </div>
        </div>
        @endif

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

            @if($project->assigned_project_manager)
            <div class="meta-item">
                <span class="meta-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
                <div class="meta-info">
                    <strong>Project Manager</strong>
                    <span>{{ $project->getProjectManagerName() ?? 'Not assigned' }}</span>
                </div>
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
            <!-- Left Side: Status & Health Badges (list-card pills) -->
            <div class="header-badges-left">
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

                @if($headerCompleted)
                    @if($headerDelay !== null)
                    <span class="health-badge {{ $headerDelay === 0 ? 'on-schedule' : ($headerDelay > 0 ? 'late' : 'early') }}">
                        @if($headerDelay === 0)
                            <i class="bi bi-check-lg"></i> On Schedule
                        @elseif($headerDelay > 0)
                            <i class="bi bi-exclamation-lg"></i> {{ $headerDelay }} days late
                        @else
                            <i class="bi bi-lightning-fill"></i> {{ abs($headerDelay) }} days early
                        @endif
                    </span>
                    @endif
                @else
                    <span class="health-badge {{ $headerOverdue ? 'overdue' : ($headerAtRisk ? 'at-risk' : 'on-track') }}">
                        @if($headerOverdue)
                            <i class="bi bi-exclamation-triangle-fill"></i> Overdue
                        @elseif($headerAtRisk)
                            <i class="bi bi-exclamation-diamond-fill"></i> At Risk
                        @else
                            <i class="bi bi-check-circle-fill"></i> On Track
                        @endif
                    </span>
                @endif

                <span class="health-badge">
                    <i class="bi bi-clock"></i>
                    <strong>{{ number_format($headerProgress, 0) }}%</strong>&nbsp;Complete
                </span>
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
                        <li>
                            {{-- Project Monitoring Report: pick a month, then preview / Word / PDF --}}
                            <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#projectReportModal">
                                <i class="bi bi-file-earmark-text"></i> Project Report (Word / PDF)…
                            </button>
                        </li>
                        {{--
                            Excel exports HIDDEN (user decision, 2026-09-21) until the client or
                            panel asks for them — the Project Report above covers progress,
                            extensions and issues. They WORK (the "corrupt file" was the UTF-8
                            BOM, fixed on phase-38); routes, ReportExportService and
                            ReportExportTest stay in place. To re-enable, remove this comment
                            wrapper. See textfiles/project_report_overhaul_plan.md §8.

                        <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.progress', $project->id) }}">
                            <i class="bi bi-file-earmark-excel"></i> Progress History (Excel)</a></li>
                        <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.baseline', $project->id) }}">
                            <i class="bi bi-file-earmark-excel"></i> Baseline Schedule (Excel)</a></li>
                        <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.extensions', $project->id) }}">
                            <i class="bi bi-file-earmark-excel"></i> Extensions (Excel)</a></li>
                        <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.issues', $project->id) }}">
                            <i class="bi bi-file-earmark-excel"></i> Issues (Excel)</a></li>
                        --}}
                    </ul>
                </div>
                @include('projects.project_detail.components.modals._project_report_modal')

                @can('update', $project)
                    <button type="button" class="header-badge-btn"
                            data-bs-toggle="modal" data-bs-target="#addProjectModal" data-project-id="{{ $project->id }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Edit
                    </button>
                @endcan

                {{-- Calendar: auto-sync status + manual actions (sync, .ics) --}}
                <div class="dropdown d-inline-block">
                    <button type="button" class="header-badge-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Project dates sync automatically to Google Calendar for connected users">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Calendar
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-header">Start, deadline &amp; inspections auto-sync to Google Calendar</span></li>
                        @if(in_array(auth()->user()->role, ['admin', 'staff']))
                        <li>
                            <button type="button" class="dropdown-item" onclick="syncProjectCalendar({{ $project->id }})">
                                <i class="bi bi-arrow-repeat"></i> Sync to Google Calendar now
                            </button>
                        </li>
                        @endif
                        <li><a class="dropdown-item" href="{{ route('calendar.project.ics', $project->id) }}">
                            <i class="bi bi-download"></i> Download .ics (no sign-in needed)</a></li>
                        @if(!auth()->user()->hasGoogleCalendar())
                        <li><a class="dropdown-item" href="{{ route('calendar.google.connect') }}">
                            <i class="bi bi-calendar-plus"></i> Connect my Google Calendar</a></li>
                        @else
                        <li>
                            <button type="button" class="dropdown-item" onclick="disconnectGoogleCalendar()">
                                <i class="bi bi-calendar-x"></i> Disconnect my Google Calendar
                            </button>
                        </li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// Manual calendar re-sync (auto-sync already covers create/edit/extension/baseline changes).
function syncProjectCalendar(projectId) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    fetch(`/calendar/projects/${projectId}/sync`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token || '',
            'Accept': 'application/json',
        },
    })
        .then(async (res) => {
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success) {
                const parts = [];
                if (data.synced) parts.push(`${data.synced} event${data.synced !== 1 ? 's' : ''} synced`);
                if (data.skipped) parts.push(`${data.skipped} skipped (not connected)`);
                if (data.failed) parts.push(`${data.failed} failed`);
                alert(parts.length ? 'Calendar: ' + parts.join(', ') + '.' : 'Calendar sync queued.');
            } else {
                alert(data.message || 'Calendar sync failed.');
            }
        })
        .catch(() => alert('Calendar sync failed.'));
}
// Disconnect the user's own Google Calendar (e.g. to reconnect with new permissions).
function disconnectGoogleCalendar() {
    if (!confirm('Disconnect your Google Calendar? Auto-sync will stop until you connect again.')) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    fetch(`{{ route('calendar.google.disconnect') }}`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token || '',
            'Accept': 'application/json',
        },
    })
        .then(async (res) => {
            const data = await res.json().catch(() => ({}));
            alert(data.success ? 'Google Calendar disconnected.' : (data.message || 'Disconnect failed.'));
            if (res.ok) window.location.reload();
        })
        .catch(() => alert('Disconnect failed.'));
}
</script>
