<!-- ISSUES MODAL -->
@php
    $baselines = $project->baselineSchedules()->orderBy('planned_date')->get();
    $defaultBaselineId = app(\App\Services\ProjectOverviewService::class)->getDefaultIssueBaseline($project)?->id;
@endphp
<div class="modal fade" id="issuesModal" tabindex="-1" aria-labelledby="issuesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="issuesModalLabel">
                    <i class="bi bi-exclamation-circle" style="margin-right: 8px;"></i>
                    Manage Issues
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if(in_array(auth()->user()->role, ['admin', 'staff']))
                    <!-- Add Issue Form -->
                    <div class="card mb-4" style="border: 1px solid #e0e0e0;">
                        <div class="card-header" style="background-color: #f8f9fa; border-bottom: 1px solid #e0e0e0;">
                            <h6 class="mb-0" style="font-weight: 600;">Add New Issue</h6>
                        </div>
                        <div class="card-body">
                            <form id="addIssueForm">
                                @csrf
                                <div class="mb-3">
                                    <label for="issueName" class="form-label">Issue Name *</label>
                                    <input type="text" class="form-control" id="issueName" name="issue_name" 
                                           placeholder="e.g., Delay in Material Delivery" required>
                                    <small class="text-muted">Brief title for the issue</small>
                                </div>
                                <div class="mb-3">
                                    <label for="issueDescription" class="form-label">Description *</label>
                                    <textarea class="form-control" id="issueDescription" name="description" 
                                              rows="3" placeholder="Detailed description of the issue..." required></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="issueStatus" class="form-label">Status *</label>
                                    <select class="form-control" id="issueStatus" name="status" required>
                                        <option value="">Select Status</option>
                                        <option value="Open">Open</option>
                                        <option value="In Progress">In Progress</option>
                                        <option value="Resolved">Resolved</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="issueType" class="form-label">Issue Type</label>
                                        <select class="form-control" id="issueType" name="issue_type">
                                            <option value="">-- Select Type (Optional) --</option>
                                            <option value="material">Material</option>
                                            <option value="contractor">Contractor</option>
                                            <option value="weather">Weather</option>
                                            <option value="design">Design</option>
                                            <option value="regulatory">Regulatory</option>
                                            <option value="financial">Financial</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="issueSeverity" class="form-label">Severity</label>
                                        <select class="form-control" id="issueSeverity" name="severity">
                                            <option value="">-- Select Severity (Optional) --</option>
                                            <option value="Low">Low</option>
                                            <option value="Medium">Medium</option>
                                            <option value="High">High</option>
                                            <option value="Critical">Critical</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="issueBaselineId" class="form-label">Baseline Month</label>
                                    <select class="form-control" id="issueBaselineId" name="baseline_schedule_id">
                                        <option value="">-- Select Baseline Month (Optional) --</option>
                                        @foreach($baselines as $baseline)
                                            <option value="{{ $baseline->id }}" {{ $baseline->id === $defaultBaselineId ? 'selected' : '' }}>{{ $baseline->month_display }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">Link the issue to the baseline month it belongs to</small>
                                </div>
                                <div class="mb-3" id="issueResolutionNotesWrap" style="display: none;">
                                    <label for="issueResolutionNotes" class="form-label">Resolution Notes *</label>
                                    <textarea class="form-control" id="issueResolutionNotes" name="resolution_notes" rows="3"
                                              placeholder="Describe how this issue was resolved (required when status is Resolved or Closed)..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="issueAttachmentFiles" class="form-label">Issue Evidence Files</label>
                                    <input type="file" class="form-control" id="issueAttachmentFiles" name="issue_attachment_files[]" multiple accept="image/*,.pdf">
                                    <small class="text-muted">Photos or documents supporting this issue (jpg, png, pdf â€” max 20 MB each)</small>
                                </div>
                                <div class="mb-3" id="issueResolutionFilesWrap" style="display: none;">
                                    <label for="resolutionAttachmentFiles" class="form-label">Resolution Evidence Files</label>
                                    <input type="file" class="form-control" id="resolutionAttachmentFiles" name="resolution_attachment_files[]" multiple accept="image/*,.pdf">
                                    <small class="text-muted">Proof of resolution (jpg, png, pdf â€” max 20 MB each)</small>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-plus-circle" style="margin-right: 6px;"></i>
                                    Add Issue
                                </button>
                            </form>
                        </div>
                    </div>
                @endif

                <!-- Issues List -->
                <div class="card">
                    <div class="card-header" style="background-color: #f8f9fa; border-bottom: 1px solid #e0e0e0;">
                        <h6 class="mb-0" style="font-weight: 600;">
                            <i class="bi bi-list-ul" style="margin-right: 6px;"></i>
                            All Issues <span id="issuesCount" class="badge bg-secondary">0</span>
                        </h6>
                    </div>
                    <div class="card-body" id="issuesList">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox" style="font-size: 2rem; margin-bottom: 10px;"></i>
                            <p>No issues yet</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- EDIT ISSUE MODAL -->
<div class="modal fade" id="editIssueModal" tabindex="-1" aria-labelledby="editIssueModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editIssueModalLabel">
                    <i class="bi bi-pencil-square" style="margin-right: 8px;"></i>
                    Edit Issue
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editIssueForm">
                    @csrf
                    @method('PUT')
                    <input type="hidden" id="editIssueId" name="issue_id">
                    <div class="mb-3">
                        <label for="editIssueName" class="form-label">Issue Name *</label>
                        <input type="text" class="form-control" id="editIssueName" name="issue_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="editIssueDescription" class="form-label">Description *</label>
                        <textarea class="form-control" id="editIssueDescription" name="description" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="editIssueStatus" class="form-label">Status *</label>
                        <select class="form-control" id="editIssueStatus" name="status" required>
                            <option value="">Select Status</option>
                            <option value="Open">Open</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Closed">Closed</option>
                        </select>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="editIssueType" class="form-label">Issue Type</label>
                            <select class="form-control" id="editIssueType" name="issue_type">
                                <option value="">-- Select Type (Optional) --</option>
                                <option value="material">Material</option>
                                <option value="contractor">Contractor</option>
                                <option value="weather">Weather</option>
                                <option value="design">Design</option>
                                <option value="regulatory">Regulatory</option>
                                <option value="financial">Financial</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="editIssueSeverity" class="form-label">Severity</label>
                            <select class="form-control" id="editIssueSeverity" name="severity">
                                <option value="">-- Select Severity (Optional) --</option>
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="editIssueBaselineId" class="form-label">Baseline Month</label>
                        <select class="form-control" id="editIssueBaselineId" name="baseline_schedule_id">
                            <option value="">-- Select Baseline Month (Optional) --</option>
                            @foreach($baselines as $baseline)
                                <option value="{{ $baseline->id }}">{{ $baseline->month_display }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3" id="editIssueResolutionNotesWrap" style="display: none;">
                        <label for="editIssueResolutionNotes" class="form-label">Resolution Notes *</label>
                        <textarea class="form-control" id="editIssueResolutionNotes" name="resolution_notes" rows="3"
                                  placeholder="Describe how this issue was resolved (required when status is Resolved or Closed)..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="editIssueAttachmentFiles" class="form-label">Add Issue Evidence Files</label>
                        <input type="file" class="form-control" id="editIssueAttachmentFiles" name="issue_attachment_files[]" multiple accept="image/*,.pdf">
                        <small class="text-muted">New photos or documents to attach (jpg, png, pdf — max 20 MB each). Existing attachments are listed below.</small>
                    </div>
                    <div class="mb-3" id="editIssueResolutionFilesWrap" style="display: none;">
                        <label for="editResolutionAttachmentFiles" class="form-label">Add Resolution Evidence Files</label>
                        <input type="file" class="form-control" id="editResolutionAttachmentFiles" name="resolution_attachment_files[]" multiple accept="image/*,.pdf">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Existing Attachments</label>
                        <div id="editIssueAttachmentList">
                            <small class="text-muted">Loading...</small>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle" style="margin-right: 6px;"></i>
                            Update Issue
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.issue-card {
    border-left: 4px solid #007bff;
    background: #f8f9fa;
    margin-bottom: 12px;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.issue-card:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.issue-card.open {
    border-left-color: #dc3545;
}

.issue-card.in-progress {
    border-left-color: #ffc107;
}

.issue-card.resolved {
    border-left-color: #20c997;
}

.issue-card.closed {
    border-left-color: #6c757d;
}

.issue-badge {
    font-size: 0.75rem;
    padding: 0.4rem 0.6rem;
    font-weight: 600;
}

.issue-meta-line {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #6b7280;
    font-size: 0.8rem;
}

.attachment-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fff;
    border: 1px solid #dbeafe;
    color: #0369a1;
    border-radius: 4px;
    padding: 3px 8px;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    transition: all 0.15s;
}

.attachment-chip:hover {
    background: #dbeafe;
    border-color: #93c5fd;
}

.attachment-chip i {
    font-size: 0.8rem;
}

.attachment-remove-btn {
    border: none;
    background: transparent;
    color: #dc2626;
    padding: 0 2px;
    margin-left: 4px;
    font-size: 12px;
    line-height: 1;
    cursor: pointer;
}

.attachment-remove-btn:hover {
    color: #991b1b;
}

.attachment-chip:hover .attachment-remove-btn {
    background: transparent;
}

/* Dropdown arrow indicator so select fields read as selection, not text input */
#issuesModal select.form-control,
#editIssueModal select.form-control {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 14px 14px;
    padding-right: 34px;
    cursor: pointer;
}

#issuesModal select.form-control:focus,
#editIssueModal select.form-control:focus {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%231e3a8a' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
}
</style>

