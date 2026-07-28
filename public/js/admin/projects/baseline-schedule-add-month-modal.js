/**
 * Add Month Target Modal - Baseline Schedule Feature
 * Handles form validation, activity management, and month target creation
 * 
 * Dependencies:
 * - window.projectId (set by baseline-schedule.blade.php)
 * - window.routePrefix (set by baseline-schedule.blade.php)
 * - window.csrfToken (set by baseline-schedule.blade.php)
 * - buildBaselineScheduleEndpoint() from baseline-schedule-utils.js
 * - formatDateForInput() from baseline-schedule-utils.js
 * - showMonthTargetErrors() from baseline-schedule-utils.js
 * - clearMonthTargetErrors() from baseline-schedule-utils.js
 * - showToast() from baseline-schedule-utils.js
 */

/**
 * Open the Add Month Target Modal
 * Initializes form with smart defaults based on project timeline and existing targets
 */

// NEW: tracks whether the modal is currently in "edit" mode and, if so, which schedule row
let editingScheduleId = null;

function openAddMonthTargetModal() {
    console.log('[openAddMonthTargetModal] Opening modal');
    clearMonthTargetErrors();
    // NEW: make sure a stale edit-mode state from a previous Edit click doesn't leak in
    editingScheduleId = null;
    // NEW: reset modal title/icon/button label back to "add" mode
    const titleText = document.getElementById('monthTargetModalTitleText');
    if (titleText) titleText.textContent = 'Add Monthly Progress Target';
    const titleIcon = document.getElementById('monthTargetModalIcon');
    if (titleIcon) titleIcon.className = 'bi bi-calendar-plus';
    const saveBtnText = document.getElementById('monthTargetSaveBtnText');
    if (saveBtnText) saveBtnText.textContent = 'Save Target';
    
    document.getElementById('monthTargetForm').reset();
    const monthInput = document.getElementById('monthLabel');
    monthInput.disabled = false;
    const dateInput = document.getElementById('plannedDate');
    
    // Clear activities container
    document.getElementById('activitiesContainer').innerHTML = 
        '<div style="text-align: center; color: #9ca3af; font-size: 12px; padding: 20px;">Click "Add Row" to add activities for this month</div>';
    
    // Find the latest month from existing targets
    let latestMonthLabel = null;
    const cardElements = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    const hasExistingTargets = cardElements.length > 0;
    
    if (hasExistingTargets) {
        cardElements.forEach(card => {
            const monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
            if (monthBadge) {
                const monthText = monthBadge.textContent.trim();
                const monthMatch = monthText.match(/\d{4}-\d{2}/);
                if (monthMatch) {
                    const currentMonth = monthMatch[0];
                    if (!latestMonthLabel || currentMonth > latestMonthLabel) {
                        latestMonthLabel = currentMonth;
                    }
                }
            }
        });
    }
    
    // Determine the month and date for the new target
    let nextMonth;
    let nextDate;
    
    if (!hasExistingTargets || !latestMonthLabel) {
        // First month target - use project's start date
        const projectStartDate = document.getElementById('s-curve-planning').getAttribute('data-project-start-date');
        
        if (projectStartDate && projectStartDate.trim() !== '') {
            // Parse the project start date
            const startDate = new Date(projectStartDate + 'T00:00:00');
            const year = startDate.getFullYear();
            const month = String(startDate.getMonth() + 1).padStart(2, '0');
            
            nextMonth = `${year}-${month}`;
            
            // Set date to the last day of the start month
            const lastDay = new Date(year, parseInt(month), 0);
            nextDate = formatDateForInput(lastDay);
        } else {
            // Fallback: use current month if no project start date
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            nextMonth = `${year}-${month}`;
            
            const lastDay = new Date(year, parseInt(month), 0);
            nextDate = formatDateForInput(lastDay);
        }
    } else {
        // Subsequent months - calculate next month from latest
        const [year, month] = latestMonthLabel.split('-');
        const monthNum = parseInt(month);
        const yearNum = parseInt(year);
        
        // Calculate next month
        if (monthNum === 12) {
            nextMonth = `${yearNum + 1}-01`;
        } else {
            nextMonth = `${yearNum}-${String(monthNum + 1).padStart(2, '0')}`;
        }
        
        // Set date to last day of next month
        const [nextYear, nextMonthStr] = nextMonth.split('-');
        const lastDay = new Date(nextYear, parseInt(nextMonthStr), 0);
        nextDate = formatDateForInput(lastDay);
    }
    
    // Set month and date inputs
    monthInput.value = nextMonth;
    dateInput.value = nextDate;
    
    // Calculate and set default progress value (highest planned target from existing cards)
    let highestProgress = 0;
    if (hasExistingTargets) {
        cardElements.forEach(card => {
            const plannedProgressSpan = card.querySelector('span[style*="color: #667eea"]');
            if (plannedProgressSpan) {
                const percentText = plannedProgressSpan.textContent.match(/[\d.]+/);
                if (percentText) {
                    highestProgress = Math.max(highestProgress, parseFloat(percentText[0]));
                }
            }
        });
    }
    
    // Set default value to highest progress, or 50 if no existing targets
    const defaultValue = highestProgress > 0 ? highestProgress : 50;
    document.getElementById('plannedProgressPercent').value = defaultValue;
    
    // Add event listeners for auto-sync
    setupMonthDateSync();
    
    const modal = new bootstrap.Modal(document.getElementById('addMonthTargetModal'));
    modal.show();
    console.log('[openAddMonthTargetModal] Modal opened with next month:', nextMonth);
}

