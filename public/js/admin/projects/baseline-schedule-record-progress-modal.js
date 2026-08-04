/**
 * Record Progress Modal - Baseline Schedule Feature
 * Handles monthly progress recording with validation and activity tracking
 * 
 * Dependencies:
 * - window.projectId (set by baseline-schedule.blade.php)
 * - window.routePrefix (set by baseline-schedule.blade.php)
 * - window.csrfToken (set by baseline-schedule.blade.php)
 * - buildRecordProgressEndpoint() from baseline-schedule-utils.js
 * - buildActivitiesByMonthEndpoint() from baseline-schedule-utils.js
 * - sortActivitiesByCreationOrder() from baseline-schedule-utils.js
 * - showToast() from baseline-schedule-utils.js
 * - reloadMonthCardFromServer() from baseline-schedule.blade.php
 */

/**
 * Open the Record Monthly Progress Modal
 * Initializes form with date constraints and loads activities for the month
 */
function openRecordProgressModal(monthLabel, plannedPercent, plannedDate, latestActualProgress, highestOverallProgress, isLastMonth) {
    console.log('[openRecordProgressModal] Opening modal for month:', monthLabel);
    document.getElementById('progressMonthLabel').value = monthLabel;
    document.getElementById('progressMonthDisplay').textContent = monthLabel;
    document.getElementById('progressPlannedDisplay').textContent = plannedPercent + '%';
    
    // Helper: Format date as YYYY-MM-DD without timezone conversion
    function formatDateString(dateObj) {
        const year = dateObj.getFullYear();
        const month = String(dateObj.getMonth() + 1).padStart(2, '0');
        const day = String(dateObj.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }
    
    const planDate = new Date(plannedDate + 'T00:00:00');
    const monthStart = new Date(planDate.getFullYear(), planDate.getMonth(), 1);
    const monthEnd = isLastMonth 
        ? new Date(planDate.getFullYear(), planDate.getMonth() + 2, 0) 
        : new Date(planDate.getFullYear(), planDate.getMonth() + 1, 0);
    
    const monthStartStr = formatDateString(monthStart);
    const monthEndStr = formatDateString(monthEnd);
    
    // Determine minimum progress: if this month has records, use those; otherwise use highest overall
    const minProgress = latestActualProgress > 0 ? latestActualProgress : highestOverallProgress;
    
    // Set min constraint for progress
    const actualInput = document.getElementById('actualProgressPercent');
    actualInput.min = minProgress || '0';
    if (minProgress > 0) {
        actualInput.title = `Must be >= ${minProgress}%. Progress cannot decrease.`;
    }
    
    // Get default progress
    const defaultProgress = latestActualProgress > 0 ? latestActualProgress : highestOverallProgress;
    
    // Reset form to clear old values
    document.getElementById('recordProgressForm').reset();
    
    // Now restore values with proper constraints
    document.getElementById('recordProgressForm').querySelector('input[name="month_label"]').value = monthLabel;
    
    // CRITICAL: Set date constraints BEFORE setting value to ensure date picker respects them
    const inspectionDateInput = document.getElementById('inspectionDate');
    inspectionDateInput.min = monthStartStr;
    inspectionDateInput.max = monthEndStr;
    inspectionDateInput.value = plannedDate; // Set AFTER constraints
    
    // Restore progress value
    actualInput.value = defaultProgress || '0';
    
    // Load activities for this month
    loadActivitiesForProgressModal(monthLabel);
    
    const modal = new bootstrap.Modal(document.getElementById('recordProgressModal'));
    modal.show();
    console.log('[openRecordProgressModal] Modal opened with date constraints:', monthStartStr, 'to', monthEndStr);
}

/**
 * Load activities for the progress modal checklist
 * Fetches activities for the month and displays them as checkboxes
 */
function loadActivitiesForProgressModal(monthLabel) {
    console.log('[loadActivitiesForProgressModal] Loading activities for month:', monthLabel);
    
    const container = document.getElementById('activitiesChecklistContainer');
    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #9ca3af;"><div class="spinner-border spinner-border-sm"></div></div>';
    
    fetch(`${buildActivitiesByMonthEndpoint()}?month_label=${monthLabel}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('[loadActivitiesForProgressModal] Received data:', data);
            
            if (data.success && data.activities && data.activities.length > 0) {
                // Sort activities by ID (creation order) for consistency
                const sortedActivities = sortActivitiesByCreationOrder(data.activities);
                console.log('[loadActivitiesForProgressModal] Found', sortedActivities.length, 'activities');
                
                let html = '';
                sortedActivities.forEach(activity => {
                    html += `
                        <div style="display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid #e5e7eb;">
                            <input type="checkbox" value="${activity.id}" name="activity_ids" 
                                   style="width: 18px; height: 18px; cursor: pointer;" 
                                   ${activity.is_completed ? 'checked' : ''}>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #111827; font-size: 13px;">${activity.activity_name}</div>
                                ${activity.description ? '<div style="color: #6b7280; font-size: 12px;">' + activity.description + '</div>' : ''}
                            </div>
                            <span style="background: ${activity.is_completed ? '#dcfce7' : '#fef3c7'}; color: ${activity.is_completed ? '#166534' : '#92400e'}; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                                ${activity.is_completed ? '✓ Done' : 'Pending'}
                            </span>
                        </div>
                    `;
                });
                container.innerHTML = html;
                console.log('[loadActivitiesForProgressModal] Successfully rendered activities');
            } else {
                console.log('[loadActivitiesForProgressModal] No activities found for month:', monthLabel);
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #9ca3af; font-size: 12px;">No activities for this month. <a href="javascript:void(0)" onclick="openManageMonthModal(null, \'' + monthLabel + '\', \'\', 0, \'\', \'activities-only\')">Add activities</a></div>';
            }
        })
        .catch(error => {
            console.error('[loadActivitiesForProgressModal] Error:', error);
            container.innerHTML = '<div style="color: #ef4444; font-size: 12px;">Error loading activities: ' + error.message + '</div>';
        });
}

/**
 * Save monthly progress with comprehensive validation
 * Validates form, checks progress constraints, and submits to backend
 */
function saveMonthlyProgress() {
    console.log('[saveMonthlyProgress] Starting progress save');
    clearRecordProgressErrors();
    
    const form = document.getElementById('recordProgressForm');
    const monthLabel = document.getElementById('progressMonthLabel').value;
    const actualProgress = parseFloat(document.getElementById('actualProgressPercent').value);
    const inspectionDate = document.getElementById('inspectionDate').value;
    const remarks = document.getElementById('progressRemarks').value;
    const errors = [];
    
    const checkedActivities = Array.from(document.querySelectorAll('input[name="activity_ids"]:checked')).map(cb => parseInt(cb.value));
    
    // Validation
    if (!monthLabel) {
        errors.push('Month label is missing');
    }
    if (!inspectionDate) {
        errors.push('Please select an inspection date');
    }
    if (isNaN(actualProgress) || actualProgress < 0 || actualProgress > 100) {
        errors.push('Actual progress must be a number between 0 and 100%');
    }
    
    // Validate against all months' actual progress (including current month)
    const cardElements = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    let maxActualProgress = 0;
    let foundCurrentMonth = false;
    
    cardElements.forEach((card) => {
        const monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
        const rowMonth = monthBadge ? monthBadge.textContent.trim().match(/\d{4}-\d{2}/)?.[0] : null;
        
        // Get actual progress - find the SECOND span with color: #10b981 (actual progress, not planned)
        // Structure: Planned has color #667eea, Actual has color #10b981
        const actualProgressSpan = card.querySelector('span[style*="color: #10b981"]');
        let actualProgressValue = 0;
        if (actualProgressSpan) {
            const percentText = actualProgressSpan.textContent.match(/[\d.]+/);
            actualProgressValue = percentText ? parseFloat(percentText[0]) : 0;
        }
        
        if (rowMonth === monthLabel) {
            // Found current month - include it in comparison
            foundCurrentMonth = true;
            maxActualProgress = Math.max(maxActualProgress, actualProgressValue);
        } else if (!foundCurrentMonth && rowMonth) {
            // Previous months - include in comparison
            maxActualProgress = Math.max(maxActualProgress, actualProgressValue);
        }
    });
    
    // Validate that new actual progress is not lower than any recorded progress
    if (actualProgress < maxActualProgress) {
        errors.push(`Actual progress % cannot be lower than the highest recorded progress (${maxActualProgress}%). You entered: ${actualProgress}%`);
    }
    
    // Show errors if any exist
    if (errors.length > 0) {
        console.error('[saveMonthlyProgress] Validation errors:', errors);
        showRecordProgressErrors(errors);
        return;
    }
    
    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    
    if (!token) {
        showRecordProgressErrors(['Security error: CSRF token not found. Please refresh the page.']);
        return;
    }
    
    console.log('[saveMonthlyProgress] Validation passed, sending request for month:', monthLabel);
    
    fetch(buildRecordProgressEndpoint(), {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': token,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            month_label: monthLabel,
            inspection_date: inspectionDate,
            actual_progress_percent: actualProgress,
            activity_ids: checkedActivities.length > 0 ? checkedActivities : [],
            remarks: remarks
        })
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                console.error('[saveMonthlyProgress] Response error:', text);
                throw new Error(`HTTP ${response.status}: ${text}`);
            });
        }
        return response.json();
    })
    .then(data => {
        if (!data.success) {
            console.error('[saveMonthlyProgress] API returned error:', data.message);
            showRecordProgressErrors([data.message || 'Failed to save progress. Please try again.']);
            return;
        }
        console.log('[saveMonthlyProgress] Success! Progress saved for month:', monthLabel);

        // Phase D: upload any pending inspection evidence files via the existing
        // /attachments/upload endpoint (context 'inspection'). The record already
        // exists — a failed upload is surfaced but must NOT block the reload
        // (the user can re-add the file afterwards).
        var recordId = data.progress_record && data.progress_record.id;
        var pendingFiles = (typeof collectPendingFiles === 'function')
            ? collectPendingFiles('progressAttachmentFiles')
            : [];
        var uploadPromise = (recordId && pendingFiles.length > 0 && typeof uploadProgressFiles === 'function')
            ? uploadProgressFiles(recordId, pendingFiles)
                .catch(function(err) {
                    console.error('[saveMonthlyProgress] Evidence upload failed (record already saved):', err);
                    showToast('Progress saved, but one or more attachments failed to upload.', 'error', 4000);
                })
            : Promise.resolve();

        return uploadPromise.then(function() {
            if (typeof clearPendingFiles === 'function') {
                clearPendingFiles('progressAttachmentFiles');
            }

            // Show success toast notification
            showToast('Monthly progress recorded successfully!', 'success', 2500);
            
            // Close modal first
            try {
                const modal = bootstrap.Modal.getInstance(document.getElementById('recordProgressModal'));
                if (modal) {
                    modal.hide();
                }
            } catch (err) {
                console.error('[saveMonthlyProgress] Error closing modal:', err);
            }
            
            // Check for incomplete activities that could be carried over
            if (data.incomplete_activities && data.incomplete_activities.length > 0) {
                console.log('[saveMonthlyProgress] Found', data.incomplete_activities.length, 'incomplete activities for carry-over');
                
                // Defer page reload; show carry-over modal after a short delay
                setTimeout(() => {
                    openCarryOverModalFromSave(data.incomplete_activities, monthLabel);
                }, 2000);
            } else {
                // No carry-over needed: proceed with page reload
                console.log('[saveMonthlyProgress] No incomplete activities — reloading page');
                setTimeout(() => {
                    sessionStorage.setItem('activeTab', 's-curve-planning');
                    location.reload();
                }, 2000);
            }
        });
    })
    .catch(error => {
        console.error('[saveMonthlyProgress] Fetch error:', error);
        showRecordProgressErrors(['Error saving progress: ' + error.message]);
    });
}

/**
 * Display inline error messages in the Record Progress modal
 */
function showRecordProgressErrors(errors) {
    console.log('[showRecordProgressErrors] Displaying', errors.length, 'errors');
    const container = document.getElementById('recordProgressErrorContainer');
    const errorList = document.getElementById('recordProgressErrorList');
    errorList.innerHTML = '';
    
    errors.forEach(error => {
        const li = document.createElement('li');
        li.textContent = error;
        errorList.appendChild(li);
    });
    
    container.style.display = 'block';
}

/**
 * Clear inline error messages from the Record Progress modal
 */
function clearRecordProgressErrors() {
    const container = document.getElementById('recordProgressErrorContainer');
    const errorList = document.getElementById('recordProgressErrorList');
    errorList.innerHTML = '';
    container.style.display = 'none';
}
