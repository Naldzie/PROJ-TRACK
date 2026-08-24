/**
 * Manage Month Modal — Merged Edit Target + Manage Activities
 *
 * Handles two modes based on project state:
 *   "edit" mode (extension_count > 0 && no progress):
 *       Target fields editable, "Save Changes" button visible.
 *       PATCH endpoint on save.
 *
 *   "activities-only" mode (no extension || has progress):
 *       Target fields locked/read-only, "Close" button only.
 *       Activities section always interactive.
 *
 * Dependencies:
 *   - buildActivitiesByMonthEndpoint(), buildBaselineScheduleEndpoint()
 *     from baseline-schedule-utils.js
 *   - csrfToken (window.csrfToken or meta tag)
 *   - formatMonthLabel() from baseline-schedule-utils.js
 *   - reloadInlineActivities() — self-contained (moved here from old modal)
 */

function openManageMonthModal(scheduleId, monthLabel, plannedDate, plannedProgressPercent, description, mode, prevTarget, nextTarget) {
    console.log('[openManageMonthModal] Opening for month:', monthLabel, 'mode:', mode);

    document.getElementById('manageMonthScheduleId').value = scheduleId || '';
    document.getElementById('manageMonthMode').value = mode || 'activities-only';
    document.getElementById('manageMonthRawLabel').value = monthLabel || '';
    document.getElementById('manageMonthLabel').textContent = formatMonthLabel(monthLabel);
    document.getElementById('manageMonthMonthDisplay').value = formatMonthLabel(monthLabel);

    // Ordering-lock neighbors (nullable). Used for the inline range hint and
    // the client-side check in saveManageMonthTarget(); the server stays
    // authoritative.
    prevTarget = (prevTarget === undefined || prevTarget === null) ? null : parseFloat(prevTarget);
    nextTarget = (nextTarget === undefined || nextTarget === null) ? null : parseFloat(nextTarget);
    document.getElementById('manageMonthPrevTarget').value = (prevTarget === null) ? '' : prevTarget;
    document.getElementById('manageMonthNextTarget').value = (nextTarget === null) ? '' : nextTarget;
    var hintEl = document.getElementById('manageMonthRangeHint');
    if (prevTarget !== null && nextTarget !== null) {
        hintEl.textContent = 'Allowed range: ' + prevTarget + '% < target < ' + nextTarget + '%';
    } else if (prevTarget !== null) {
        hintEl.textContent = 'Allowed range: target > ' + prevTarget + '%';
    } else if (nextTarget !== null) {
        hintEl.textContent = 'Allowed range: target < ' + nextTarget + '%';
    } else {
        hintEl.textContent = '';
    }
    showManageMonthTargetError('');

    var isEditMode = (mode === 'edit');

    var dateInput = document.getElementById('manageMonthPlannedDate');
    var percentInput = document.getElementById('manageMonthProgressPercent');
    var descInput = document.getElementById('manageMonthDescription');
    var saveBtn = document.getElementById('manageMonthSaveBtn');
    var closeBtn = document.getElementById('manageMonthCloseBtn');

    if (isEditMode) {
        dateInput.value = plannedDate;
        dateInput.disabled = false;
        percentInput.value = plannedProgressPercent;
        percentInput.disabled = false;
        descInput.value = description || '';
        descInput.disabled = false;
        saveBtn.style.display = 'inline-block';
        closeBtn.textContent = 'Cancel';
        closeBtn.onclick = function () {
            bootstrap.Modal.getInstance(document.getElementById('manageMonthModal')).hide();
        };
    } else {
        dateInput.value = plannedDate;
        dateInput.disabled = true;
        percentInput.value = plannedProgressPercent;
        percentInput.disabled = true;
        descInput.value = description || '';
        descInput.disabled = true;
        saveBtn.style.display = 'none';
        closeBtn.textContent = 'Close';
        closeBtn.onclick = function () {
            bootstrap.Modal.getInstance(document.getElementById('manageMonthModal')).hide();
        };
    }

    // Load existing activities
    loadManageMonthActivities(monthLabel);

    // Store current month label for activity operations
    document.getElementById('manageMonthNewActivityName').value = '';
    document.getElementById('manageMonthNewActivityDesc').value = '';

    var modal = new bootstrap.Modal(document.getElementById('manageMonthModal'));
    modal.show();
}