/**
 * NEW: Open the same Add Month Target modal, but pre-filled in edit mode.
 * Reuses the existing modal (matches the codebase pattern already used for
 * the Request Extension modal's edit mode).
 */
function openEditMonthTargetModal(scheduleId, plannedDate, plannedProgressPercent, description) {
    console.log('[openEditMonthTargetModal] Editing schedule', scheduleId);
    clearMonthTargetErrors();
    document.getElementById('monthTargetForm').reset();

    editingScheduleId = scheduleId;

    // NEW: swap modal title/icon/button label to reflect edit mode
    const titleText = document.getElementById('monthTargetModalTitleText');
    if (titleText) titleText.textContent = 'Edit Monthly Progress Target';
    const titleIcon = document.getElementById('monthTargetModalIcon');
    if (titleIcon) titleIcon.className = 'bi bi-pencil-square';
    const saveBtnText = document.getElementById('monthTargetSaveBtnText');
    if (saveBtnText) saveBtnText.textContent = 'Update Target';

    // Prefill with the row's current values
    document.getElementById('plannedDate').value = plannedDate;
    document.getElementById('plannedProgressPercent').value = plannedProgressPercent;
    const descInput = document.getElementById('description');
    if (descInput) descInput.value = description || '';

    // Month label is locked in edit mode — the row's month never changes
    const monthInput = document.getElementById('monthLabel');
    if (monthInput) {
        monthInput.value = plannedDate.substring(0, 7);
        monthInput.disabled = true;
    }

    // Activities aren't editable from this modal — managed separately via "Manage"
    document.getElementById('activitiesContainer').innerHTML =
        '<div style="text-align: center; color: #9ca3af; font-size: 12px; padding: 20px;">Activities are managed separately via the "Manage" button.</div>';

    const modal = new bootstrap.Modal(document.getElementById('addMonthTargetModal'));
    modal.show();
}

/**
 * Setup auto-sync between month and date fields
 * When user selects a month, the date auto-populates with the last day of that month
 * When user selects a date, the month auto-populates with that date's month/year
 */
