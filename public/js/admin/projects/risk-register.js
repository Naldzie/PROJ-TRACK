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
        'Closed':    { bg: '#dcfce7', text: '#166534' }
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
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Mitigation</th>
                    <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; font-size: 13px;">Owner</th>
                    <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151; font-size: 13px;">Identified</th>
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
                        </td>
                        <td style="padding: 12px 16px; color: #6b7280; font-size: 13px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(risk.mitigation_plan || '')}">${risk.mitigation_plan ? escapeHtml(risk.mitigation_plan) : '<span style="color:#9ca3af;">—</span>'}</td>
                        <td style="padding: 12px 16px; color: #6b7280; font-size: 13px;">${risk.owner ? escapeHtml(risk.owner) : '<span style="color:#9ca3af;">—</span>'}</td>
                        <td style="padding: 12px 16px; text-align: center; color: #6b7280; font-size: 13px;">${risk.identified_at ? '<i class="bi bi-calendar-event" style="margin-right: 4px;"></i>' + escapeHtml(risk.identified_at) : '<span style="color:#9ca3af;">—</span>'}</td>
                        ${window.userRole === 'contractor' ? '' : `
                        <td style="padding: 12px 16px; text-align: center;">
                            <button class="btn btn-sm" onclick="openEditRiskModal(${risk.id})" title="Edit"
                                    style="background: #dbeafe; color: #0369a1; border: none; padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer; margin-right: 4px;">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm" onclick="confirmDeleteRisk(${risk.id})" title="Delete"
                                    style="background: #fee2e2; color: #991b1b; border: none; padding: 4px 8px; border-radius: 4px; font-size: 12px; cursor: pointer;">
                                <i class="bi bi-trash"></i>
                            </button>
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

        const payload = {
            risk_name:       document.getElementById('riskName').value.trim(),
            description:     document.getElementById('riskDescription').value.trim(),
            probability:     document.getElementById('riskProbability').value,
            impact:          document.getElementById('riskImpact').value,
            mitigation_plan: document.getElementById('riskMitigation').value.trim(),
            owner:           document.getElementById('riskOwner').value.trim(),
            status:          document.getElementById('riskStatus').value,
            identified_at:   document.getElementById('riskIdentifiedAt').value || null,
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
                bootstrap.Modal.getInstance(document.getElementById('riskModal')).hide();
                form.reset();
                loadTabRisks();
                showSuccessMessage('Risk created successfully');
            } else if (data.errors) {
                const first = Object.values(data.errors)[0];
                showErrorMessage(first ? first[0] : 'Validation error');
            } else {
                showErrorMessage(data.message || 'Error creating risk');
            }
        })
        .catch(error => {
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

    document.getElementById('editRiskId').value = risk.id;
    document.getElementById('editRiskName').value = risk.risk_name;
    document.getElementById('editRiskDescription').value = risk.description || '';
    document.getElementById('editRiskProbability').value = risk.probability;
    document.getElementById('editRiskImpact').value = risk.impact;
    document.getElementById('editRiskMitigation').value = risk.mitigation_plan || '';
    document.getElementById('editRiskOwner').value = risk.owner || '';
    document.getElementById('editRiskStatus').value = risk.status;

    const identified = document.getElementById('editRiskIdentifiedAt');
    if (identified) {
        identified.value = risk.identified_at ? toDateInputValue(risk.identified_at) : '';
    }

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
        const payload = {
            risk_name:       document.getElementById('editRiskName').value.trim(),
            description:     document.getElementById('editRiskDescription').value.trim(),
            probability:     document.getElementById('editRiskProbability').value,
            impact:          document.getElementById('editRiskImpact').value,
            mitigation_plan: document.getElementById('editRiskMitigation').value.trim(),
            owner:           document.getElementById('editRiskOwner').value.trim(),
            status:          document.getElementById('editRiskStatus').value,
            identified_at:   document.getElementById('editRiskIdentifiedAt').value || null,
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
                bootstrap.Modal.getInstance(document.getElementById('editRiskModal')).hide();
                loadTabRisks();
                showSuccessMessage('Risk updated successfully');
            } else if (data.errors) {
                const first = Object.values(data.errors)[0];
                showErrorMessage(first ? first[0] : 'Validation error');
            } else {
                showErrorMessage(data.message || 'Error updating risk');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorMessage('Error updating risk');
        });
    });
}

// ==================== DELETE ====================

let riskToDelete = null;

/**
 * Show the delete confirmation modal for a risk.
 */
function confirmDeleteRisk(id) {
    riskToDelete = id;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteRiskConfirmModal'));
    deleteModal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    const confirmBtn = document.getElementById('confirmDeleteRiskBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (riskToDelete) {
                deleteTabRisk(riskToDelete);
                bootstrap.Modal.getInstance(document.getElementById('deleteRiskConfirmModal')).hide();
                riskToDelete = null;
            }
        });
    }
});

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

    // Load risks once the tab is present (in case the page loads directly on it)
    if (document.getElementById('risksTableContainer')) {
        loadTabRisks();
    }
});