/**
 * Fetch and render existing activities for the given month.
 */
function loadManageMonthActivities(monthLabel) {
    var container = document.getElementById('manageMonthActivitiesList');
    container.innerHTML = '<div style="text-align: center; padding: 16px; color: #9ca3af; font-size: 12px;"><i class="bi bi-arrow-repeat" style="font-size: 20px; display: block; margin-bottom: 6px;"></i>Loading activities...</div>';

    fetch(buildActivitiesByMonthEndpoint() + '?month_label=' + encodeURIComponent(monthLabel))
        .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function (data) {
            if (data.success && data.activities && data.activities.length > 0) {
                var sorted = data.activities.sort(function (a, b) { return (a.id || 0) - (b.id || 0); });
                var html = '';
                sorted.forEach(function (activity) {
                    html +=
                        '<div style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: white; border-radius: 6px; border: 1px solid #e5e7eb; margin-bottom: 6px;">' +
                            '<span style="flex: 1; font-weight: 500; font-size: 13px; color: #111827;">' + escapeHtml(activity.activity_name) + '</span>' +
                            (activity.description ? '<span style="color: #6b7280; font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + escapeHtml(activity.description) + '</span>' : '') +
                            '<span style="background: ' + (activity.is_completed ? '#dcfce7' : '#fef3c7') + '; color: ' + (activity.is_completed ? '#166534' : '#92400e') + '; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap;">' +
                                (activity.is_completed ? '✓ Done' : 'Pending') +
                            '</span>' +
                            '<button type="button" onclick="deleteManageMonthActivity(' + activity.id + ', ' + (activity.is_completed ? 'true' : 'false') + ')" ' +
                                    'style="background: none; border: none; color: #dc2626; cursor: pointer; padding: 4px; font-size: 16px; line-height: 1;" ' +
                                    'title="Delete Activity">' +
                                '<i class="bi bi-trash"></i>' +
                            '</button>' +
                        '</div>';
                });
                container.innerHTML = html;
            } else {
                container.innerHTML =
                    '<div style="text-align: center; padding: 16px; color: #9ca3af; font-size: 12px;">' +
                        '<i class="bi bi-inbox" style="font-size: 20px; display: block; margin-bottom: 6px; opacity: 0.5;"></i>' +
                        'No activities yet. Add one below.' +
                    '</div>';
            }
        })
        .catch(function (error) {
            console.error('[loadManageMonthActivities] Error:', error);
            container.innerHTML = '<div style="text-align: center; padding: 16px; color: #dc2626; font-size: 12px;">Error loading activities.</div>';
        });
}

/**
 * Add a new activity to the current month.
 */
function addManageMonthActivity() {
    var monthLabel = document.getElementById('manageMonthRawLabel').value;
    var nameInput = document.getElementById('manageMonthNewActivityName');
    var descInput = document.getElementById('manageMonthNewActivityDesc');
    var name = nameInput.value.trim();
    var desc = descInput.value.trim();

    if (!name) {
        showToast('Please enter an activity name.', 'warning');
        nameInput.focus();
        return;
    }

    var token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        alert('Security error: CSRF token not found. Please refresh the page.');
        return;
    }

    fetch(buildActivitiesEndpoint(), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            month_label: monthLabel,
            activity_name: name,
            description: desc
        })
    })
    .then(function (response) {
        if (!response.ok) {
            return response.json().then(function (errData) {
                throw new Error(errData.message || 'HTTP ' + response.status);
            }).catch(function () {
                throw new Error('HTTP ' + response.status);
            });
        }
        return response.json();
    })
    .then(function (data) {
        if (data.success) {
            nameInput.value = '';
            descInput.value = '';
            nameInput.focus();
            showToast('Activity added successfully!', 'success');
            loadManageMonthActivities(monthLabel);
            // Also update the card's inline activities
            if (typeof reloadInlineActivities === 'function') {
                reloadInlineActivities(monthLabel);
            }
        } else {
            alert(data.message || 'Failed to add activity.');
        }
    })
    .catch(function (error) {
        console.error('[addManageMonthActivity] Error:', error);
        alert('Error adding activity: ' + error.message);
    });
}

/**
 * Delete an activity by ID using the reusable delete confirmation modal.
 * Completed activities cannot be deleted.
 */