<script>
// Initialize issues on modal open
document.addEventListener('DOMContentLoaded', function() {
    const issuesModal = document.getElementById('issuesModal');
    if (issuesModal) {
        issuesModal.addEventListener('show.bs.modal', function() {
            loadIssues();
        });
    }

    // Toggle resolution fields when status changes (add + edit forms)
    document.getElementById('issueStatus')?.addEventListener('change', function() {
        toggleIssueResolutionFields(this.value);
    });
    document.getElementById('editIssueStatus')?.addEventListener('change', function() {
        toggleIssueResolutionFields(this.value);
    });
});

window._issueAttachments = window._issueAttachments || {};
window._issueDataById = window._issueDataById || {};

function toggleIssueResolutionFields(status) {
    const show = status === 'Resolved' || status === 'Closed';
    ['', 'edit'].forEach(prefix => {
        const notesWrap = document.getElementById(prefix + 'IssueResolutionNotesWrap');
        const filesWrap = document.getElementById(prefix + 'IssueResolutionFilesWrap');
        if (notesWrap) notesWrap.style.display = show ? '' : 'none';
        if (filesWrap) filesWrap.style.display = show ? '' : 'none';
    });
}

function loadIssues() {
    const projectId = window.projectId;
    const apiUrl = getIssuesApiUrl();
    
    fetch(apiUrl, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayIssues(data.issues);
            document.getElementById('issuesCount').textContent = data.count;
        }
    })
    .catch(error => console.error('Error loading issues:', error));
}

