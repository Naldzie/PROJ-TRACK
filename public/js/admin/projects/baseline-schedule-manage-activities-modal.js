/**
 * Manage Activities Modal - Baseline Schedule Feature
 * Handles activity management, loading, adding, and deleting activities
 * 
 * Dependencies:
 * - window.projectId (set by baseline-schedule.blade.php)
 * - window.routePrefix (set by baseline-schedule.blade.php)
 * - window.csrfToken (set by baseline-schedule.blade.php)
 * - buildActivitiesEndpoint() from baseline-schedule-utils.js
 * - buildActivitiesByMonthEndpoint() from baseline-schedule-utils.js
 * - sortActivitiesByCreationOrder() from baseline-schedule-utils.js
 * - updateActivityCount() from baseline-schedule-utils.js
 * - showToast() from baseline-schedule-utils.js
 */

/**
 * Open the Manage Activities Modal
 * Displays list of activities for a specific month
 */
function openManageActivitiesModal(monthLabel) {
    console.log('[openManageActivitiesModal] Opening modal for month:', monthLabel);
    document.getElementById('currentMonthLabel').value = monthLabel;
    document.getElementById('activityMonthLabel').textContent = monthLabel;
    document.getElementById('activityMonthLabelInput').value = monthLabel;
    
    loadActivitiesForMonth(monthLabel);
    
    const modal = new bootstrap.Modal(document.getElementById('manageActivitiesModal'));
    modal.show();
}

/**
 * Load activities for a specific month
 * Fetches and displays activities in the modal
 */
function loadActivitiesForMonth(monthLabel) {
    console.log('[loadActivitiesForMonth] Loading activities for month:', monthLabel);
    const container = document.getElementById('activitiesList');
    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #9ca3af;"><div class="spinner-border spinner-border-sm"></div><div style="margin-top: 8px;">Loading...</div></div>';
    
    fetch(`${buildActivitiesByMonthEndpoint()}?month_label=${monthLabel}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('[loadActivitiesForMonth] Received data:', data);
            if (data.success && data.activities && data.activities.length > 0) {
                // Sort activities by ID (creation order) for consistency
                const sortedActivities = sortActivitiesByCreationOrder(data.activities);
                
                let html = '';
                sortedActivities.forEach(activity => {
                    html += `
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px; background: white; border-radius: 6px; margin-bottom: 8px; border: 1px solid #e5e7eb;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #111827;">${activity.activity_name}</div>
                                ${activity.description ? '<div style="color: #6b7280; font-size: 12px;">' + activity.description + '</div>' : ''}
                            </div>
                            <button onclick="deleteActivity(${activity.id})" style="background: #fee2e2; color: #dc2626; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    `;
                });
                container.innerHTML = html;
                console.log('[loadActivitiesForMonth] Successfully rendered', sortedActivities.length, 'activities');
            } else {
                console.log('[loadActivitiesForMonth] No activities found');
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #9ca3af;">No activities yet. Add one below!</div>';
            }
        })
        .catch(error => {
            console.error('[loadActivitiesForMonth] Error:', error);
            container.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;">Error loading activities: ' + error.message + '</div>';
        });
}

/**
 * Reload inline activities in the month card (collapsible section)
 * IMPORTANT: Preserves the action buttons by only updating activities list
 */
