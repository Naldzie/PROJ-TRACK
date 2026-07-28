/**
 * Carry Over Activity Modal - Baseline Schedule Feature
 * Handles carrying over incomplete activities to future months
 *
 * Dependencies:
 * - window.projectId (set by baseline-schedule.blade.php)
 * - window.routePrefix (set by baseline-schedule.blade.php)
 * - showToast() from baseline-schedule-utils.js
 * - carry-over modal DOM
 */

/**
 * Build role-aware carry-over endpoint for a specific activity
 */
function buildCarryOverEndpoint(activityId) {
    const projectId = window.projectId;
    const routePrefix = window.routePrefix;
    if (routePrefix === 'admin.projects') {
        return `/admin/projects/${projectId}/activities/${activityId}/carry-over`;
    } else if (routePrefix === 'staff.projects') {
        return `/staff/projects/${projectId}/activities/${activityId}/carry-over`;
    }
    return `/contractor/projects/${projectId}/activities/${activityId}/carry-over`;
}

/**
 * The list of activities currently shown in the modal
 */
let _carryOverActivities = [];

/**
 * When true, closing the modal will trigger a page reload.
 * Set by openCarryOverModalFromSave() when invoked after progress recording.
 */
let _carryOverAutoReload = false;

/**
 * Open the Carry Over Activity Modal after progress recording.
 * Sets the auto-reload flag so that closing (skip) will reload the page
 * to reflect the newly saved progress.
 *
 * @param {Array} activities - Array of incomplete {id, activity_name, description, month_label}
 * @param {string} fromMonthLabel - The month the activities currently belong to
 */
function openCarryOverModalFromSave(activities, fromMonthLabel) {
    _carryOverAutoReload = true;
    openCarryOverModal(activities, fromMonthLabel);
}

/**
 * Open the Carry Over Activity Modal
 * Shows a list of activities and lets the user pick a target month.
 * Can be called:
 *   1) After progress recording (post-save prompt) — use openCarryOverModalFromSave()
 *   2) Manually from "↗ Carry Over" button on a past month card
 *
 * @param {Array} activities - Array of {id, activity_name, description, month_label} for incomplete activities
 * @param {string} fromMonthLabel - The month the activities currently belong to
 */
function openCarryOverModal(activities, fromMonthLabel) {
    if (!activities || activities.length === 0) {
        showToast('No incomplete activities to carry over.', 'info');
        return;
    }

    _carryOverActivities = activities.map(a => ({ ...a, fromMonthLabel }));

    // Render activity list with checkboxes
    renderCarryOverActivities(_carryOverActivities);

    // Populate target month dropdown
    populateTargetMonthDropdown(fromMonthLabel);

    // Clear errors
    clearCarryOverErrors();

    // Reset button and select-all state
    const btn = document.getElementById('carryOverSubmitBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-arrow-right-circle" style="margin-right: 6px;"></i> Carry Over';
    document.getElementById('carryOverSelectAll').checked = true;

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('carryOverActivityModal'));
    modal.show();
}

/**
 * Render the list of activities with checkboxes for multi-select
 */
function renderCarryOverActivities(activities) {
    const container = document.getElementById('carryOverActivitiesContainer');
    if (activities.length === 0) {
        container.innerHTML = '<div style="text-align: center; padding: 20px; color: #9ca3af; font-size: 12px;">No activities selected.</div>';
        return;
    }

    let html = '';
    activities.forEach((activity, index) => {
        const safeName = escapeHtml(activity.activity_name);
        const safeDesc = activity.description ? escapeHtml(activity.description) : '';
        const safeMonth = escapeHtml(activity.month_label);
        html += `
            <div style="display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: ${index < activities.length - 1 ? '1px solid #e5e7eb' : 'none'};">
                <input type="checkbox" class="carry-over-activity-checkbox" value="${activity.id}"
                       data-name="${safeName}" data-month="${safeMonth}"
                       checked
                       onchange="updateCarryOverSelectAllState()"
                       style="width: 18px; height: 18px; cursor: pointer; flex-shrink: 0;">
                <div style="flex: 1;">
                    <div style="font-weight: 600; color: #111827; font-size: 13px;">${safeName}</div>
                    ${safeDesc ? '<div style="color: #6b7280; font-size: 12px; margin-top: 4px;">' + safeDesc + '</div>' : ''}
                </div>
                <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap;">${safeMonth}</span>
            </div>
        `;
    });
    container.innerHTML = html;
}

