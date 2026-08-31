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
        // Parent "Resolution" group (notes + resolution evidence files)
        const groupWrap = document.getElementById(prefix + 'IssueResolutionWrap');
        if (groupWrap) groupWrap.style.display = show ? '' : 'none';
        const notesWrap = document.getElementById(prefix + 'IssueResolutionNotesWrap');
        const filesWrap = document.getElementById(prefix + 'IssueResolutionFilesWrap');
        if (notesWrap) notesWrap.style.display = show ? '' : 'none';
        if (filesWrap) filesWrap.style.display = show ? '' : 'none';
    });
}

function loadIssues() {
    // The in-modal issues list was temporarily commented out for small screens.
    // Keep this a safe no-op so existing callers (delete attachment, submit) don't error.
    if (!document.getElementById('issuesList')) return;
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
        downloadUrl: `/${role}/attachments/${att.id}/download`,
        previewUrl: `/${role}/attachments/${att.id}/preview`
    });
}

function renderEditIssueAttachmentList(issue) {
    const container = document.getElementById('editIssueAttachmentList');
    if (!container) return;

    // Read from the source list and exclude any attachments currently queued
    // for deletion (pending). We must NOT mutate issue.attachments itself so a
    // cancelled edit session leaves the cached data intact for re-opening.
    const pending = (window._pendingDeleteAttachmentIds || []);
    const attachments = ((issue && issue.attachments) || []).filter(a => pending.indexOf(a.id) === -1);
    if (attachments.length === 0) {
        container.innerHTML = '<small class="text-muted">No attachments yet.</small>';
        return;
    }

    // Separate existing attachments by context: issue evidence vs resolution proof
    const issueAtts = attachments.filter(a => a.context !== 'resolution');
    const resolutionAtts = attachments.filter(a => a.context === 'resolution');

    const renderChip = (att) => {
        window._issueAttachments[att.id] = att;
        return `
            <div class="attachment-chip edit-chip me-1 mb-1" style="cursor: default;" title="${escapeHtml(att.original_name)}">
                <span class="att-name" onclick="openIssueAttachmentById(${att.id})" title="View attachment"
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
    };

    let html = '';
    if (issueAtts.length > 0) {
        html += `
            <div class="issue-evidence-group-label" style="margin-bottom: 4px;">
                <i class="bi bi-clipboard-plus"></i> Issue Evidence (${issueAtts.length})
            </div>
            <div class="d-flex flex-wrap mb-2">${issueAtts.map(renderChip).join('')}</div>
        `;
    }
    if (resolutionAtts.length > 0) {
        html += `
            <div class="issue-evidence-group-label" style="margin-bottom: 4px;">
                <i class="bi bi-check-circle"></i> Resolution Evidence (${resolutionAtts.length})
            </div>
            <div class="d-flex flex-wrap">${resolutionAtts.map(renderChip).join('')}</div>
        `;
    }

    container.innerHTML = html;
}

// Attachment deletions in the EDIT modal are deferred: they are queued in
// window.pendingDeleteAttachmentIds and only deleted on the server when the
// user actually clicks the "Update Issue" button. Queued files are visually
// removed from the list immediately (on confirm), but no DELETE request fires
// until the issue is saved â€” so closing/cancelling the modal keeps them intact.

window._pendingDeleteAttachmentIds = [];

function deleteIssueAttachment(attachmentId) {
    // Use the reusable delete confirmation modal (components/delete-confirmation-modal.blade.php).
    // The component supplies showDeleteConfirmation(title, message, onConfirm) + confirmDeleteAction().
    if (typeof showDeleteConfirmation !== 'function') {
        showErrorMessage('Delete confirmation component is not available');
        return;
    }
    showDeleteConfirmation(
        'Remove Attachment',
        'This attachment will be removed when you save the issue. It will not be deleted unless you click "Update Issue".',
        function() { queueAttachmentForDeletion(attachmentId); }
    );
}

function queueAttachmentForDeletion(attachmentId) {
    // Add to the pending-deletion queue for this edit session. We do NOT mutate
    // window._issueDataById so that cancelling/refreshing keeps the attachment.
    if (window._pendingDeleteAttachmentIds.indexOf(attachmentId) === -1) {
        window._pendingDeleteAttachmentIds.push(attachmentId);
    }
    // Visually remove the chip from the list immediately (UI only â€” server not touched yet).
    const editIssueId = document.getElementById('editIssueId')?.value;
    if (editIssueId && window._issueDataById[editIssueId]) {
        renderEditIssueAttachmentList(window._issueDataById[editIssueId]);
    }
    delete window._issueAttachments[attachmentId];
}

// Actually delete all queued attachments on the server. Called only after the
// issue update has been persisted successfully (Update button), never on a
// plain "remove" click or on modal close.
function flushPendingAttachmentDeletions() {
    const toDelete = window._pendingDeleteAttachmentIds.slice();
    if (toDelete.length === 0) return Promise.resolve();

    const role = window.userRole;
    const requests = toDelete.map(attachmentId =>
        fetch(`/${role}/attachments/${attachmentId}`, {
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
                throw new Error((data && data.message) || 'Failed to delete attachment #' + attachmentId);
            }
        })
    );

    // Clear the queue once we attempt the flushes.
    window._pendingDeleteAttachmentIds = [];
    return Promise.all(requests);
}

function displayIssues(issues) {
    const issuesList = document.getElementById('issuesList');
    if (!issuesList) return;
    
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
                ${window.userRole === 'admin' || window.isAssignedStaff ? `
                <div class="ms-2" style="flex-shrink: 0;">
                    <button class="btn btn-sm btn-outline-primary" onclick="editIssue(${issue.id}, '${escapeHtml(issue.issue_name)}', '${escapeHtml(issue.description)}', '${issue.status}', '${issue.issue_type || ''}', '${issue.severity || ''}', '${issue.baseline_schedule_id || ''}', '${escapeHtml(issue.resolution_notes || '')}')">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteIssue(${issue.id})">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                ` : ''}
            </div>
        </div>
    `;
    }).join('');
}

function editIssue(id, name, description, status, issueType, severity, baselineScheduleId, resolutionNotes) {
    // Start a fresh deletion queue each time the edit modal is opened.
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
    clearPendingFiles('editIssueAttachmentFiles');
    clearPendingFiles('editResolutionAttachmentFiles');

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
                // A converted issue's source risk reverts to Open server-side;
                // refresh the risks table too so it appears without a reload.
                if (typeof loadTabRisks === 'function') {
                    loadTabRisks();
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

// ==== PENDING FILE SELECTION (multi-file) ====
// Files the user has picked but not yet uploaded are accumulated per input id.
// A later pick APPENDS to earlier picks instead of replacing them (browser default).
window._pendingIssueFiles = window._pendingIssueFiles || {};

function getPendingFiles(inputId) {
    if (!window._pendingIssueFiles[inputId]) window._pendingIssueFiles[inputId] = [];
    return window._pendingIssueFiles[inputId];
}

function addPendingFiles(inputId, fileList) {
    const pending = getPendingFiles(inputId);
    Array.from(fileList || []).forEach(file => {
        const dup = pending.some(p => p.file.name === file.name && p.file.size === file.size);
        if (!dup) {
            pending.push({
                file: file,
                url: file.type && file.type.startsWith('image/') ? URL.createObjectURL(file) : null
            });
        }
    });
    const input = document.getElementById(inputId);
    if (input) input.value = '';
    renderPendingFiles(inputId);
}

function removePendingFile(inputId, index) {
    const pending = getPendingFiles(inputId);
    const removed = pending.splice(index, 1)[0];
    if (removed && removed.url) URL.revokeObjectURL(removed.url);
    renderPendingFiles(inputId);
}

function clearPendingFiles(inputId) {
    getPendingFiles(inputId).forEach(p => { if (p.url) URL.revokeObjectURL(p.url); });
    window._pendingIssueFiles[inputId] = [];
    const container = document.getElementById(inputId + 'Preview');
    if (container) container.innerHTML = '';
    const input = document.getElementById(inputId);
    if (input) input.value = '';
}

function renderPendingFiles(inputId) {
    const container = document.getElementById(inputId + 'Preview');
    if (!container) return;
    const pending = getPendingFiles(inputId);
    if (pending.length === 0) {
        container.innerHTML = '';
        return;
    }
    container.innerHTML = pending.map((p, i) => `
        <span class="attachment-chip edit-chip me-1 mb-1" style="cursor: default;" title="${escapeHtml(p.file.name)}">
            <span style="display: inline-flex; align-items: center; gap: 5px;">
                ${p.url
                    ? `<img src="${p.url}" alt="" style="width: 22px; height: 22px; object-fit: cover; border-radius: 3px;">`
                    : `<i class="bi ${getAttachmentIcon(p.file.name)}"></i>`}
                <span class="att-name">${escapeHtml(p.file.name)}</span>
            </span>
            <button type="button" class="attachment-remove-btn" title="Remove file"
                    onclick="removePendingFile('${inputId}', ${i})">
                <i class="bi bi-x-circle-fill"></i>
            </button>
        </span>
    `).join('');
}

function collectPendingFiles(inputId) {
    return getPendingFiles(inputId).map(p => p.file);
}

// Accumulate multiple file picks on every file input (add + edit forms).
['issueAttachmentFiles', 'resolutionAttachmentFiles', 'editIssueAttachmentFiles', 'editResolutionAttachmentFiles']
    .forEach(inputId => {
        const input = document.getElementById(inputId);
        if (input) {
            input.addEventListener('change', function() {
                addPendingFiles(inputId, this.files);
            });
        }
    });

// Form submission (admin + assigned staff only)
if (window.userRole === 'admin' || window.isAssignedStaff) {
document.getElementById('addIssueForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const issueFiles = collectPendingFiles('issueAttachmentFiles');
    const resolutionFiles = collectPendingFiles('resolutionAttachmentFiles');
    
    const formData = new FormData(this);
    // Files are uploaded after issue creation (attachable_id requires the new issue id)
    formData.delete('issue_attachment_files[]');
    formData.delete('resolution_attachment_files[]');
    
    const apiUrl = getIssuesApiUrl();
    
    try {
        var addModal = bootstrap.Modal.getInstance(document.getElementById('addIssueModal'));
        if (addModal) addModal.hide();
    } catch (err) {}
    if (window.ProjTrackPreloader && window.ProjTrackPreloader.show) {
        window.ProjTrackPreloader.show('Saving issue&hellip;');
    }

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
        clearPendingFiles('issueAttachmentFiles');
        clearPendingFiles('resolutionAttachmentFiles');
        toggleIssueResolutionFields('Open');
        loadIssues();
        // Also refresh the issues tab
        if (typeof loadTabIssues === 'function') {
            loadTabIssues();
        }
        if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
        showSuccessMessage('Issue created successfully');
    })
    .catch(error => {
        console.error('Error creating issue:', error);
        if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
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
    const issueFiles = collectPendingFiles('editIssueAttachmentFiles');
    const resolutionFiles = collectPendingFiles('editResolutionAttachmentFiles');
    
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
    
    try {
        var editModal = bootstrap.Modal.getInstance(document.getElementById('editIssueModal'));
        if (editModal) editModal.hide();
    } catch (err) {}
    if (window.ProjTrackPreloader && window.ProjTrackPreloader.show) {
        window.ProjTrackPreloader.show('Updating issue&hellip;');
    }

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
            return uploadIssueFiles(issueId, issueFiles, resolutionFiles)
                .then(() => flushPendingAttachmentDeletions())
                .then(() => data);
        }
        throw {status: 400, data: data};
    })
    .then(data => {
        bootstrap.Modal.getInstance(document.getElementById('editIssueModal')).hide();
        clearPendingFiles('editIssueAttachmentFiles');
        clearPendingFiles('editResolutionAttachmentFiles');
        loadIssues();
        // Also refresh the issues tab
        if (typeof loadTabIssues === 'function') {
            loadTabIssues();
        }
        if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
        showSuccessMessage('Issue updated successfully');
    })
    .catch(error => {
        console.error('Error updating issue:', error);
        if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
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
