/**
 * Baseline Schedule Utilities
 * Shared utility functions for baseline schedule feature
 * Last Updated: April 25, 2026
 */

/**
 * Build role-aware baseline schedule endpoint
 */
function buildBaselineScheduleEndpoint() {
    const projectId = window.projectId;
    const routePrefix = window.routePrefix;
    if (routePrefix === 'admin.projects') {
        return `/admin/projects/${projectId}/baseline-schedules`;
    } else if (routePrefix === 'staff.projects') {
        return `/staff/projects/${projectId}/baseline-schedules`;
    }
    return `/contractor/projects/${projectId}/baseline-schedules`;
}

/**
 * Build role-aware activities endpoint
 */
function buildActivitiesEndpoint() {
    const projectId = window.projectId;
    const routePrefix = window.routePrefix;
    if (routePrefix === 'admin.projects') {
        return `/admin/projects/${projectId}/activities`;
    } else if (routePrefix === 'staff.projects') {
        return `/staff/projects/${projectId}/activities`;
    }
    return `/contractor/projects/${projectId}/activities`;
}

/**
 * Build role-aware activities-by-month endpoint
 */
function buildActivitiesByMonthEndpoint() {
    const projectId = window.projectId;
    const routePrefix = window.routePrefix;
    if (routePrefix === 'admin.projects') {
        return `/admin/projects/${projectId}/activities-by-month`;
    } else if (routePrefix === 'staff.projects') {
        return `/staff/projects/${projectId}/activities-by-month`;
    }
    return `/contractor/projects/${projectId}/activities-by-month`;
}

/**
 * Build role-aware record progress endpoint
 */
function buildRecordProgressEndpoint() {
    const projectId = window.projectId;
    const routePrefix = window.routePrefix;
    if (routePrefix === 'admin.projects') {
        return `/admin/projects/${projectId}/record-monthly-progress`;
    } else if (routePrefix === 'staff.projects') {
        return `/staff/projects/${projectId}/record-monthly-progress`;
    }
    return `/contractor/projects/${projectId}/record-monthly-progress`;
}

// ==================== Display/UI Utilities ====================

/**
 * Toggle activity collapse for a month card
 */
function toggleActivityCollapse(collapseId) {
    const element = document.getElementById(collapseId);
    const chevron = document.getElementById('chevron-' + collapseId);
    
    if (element.style.display === 'none') {
        element.style.display = 'block';
        element.style.animation = 'slideDown 0.3s ease-out';
        chevron.style.transform = 'rotate(180deg)';
    } else {
        element.style.display = 'none';
        chevron.style.transform = 'rotate(0deg)';
    }
}

/**
 * Format "YYYY-MM" to "MonthName YYYY" display string
 */
function formatMonthLabel(val) {
    if (!val) return '';
    var parts = val.split('-');
    if (parts.length !== 2) return val;
    var monthIdx = parseInt(parts[1], 10) - 1;
    var months = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    return months[monthIdx] + ' ' + parts[0];
}

/**
 * Format a Date object to YYYY-MM-DD string for input[type="date"]
 */