function getAttachmentIcon(filename) {
    const ext = (filename || '').split('.').pop().toLowerCase();
    const map = {
        pdf: 'bi-file-earmark-pdf',
        jpg: 'bi-file-earmark-image',
        jpeg: 'bi-file-earmark-image',
        png: 'bi-file-earmark-image',
        gif: 'bi-file-earmark-image',
        webp: 'bi-file-earmark-image',
        svg: 'bi-file-earmark-image'
    };
    return map[ext] || 'bi-file-earmark-text';
}

function renderAttachmentChips(attachments) {
    return (attachments || []).map(att => {
        window._issueAttachments[att.id] = att;
        return `
            <span class="attachment-chip me-1 mb-1" title="${escapeHtml(att.original_name)}"
                  onclick="openIssueAttachmentById(${att.id})">
                <i class="bi ${getAttachmentIcon(att.original_name)}"></i>
                <span>${escapeHtml(att.original_name)}</span>
            </span>
        `;
    }).join('');
}

function openIssueAttachmentById(id) {
    const att = window._issueAttachments[id];
    if (!att || typeof openDocumentViewer !== 'function') return;
    const role = window.userRole;
    openDocumentViewer({
        id: att.id,
        name: att.original_name,
        type: att.context === 'resolution' ? 'Resolution Proof' : 'Issue Evidence',
        size: att.display_size,
        uploadedBy: att.uploaded_by,
        uploadedAt: att.created_at,
        viewUrl: `/${role}/attachments/${att.id}/view`,
        downloadUrl: `/${role}/attachments/${att.id}/download`
    });
}