function setupMonthDateSync() {
    const monthInput = document.getElementById('monthLabel');
    const dateInput = document.getElementById('plannedDate');
    
    // Flag to prevent circular updates
    let isUpdatingFromMonth = false;
    let isUpdatingFromDate = false;
    
    // When month changes, auto-populate date with last day of that month
    monthInput.addEventListener('change', function() {
        if (isUpdatingFromDate) {
            isUpdatingFromDate = false;
            return;
        }
        
        if (!this.value) return;
        
        isUpdatingFromMonth = true;
        
        // Parse YYYY-MM format from month input
        const [year, month] = this.value.split('-');
        const monthNum = parseInt(month);
        
        // Get last day of the month
        const lastDay = new Date(year, monthNum, 0);
        const lastDayFormatted = formatDateForInput(lastDay);
        
        // Set the date to last day of month
        dateInput.value = lastDayFormatted;
        
        isUpdatingFromMonth = false;
    });
    
    // When date changes, auto-populate month with that date's month/year
    dateInput.addEventListener('change', function() {
        if (isUpdatingFromMonth) {
            isUpdatingFromMonth = false;
            return;
        }
        
        if (!this.value) return;
        
        isUpdatingFromDate = true;
        
        // Parse YYYY-MM-DD format from date input
        const dateParts = this.value.split('-');
        const year = dateParts[0];
        const month = dateParts[1];
        
        // Set the month to the date's month/year
        monthInput.value = `${year}-${month}`;
        
        isUpdatingFromDate = false;
    });
}

/**
 * Add a new activity row to the modal form
 */