function formatDateForInput(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// ==================== Data Manipulation ====================

/**
 * Sort activities by ID (creation order) in ascending order
 */
function sortActivitiesByCreationOrder(activities) {
    return activities.sort((a, b) => {
        const aId = parseInt(a.id) || 0;
        const bId = parseInt(b.id) || 0;
        return aId - bId;
    });
}

// ==================== Notification Utilities ====================

/**
 * Show toast notification
 * @param {string} message - The message to display
 * @param {string} type - 'success', 'error', 'warning', 'info'
 * @param {number} duration - Duration in milliseconds (default: 3000)
 */
// showToast() is defined globally by resources/views/components/toast.blade.php
// which is included in project_detail.blade.php

/**
 * Queue a toast to display AFTER the page reloads. The message is written to
 * sessionStorage (same pattern as the 'activeTab' restore) and consumed by
 * consumePendingToast() on the freshly loaded page. This fixes the
 * toast-before-reload flicker across the baseline tab's add/edit/delete flows.
 *
 * @param {string} message - The message to display
 * @param {string} type - 'success', 'error', 'warning', 'info'
 * @param {number} duration - Duration in milliseconds (default: 3000)
 */
function queueToastAfterReload(message, type, duration) {
    sessionStorage.setItem('pendingToast', JSON.stringify({
        message: message,
        type: type || 'success',
        duration: duration || 3000
    }));
}

/**
 * Read and display any toast queued via queueToastAfterReload(), then clear it.
 * Called on DOMContentLoaded by the baseline tab (alongside tab restoration).
 */
function consumePendingToast() {
    var raw = sessionStorage.getItem('pendingToast');
    if (!raw) return;
    sessionStorage.removeItem('pendingToast');
    try {
        var pending = JSON.parse(raw);
        showToast(pending.message, pending.type || 'success', pending.duration || 3000);
    } catch (e) {
        console.error('[consumePendingToast] Error showing pending toast:', e);
    }
}

// ==================== Error Handling ====================

/**
 * Display inline error messages in the modal
 */
function showMonthTargetErrors(errors) {
    const container = document.getElementById('formErrorContainer');
    const errorList = document.getElementById('errorList');
    errorList.innerHTML = '';
    
    errors.forEach(error => {
        const li = document.createElement('li');
        li.textContent = error;
        errorList.appendChild(li);
    });
    
    container.style.display = 'block';
}

/**
 * Clear inline error messages from the modal
 */
function clearMonthTargetErrors() {
    const container = document.getElementById('formErrorContainer');
    const errorList = document.getElementById('errorList');
    errorList.innerHTML = '';
    container.style.display = 'none';
}

// ==================== DOM Update Utilities ====================

/**
 * Update the Actual Progress % in the card header
 */
function updateActualProgressPercent(monthLabel, actualProgressPercent) {
    console.log('[updateActualProgressPercent] Updating for month:', monthLabel, 'value:', actualProgressPercent);
    
    const cards = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    
    for (const card of cards) {
        const monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
        if (monthBadge && monthBadge.textContent.trim() === monthLabel) {
            console.log('[updateActualProgressPercent] Found matching card for month:', monthLabel);
            
            // Find the Actual progress spans - look for the green color #10b981
            const progressSection = card.querySelector('[style*="gap: 20px"]');
            if (progressSection) {
                // Find all divs that might contain the actual progress (look for the green inline-flex)
                const allDivs = progressSection.querySelectorAll('div[style*="background: rgba(16, 185, 129"]');
                if (allDivs.length > 0) {
                    // The actual progress span is inside the last matching div
                    const actualProgressDiv = allDivs[allDivs.length - 1];
                    const actualProgressSpan = actualProgressDiv.querySelector('span[style*="color: #10b981"]');
                    if (actualProgressSpan) {
                        actualProgressSpan.textContent = `${actualProgressPercent}%`;
                        console.log('[updateActualProgressPercent] Updated span to:', actualProgressSpan.textContent);
                    }
                } else {
                    // Fallback: look for any span with color #10b981
                    const spans = progressSection.querySelectorAll('span[style*="color: #10b981"]');
                    if (spans.length > 0) {
                        const lastSpan = spans[spans.length - 1];
                        lastSpan.textContent = `${actualProgressPercent}%`;
                        console.log('[updateActualProgressPercent] Updated (fallback) span to:', lastSpan.textContent);
                    }
                }
            }
            break;
        }
    }
}

/**
 * Update activity count display in the month card header
 */
function updateActivityCount(monthLabel) {
    console.log('[updateActivityCount] Updating count for monthLabel:', monthLabel);
    
    const cards = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    
    cards.forEach(card => {
        const monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
        if (monthBadge) {
            const badgeText = monthBadge.textContent.trim();
            if (badgeText === monthLabel) {
                console.log('[updateActivityCount] Found matching card for:', monthLabel);
                
                // Get all activities from the card (only count actual activity checkboxes)
                const collapseDiv = card.querySelector('[id^="activities-"]');
                if (collapseDiv) {
                    const allActivities = collapseDiv.querySelectorAll('input[type="checkbox"].activity-checkbox');
                    const completedActivities = collapseDiv.querySelectorAll('input[type="checkbox"].activity-checkbox:checked');
                    
                    console.log('[updateActivityCount] Activities found:', allActivities.length, 'Completed:', completedActivities.length);
                    
                    // Find and update the activities count badge in the header
                    // The count badge is in the right section showing "X/Y"
                    const countBadge = card.querySelector('span[style*="background: #f0f9ff"]');
                    if (countBadge && countBadge.textContent.includes('/')) {
                        countBadge.textContent = `${completedActivities.length}/${allActivities.length}`;
                        console.log('[updateActivityCount] Updated badge to:', countBadge.textContent);
                    }
                }
                return; // Found and updated, exit loop
            }
        }
    });
}

/**
 * Find the month card ID from month label
 */
function findMonthCardId(monthLabel) {
    const cards = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
    for (const card of cards) {
        const monthBadge = card.querySelector('span[style*="background: #dbeafe"]');
        if (monthBadge && monthBadge.textContent.trim().includes(monthLabel)) {
            // Extract ID from the card's collapse element
            const collapseDiv = card.querySelector('[id^="activities-"]');
            if (collapseDiv) {
                return collapseDiv.id.replace('activities-', '');
            }
        }
    }
    return null;
}

// ==================== Animation Setup ====================

/**
 * Initialize slide-down animation style
 */
document.addEventListener('DOMContentLoaded', function() {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    `;
    document.head.appendChild(style);
});