/**
 * Toggle all activity checkboxes on/off
 */
function toggleAllCarryOverActivities(checked) {
    document.querySelectorAll('.carry-over-activity-checkbox').forEach(cb => {
        cb.checked = checked;
    });
}

/**
 * Update the "Select All" checkbox state based on individual selections
 */
function updateCarryOverSelectAllState() {
    const allCheckboxes = document.querySelectorAll('.carry-over-activity-checkbox');
    const checkedCount = document.querySelectorAll('.carry-over-activity-checkbox:checked').length;
    const selectAll = document.getElementById('carryOverSelectAll');
    selectAll.checked = checkedCount === allCheckboxes.length;
}

/**
 * Populate the target month dropdown with future months from the DOM
 * @param {string} fromMonthLabel - The source month to filter against
 */
function populateTargetMonthDropdown(fromMonthLabel) {
    const select = document.getElementById('carryOverTargetMonth');
    select.innerHTML = '<option value="">— Select target month —</option>';

    const cards = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    const futureOptions = [];

    cards.forEach((card) => {
        const monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
        if (!monthBadge) return;
        const cardMonth = monthBadge.textContent.trim();

        // Only include months after the source month
        if (cardMonth > fromMonthLabel) {
            const monthDisplay = card.querySelector('span[style*="color: #6b7280"]');
            const dateLabel = monthDisplay ? monthDisplay.textContent.trim() : '';
            futureOptions.push({ value: cardMonth, label: `${cardMonth} ${dateLabel}` });
        }
    });

    futureOptions.sort((a, b) => a.value.localeCompare(b.value));

    futureOptions.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt.value;
        option.textContent = opt.label;
        select.appendChild(option);
    });

    // Auto-select the first (next chronological) future month if available
    if (futureOptions.length > 0) {
        select.value = futureOptions[0].value;
    }
}

/**
 * Get the baseline schedule ID for a given month label from the DOM
 * The card structure embeds the month_label in the activities collapse ID pattern
 */
function findBaselineScheduleIdByMonthLabel(monthLabel) {
    const cards = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    for (const card of cards) {
        const monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
        if (monthBadge && monthBadge.textContent.trim() === monthLabel) {
            const collapseDiv = card.querySelector('[id^="activities-"]');
            if (collapseDiv) {
                return collapseDiv.id.replace('activities-', '');
            }
        }
    }
    return null;
}

/**
 * Submit carry-over for all selected (checked) activities in batch
 */
function submitCarryOver() {
    const targetMonth = document.getElementById('carryOverTargetMonth').value;
    if (!targetMonth) {
        showCarryOverErrors(['Please select a target month.']);
        return;
    }

    const targetScheduleId = findBaselineScheduleIdByMonthLabel(targetMonth);
    if (!targetScheduleId) {
        showCarryOverErrors(['Target baseline schedule not found. Please refresh the page.']);
        return;
    }

    // Collect selected activities
    const selectedIds = [];
    document.querySelectorAll('.carry-over-activity-checkbox:checked').forEach(cb => {
        selectedIds.push(parseInt(cb.value));
    });

    if (selectedIds.length === 0) {
        showCarryOverErrors(['Please select at least one activity to carry over.']);
        return;
    }

    const btn = document.getElementById('carryOverSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Carrying over...';

    clearCarryOverErrors();

    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;

    // Process each selected activity sequentially
    let completed = 0;
    const total = selectedIds.length;
    let hadError = false;

    function processNext(index) {
        if (index >= total) {
            // All done
            const successMsg = hadError
                ? `Carried over ${completed}/${total} activities.`
                : `Successfully carried over ${total} activities.`;
            showToast(successMsg, hadError ? 'warning' : 'success', 3000);
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Done';
            _carryOverAutoReload = false;
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('carryOverActivityModal'));
                if (modal) modal.hide();
                sessionStorage.setItem('activeTab', 's-curve-planning');
                location.reload();
            }, 1500);
            return;
        }

        const activityId = selectedIds[index];
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Carrying over (${index + 1}/${total})...`;

        fetch(buildCarryOverEndpoint(activityId), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': token,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                to_baseline_schedule_id: parseInt(targetScheduleId)
            })
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    let parsed;
                    try { parsed = JSON.parse(text); } catch (e) { parsed = { message: text }; }
                    throw new Error(parsed.message || `HTTP ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                completed++;
            } else {
                hadError = true;
                console.error('[submitCarryOver] Error for activity', activityId, ':', data.message);
            }
            processNext(index + 1);
        })
        .catch(error => {
            hadError = true;
            console.error('[submitCarryOver] Error for activity', activityId, ':', error.message);
            processNext(index + 1);
        });
    }

    processNext(0);
}

