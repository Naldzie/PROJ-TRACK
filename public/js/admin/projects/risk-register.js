/**
 * risk-register.js
 * Risk Register (gap #20) front-end for the CSPC PMU System.
 *
 * Loads and renders the risk list inside the Issues & Risks tab, handles the
 * Add/Edit/Delete modals, and updates the summary cards.
 *
 * Depends on page-level helpers defined in project_detail.blade.php:
 *   - getRisksApiUrl(action)
 *   - window.userRole, window.csrfToken
 *   - showToast() (fallback to alert)
 */

// ==================== LOAD RISKS ====================

/**
 * Fetch the risk list for the current project and render table + summary.
 */
function loadTabRisks() {
    const apiUrl = getRisksApiUrl();

    fetch(apiUrl, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayTabRisks(data.risks);
            updateRisksSummary(data.risks);
        } else {
            showErrorMessage(data.message || 'Error loading risks');
        }
    })
    .catch(error => {
        console.error('Error loading risks:', error);
        showErrorMessage('Error loading risks');
    });
}

// ==================== RENDER RISKS TABLE ====================

/**
 * Build the risks table HTML and inject it into #risksTableContainer.
 */
function displayTabRisks(risks) {
    const container = document.getElementById('risksTableContainer');
    if (!container) return;

    if (risks.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #9ca3af;">
                <i class="bi bi-shield-check" style="font-size: 32px; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                <div style="font-size: 13px;">No risks recorded for this project</div>
            </div>
        `;
        return;
    }

    const statusColors = {
        'Open':      { bg: '#fee2e2', text: '#991b1b' },
        'Mitigated': { bg: '#fef3c7', text: '#92400e' },
        'Closed':    { bg: '#dcfce7', text: '#166534' },
        'Realized':  { bg: '#ede9fe', text: '#5b21b6' }
    };

    const html = `
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f3f4f6; border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Risk Name</th>
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Description</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Score</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Level</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Status</th>
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Baseline</th>
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Mitigation</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Identified</th>
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Evidence</th>
                    ${window.userRole === 'contractor' ? '' : `
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Actions</th>
                    `}
                </tr>
            </thead>
            <tbody>
                ${risks.map((risk, index) => {
                    window._riskDataById = window._riskDataById || {};
                    window._riskDataById[risk.id] = risk;
                    const sc = statusColors[risk.status] || { bg: '#e5e7eb', text: '#374151' };
                    const lvl = risk.risk_level || riskLevelFromScore(risk.risk_score);
                    return `
                    <tr style="border-bottom: 1px solid #e5e7eb; ${index % 2 === 0 ? 'background: #f9fafb;' : 'background: white;'} transition: background 0.2s;">
                        <td style="padding: 12px 16px; font-weight: 600; color: #111827; font-size: 13px;">${escapeHtml(risk.risk_name)}</td>
                        <td style="padding: 12px 16px; color: #6b7280; font-size: 13px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(risk.description || '')}">${risk.description ? escapeHtml(risk.description) : '<span style="color:#9ca3af;">—</span>'}</td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span class="risk-score-badge ${riskScoreClass(risk.risk_score)}">${risk.risk_score}</span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span class="risk-level-badge ${riskLevelClass(lvl)}">${escapeHtml(lvl)}</span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; background: ${sc.bg}; color: ${sc.text};">
                                ${escapeHtml(risk.status)}
                            </span>
                            ${risk.converted_issue_label ? `
                            <div style="margin-top: 5px;">
                                <a href="#" onclick="event.preventDefault(); switchToIssuesTab();" title="View the issue this risk became"
                                   style="font-size: 11px; font-weight: 600; color: #5b21b6; text-decoration: none; background: #ede9fe; padding: 3px 8px; border-radius: 10px; display: inline-flex; align-items: center; gap: 4px;">
                                    <i class="bi bi-arrow-right-short"></i> ${escapeHtml(risk.converted_issue_label)}
                                </a>
                            </div>
                            ` : ''}
                        </td>
                        <td style="padding: 12px 16px; color: #6b7280; font-size: 13px;">${risk.baseline_label ? '<i class="bi bi-calendar-month" style="margin-right: 4px;"></i>' + escapeHtml(risk.baseline_label) : '<span style="color:#9ca3af;">—</span>'}</td>
                        <td style="padding: 12px 16px; color: #6b7280; font-size: 13px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(risk.mitigation_plan || '')}">${risk.mitigation_plan ? escapeHtml(risk.mitigation_plan) : '<span style="color:#9ca3af;">—</span>'}</td>
                        <td style="padding: 12px 16px; text-align: center; color: #6b7280; font-size: 13px;">${risk.identified_at ? '<i class="bi bi-calendar-event" style="margin-right: 4px;"></i>' + escapeHtml(risk.identified_at) : '<span style="color:#9ca3af;">—</span>'}</td>
                        <td style="padding: 12px 16px;">${renderRiskAttachmentChips(risk.attachments)}</td>
                        ${window.userRole === 'contractor' ? '' : `
                        <td style="padding: 12px 16px; text-align: center;">
                            ${risk.status !== 'Realized' && risk.status !== 'Closed' ? `
                            <button class="btn btn-sm" onclick="openConvertRiskModal(${risk.id})" title="Convert to Issue"
                                    style="background: #dcfce7; color: #166534; border: none; padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer; margin-right: 4px;">
                                <i class="bi bi-arrow-left-right"></i>
                            </button>
                            ` : ''}
                            <button class="btn btn-sm" onclick="openEditRiskModal(${risk.id})" title="Edit"
                                    style="background: #dbeafe; color: #0369a1; border: none; padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer; margin-right: 4px;">
                                <i class="bi bi-pencil"></i>
                            </button>
                            ${risk.status === 'Realized' ? '' : `
                            <button class="btn btn-sm" onclick="confirmDeleteRisk(${risk.id})" title="Delete"
                                    style="background: #fee2e2; color: #991b1b; border: none; padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer;">
                                <i class="bi bi-trash"></i>
                            </button>
                            `}
                        </td>
                        `}
                    </tr>
                `;
                }).join('')}
            </tbody>
        </table>
    `;

    container.innerHTML = html;
}

/**
 * Update the four summary cards (Open / Mitigated / Closed / High) and total.
 */
function updateRisksSummary(risks) {
    const summary = { 'Open': 0, 'Mitigated': 0, 'Closed': 0, 'High': 0 };

    risks.forEach(risk => {
        if (summary.hasOwnProperty(risk.status)) {
            summary[risk.status]++;
        }
        const lvl = risk.risk_level || riskLevelFromScore(risk.risk_score);
        if (lvl === 'High') {
            summary['High']++;
        }
    });

    document.getElementById('openRisksCount').textContent      = summary['Open'];
    document.getElementById('mitigatedRisksCount').textContent = summary['Mitigated'];
    document.getElementById('closedRisksCount').textContent    = summary['Closed'];
    document.getElementById('highRisksCount').textContent      = summary['High'];
    document.getElementById('totalRisksCount').textContent     = risks.length;
}

// ==================== RISK ATTACHMENT HELPERS ====================

/**
 * Cache for risk attachment payloads (keyed by attachment id) so the
 * document viewer can be opened without another network call.
 */
window._riskAttachments = window._riskAttachments || {};

/**
 * Bootstrap icon for a file based on its extension.
 */
function riskAttachmentIcon(filename) {
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

/**
 * Render clickable evidence chips for a risk's attachments.
 */
function renderRiskAttachmentChips(attachments) {
    const list = attachments || [];
    if (list.length === 0) return '<span style="color:#9ca3af;">—</span>';
    return list.map(att => {
        window._riskAttachments[att.id] = att;
        return `
            <span class="attachment-chip me-1 mb-1" title="${escapeHtml(att.original_name)}"
                  onclick="openRiskAttachmentById(${att.id})">
                <i class="bi ${riskAttachmentIcon(att.original_name)}"></i>
                <span>${escapeHtml(att.original_name)}</span>
            </span>
        `;
    }).join('');
}

/**
 * Open the shared document viewer for a risk attachment.
 */
function openRiskAttachmentById(id) {
    const att = window._riskAttachments[id];
    if (!att || typeof openDocumentViewer !== 'function') return;
    const role = window.userRole;
    openDocumentViewer({
        id: att.id,
        name: att.original_name,
        type: 'Risk Evidence',
        size: att.display_size,
        uploadedBy: att.uploaded_by,
        uploadedAt: att.created_at,
        viewUrl: `/${role}/attachments/${att.id}/view`,
        downloadUrl: `/${role}/attachments/${att.id}/download`
    });
}

/**
 * Render the current (saved) attachment list in the EDIT modal, excluding any
 * attachments queued for deletion this session.
 */
function renderEditRiskAttachmentList(risk) {
    const container = document.getElementById('editRiskAttachmentList');
    if (!container) return;

    const pending = (window._pendingRiskDeleteAttachmentIds || []);
    const attachments = ((risk && risk.attachments) || []).filter(a => pending.indexOf(a.id) === -1);
    if (attachments.length === 0) {
        container.innerHTML = '<small class="text-muted">No evidence attached.</small>';
        return;
    }

    const renderChip = (att) => {
        window._riskAttachments[att.id] = att;
        return `
            <div class="attachment-chip edit-chip me-1 mb-1" style="cursor: default;" title="${escapeHtml(att.original_name)}">
                <span class="att-name" onclick="openRiskAttachmentById(${att.id})" title="View attachment"
                      style="cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="bi ${riskAttachmentIcon(att.original_name)}"></i>
                    <span>${escapeHtml(att.original_name)}</span>
                </span>
                <button type="button" class="attachment-remove-btn" title="Remove attachment"
                        onclick="deleteRiskAttachment(${att.id})">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
        `;
    };

    container.innerHTML = attachments.map(renderChip).join('');
}

// Attachment deletions in the EDIT modal are deferred: they are queued in
// window._pendingRiskDeleteAttachmentIds and only deleted on the server when
// the user actually clicks "Update Risk". Queued files are visually removed
// immediately, but no DELETE request fires until the risk is saved.

window._pendingRiskDeleteAttachmentIds = [];

function deleteRiskAttachment(attachmentId) {
    if (typeof showDeleteConfirmation !== 'function') {
        showErrorMessage('Delete confirmation component is not available');
        return;
    }
    showDeleteConfirmation(
        'Remove Attachment',
        'This attachment will be removed when you save the risk. It will not be deleted unless you click "Update Risk".',
        function() { queueRiskAttachmentForDeletion(attachmentId); }
    );
}

function queueRiskAttachmentForDeletion(attachmentId) {
    if (window._pendingRiskDeleteAttachmentIds.indexOf(attachmentId) === -1) {
        window._pendingRiskDeleteAttachmentIds.push(attachmentId);
    }
    const editRiskId = document.getElementById('editRiskId')?.value;
    if (editRiskId && window._riskDataById[editRiskId]) {
        renderEditRiskAttachmentList(window._riskDataById[editRiskId]);
    }
    delete window._riskAttachments[attachmentId];
}

/**
 * Actually delete all queued risk attachments on the server. Called only after
 * the risk update has been persisted successfully, never on plain "remove".
 */
function flushPendingRiskAttachmentDeletions() {
    const toDelete = window._pendingRiskDeleteAttachmentIds.slice();
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

    window._pendingRiskDeleteAttachmentIds = [];
    return Promise.all(requests);
}

/**
 * Upload a single risk evidence file (context 'risk').
 */
function uploadRiskAttachment(riskId, file) {
    const fd = new FormData();
    fd.append('project_id', window.projectId);
    fd.append('attachable_type', 'App\\Models\\Risk');
    fd.append('attachable_id', riskId);
    fd.append('context', 'risk');
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
            throw new Error((data && data.message) || 'Attachment upload failed');
        }
        return data;
    });
}

/**
 * Upload all pending risk files for a risk.
 */
function uploadRiskFiles(riskId, files) {
    return Promise.all((files || []).map(file => uploadRiskAttachment(riskId, file)));
}

// ==================== CONVERT RISK → ISSUE ====================

/**
 * Fill and open the Convert Risk to Issue modal, pre-filling issue fields
 * from the risk and carrying over the baseline month + severity.
 */
function openConvertRiskModal(riskId) {
    const risk = window._riskDataById ? window._riskDataById[riskId] : null;
    if (!risk) return;

    document.getElementById('convertIssueName').value = risk.risk_name;
    document.getElementById('convertIssueDescription').value =
        risk.description || 'Risk "' + risk.risk_name + '" materialized and was converted into an issue.';
    document.getElementById('convertIssueType').value = 'other';
    document.getElementById('convertIssueSeverity').value = risk.risk_level || riskLevelFromScore(risk.risk_score);
    document.getElementById('convertIssueBaselineId').value = risk.baseline_schedule_id || '';
    window._riskBeingConverted = risk.id;

    // Start a fresh evidence-deletion queue + reset the add-evidence input.
    window._pendingConvertDeleteAttachmentIds = [];
    const fileInput = document.getElementById('convertRiskAttachmentFiles');
    if (fileInput) fileInput.value = '';
    if (typeof clearPendingFiles === 'function') clearPendingFiles('convertRiskAttachmentFiles');
    renderConvertRiskAttachmentList(risk);

    const convertModal = new bootstrap.Modal(document.getElementById('riskToIssueModal'));
    convertModal.show();
}

// Evidence the user removes in the convert modal is queued here and permanently
// deleted only AFTER the conversion succeeds (mirrors the risk edit modal).
window._pendingConvertDeleteAttachmentIds = [];

/**
 * Render the risk's current evidence in the convert modal with a remove (X)
 * button per chip. Removed chips disappear immediately but are only deleted on
 * the server once the issue has been created.
 */
function renderConvertRiskAttachmentList(risk) {
    const container = document.getElementById('convertRiskAttachmentList');
    if (!container) return;

    const pending = window._pendingConvertDeleteAttachmentIds;
    const attachments = ((risk && risk.attachments) || []).filter(a => pending.indexOf(a.id) === -1);
    if (attachments.length === 0) {
        container.innerHTML = '<small class="text-muted">No evidence attached to this risk.</small>';
        return;
    }

    container.innerHTML = attachments.map(att => {
        window._riskAttachments[att.id] = att;
        return `
            <div class="attachment-chip edit-chip me-1 mb-1" style="cursor: default;" title="${escapeHtml(att.original_name)}">
                <span class="att-name" onclick="openRiskAttachmentById(${att.id})" title="View attachment"
                      style="cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="bi ${riskAttachmentIcon(att.original_name)}"></i>
                    <span>${escapeHtml(att.original_name)}</span>
                </span>
                <button type="button" class="attachment-remove-btn" title="Remove evidence"
                        onclick="removeConvertAttachment(${att.id})">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
        `;
    }).join('');
}

/**
 * Confirm + queue a risk evidence removal in the convert modal. The file is
 * permanently deleted only after conversion succeeds.
 */
function removeConvertAttachment(attachmentId) {
    if (typeof showDeleteConfirmation !== 'function') {
        showErrorMessage('Delete confirmation component is not available');
        return;
    }
    showDeleteConfirmation(
        'Remove Evidence',
        'This file will be permanently deleted when you convert the risk. It will not be moved to the new issue.',
        function() { queueConvertAttachmentForDeletion(attachmentId); }
    );
}

function queueConvertAttachmentForDeletion(attachmentId) {
    if (window._pendingConvertDeleteAttachmentIds.indexOf(attachmentId) === -1) {
        window._pendingConvertDeleteAttachmentIds.push(attachmentId);
    }
    const riskId = window._riskBeingConverted;
    if (riskId && window._riskDataById[riskId]) {
        renderConvertRiskAttachmentList(window._riskDataById[riskId]);
    }
    delete window._riskAttachments[attachmentId];
}

/**
 * Permanently delete all queued evidence on the server after the issue has
 * been created. Uses the shared DELETE /attachments/{id} endpoint.
 */
function flushPendingConvertAttachmentDeletions() {
    const toDelete = window._pendingConvertDeleteAttachmentIds.slice();
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

    window._pendingConvertDeleteAttachmentIds = [];
    return Promise.all(requests);
}

/**
 * Upload newly picked evidence files to the newly created issue (context 'issue').
 * Reuses uploadIssueAttachment() from issues-modal.js when available.
 */
function uploadConvertIssueFiles(issueId, files) {
    const list = files || [];
    if (list.length === 0) return Promise.resolve();

    if (typeof uploadIssueAttachment === 'function') {
        return Promise.all(list.map(file => uploadIssueAttachment(issueId, 'issue', file)));
    }

    const role = window.userRole;
    return Promise.all(list.map(file => {
        const fd = new FormData();
        fd.append('project_id', window.projectId);
        fd.append('attachable_type', 'App\\Models\\Issue');
        fd.append('attachable_id', issueId);
        fd.append('context', 'issue');
        fd.append('file', file);
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
                throw new Error((data && data.message) || 'Attachment upload failed');
            }
            return data;
        });
    }));
}

/**
 * Wire the Convert to Issue modal submit → POST the staff/admin convert route.
 */
function initConvertRiskForm() {
    const btn = document.getElementById('confirmConvertRiskBtn');
    if (!btn) return;

    btn.addEventListener('click', function() {
        const riskId = window._riskBeingConverted;
        if (!riskId) return;

        const payload = {
            issue_name:       document.getElementById('convertIssueName').value.trim(),
            description:      document.getElementById('convertIssueDescription').value.trim(),
            issue_type:       document.getElementById('convertIssueType').value || null,
            severity:         document.getElementById('convertIssueSeverity').value || null,
            baseline_schedule_id: document.getElementById('convertIssueBaselineId').value || null,
        };

        fetch(getRisksApiUrl(riskId + '/convert'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window.csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const issueId = data.issue && data.issue.id;
                const newFiles = (typeof collectPendingFiles === 'function') ? collectPendingFiles('convertRiskAttachmentFiles') : [];

                // Issue created + risk evidence transferred server-side. Now
                // delete any removed evidence and upload newly picked files.
                return Promise.all([
                    flushPendingConvertAttachmentDeletions(),
                    issueId ? uploadConvertIssueFiles(issueId, newFiles) : Promise.resolve()
                ])
                .then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('riskToIssueModal')).hide();
                    window._riskBeingConverted = null;
                    if (typeof clearPendingFiles === 'function') clearPendingFiles('convertRiskAttachmentFiles');
                    loadTabRisks();
                    if (typeof loadTabIssues === 'function') loadTabIssues();
                    showSuccessMessage('Risk converted to issue successfully');
                })
                .catch(err => {
                    // The issue was created; only the attachment sync failed.
                    console.error('Attachment sync error:', err);
                    bootstrap.Modal.getInstance(document.getElementById('riskToIssueModal')).hide();
                    window._riskBeingConverted = null;
                    loadTabRisks();
                    if (typeof loadTabIssues === 'function') loadTabIssues();
                    showErrorMessage('Issue created, but some attachment changes failed. Manage attachments from the issue edit modal.');
                });
            } else {
                showErrorMessage(data.message || 'Error converting risk');
            }
        })
        .catch(error => {
            console.error('Error converting risk:', error);
            showErrorMessage('Error converting risk');
        });
    });
}

/**
 * Jump to the Issues section (via the All/Issues/Risks toggle) so the user
 * can see the issue a Realized risk became.
 */
function switchToIssuesTab() {
    const toggleBtn = document.querySelector('.risk-toggle-btn[data-risk-view="all"]') ||
                      document.querySelector('.risk-toggle-btn[data-risk-view="issues"]');
    if (toggleBtn) toggleBtn.click();
    const target = document.getElementById('issuesSection');
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/**
 * Jump to the Risks section (via the All/Issues/Risks toggle) so the user
 * can see the source risk a converted issue came from.
 */
function switchToRisksTab() {
    const toggleBtn = document.querySelector('.risk-toggle-btn[data-risk-view="all"]') ||
                      document.querySelector('.risk-toggle-btn[data-risk-view="risks"]');
    if (toggleBtn) toggleBtn.click();
    const target = document.getElementById('risksSection');
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ==================== SCORE / LEVEL HELPERS ====================

/**
 * Map a numeric score (1-9) to its level label.
 */
function riskLevelFromScore(score) {
    if (score >= 7) return 'High';
    if (score >= 4) return 'Medium';
    return 'Low';
}

/**
 * CSS class for the score pill based on its level.
 */
function riskScoreClass(score) {
    const level = riskLevelFromScore(score);
    if (level === 'High') return 'risk-level-high';
    if (level === 'Medium') return 'risk-level-medium';
    return 'risk-level-low';
}

/**
 * CSS class for the level badge.
 */
function riskLevelClass(level) {
    const l = String(level).toLowerCase();
    return 'risk-level-' + l;
}

// ==================== ADD / EDIT MODAL ====================

/**
 * Live-update the score preview badge in the add modal as P × I changes.
 */
function bindRiskScorePreview() {
    const weight = { 'Low': 1, 'Medium': 2, 'High': 3 };

    function update(prefix) {
        const p = document.getElementById(prefix + 'Probability').value;
        const i = document.getElementById(prefix + 'Impact').value;
        const score = weight[p] * weight[i];
        const level = riskLevelFromScore(score);
        const el = document.getElementById(prefix + 'ScorePreview');
        if (el) {
            el.innerHTML = `<span class="risk-score-badge ${riskScoreClass(score)}">Score ${score} · ${level}</span>`;
        }
    }

    ['risk', 'editRisk'].forEach(prefix => {
        const p = document.getElementById(prefix + 'Probability');
        const i = document.getElementById(prefix + 'Impact');
        if (p) p.addEventListener('change', () => update(prefix));
        if (i) i.addEventListener('change', () => update(prefix));
    });
}

// ==================== SUBMIT HANDLERS ====================

/**
 * Handle Add Risk form submission.
 */
function initAddRiskForm() {
    const form = document.getElementById('addRiskForm');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const riskFiles = (typeof collectPendingFiles === 'function') ? collectPendingFiles('riskAttachmentFiles') : [];

        const payload = {
            risk_name:       document.getElementById('riskName').value.trim(),
            description:     document.getElementById('riskDescription').value.trim(),
            probability:     document.getElementById('riskProbability').value,
            impact:          document.getElementById('riskImpact').value,
            mitigation_plan: document.getElementById('riskMitigation').value.trim(),
            status:          document.getElementById('riskStatus').value,
            identified_at:   document.getElementById('riskIdentifiedAt').value || null,
            baseline_schedule_id: document.getElementById('riskBaselineId').value || null,
        };

        fetch(getRisksApiUrl(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window.csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                return uploadRiskFiles(data.risk.id, riskFiles).then(() => data);
            } else if (data.errors) {
                const first = Object.values(data.errors)[0];
                showErrorMessage(first ? first[0] : 'Validation error');
                return Promise.reject({ silent: true });
            } else {
                showErrorMessage(data.message || 'Error creating risk');
                return Promise.reject({ silent: true });
            }
        })
        .then(data => {
            bootstrap.Modal.getInstance(document.getElementById('riskModal')).hide();
            form.reset();
            if (typeof clearPendingFiles === 'function') clearPendingFiles('riskAttachmentFiles');
            loadTabRisks();
            showSuccessMessage('Risk created successfully');
        })
        .catch(error => {
            if (error && error.silent) return;
            console.error('Error:', error);
            showErrorMessage('Error creating risk');
        });
    });
}

/**
 * Populate and open the Edit Risk modal.
 */
function openEditRiskModal(id) {
    const risk = window._riskDataById ? window._riskDataById[id] : null;
    if (!risk) return;

    // Start a fresh deletion queue each time the edit modal is opened.
    window._pendingRiskDeleteAttachmentIds = [];

    document.getElementById('editRiskId').value = risk.id;
    document.getElementById('editRiskName').value = risk.risk_name;
    document.getElementById('editRiskDescription').value = risk.description || '';
    document.getElementById('editRiskProbability').value = risk.probability;
    document.getElementById('editRiskImpact').value = risk.impact;
    document.getElementById('editRiskMitigation').value = risk.mitigation_plan || '';
    document.getElementById('editRiskStatus').value = risk.status;
    document.getElementById('editRiskBaselineId').value = risk.baseline_schedule_id || '';

    const identified = document.getElementById('editRiskIdentifiedAt');
    if (identified) {
        identified.value = risk.identified_at ? toDateInputValue(risk.identified_at) : '';
    }

    // Lock the status select when the risk has been converted (Realized)
    const statusSelect = document.getElementById('editRiskStatus');
    const statusNote = document.getElementById('editRiskStatusNote');
    const isRealized = risk.status === 'Realized';
    if (statusSelect) statusSelect.disabled = isRealized;
    if (statusNote) statusNote.style.display = isRealized ? '' : 'none';

    // Reset file inputs + pending previews, then render existing evidence
    const fileInput = document.getElementById('editRiskAttachmentFiles');
    if (fileInput) fileInput.value = '';
    if (typeof clearPendingFiles === 'function') clearPendingFiles('editRiskAttachmentFiles');
    renderEditRiskAttachmentList(risk);

    // Update score preview to the actual stored score
    const preview = document.getElementById('editRiskScorePreview');
    if (preview) {
        preview.innerHTML = `<span class="risk-score-badge ${riskScoreClass(risk.risk_score)}">Score ${risk.risk_score} · ${risk.risk_level || riskLevelFromScore(risk.risk_score)}</span>`;
    }

    const editModal = new bootstrap.Modal(document.getElementById('editRiskModal'));
    editModal.show();
}

/**
 * Convert a "Mon DD, YYYY" (or ISO) date string to <input type="date"> value.
 */
function toDateInputValue(value) {
    const d = new Date(value);
    if (isNaN(d.getTime())) return '';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/**
 * Handle Edit Risk form submission.
 */
function initEditRiskForm() {
    const form = document.getElementById('editRiskForm');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const id = document.getElementById('editRiskId').value;
        const editFiles = (typeof collectPendingFiles === 'function') ? collectPendingFiles('editRiskAttachmentFiles') : [];
        const payload = {
            risk_name:       document.getElementById('editRiskName').value.trim(),
            description:     document.getElementById('editRiskDescription').value.trim(),
            probability:     document.getElementById('editRiskProbability').value,
            impact:          document.getElementById('editRiskImpact').value,
            mitigation_plan: document.getElementById('editRiskMitigation').value.trim(),
            status:          document.getElementById('editRiskStatus').value,
            identified_at:   document.getElementById('editRiskIdentifiedAt').value || null,
            baseline_schedule_id: document.getElementById('editRiskBaselineId').value || null,
        };

        fetch(getRisksApiUrl(id), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window.csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                return Promise.all([
                    flushPendingRiskAttachmentDeletions(),
                    uploadRiskFiles(id, editFiles)
                ]).then(() => data);
            } else if (data.errors) {
                const first = Object.values(data.errors)[0];
                showErrorMessage(first ? first[0] : 'Validation error');
                return Promise.reject({ silent: true });
            } else {
                showErrorMessage(data.message || 'Error updating risk');
                return Promise.reject({ silent: true });
            }
        })
        .then(data => {
            bootstrap.Modal.getInstance(document.getElementById('editRiskModal')).hide();
            window._pendingRiskDeleteAttachmentIds = [];
            if (typeof clearPendingFiles === 'function') clearPendingFiles('editRiskAttachmentFiles');
            loadTabRisks();
            showSuccessMessage('Risk updated successfully');
        })
        .catch(error => {
            if (error && error.silent) return;
            console.error('Error:', error);
            showErrorMessage('Error updating risk');
        });
    });
}

// ==================== DELETE ====================

/**
 * Show the delete confirmation for a risk using the reusable
 * components/delete-confirmation-modal (showDeleteConfirmation).
 */
function confirmDeleteRisk(id) {
    const message = 'Are you sure you want to delete this risk? This action cannot be undone.';
    if (typeof showDeleteConfirmation === 'function') {
        showDeleteConfirmation('Delete Risk', message, function() { deleteTabRisk(id); });
    } else {
        deleteTabRisk(id);
    }
}

/**
 * Delete a risk via the API and refresh the list.
 */
function deleteTabRisk(id) {
    fetch(getRisksApiUrl(id), {
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
            loadTabRisks();
            showSuccessMessage('Risk deleted successfully');
        } else {
            showErrorMessage(data.message || 'Error deleting risk');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showErrorMessage('Error deleting risk');
    });
}

// ==================== UTILITIES ====================

/**
 * HTML-escape a string for safe rendering.
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

/**
 * Show a success toast (falls back to alert).
 */
function showSuccessMessage(message) {
    if (window.showToast && typeof window.showToast === 'function') {
        window.showToast(message, 'success', 2500);
    } else {
        console.warn('showToast not available, using alert');
        alert('Success: ' + message);
    }
}

/**
 * Show an error toast (falls back to alert).
 */
function showErrorMessage(message) {
    if (window.showToast && typeof window.showToast === 'function') {
        window.showToast(message, 'error', 3000);
    } else {
        console.warn('showToast not available, using alert');
        alert('Error: ' + message);
    }
}

// ==================== INIT ====================

document.addEventListener('DOMContentLoaded', function() {
    bindRiskScorePreview();
    initAddRiskForm();
    initEditRiskForm();
    initConvertRiskForm();

    // Accumulate multiple file picks for the risk evidence inputs (add + edit + convert).
    ['riskAttachmentFiles', 'editRiskAttachmentFiles', 'convertRiskAttachmentFiles'].forEach(inputId => {
        const input = document.getElementById(inputId);
        if (input && typeof addPendingFiles === 'function') {
            input.addEventListener('change', function() {
                addPendingFiles(inputId, this.files);
            });
        }
    });

    // Load risks once the tab is present (in case the page loads directly on it)
    if (document.getElementById('risksTableContainer')) {
        loadTabRisks();
    }
});