function reloadInlineActivities(monthLabel) {
    console.log('[reloadInlineActivities] Starting for monthLabel:', monthLabel);
    
    // Find the card more robustly by searching through all cards
    const cards = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    let targetCard = null;
    let collapseElement = null;
    
    for (const card of cards) {
        const monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
        if (monthBadge) {
            const badgeText = monthBadge.textContent.trim();
            console.log('[reloadInlineActivities] Found badge with text:', badgeText, 'comparing with:', monthLabel);
            if (badgeText === monthLabel) {
                targetCard = card;
                const collapseDiv = card.querySelector('[id^="activities-"]');
                if (collapseDiv) {
                    collapseElement = collapseDiv;
                    break;
                }
            }
        }
    }
    
    if (!collapseElement || !targetCard) {
        console.error('[reloadInlineActivities] Could not find collapse element or target card for:', monthLabel);
        return;
    }
    
    console.log('[reloadInlineActivities] Found target card and collapse element');
    
    // Extract existing button data from the card
    const recordProgressBtn = targetCard.querySelector('button[onclick*="openRecordProgressModal"]');
    const manageBtn = targetCard.querySelector('button[onclick*="openManageMonthModal"]');
    const deleteBtn = targetCard.querySelector('button[onclick*="deleteMonthTarget"]');
    
    if (!recordProgressBtn || !manageBtn || !deleteBtn) {
        console.error('[reloadInlineActivities] Could not find action buttons');
        return;
    }
    
    // Store button onclick handlers before we modify the DOM
    const recordProgressOnclick = recordProgressBtn.getAttribute('onclick');
    const manageOnclick = manageBtn.getAttribute('onclick');
    const deleteOnclick = deleteBtn.getAttribute('onclick');
    
    console.log('[reloadInlineActivities] Found all buttons, proceeding with fetch');
    
    fetch(`${buildActivitiesByMonthEndpoint()}?month_label=${monthLabel}`)
        .then(response => {
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return response.json();
        })
        .then(data => {
            if (data.success && data.activities) {
                // Sort activities by ID (creation order) for consistency
                const sortedActivities = sortActivitiesByCreationOrder(data.activities);
                let activitiesHtml = '';
                
                if (sortedActivities.length > 0) {
                    sortedActivities.forEach(activity => {
                        activitiesHtml += `
                            <div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                                <input type="checkbox" 
                                       id="activity-${activity.id}" 
                                       data-activity-id="${activity.id}" 
                                       class="activity-checkbox"
                                       onchange="toggleActivityCompletion(${activity.id}, this.checked)"
                                       ${activity.is_completed ? 'checked' : ''} 
                                       style="width: 20px; height: 20px; cursor: pointer; flex-shrink: 0; margin-top: 2px;">
                                <div style="flex: 1;">
                                    <label for="activity-${activity.id}" style="cursor: pointer; display: block;">
                                        <div style="font-weight: 600; color: #111827; font-size: 13px;">${activity.activity_name}</div>
                                        ${activity.description ? '<div style="color: #6b7280; font-size: 12px; margin-top: 4px;">' + activity.description + '</div>' : ''}
                                    </label>
                                </div>
                                <span style="background: ${activity.is_completed ? '#dcfce7' : '#fef3c7'}; color: ${activity.is_completed ? '#166534' : '#92400e'}; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; flex-shrink: 0;">
                                    ${activity.is_completed ? '✓ Done' : 'Pending'}
                                </span>
                            </div>
                        `;
                    });
                } else {
                    activitiesHtml = '<div style="padding: 20px; text-align: center; color: #9ca3af; font-size: 12px;"><i class="bi bi-inbox" style="font-size: 24px; display: block; margin-bottom: 8px; opacity: 0.5;"></i>No activities yet. Click "Manage" button to add activities.</div>';
                }
                
                // Build the complete HTML including buttons
                const buttonsHtml = `
                    <div style="display: flex; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                        <button onclick="${recordProgressOnclick}" 
                                style="flex: 1; background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;"
                                title="Record Monthly Progress"
                                onmouseover="this.style.background='#c6f6d5'; this.style.borderColor='#68e6a8';"
                                onmouseout="this.style.background='#dcfce7'; this.style.borderColor='#86efac';">
                            <i class="bi bi-graph-up-arrow"></i> Record Progress
                        </button>
                        <button onclick="${manageOnclick}"
                                style="flex: 1; background: #dbeafe; color: #0369a1; border: 1px solid #bae6fd; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;"
                                title="Manage Activities"
                                onmouseover="this.style.background='#bfdbfe'; this.style.borderColor='#7dd3fc';"
                                onmouseout="this.style.background='#dbeafe'; this.style.borderColor='#bae6fd';">
                            <i class="bi bi-pencil-square"></i> Manage
                        </button>
                        <button onclick="${deleteOnclick}" 
                                style="flex: 1; background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;"
                                title="Delete Month Target"
                                onmouseover="this.style.background='#fecaca'; this.style.borderColor='#f878a8';"
                                onmouseout="this.style.background='#fee2e2'; this.style.borderColor='#fca5a5';">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </div>
                `;
                
                collapseElement.innerHTML = activitiesHtml + buttonsHtml;
                
                // Also update the activity count in the card header
                updateActivityCount(monthLabel);
                console.log('[reloadInlineActivities] Successfully reloaded inline activities');
            }
        })
        .catch(error => console.error('[reloadInlineActivities] Error:', error));
}

/**
 * Add a new activity to a month
 * Validates form and submits to backend
 */
function addNewActivity() {
    console.log('[addNewActivity] Starting add activity process');
    const form = document.getElementById('addActivityForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = new FormData(form);
    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    const monthLabel = document.getElementById('currentMonthLabel').value;
    const activityName = formData.get('activity_name');
    
    if (!token) {
        alert('Security error: CSRF token not found. Please refresh the page.');
        return;
    }
    
    console.log('[addNewActivity] Adding activity:', activityName, 'for month:', monthLabel);
    
    // Disable submit button during request
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.6';
    }
    
    fetch(buildActivitiesEndpoint(), {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': token,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            activity_name: formData.get('activity_name'),
            description: formData.get('description'),
            month_label: monthLabel
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('[addNewActivity] Activity added successfully');
            
            form.reset();
            
            // Reload activities in the modal immediately
            loadActivitiesForMonth(monthLabel);
            
            // Also reload activities in the inline card view immediately (no delay)
            reloadInlineActivities(monthLabel);
            
            // Show success toast
            showToast(`Activity "${activityName}" added successfully!`, 'success', 2000);
        } else {
            console.error('[addNewActivity] Error response:', data);
            showToast('Error: ' + (data.message || 'Failed to add activity'), 'error', 3000);
        }
    })
    .catch(error => {
        console.error('[addNewActivity] Fetch error:', error);
        showToast('Error adding activity: ' + error.message, 'error', 3000);
    })
    .finally(() => {
        // Re-enable submit button
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        }
    });
}

/**
 * Delete an activity
 */
function deleteActivity(activityId) {
    console.log('[deleteActivity] Deleting activity:', activityId);
    if (confirm('Delete this activity?')) {
        const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
        const monthLabel = document.getElementById('currentMonthLabel').value;
        
        if (!token) {
            alert('Security error: CSRF token not found. Please refresh the page.');
            return;
        }
        
        fetch(`${buildActivitiesEndpoint()}/${activityId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('[deleteActivity] Activity deleted successfully');
                
                // Reload activities in modal
                loadActivitiesForMonth(monthLabel);
                
                // Also reload inline activities in the card
                setTimeout(() => {
                    reloadInlineActivities(monthLabel);
                }, 300);
                
                // Show success toast
                showToast('Activity deleted successfully!', 'success', 2000);
            } else {
                console.error('[deleteActivity] Delete failed:', data);
                alert('Error deleting activity');
            }
        })
        .catch(error => {
            console.error('[deleteActivity] Error:', error);
            alert('Error deleting activity');
        });
    }
}
