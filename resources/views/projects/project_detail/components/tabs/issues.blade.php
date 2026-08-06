<!-- ISSUES & RISKS TAB -->
<div id="issues" class="tab-content">
    <div style="margin-top: 30px; padding: 20px; background: white; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e5e7eb;">
            <div>
                <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 10px;">
                    <i class="bi bi-exclamation-circle" style="color: #ef4444; font-size: 20px;"></i> Issues & Risks
                </h4>
                <p style="margin: 0; font-size: 12px; color: #6b7280;">Track project issues and forward-looking risks</p>
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

        <!-- All / Issues / Risks Toggle -->
        <div class="risk-toggle-bar">
            <button type="button" class="risk-toggle-btn active" data-risk-view="all">All</button>
            <button type="button" class="risk-toggle-btn" data-risk-view="issues">Issues</button>
            <button type="button" class="risk-toggle-btn" data-risk-view="risks">Risks</button>
        </div>

        <!-- Issues Section -->
        <div id="issuesSection">
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

        <!-- Risk Register Section -->
        @include('projects.project_detail.components.tabs.partials.risk-register', ['project' => $project])
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

// All / Issues / Risks toggle behavior
document.querySelectorAll('.risk-toggle-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const view = this.dataset.riskView;
        document.querySelectorAll('.risk-toggle-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');

        const issuesSection = document.getElementById('issuesSection');
        const risksSection = document.getElementById('risksSection');

        if (view === 'issues') {
            issuesSection.style.display = '';
            risksSection.style.display = 'none';
        } else if (view === 'risks') {
            issuesSection.style.display = 'none';
            risksSection.style.display = '';
            if (typeof loadTabRisks === 'function') loadTabRisks();
        } else {
            issuesSection.style.display = '';
            risksSection.style.display = '';
        }
    });
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
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Month</th>
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Resolution</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Created Date</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Attachments</th>
                    @if(in_array(auth()->user()->role, ['admin', 'staff']))
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                    ${issues.map((issue, index) => {
                        window._issueDataById = window._issueDataById || {};
                        window._issueDataById[issue.id] = issue;
                        return `
                    <tr style="border-bottom: 1px solid #e5e7eb; ${index % 2 === 0 ? 'background: #f9fafb;' : 'background: white;'} transition: background 0.2s;">
                        <td style="padding: 12px 16px; font-weight: 600; color: #111827; font-size: 13px;">
                            ${escapeHtml(issue.issue_name)}
                            ${issue.source_risk_id ? `
                            <div style="margin-top: 4px;">
                                <a href="#" onclick="event.preventDefault(); switchToRisksTab();" title="Converted from a risk — view the source risk"
                                   style="font-size: 11px; font-weight: 600; color: #5b21b6; text-decoration: none; background: #ede9fe; padding: 3px 8px; border-radius: 10px; display: inline-flex; align-items: center; gap: 4px;">
                                    <i class="bi bi-shield-exclamation"></i> from Risk: ${escapeHtml(issue.source_risk_name || '#' + issue.source_risk_id)}
                                </a>
                            </div>
                            ` : ''}
                        </td>
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
                            ${issue.month_display || issue.month_label ? escapeHtml(issue.month_display || issue.month_label) : '<span style="color: #9ca3af; font-size: 11px;">—</span>'}
                        </td>
                        <td style="padding: 12px 16px; color: #6b7280; font-size: 13px; max-width: 220px;">
                            ${issue.status === 'Resolved' || issue.status === 'Closed'
                                ? `<div><i class="bi bi-check-circle" style="color: #15803d; margin-right: 4px;"></i>${issue.resolved_at ? escapeHtml(issue.resolved_at) : ''}</div>
                                   ${issue.resolution_notes ? `<div style="font-size: 12px; color: #6b7280; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(issue.resolution_notes)}">${escapeHtml(issue.resolution_notes)}</div>` : ''}`
                                : '<span style="color: #9ca3af; font-size: 11px;">—</span>'}
                        </td>
                        <td style="padding: 12px 16px; text-align: center; color: #6b7280; font-size: 13px;">
                            <i class="bi bi-calendar-event" style="margin-right: 4px;"></i>${issue.created_at}
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            ${renderAttachmentChips(issue.attachments) || '<span style="color: #9ca3af; font-size: 11px;">—</span>'}
                        </td>
                        @if(in_array(auth()->user()->role, ['admin', 'staff']))
                        <td style="padding: 12px 16px; text-align: center;">
                            <button class="btn btn-sm" onclick="openEditIssueModal(${issue.id}, '${escapeHtml(issue.issue_name)}', '${escapeHtml(issue.description)}', '${issue.status}', '${issue.issue_type || ''}', '${issue.severity || ''}', '${issue.baseline_schedule_id || ''}', '${escapeHtml(issue.resolution_notes || '')}')" title="Edit"
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
                `;
                    }).join('')}
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

function openEditIssueModal(id, name, description, status, issueType, severity, baselineScheduleId, resolutionNotes) {
    // Start a fresh deletion queue each time the edit modal is opened.
    window._pendingDeleteAttachmentIds = window._pendingDeleteAttachmentIds || [];
    window._pendingDeleteAttachmentIds = [];
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
    if (typeof clearPendingFiles === 'function') {
        clearPendingFiles('editIssueAttachmentFiles');
        clearPendingFiles('editResolutionAttachmentFiles');
    }

    if (typeof renderEditIssueAttachmentList === 'function') {
        renderEditIssueAttachmentList(window._issueDataById[id]);
    }

    if (typeof toggleIssueResolutionFields === 'function') {
        toggleIssueResolutionFields(status);
    }

    // Show a "converted from risk" note when this issue came from a risk
    const fromRiskWrap = document.getElementById('editIssueFromRiskWrap');
    const fromRiskName = document.getElementById('editIssueFromRiskName');
    const issueData = window._issueDataById[id];
    if (fromRiskWrap && fromRiskName && issueData && issueData.source_risk_id) {
        fromRiskName.textContent = issueData.source_risk_name || '#' + issueData.source_risk_id;
        fromRiskWrap.style.display = '';
    } else if (fromRiskWrap) {
        fromRiskWrap.style.display = 'none';
    }
    
    const editModal = new bootstrap.Modal(document.getElementById('editIssueModal'));
    editModal.show();
}

function confirmDeleteTabIssue(id) {
    const issueData = window._issueDataById && window._issueDataById[id];
    let message = 'Are you sure you want to delete this issue? This action cannot be undone.';
    if (issueData && issueData.source_risk_id) {
        const riskName = issueData.source_risk_name || ('#' + issueData.source_risk_id);
        message = 'This issue was created from risk <strong>' + escapeHtml(riskName) + '</strong>. ' +
            'Deleting it will revert the risk back to Open (and its evidence returns to the risk) so it can be re-converted or mitigated.';
    }
    if (typeof showDeleteConfirmation === 'function') {
        showDeleteConfirmation('Delete Issue', message, function() { deleteTabIssue(id); });
    } else {
        deleteTabIssue(id);
    }
}

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
            // A converted issue's source risk reverts to Open server-side;
            // refresh the risks table too so the reverted risk (and its
            // delete button) appears without a page reload.
            if (typeof loadTabRisks === 'function') loadTabRisks();
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
</script>