function addActivityRow() {
    const container = document.getElementById('activitiesContainer');
    
    // Remove placeholder if exists
    if (container.children.length === 1 && container.children[0].textContent.includes('Click "Add Row"')) {
        container.innerHTML = '';
    }
    
    const rowId = 'activity-row-' + Date.now();
    const rowHTML = `
        <div id="${rowId}" style="display: grid; grid-template-columns: 1fr 1fr 50px; gap: 8px; padding: 12px; background: white; border-radius: 8px; border: 1px solid #bae6fd; align-items: start; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <div>
                <input type="text" class="activity-name" placeholder="Activity (e.g., Safety inspection)" 
                       style="width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; font-weight: 500;">
            </div>
            <div>
                <input type="text" class="activity-desc" placeholder="Description (optional)" 
                       style="width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px;">
            </div>
            <button type="button" onclick="removeActivityRow('${rowId}')" 
                    style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 8px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; height: 36px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;"
                    onmouseover="this.style.background='#fecaca';"
                    onmouseout="this.style.background='#fee2e2';">
                <i class="bi bi-trash" style="font-size: 14px;"></i>
            </button>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', rowHTML);
    console.log('[addActivityRow] Row added with ID:', rowId);
}

/**
 * Remove an activity row from the modal form
 */
function removeActivityRow(rowId) {
    const row = document.getElementById(rowId);
    if (row) {
        row.remove();
        
        // Show placeholder if no activities
        const container = document.getElementById('activitiesContainer');
        if (container.children.length === 0) {
            container.innerHTML = '<div style="text-align: center; color: #7dd3fc; font-size: 12px; padding: 16px; background: white; border-radius: 8px; border: 1px dashed #bae6fd;">Click "Add Row" to add activities for this month</div>';
        }
        console.log('[removeActivityRow] Row removed:', rowId);
    }
}

/**
 * Save the month target with validation
 * Validates all fields, checks progress constraints, and submits to backend
 */
function saveMonthTarget() {
    // NEW: route to the edit flow if a row is currently being edited
    if (editingScheduleId) {
        saveMonthTargetEdit();
        return;
    }
    console.log('[saveMonthTarget] Saving month target');
    clearMonthTargetErrors();
    
    const form = document.getElementById('monthTargetForm');
    const formData = new FormData(form);
    const errors = [];
    
    // Validate Month field
    const monthLabel = formData.get('month_label')?.trim();
    if (!monthLabel) {
        errors.push('Month is required');
    }
    
    // Validate Target Date field
    const plannedDate = formData.get('planned_date')?.trim();
    if (!plannedDate) {
        errors.push('Target Date is required');
    }
    
    // Validate Progress % field
    const progressInput = formData.get('planned_progress_percent')?.trim();
    if (!progressInput) {
        errors.push('Target Progress % is required');
    }
    
    const newProgressPercent = parseFloat(progressInput) || 0;
    if (isNaN(newProgressPercent) || newProgressPercent < 0 || newProgressPercent > 100) {
        errors.push('Target Progress % must be a number between 0 and 100');
    }
    
    // Get max progress % from existing cards in the container
    const cardElements = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    let maxProgressPercent = 0;
    cardElements.forEach(card => {
        const progressSpans = card.querySelectorAll('span[style*="color: #667eea"]');
        if (progressSpans.length > 0) {
            const percentText = progressSpans[0].textContent.match(/[\d.]+/);
            if (percentText) {
                maxProgressPercent = Math.max(maxProgressPercent, parseFloat(percentText[0]));
            }
        }
    });

    // NEW: extension-aware validation — mirrors BaselineScheduleController::storeBaselineSchedule()
    // Once the project has an approved extension, the original 100%-cap no longer blocks new rows.
    // (Server re-validates strictly-increasing against extension-only rows.)
    if (window.projectExtensionCount > 0 && maxProgressPercent >= 100) {
        // Extension mode: skip the cap block entirely, fall through with no error pushed here
    } else if (maxProgressPercent < 100 && newProgressPercent <= maxProgressPercent) {
        errors.push(`Target progress % must be higher than the previous month (${maxProgressPercent}%). You entered ${newProgressPercent}%`);
    } else if (maxProgressPercent === 100) {
        errors.push(`Previous month has already reached 100%. No additional targets can be added without an approved extension.`);
    }
    
    // Rule A: target date must fall within the selected calendar month
    if (monthLabel && plannedDate) {
        var dateMonth = plannedDate.substring(0, 7);
        if (dateMonth !== monthLabel) {
            errors.push('Target date must fall within ' + formatMonthLabel(monthLabel) + '.');
        }
    }
    
    // Collect existing month labels for Rules B and C
    var existingMonthLabels = [];
    cardElements.forEach(function (card) {
        var monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
        if (monthBadge) {
            var match = monthBadge.textContent.match(/\d{4}-\d{2}/);
            if (match) existingMonthLabels.push(match[0]);
        }
    });
    if (existingMonthLabels.length > 0) {
        existingMonthLabels.sort();
        var latestMonth = existingMonthLabels[existingMonthLabels.length - 1];
        var earliestMonth = existingMonthLabels[0];
        
        // Reject duplicate month
        if (monthLabel) {
            var isDuplicate = existingMonthLabels.some(function (m) { return m === monthLabel; });
            if (isDuplicate) {
                errors.push('"' + formatMonthLabel(monthLabel) + '" has already been added to this plan.');
            }
        }
        
        // Rule B: new target must be chronologically after all existing targets
        if (monthLabel && monthLabel < latestMonth) {
            errors.push('"' + formatMonthLabel(monthLabel) + '" must be chronologically after all previously added months (latest: "' + formatMonthLabel(latestMonth) + '"). Add months in chronological order.');
        }
        
        // Rule C: new target must not be before the project's first baseline target
        if (monthLabel && monthLabel < earliestMonth) {
            errors.push('"' + formatMonthLabel(monthLabel) + '" cannot be earlier than the project\'s first baseline target (' + formatMonthLabel(earliestMonth) + ').');
        }
    }
    
    // Show errors if any exist
    if (errors.length > 0) {
        console.error('[saveMonthTarget] Validation errors:', errors);
        showMonthTargetErrors(errors);
        return;
    }
    
    // Collect activities from rows
    const activities = [];
    const activityRows = document.querySelectorAll('#activitiesContainer > div');
    
    activityRows.forEach(row => {
        const nameInput = row.querySelector('.activity-name');
        const descInput = row.querySelector('.activity-desc');
        
        // Skip placeholder row
        if (nameInput && descInput) {
            const activityName = nameInput.value.trim();
            if (activityName) { // Only add if name is not empty
                activities.push({
                    activity_name: activityName,
                    description: descInput.value.trim() || ''
                });
            }
        }
    });
    
    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    
    if (!token) {
        showMonthTargetErrors(['Security error: CSRF token not found. Please refresh the page.']);
        return;
    }
    
    console.log('[saveMonthTarget] Submitting with', activities.length, 'activities');
    
    fetch(buildBaselineScheduleEndpoint(), {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': token,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            planned_date: formData.get('planned_date'),
            month_label: monthLabel,
            planned_progress_percent: newProgressPercent,
            description: formData.get('description'),
            activities: activities
        })
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                console.error('[saveMonthTarget] Response error:', text);
                throw new Error(`HTTP ${response.status}: ${text}`);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            console.log('[saveMonthTarget] Success response:', data);
            
            // Show success toast notification
            showToast('Month target and activities saved successfully!', 'success', 2500);
            
            // Store the active tab before reloading
            sessionStorage.setItem('activeTab', 's-curve-planning');
            
            // Close modal and reload after brief delay
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('addMonthTargetModal')).hide();
                location.reload();
            }, 1000);
        } else {
            console.error('[saveMonthTarget] Backend error:', data);
            showMonthTargetErrors([data.message || 'Failed to save month target. Please try again.']);
        }
    })
    .catch(error => {
        console.error('[saveMonthTarget] Fetch error:', error);
        showMonthTargetErrors(['Error saving month target: ' + error.message]);
    });
}

/**
 * NEW: Submit an edit for an existing baseline schedule row (PATCH).
 * No activities, no month-already-exists check, no 100%-cap — server enforces
 * the relevant guards (extension_count > 0, no actual progress recorded).
 */
function saveMonthTargetEdit() {
    console.log('[saveMonthTargetEdit] Updating schedule', editingScheduleId);
    clearMonthTargetErrors();

    const form = document.getElementById('monthTargetForm');
    const formData = new FormData(form);
    const errors = [];

    const plannedDate = formData.get('planned_date')?.trim();
    if (!plannedDate) errors.push('Target Date is required');

    const progressInput = formData.get('planned_progress_percent')?.trim();
    const newProgressPercent = parseFloat(progressInput) || 0;
    if (!progressInput || isNaN(newProgressPercent) || newProgressPercent < 0 || newProgressPercent > 100) {
        errors.push('Target Progress % must be a number between 0 and 100');
    }

    // Rule A (edit): edited date must still fall within the locked month
    var editMonth = document.getElementById('monthLabel').value;
    if (editMonth && plannedDate) {
        var dateMonth = plannedDate.substring(0, 7);
        if (dateMonth !== editMonth) {
            errors.push('Target date must fall within ' + formatMonthLabel(editMonth) + '.');
        }
    }

    if (errors.length > 0) {
        showMonthTargetErrors(errors);
        return;
    }

    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        showMonthTargetErrors(['Security error: CSRF token not found. Please refresh the page.']);
        return;
    }

    fetch(`${buildBaselineScheduleEndpoint()}/${editingScheduleId}`, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': token,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            planned_date: plannedDate,
            planned_progress_percent: newProgressPercent,
            description: formData.get('description') || ''
        })
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => { throw new Error(`HTTP ${response.status}: ${text}`); });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            console.log('[saveMonthTargetEdit] Success response:', data);
            showToast('Month target updated successfully!', 'success', 2500);
            sessionStorage.setItem('activeTab', 's-curve-planning');
            editingScheduleId = null;
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('addMonthTargetModal')).hide();
                location.reload();
            }, 1000);
        } else {
            console.error('[saveMonthTargetEdit] Backend error:', data);
            showMonthTargetErrors([data.message || 'Failed to update month target. Please try again.']);
        }
    })
    .catch(error => {
        console.error('[saveMonthTargetEdit] Fetch error:', error);
        showMonthTargetErrors(['Error updating month target: ' + error.message]);
    });
}