function deleteManageMonthActivity(activityId, isCompleted) {
    if (isCompleted) {
        showToast('Cannot delete a completed activity.', 'warning');
        return;
    }
    showDeleteConfirmation(
        'Delete Activity',
        'Are you sure you want to delete this activity?',
        function () {
            var monthLabel = document.getElementById('manageMonthRawLabel').value;
            var token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
            if (!token) {
                alert('Security error: CSRF token not found.');
                return;
            }

            fetch(buildActivitiesEndpoint() + '/' + activityId, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }
            })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    showToast('Activity deleted successfully!', 'success');
                    loadManageMonthActivities(monthLabel);
                    if (typeof reloadInlineActivities === 'function') {
                        reloadInlineActivities(monthLabel);
                    }
                } else {
                    alert(data.message || 'Failed to delete activity.');
                }
            })
            .catch(function (error) {
                console.error('[deleteManageMonthActivity] Error:', error);
                alert('Error deleting activity: ' + error.message);
            });
        }
    );
}

/**
 * Show/hide the inline red error text next to the Target % input.
 */
function showManageMonthTargetError(message) {
    var errEl = document.getElementById('manageMonthTargetError');
    if (!errEl) return;
    errEl.textContent = message || '';
    errEl.style.display = message ? 'block' : 'none';
}

/**
 * Save target changes (edit mode only).
 */
function saveManageMonthTarget() {
    var scheduleId = document.getElementById('manageMonthScheduleId').value;
    if (!scheduleId) return;

    var plannedDate = document.getElementById('manageMonthPlannedDate').value;
    var progressPercent = document.getElementById('manageMonthProgressPercent').value;
    var description = document.getElementById('manageMonthDescription').value;
    var errors = [];

    if (!plannedDate) errors.push('Target Date is required.');
    if (!progressPercent || isNaN(parseFloat(progressPercent)) || parseFloat(progressPercent) < 0 || parseFloat(progressPercent) > 100) {
        errors.push('Target % must be a number between 0 and 100.');
    }

    // Ordering lock: value must stay strictly between its chronological
    // neighbors (mirrors the server-side rule in updateBaselineSchedule).
    var numPercent = parseFloat(progressPercent);
    var prevTargetVal = document.getElementById('manageMonthPrevTarget').value;
    var nextTargetVal = document.getElementById('manageMonthNextTarget').value;
    if (!isNaN(numPercent)) {
        if (prevTargetVal !== '' && numPercent <= parseFloat(prevTargetVal)) {
            errors.push('Target % must be higher than the previous month (' + prevTargetVal + '%).');
        }
        if (nextTargetVal !== '' && numPercent >= parseFloat(nextTargetVal)) {
            errors.push('Target % must be lower than the next month (' + nextTargetVal + '%).');
        }
    }

    if (errors.length > 0) {
        showManageMonthTargetError(errors.join(' '));
        return;
    }

    var token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        alert('Security error: CSRF token not found.');
        return;
    }

    fetch(buildBaselineScheduleEndpoint() + '/' + scheduleId, {
        method: 'PATCH',
        headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            planned_date: plannedDate,
            planned_progress_percent: parseFloat(progressPercent),
            description: description || ''
        })
    })
    .then(function (response) {
        if (!response.ok) return response.text().then(function (text) { throw new Error('HTTP ' + response.status + ': ' + text); });
        return response.json();
    })
    .then(function (data) {
        if (data.success) {
            queueToastAfterReload('Month target updated successfully!', 'success');
            sessionStorage.setItem('activeTab', 's-curve-planning');
            bootstrap.Modal.getInstance(document.getElementById('manageMonthModal')).hide();
            setTimeout(function () { location.reload(); }, 600);
        } else {
            showManageMonthTargetError(data.message || 'Failed to save changes.');
        }
    })
    .catch(function (error) {
        console.error('[saveManageMonthTarget] Error:', error);
        showManageMonthTargetError('Error saving changes: ' + error.message);
    });
}

/**
 * Simple HTML escape helper.
 */
function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

/**
 * Reload the inline activities display on the timeline detail panel.
 * Called after adding/deleting an activity so the panel reflects the change.
 */
