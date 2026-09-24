@php
    $layout = match(auth()->user()->role) {
        'staff' => 'layouts.stafflayouts',
        default => 'layouts.app'
    };

    $indexRoute = match(auth()->user()->role) {
        'staff'  => 'staff.projects',
        default  => 'admin.projects',
    };

    $restoreRouteName = auth()->user()->role === 'admin'
        ? 'admin.projects.restore'
        : 'staff.projects.restore';

    $showRoute = match(auth()->user()->role) {
        'staff' => 'staff.projects.detail',
        default => 'admin.projects.detail',
    };
@endphp
@extends($layout)

@section('content')

<div class="page-header">
    <h1>Archived Projects</h1>
    <a href="{{ route($indexRoute) }}" class="btn-primary" style="text-decoration: none;">
        <i class="bi bi-arrow-left-circle"></i> Back to Projects
    </a>
</div>

<div class="projects-table-wrap">
    <table class="projects-table show" id="archivedProjectsTable">
        <thead>
            <tr>
                <th>Code</th>
                <th>Title</th>
                <th>Category</th>
                <th>Start Date</th>
                <th>Duration</th>
                <th>Deadline</th>
                <th>Archived Date</th>
                <th>Manager</th>
                <th>Contractor</th>
                <th>Contract Price</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @if($projects->count() > 0)
                @foreach($projects as $project)
                    @php
                        $riskStatus = $project->isOverdue() ? 'danger' : ($project->isAtRisk() ? 'warning' : 'success');
                        $isCompleted = $project->status === 'Completed';
                    @endphp
                    <tr data-id="{{ $project->id }}">
                        <td><span class="table-project-code">{{ $project->code }}</span></td>
                        <td><span class="table-project-title" title="{{ $project->title }}">{{ $project->title }}</span></td>
                        <td><span class="table-category">{{ $project->category?->name ?? 'N/A' }}</span></td>
                        <td><span class="table-start-date">{{ $project->planned_start_date ? $project->planned_start_date->format('M d, Y') : 'TBD' }}</span></td>
                        <td><span class="table-duration">{{ $project->planned_duration_days ? $project->planned_duration_days . ' days' : 'N/A' }}</span></td>
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
                            <span class="table-start-date">
                                {{ $project->archived_at ? $project->archived_at->format('M d, Y g:i A') : 'N/A' }}
                            </span>
                        </td>
                        <td>
                            @if($project->assigned_project_manager)
                                <span class="table-manager">{{ $project->getProjectManagerName() }}</span>
                            @else
                                <span class="table-manager-empty">Unassigned</span>
                            @endif
                        </td>
                        <td>
                            <span class="table-contractor-name">{{ $project->contractor?->company_name ?: ($project->contractor?->contractor_name ?: ($project->contractor?->user?->name ?? 'Unassigned')) }}</span>
                        </td>
                        <td><span class="table-budget">{{ \App\Helpers\FormatHelper::formatCurrency($project->effectiveContractPrice() ?? 0) }}</span></td>
                        <td>
                            <div class="table-actions">
                                <a href="{{ route($showRoute, $project->id) }}" class="table-action-btn table-action-view" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @can('restore', $project)
                                    <button class="table-action-btn" style="background:#dcfce7;color:#166534;"
                                            onclick="restoreProject('{{ route($restoreRouteName, $project->id) }}', '{{ $project->title }}')"
                                            title="Restore to active projects">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                @endcan
                                @can('delete', $project)
                                    <button class="table-action-btn table-action-danger"
                                            onclick="permanentlyDeleteProject('{{ route('projects.destroy.shared', $project->id) }}', '{{ $project->title }}')"
                                            title="Permanently delete (cannot be undone)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="11" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        No archived projects
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

@if($projects->hasPages())
    <div style="margin-top: 20px;">
        {{ $projects->links() }}
    </div>
@endif

@include('components.delete-confirmation-modal')
@include('components.loading-overlay', ['id' => 'archiveProjectLoadingOverlay', 'label' => 'Working…'])

{{-- Show the success toast AFTER the post-action page reload (flash set by
     ProjectController@archive / @restore / @delete). --}}
@if(session('toast_success'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.showToast(@json(session('toast_success')), 'success');
    });
</script>
@endif

<script>
/**
 * Restore an archived project back to the active list (admin/staff).
 * Called by: archived projects table Restore button.
 * Uses: components/delete-confirmation-modal + loading-overlay.
 */
function restoreProject(url, projectTitle) {
    showDeleteConfirmation(
        'Restore Project',
        `Are you sure you want to restore <strong>"${projectTitle}"</strong> to the active project list?`,
        function() {
            showLoadingOverlay('archiveProjectLoadingOverlay', 'Restoring project…');
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
                    showLoadingOverlay('archiveProjectLoadingOverlay', 'Almost done…');
                    location.reload();
                } else {
                    hideLoadingOverlay('archiveProjectLoadingOverlay');
                    showToast(data.message || 'Failed to restore project', 'error');
                }
            })
            .catch(err => {
                console.error('Restore error:', err);
                hideLoadingOverlay('archiveProjectLoadingOverlay');
                showToast('Error restoring project. Please try again.', 'error');
            });
        }
    );
}

/**
 * Permanently delete an ARCHIVED project (admin only).
 * Called by: archived projects table Delete button.
 * Uses: shared DELETE /projects/{id} endpoint (projects.destroy.shared).
 */
function permanentlyDeleteProject(url, projectTitle) {
    showDeleteConfirmation(
        'Permanently Delete Project',
        `<strong style="color: #ef4444;"><i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px;"></i>Warning:</strong> Are you sure you want to permanently delete <strong>"${projectTitle}"</strong> and all associated data? This action cannot be undone.`,
        function() {
            showLoadingOverlay('archiveProjectLoadingOverlay', 'Deleting project…');
            fetch(url, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Content-Type': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showLoadingOverlay('archiveProjectLoadingOverlay', 'Almost done…');
                    location.reload();
                } else {
                    hideLoadingOverlay('archiveProjectLoadingOverlay');
                    showToast(data.message || 'Failed to delete project', 'error');
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                hideLoadingOverlay('archiveProjectLoadingOverlay');
                showToast('Error deleting project. Please try again.', 'error');
            });
        }
    );
}
</script>

@endsection