/**
 * Close the carry-over modal
 * If _carryOverAutoReload is true (post-save flow), reload the page on close
 */
function closeCarryOverModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('carryOverActivityModal'));
    if (modal) modal.hide();
    
    if (_carryOverAutoReload) {
        _carryOverAutoReload = false;
        sessionStorage.setItem('activeTab', 's-curve-planning');
        setTimeout(() => location.reload(), 500);
    }
}

/**
 * Reset the auto-reload flag (call after manual carry-over is complete)
 */
function resetCarryOverAutoReload() {
    _carryOverAutoReload = false;
}

/**
 * Show inline errors in the carry-over modal
 */
function showCarryOverErrors(errors) {
    const container = document.getElementById('carryOverErrorContainer');
    const errorList = document.getElementById('carryOverErrorList');
    errorList.innerHTML = '';
    errors.forEach(error => {
        const li = document.createElement('li');
        li.textContent = error;
        errorList.appendChild(li);
    });
    container.style.display = 'block';
}

/**
 * Clear inline errors from the carry-over modal
 */
function clearCarryOverErrors() {
    const container = document.getElementById('carryOverErrorContainer');
    const errorList = document.getElementById('carryOverErrorList');
    errorList.innerHTML = '';
    container.style.display = 'none';
}

/**
 * Manual trigger: open carry-over modal for a specific month card
 * Finds incomplete activities from the DOM for that month and opens the modal
 * @param {string} monthLabel - The month label (e.g., "2026-01")
 */
function openCarryOverForMonth(monthLabel) {
    // Find the card's collapsible section to get activities
    const cards = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    let activities = [];

    for (const card of cards) {
        const monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
        if (monthBadge && monthBadge.textContent.trim() === monthLabel) {
            const collapseDiv = card.querySelector('[id^="activities-"]');
            if (collapseDiv) {
                const checkboxes = collapseDiv.querySelectorAll('input[type="checkbox"].activity-checkbox');
                checkboxes.forEach(cb => {
                    if (!cb.checked) {
                        const activityDiv = cb.closest('div[style*="display: flex; align-items: flex-start"]');
                        const nameLabel = activityDiv ? activityDiv.querySelector('label div[style*="font-weight: 600"]') : null;
                        const descEl = activityDiv ? activityDiv.querySelector('div[style*="color: #6b7280"]') : null;
                        activities.push({
                            id: parseInt(cb.dataset.activityId),
                            activity_name: nameLabel ? nameLabel.textContent.trim() : 'Unknown Activity',
                            description: descEl ? descEl.textContent.trim() : '',
                            month_label: monthLabel
                        });
                    }
                });
            }
            break;
        }
    }

    if (activities.length === 0) {
        showToast('No incomplete activities found for ' + monthLabel, 'info');
        return;
    }

    openCarryOverModal(activities, monthLabel);
}

/**
 * Simple HTML escaping helper
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}