function reloadInlineActivities(monthLabel) {
    console.log('[reloadInlineActivities] Starting for monthLabel:', monthLabel);
    
    var collapseElement = document.getElementById('activities-' + monthLabel);
    if (!collapseElement) {
        console.error('[reloadInlineActivities] Could not find detail panel for:', monthLabel);
        return;
    }
    
    // Extract current onclick attributes from action buttons
    var recordProgressBtn = collapseElement.querySelector('button[onclick*="openRecordProgressModal"]');
    var manageBtn = collapseElement.querySelector('button[onclick*="openManageMonthModal"]');
    var deleteBtn = collapseElement.querySelector('button[onclick*="deleteMonthTarget"]');
    
    var recordProgressOnclick = recordProgressBtn ? recordProgressBtn.getAttribute('onclick') : '';
    var manageOnclick = manageBtn ? manageBtn.getAttribute('onclick') : '';
    var deleteOnclick = deleteBtn ? deleteBtn.getAttribute('onclick') : '';
    
    fetch(buildActivitiesByMonthEndpoint() + '?month_label=' + monthLabel)
        .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function (data) {
            if (data.success && data.activities) {
                var sorted = sortActivitiesByCreationOrder(data.activities);
                var activitiesHtml = '';
                
                if (sorted.length > 0) {
                    sorted.forEach(function (activity) {
                        activitiesHtml +=
                            '<div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">' +
                                '<input type="checkbox" id="activity-' + activity.id + '" data-activity-id="' + activity.id + '" class="activity-checkbox" onchange="toggleActivityCompletion(' + activity.id + ', this.checked)" ' + (activity.is_completed ? 'checked' : '') + ' style="width: 20px; height: 20px; cursor: pointer; flex-shrink: 0; margin-top: 2px;">' +
                                '<div style="flex: 1;">' +
                                    '<label for="activity-' + activity.id + '" style="cursor: pointer; display: block;">' +
                                        '<div style="font-weight: 600; color: #111827; font-size: 13px;">' + escapeHtml(activity.activity_name) + '</div>' +
                                        (activity.description ? '<div style="color: #6b7280; font-size: 12px; margin-top: 4px;">' + escapeHtml(activity.description) + '</div>' : '') +
                                    '</label>' +
                                '</div>' +
                                '<span style="background: ' + (activity.is_completed ? '#dcfce7' : '#fef3c7') + '; color: ' + (activity.is_completed ? '#166534' : '#92400e') + '; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; flex-shrink: 0;">' +
                                    (activity.is_completed ? '\u2713 Done' : 'Pending') +
                                '</span>' +
                            '</div>';
                    });
                } else {
                    activitiesHtml = '<div style="padding: 20px; text-align: center; color: #9ca3af; font-size: 12px;"><i class="bi bi-inbox" style="font-size: 24px; display: block; margin-bottom: 8px; opacity: 0.5;"></i>No activities yet. Click "Manage Month" to add activities.</div>';
                }
                
                // Update the activities grid
                var grid = collapseElement.querySelector('.activities-grid');
                if (grid) {
                    grid.innerHTML = activitiesHtml;
                }

                // Rebuild action buttons
                var buttonsHtml = '<div class="timeline-actions-row">';
                if (recordProgressOnclick) {
                    buttonsHtml += '<button onclick="' + recordProgressOnclick + '" style="flex: 1; background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;" title="Record Monthly Progress"><i class="bi bi-graph-up-arrow"></i> Record Progress</button>';
                }
                if (manageOnclick) {
                    buttonsHtml += '<button onclick="' + manageOnclick + '" style="flex: 1; background: #dbeafe; color: #0369a1; border: 1px solid #bae6fd; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;" title="Manage Month Target and Activities"><i class="bi bi-pencil-square"></i> Manage Month</button>';
                }
                if (deleteOnclick) {
                    buttonsHtml += '<button onclick="' + deleteOnclick + '" style="flex: 1; background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;" title="Delete Month Target"><i class="bi bi-trash"></i> Delete</button>';
                }
                buttonsHtml += '</div>';

                // Update the actions row
                var actionsRow = collapseElement.querySelector('.timeline-actions-row');
                if (actionsRow) {
                    actionsRow.outerHTML = buttonsHtml;
                }

                updateActivityCount(monthLabel);
                console.log('[reloadInlineActivities] Successfully reloaded inline activities');
            }
        })
        .catch(function (error) {
            console.error('[reloadInlineActivities] Error:', error);
        });
}