function renderEditIssueAttachmentList(issue) {
    const container = document.getElementById('editIssueAttachmentList');
    if (!container) return;

    const attachments = (issue && issue.attachments) || [];
    if (attachments.length === 0) {
        container.innerHTML = '<small class="text-muted">No attachments yet.</small>';
        return;
    }

    container.innerHTML = attachments.map(att => {
        window._issueAttachments[att.id] = att;
        return `
            <div class="attachment-chip me-1 mb-1" style="cursor: default;" title="${escapeHtml(att.original_name)}">
                <span onclick="openIssueAttachmentById(${att.id})" title="View attachment"
                      style="cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="bi ${getAttachmentIcon(att.original_name)}"></i>
                    <span>${escapeHtml(att.original_name)}</span>
                </span>
                <button type="button" class="attachment-remove-btn" title="Remove attachment"
                        onclick="deleteIssueAttachment(${att.id})">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
        `;
    }).join('');
}

function deleteIssueAttachment(attachmentId) {
    if (!confirm('Are you sure you want to remove this attachment?')) return;
    const role = window.userRole;
    const apiUrl = `/${role}/attachments/${attachmentId}`;

    fetch(apiUrl, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': window.csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            throw {data: data};
        }
        const editIssueId = document.getElementById('editIssueId')?.value;
        if (editIssueId && window._issueDataById[editIssueId]) {
            window._issueDataById[editIssueId].attachments =
                (window._issueDataById[editIssueId].attachments || []).filter(a => a.id !== attachmentId);
            renderEditIssueAttachmentList(window._issueDataById[editIssueId]);
        }
        delete window._issueAttachments[attachmentId];
        loadIssues();
        if (typeof loadTabIssues === 'function') {
            loadTabIssues();
        }
        showSuccessMessage('Attachment removed successfully');
    })
    .catch(error => {
        console.error('Error removing attachment:', error);
        showErrorMessage(error && error.data && error.data.message ? error.data.message : 'Error removing attachment');
    });
}

