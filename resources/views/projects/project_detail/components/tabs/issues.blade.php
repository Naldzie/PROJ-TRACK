<!-- ISSUES TAB -->
<div id="issues" class="tab-content">
    <div style="margin-top: 30px; padding: 20px; background: white; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e5e7eb;">
            <div>
                <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 10px;">
                    <i class="bi bi-exclamation-circle" style="color: #ef4444; font-size: 20px;"></i> Project Issues
                </h4>
                <p style="margin: 0; font-size: 12px; color: #6b7280;">Track and manage project issues with status updates</p>
            </div>
            @if(in_array(auth()->user()->role, ['admin', 'staff']))
            <button class="btn" data-bs-toggle="modal" data-bs-target="#issuesModal"
                    style="background: #3b82f6; color: white; border: none; padding: 10px 16px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;"
                    onmouseover="this.style.background='#2563eb'; this.style.transform='translateY(-2px)';"
                    onmouseout="this.style.background='#3b82f6'; this.style.transform='translateY(0)';">
                <i class="bi bi-plus-circle"></i> Add Issue
            </button>
            @else
            <button class="btn" data-bs-toggle="modal" data-bs-target="#issuesModal" disabled
                    style="background: #e5e7eb; color: #6b7280; border: none; padding: 10px 16px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: not-allowed; display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-eye"></i> View Issues (Read-only)
            </button>
            @endif
        </div>

        <!-- Issues Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
            <div style="padding: 15px; background: #fee2e2; border: 1px solid #fecaca; border-radius: 8px;">
                <small style="color: #991b1b; font-weight: 600; display: block; margin-bottom: 6px;">Open Issues</small>
                <div style="font-size: 14px; font-weight: 700; color: #7f1d1d;" id="openIssuesCount">0</div>
            </div>
            <div style="padding: 15px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;">
                <small style="color: #92400e; font-weight: 600; display: block; margin-bottom: 6px;">In Progress</small>
                <div style="font-size: 14px; font-weight: 700; color: #78350f;" id="inProgressIssuesCount">0</div>
            </div>
            <div style="padding: 15px; background: #dcfce7; border: 1px solid #bbf7d0; border-radius: 8px;">
                <small style="color: #166534; font-weight: 600; display: block; margin-bottom: 6px;">Resolved Issues</small>
                <div style="font-size: 14px; font-weight: 700; color: #15803d;" id="resolvedIssuesCount">0</div>
            </div>
            <div style="padding: 15px; background: #e5e7eb; border: 1px solid #d1d5db; border-radius: 8px;">
                <small style="color: #374151; font-weight: 600; display: block; margin-bottom: 6px;">Closed Issues</small>
                <div style="font-size: 14px; font-weight: 700; color: #1f2937;" id="closedIssuesCount">0</div>
            </div>
        </div>

        <!-- Issues Table -->
        <div style="border-top: 2px solid #e5e7eb; padding-top: 20px;">
            <h6 style="margin-bottom: 15px; font-weight: 600; color: #111827;">
                <i class="bi bi-list-check" style="margin-right: 6px;"></i>
                All Issues <span id="totalIssuesCount" style="background: #e5e7eb; color: #374151; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 8px;">0</span>
            </h6>
            <div id="issuesTableContainer">
                <!-- Will be populated by JavaScript -->
                <div style="text-align: center; padding: 40px; color: #9ca3af;">
                    <i class="bi bi-inbox" style="font-size: 32px; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                    <div style="font-size: 13px;">Loading issues...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DELETE CONFIRMATION MODAL FOR ISSUES -->
<div class="modal fade" id="deleteIssueConfirmModal" tabindex="-1" aria-labelledby="deleteIssueConfirmLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteIssueConfirmLabel">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete this issue? This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Table row hover effect */
table tbody tr:hover {
    background: #f3f4f6 !important;
}
</style>

<script>
// Load issues when tab is active
document.addEventListener('DOMContentLoaded', function() {
    const issuesTab = document.getElementById('issues');
    if (issuesTab) {
        // Load issues on initial load
        loadTabIssues();
        
        // Also load when tab is switched to
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.dataset.tab === 'issues') {
                    setTimeout(loadTabIssues, 100);
                }
            });
        });
    }
});

function loadTabIssues() {
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
            displayTabIssues(data.issues);
            updateIssuesSummary(data.issues);
        }
    })
    .catch(error => console.error('Error loading issues:', error));
}

