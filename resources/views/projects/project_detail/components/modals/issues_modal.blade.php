<!-- ISSUES MODAL -->
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
});

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

    issuesList.innerHTML = issues.map(issue => `
        <div class="issue-card ${issue.status.toLowerCase().replace(' ', '-')} p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <h6 class="mb-2" style="font-weight: 600;">${escapeHtml(issue.issue_name)}</h6>
                    <p class="mb-2 text-muted" style="font-size: 0.9rem;">${escapeHtml(issue.description)}</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            ${issue.issue_type ? `<span class="badge bg-info issue-badge me-1">${escapeHtml(issue.issue_type)}</span>` : ''}
                            ${issue.severity ? `<span class="badge ${severityBadgeClass(issue.severity)} issue-badge me-1">${escapeHtml(issue.severity)}</span>` : ''}
                            <small class="text-muted ms-1">
                                <i class="bi bi-calendar-event"></i> ${issue.created_at}
                            </small>
                        </div>
                        <span class="badge bg-${statusColors[issue.status]} issue-badge">${issue.status}</span>
                    </div>
                </div>
                    @if(in_array(auth()->user()->role, ['admin', 'staff']))
                <div class="ms-2" style="flex-shrink: 0;">
                    <button class="btn btn-sm btn-outline-primary" onclick="editIssue(${issue.id}, '${escapeHtml(issue.issue_name)}', '${escapeHtml(issue.description)}', '${issue.status}', '${issue.issue_type || ''}', '${issue.severity || ''}')">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteIssue(${issue.id})">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                @endif
            </div>
        </div>
    `).join('');
}

function editIssue(id, name, description, status, issueType, severity) {
    document.getElementById('editIssueId').value = id;
    document.getElementById('editIssueName').value = name;
    document.getElementById('editIssueDescription').value = description;
    document.getElementById('editIssueStatus').value = status;
    document.getElementById('editIssueType').value = issueType || '';
    document.getElementById('editIssueSeverity').value = severity || '';
    
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

// Form submission
@if(in_array(auth()->user()->role, ['admin', 'staff']))
document.getElementById('addIssueForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
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
            this.reset();
            loadIssues();
            // Also refresh the issues tab
            if (typeof loadTabIssues === 'function') {
                loadTabIssues();
            }
            showSuccessMessage('Issue created successfully');
        } else {
            showErrorMessage(data.message || 'Error creating issue');
        }
    })
    .catch(error => {
        console.error('Error creating issue:', error);
        showErrorMessage('Error creating issue');
    });
});

document.getElementById('editIssueForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const issueId = document.getElementById('editIssueId').value;
    const jsonData = {
        issue_name: document.getElementById('editIssueName').value,
        description: document.getElementById('editIssueDescription').value,
        status: document.getElementById('editIssueStatus').value,
        issue_type: document.getElementById('editIssueType').value || null,
        severity: document.getElementById('editIssueSeverity').value || null
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
            bootstrap.Modal.getInstance(document.getElementById('editIssueModal')).hide();
            loadIssues();
            // Also refresh the issues tab
            if (typeof loadTabIssues === 'function') {
                loadTabIssues();
            }
            showSuccessMessage('Issue updated successfully');
        } else {
            showErrorMessage(data.message || 'Error updating issue');
        }
    })
    .catch(error => {
        console.error('Error updating issue:', error);
        showErrorMessage('Error updating issue');
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