function displayIssues(issues) {
    const issuesList = document.getElementById('issuesList');
    
    if (issues.length === 0) {
        issuesList.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox" style="font-size: 2rem; margin-bottom: 10px;"></i>
                <p>No issues yet</p>
            </div>
        `;
        return;
    }

    const statusColors = {
        'Open': 'danger',
        'In Progress': 'warning',
        'Resolved': 'success',
        'Closed': 'secondary'
    };

    issuesList.innerHTML = issues.map(issue => {
        window._issueDataById[issue.id] = issue;
        return `
        <div class="issue-card ${issue.status.toLowerCase().replace(' ', '-')} p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <h6 class="mb-2" style="font-weight: 600;">${escapeHtml(issue.issue_name)}</h6>
                    <p class="mb-2 text-muted" style="font-size: 0.9rem;">${escapeHtml(issue.description)}</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            ${issue.issue_type ? `<span class="badge bg-info issue-badge me-1">${escapeHtml(issue.issue_type)}</span>` : ''}
                            ${issue.severity ? `<span class="badge ${severityBadgeClass(issue.severity)} issue-badge me-1">${escapeHtml(issue.severity)}</span>` : ''}
                            ${issue.month_display ? `<span class="issue-meta-line me-2"><i class="bi bi-calendar-month"></i> ${escapeHtml(issue.month_display)}</span>` : ''}
                            <small class="text-muted ms-1">
                                <i class="bi bi-calendar-event"></i> ${issue.created_at}
                            </small>
                        </div>
                        <span class="badge bg-${statusColors[issue.status]} issue-badge">${issue.status}</span>
                    </div>
                    ${issue.status === 'Resolved' || issue.status === 'Closed' ? `
                        <div class="mt-2" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 10px;">
                            <small style="font-weight: 600; color: #166534;">
                                <i class="bi bi-check-circle"></i> Resolved ${issue.resolved_at ? 'on ' + escapeHtml(issue.resolved_at) : ''}
                            </small>
                            ${issue.resolution_notes ? `<p class="mb-0 mt-1 text-muted" style="font-size: 0.82rem;">${escapeHtml(issue.resolution_notes)}</p>` : ''}
                        </div>
                    ` : ''}
                    ${renderAttachmentChips(issue.attachments) ? `
                        <div class="mt-2">${renderAttachmentChips(issue.attachments)}</div>
                    ` : ''}
                </div>
                    @if(in_array(auth()->user()->role, ['admin', 'staff']))
                <div class="ms-2" style="flex-shrink: 0;">
                    <button class="btn btn-sm btn-outline-primary" onclick="editIssue(${issue.id}, '${escapeHtml(issue.issue_name)}', '${escapeHtml(issue.description)}', '${issue.status}', '${issue.issue_type || ''}', '${issue.severity || ''}', '${issue.baseline_schedule_id || ''}', '${escapeHtml(issue.resolution_notes || '')}')">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteIssue(${issue.id})">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                @endif
            </div>
        </div>
    `;
    }).join('');
}

function editIssue(id, name, description, status, issueType, severity, baselineScheduleId, resolutionNotes) {
    document.getElementById('editIssueId').value = id;
    document.getElementById('editIssueName').value = name;
    document.getElementById('editIssueDescription').value = description;
    document.getElementById('editIssueStatus').value = status;
    document.getElementById('editIssueType').value = issueType || '';
    document.getElementById('editIssueSeverity').value = severity || '';
    document.getElementById('editIssueBaselineId').value = baselineScheduleId || '';
    document.getElementById('editIssueResolutionNotes').value = resolutionNotes || '';
    document.getElementById('editIssueAttachmentFiles').value = '';
    document.getElementById('editResolutionAttachmentFiles').value = '';

    renderEditIssueAttachmentList(window._issueDataById[id]);
    toggleIssueResolutionFields(status);
    
    const editModal = new bootstrap.Modal(document.getElementById('editIssueModal'));
    editModal.show();
}

function severityBadgeClass(severity) {
    const classes = {
        'Critical': 'bg-danger',
        'High': 'bg-warning text-dark',
        'Medium': 'bg-primary',
        'Low': 'bg-success'
    };
    return classes[severity] || 'bg-secondary';
}

function deleteIssue(id) {
    if (confirm('Are you sure you want to delete this issue?')) {
        const apiUrl = getIssuesApiUrl(id);
        fetch(apiUrl, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': window.csrfToken,
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadIssues();
                // Also refresh the issues tab
                if (typeof loadTabIssues === 'function') {
                    loadTabIssues();
                }
                showSuccessMessage('Issue deleted successfully');
            } else {
                showErrorMessage(data.message || 'Error deleting issue');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorMessage('Error deleting issue');
        });
    }
}

function uploadIssueAttachment(issueId, context, file) {
    const fd = new FormData();
    fd.append('project_id', window.projectId);
    fd.append('attachable_type', 'App\\Models\\Issue');
    fd.append('attachable_id', issueId);
    fd.append('context', context);
    fd.append('file', file);
    const role = window.userRole;
    return fetch(`/${role}/attachments/upload`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': window.csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: fd
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            throw new Error((data && data.message) || 'Attachment upload failed (' + context + ')');
        }
        return data;
    })
    .catch(error => {
        console.error('Attachment upload error (' + context + '):', error);
        throw error;
    });
}

function uploadIssueFiles(issueId, issueFiles, resolutionFiles) {
    const promises = [];
    (issueFiles || []).forEach(file => promises.push(uploadIssueAttachment(issueId, 'issue', file)));
    (resolutionFiles || []).forEach(file => promises.push(uploadIssueAttachment(issueId, 'resolution', file)));
    return Promise.all(promises);
}

// Form submission
@if(in_array(auth()->user()->role, ['admin', 'staff']))
document.getElementById('addIssueForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const issueFiles = Array.from(document.getElementById('issueAttachmentFiles')?.files || []);
    const resolutionFiles = Array.from(document.getElementById('resolutionAttachmentFiles')?.files || []);
    
    const formData = new FormData(this);
    // Files are uploaded after issue creation (attachable_id requires the new issue id)
    formData.delete('issue_attachment_files[]');
    formData.delete('resolution_attachment_files[]');
    
    const apiUrl = getIssuesApiUrl();
    
    fetch(apiUrl, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': window.csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(data => {
                console.error('Validation errors:', data);
                throw {status: response.status, data: data};
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            return uploadIssueFiles(data.issue.id, issueFiles, resolutionFiles).then(() => data);
        }
        throw {status: 400, data: data};
    })
    .then(data => {
        this.reset();
        toggleIssueResolutionFields('Open');
        loadIssues();
        // Also refresh the issues tab
        if (typeof loadTabIssues === 'function') {
            loadTabIssues();
        }
        showSuccessMessage('Issue created successfully');
    })
    .catch(error => {
        console.error('Error creating issue:', error);
        if (error && error.data && error.data.errors) {
            const firstError = Object.values(error.data.errors)[0];
            showErrorMessage(firstError ? firstError[0] : 'Error creating issue');
        } else if (error && error.data && error.data.message) {
            showErrorMessage(error.data.message);
        } else if (error && error.message) {
            showErrorMessage(error.message);
        } else {
            showErrorMessage('Error creating issue');
        }
    });
});

document.getElementById('editIssueForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const issueId = document.getElementById('editIssueId').value;
    const issueFiles = Array.from(document.getElementById('editIssueAttachmentFiles')?.files || []);
    const resolutionFiles = Array.from(document.getElementById('editResolutionAttachmentFiles')?.files || []);
    
    const jsonData = {
        issue_name: document.getElementById('editIssueName').value,
        description: document.getElementById('editIssueDescription').value,
        status: document.getElementById('editIssueStatus').value,
        issue_type: document.getElementById('editIssueType').value || null,
        severity: document.getElementById('editIssueSeverity').value || null,
        baseline_schedule_id: document.getElementById('editIssueBaselineId').value || null,
        resolution_notes: document.getElementById('editIssueResolutionNotes').value || null
    };
    const apiUrl = getIssuesApiUrl(issueId);
    
    fetch(apiUrl, {
        method: 'PUT',
        headers: {
            'X-CSRF-TOKEN': window.csrfToken,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(jsonData)
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(data => {
                console.error('Validation errors:', data);
                throw {status: response.status, data: data};
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            return uploadIssueFiles(issueId, issueFiles, resolutionFiles).then(() => data);
        }
        throw {status: 400, data: data};
    })
    .then(data => {
        bootstrap.Modal.getInstance(document.getElementById('editIssueModal')).hide();
        loadIssues();
        // Also refresh the issues tab
        if (typeof loadTabIssues === 'function') {
            loadTabIssues();
        }
        showSuccessMessage('Issue updated successfully');
    })
    .catch(error => {
        console.error('Error updating issue:', error);
        if (error && error.data && error.data.errors) {
            const firstError = Object.values(error.data.errors)[0];
            showErrorMessage(firstError ? firstError[0] : 'Error updating issue');
        } else if (error && error.data && error.data.message) {
            showErrorMessage(error.data.message);
        } else if (error && error.message) {
            showErrorMessage(error.message);
        } else {
            showErrorMessage('Error updating issue');
        }
    });
});
@endif

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to show success message with toast
function showSuccessMessage(message) {
    if (window.showToast && typeof window.showToast === 'function') {
        window.showToast(message, 'success', 2500);
    } else {
        console.warn('showToast not available, using alert');
        alert('Success: ' + message);
    }
}

// Helper function to show error message with toast
function showErrorMessage(message) {
    if (window.showToast && typeof window.showToast === 'function') {
        window.showToast(message, 'error', 3000);
    } else {
        console.warn('showToast not available, using alert');
        alert('Error: ' + message);
    }
}
</script>