function displayTabIssues(issues) {
    const container = document.getElementById('issuesTableContainer');
    
    if (issues.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #9ca3af;">
                <i class="bi bi-inbox" style="font-size: 32px; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                <div style="font-size: 13px;">No issues recorded for this project</div>
            </div>
        `;
        return;
    }

    const statusBgColors = {
        'Open': '#fee2e2',
        'In Progress': '#fef3c7',
        'Resolved': '#dcfce7',
        'Closed': '#e5e7eb'
    };

    const statusTextColors = {
        'Open': '#991b1b',
        'In Progress': '#92400e',
        'Resolved': '#166534',
        'Closed': '#374151'
    };

    const html = `
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f3f4f6; border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Issue Name</th>
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Description</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Type</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Severity</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Status</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Created Date</th>
                    @if(in_array(auth()->user()->role, ['admin', 'staff']))
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                    ${issues.map((issue, index) => `
                    <tr style="border-bottom: 1px solid #e5e7eb; ${index % 2 === 0 ? 'background: #f9fafb;' : 'background: white;'} transition: background 0.2s;">
                        <td style="padding: 12px 16px; font-weight: 600; color: #111827; font-size: 13px;">${escapeHtml(issue.issue_name)}</td>
                        <td style="padding: 12px 16px; color: #6b7280; font-size: 13px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(issue.description)}</td>
                        <td style="padding: 12px 16px; text-align: center;">
                            ${issue.issue_type ? `<span style="display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #e0f2fe; color: #0369a1;">${escapeHtml(issue.issue_type)}</span>` : '<span style="color: #9ca3af; font-size: 11px;">—</span>'}
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            ${issue.severity ? `<span style="display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; ${severityTabStyle(issue.severity)}">${escapeHtml(issue.severity)}</span>` : '<span style="color: #9ca3af; font-size: 11px;">—</span>'}
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; background: ${statusBgColors[issue.status]}; color: ${statusTextColors[issue.status]};">
                                ${issue.status}
                            </span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center; color: #6b7280; font-size: 13px;">
                            <i class="bi bi-calendar-event" style="margin-right: 4px;"></i>${issue.created_at}
                        </td>
                        @if(in_array(auth()->user()->role, ['admin', 'staff']))
                        <td style="padding: 12px 16px; text-align: center;">
                            <button class="btn btn-sm" onclick="openEditIssueModal(${issue.id}, '${escapeHtml(issue.issue_name)}', '${escapeHtml(issue.description)}', '${issue.status}', '${issue.issue_type || ''}', '${issue.severity || ''}')" title="Edit"
                                    style="background: #dbeafe; color: #0369a1; border: none; padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer; margin-right: 4px; transition: all 0.2s;"
                                    onmouseover="this.style.background='#bae6fd'; this.style.transform='scale(1.05)';"
                                    onmouseout="this.style.background='#dbeafe'; this.style.transform='scale(1)';">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm" onclick="confirmDeleteTabIssue(${issue.id})" title="Delete"
                                    style="background: #fee2e2; color: #991b1b; border: none; padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer; transition: all 0.2s;"
                                    onmouseover="this.style.background='#fecaca'; this.style.transform='scale(1.05)';"
                                    onmouseout="this.style.background='#fee2e2'; this.style.transform='scale(1)';">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                        @endif
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
    
    container.innerHTML = html;
}

function updateIssuesSummary(issues) {
    const summary = {
        'Open': 0,
        'In Progress': 0,
        'Resolved': 0,
        'Closed': 0
    };

    issues.forEach(issue => {
        if (summary.hasOwnProperty(issue.status)) {
            summary[issue.status]++;
        }
    });

    document.getElementById('openIssuesCount').textContent = summary['Open'];
    document.getElementById('inProgressIssuesCount').textContent = summary['In Progress'];
    document.getElementById('resolvedIssuesCount').textContent = summary['Resolved'];
    document.getElementById('closedIssuesCount').textContent = summary['Closed'];
    document.getElementById('totalIssuesCount').textContent = issues.length;
}

function severityTabStyle(severity) {
    const styles = {
        'Critical': 'background: #fecaca; color: #991b1b;',
        'High': 'background: #fed7aa; color: #9a3412;',
        'Medium': 'background: #bfdbfe; color: #1e40af;',
        'Low': 'background: #bbf7d0; color: #166534;'
    };
    return styles[severity] || 'background: #e5e7eb; color: #374151;';
}

function openEditIssueModal(id, name, description, status, issueType, severity) {
    document.getElementById('editIssueId').value = id;
    document.getElementById('editIssueName').value = name;
    document.getElementById('editIssueDescription').value = description;
    document.getElementById('editIssueStatus').value = status;
    document.getElementById('editIssueType').value = issueType || '';
    document.getElementById('editIssueSeverity').value = severity || '';
    
    const editModal = new bootstrap.Modal(document.getElementById('editIssueModal'));
    editModal.show();
}

let issueToDelete = null;

function confirmDeleteTabIssue(id) {
    issueToDelete = id;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteIssueConfirmModal'));
    deleteModal.show();
}

document.getElementById('confirmDeleteBtn')?.addEventListener('click', function() {
    if (issueToDelete) {
        deleteTabIssue(issueToDelete);
        bootstrap.Modal.getInstance(document.getElementById('deleteIssueConfirmModal')).hide();
    }
});

function deleteTabIssue(id) {
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
            loadTabIssues();
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

// Override modal form submission to refresh tab
const originalEditFormSubmit = document.getElementById('editIssueForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const issueId = document.getElementById('editIssueId').value;
    const apiUrl = getIssuesApiUrl(issueId);
    
    const jsonData = {
        issue_name: document.getElementById('editIssueName').value,
        description: document.getElementById('editIssueDescription').value,
        status: document.getElementById('editIssueStatus').value,
        issue_type: document.getElementById('editIssueType').value || null,
        severity: document.getElementById('editIssueSeverity').value || null
    };
    
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
            loadTabIssues();
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
</script>
