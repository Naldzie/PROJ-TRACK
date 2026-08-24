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
 * Toggle timeline detail panel — only one open at a time.
 * Clicking the same dot closes the panel; clicking a different dot
 * closes the current one first, then opens the new one.
 */
let _activeTimelineDot = null;
let _activeTimelinePanel = null;

function toggleTimelineDetail(collapseId, dotElement) {
    var panel = document.getElementById(collapseId);
    if (!panel) return;

    var container = document.getElementById('timeline-detail-panel');

    // Clicking the same dot — close it
    if (_activeTimelineDot === dotElement && _activeTimelinePanel === panel) {
        panel.style.display = 'none';
        _removeArrow(panel);
        _movePanelBack(panel);
        dotElement.classList.remove('active');
        _activeTimelineDot = null;
        _activeTimelinePanel = null;
        return;
    }

    // Close any currently open panel
    if (_activeTimelineDot) {
        _activeTimelineDot.classList.remove('active');
    }
    if (_activeTimelinePanel) {
        _activeTimelinePanel.style.display = 'none';
        _removeArrow(_activeTimelinePanel);
        _movePanelBack(_activeTimelinePanel);
        _activeTimelinePanel = null;
    }

    // Open the new panel
    dotElement.classList.add('active');
    _activeTimelineDot = dotElement;
    _activeTimelinePanel = panel;
    panel.style.display = 'block';
    container.appendChild(panel);
    panel.style.animation = 'slideDown 0.3s ease-out';

    // Create and position the speech-bubble arrow
    _insertArrow(panel, dotElement);
}

function _insertArrow(panel, dot) {
    _removeArrow(panel);
    var arrow = document.createElement('div');
    arrow.className = 'timeline-detail-arrow';
    panel.style.position = 'relative';
    panel.insertBefore(arrow, panel.firstChild);

    // Position the arrow at the dot's horizontal center
    requestAnimationFrame(function() {
        var dotRect = dot.getBoundingClientRect();
        var panelRect = panel.getBoundingClientRect();
        if (panelRect.width > 0) {
            var x = dotRect.left + dotRect.width / 2 - panelRect.left;
            x = Math.max(14, Math.min(x, panelRect.width - 14));
            arrow.style.left = x + 'px';
            arrow.style.transform = 'translateX(-50%)';
        }
    });
}

function _removeArrow(panel) {
    var arrow = panel.querySelector('.timeline-detail-arrow');
    if (arrow) arrow.remove();
}

function _movePanelBack(panel) {
    var placeholder = document.getElementById('timeline-detail-panel');
    if (placeholder && placeholder.parentNode) {
        placeholder.parentNode.insertBefore(panel, placeholder);
    }
}

/**
 * Legacy alias — kept for backward compatibility with any code
 * that still calls toggleActivityCollapse().
 */
function toggleActivityCollapse(collapseId) {
    toggleTimelineDetail(collapseId, null);
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
 * Display inline error messages in the modal.
 * Accepts either:
 *   - { field: 'monthLabel'|'plannedDate'|'plannedProgressPercent', message } →
 *     rendered as red text under that input, or
 *   - a plain string → rendered in the top error container.
 */
function showMonthTargetErrors(errors) {
    const fieldMap = {
        monthLabel: 'monthLabelError',
        plannedDate: 'plannedDateError',
        plannedProgressPercent: 'plannedProgressPercentError'
    };
    const generalErrors = [];

    errors.forEach(item => {
        if (item && typeof item === 'object' && item.field && fieldMap[item.field]) {
            const el = document.getElementById(fieldMap[item.field]);
            if (el) {
                el.textContent = item.message || '';
                el.style.display = item.message ? 'block' : 'none';
            }
        } else {
            generalErrors.push(typeof item === 'string' ? item : (item && item.message ? item.message : ''));
        }
    });

    const container = document.getElementById('formErrorContainer');
    const errorList = document.getElementById('errorList');
    errorList.innerHTML = '';

    generalErrors.forEach(error => {
        const li = document.createElement('li');
        li.textContent = error;
        errorList.appendChild(li);
    });

    container.style.display = generalErrors.length > 0 ? 'block' : 'none';
}

/**
 * Clear inline error messages from the modal (top container + field errors).
 */
function clearMonthTargetErrors() {
    const container = document.getElementById('formErrorContainer');
    const errorList = document.getElementById('errorList');
    errorList.innerHTML = '';
    container.style.display = 'none';

    ['monthLabelError', 'plannedDateError', 'plannedProgressPercentError'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = '';
            el.style.display = 'none';
        }
    });
}

// ==================== DOM Update Utilities ====================

/**
 * Update the Actual Progress % in the timeline tooltip and detail panel.
 */
function updateActualProgressPercent(monthLabel, actualProgressPercent) {
    console.log('[updateActualProgressPercent] Updating for month:', monthLabel, 'value:', actualProgressPercent);

    // 1. Update the hover tooltip on the timeline dot
    const dot = document.querySelector('.timeline-dot[data-month-label="' + monthLabel + '"]');
    if (dot) {
        const tooltip = dot.querySelector('.timeline-tooltip');
        if (tooltip) {
            const sections = tooltip.querySelectorAll('.tooltip-section');
            sections.forEach(function (section) {
                const label = section.querySelector('.tooltip-label');
                if (label && label.textContent.trim().toLowerCase() === 'actual') {
                    const val = section.querySelector('.tooltip-value');
                    if (val) val.textContent = actualProgressPercent + '%';
                }
            });
        }
        // Update always-visible info for current month
        const alwaysActual = dot.querySelector('.always-actual');
        if (alwaysActual) alwaysActual.textContent = 'Actual: ' + actualProgressPercent + '%';
    }

    // 2. Update the open detail panel if it belongs to this month
    var panel = document.getElementById('activities-' + monthLabel);
    if (!panel) {
        // Fallback: scan by data-month-label in the detail panel
        var panels = document.querySelectorAll('.timeline-detail-panel');
        panels.forEach(function (p) {
            var badge = p.querySelector('span[data-month-label]');
            if (badge && badge.dataset.monthLabel === monthLabel) {
                panel = p;
            }
        });
    }
    if (panel && panel.style.display !== 'none') {
        var actualSpans = panel.querySelectorAll('span[style*="color:#10b981"], span[style*="color: #10b981"]');
        if (actualSpans.length > 0) {
            var lastSpan = actualSpans[actualSpans.length - 1];
            lastSpan.textContent = actualProgressPercent + '%';
            console.log('[updateActualProgressPercent] Updated detail panel span');
        }
    }
}

/**
 * Update activity count display in the detail panel header.
 */
function updateActivityCount(monthLabel) {
    console.log('[updateActivityCount] Updating count for monthLabel:', monthLabel);

    // Find the active detail panel for this month
    var panel = document.getElementById('activities-' + monthLabel);
    if (!panel) return;

    var allActivities = panel.querySelectorAll('input[type="checkbox"].activity-checkbox');
    var completedActivities = panel.querySelectorAll('input[type="checkbox"].activity-checkbox:checked');

    console.log('[updateActivityCount] Activities found:', allActivities.length, 'Completed:', completedActivities.length);

    // Update count badge in the detail panel header
    var countBadge = panel.querySelector('.timeline-detail-header span[style*="background:#f0f9ff"], .timeline-detail-header span[style*="background: #f0f9ff"]');
    if (countBadge && countBadge.textContent.includes('/')) {
        countBadge.textContent = completedActivities.length + '/' + allActivities.length;
        console.log('[updateActivityCount] Updated badge to:', countBadge.textContent);
    }
}

/**
 * Find the month card/plan ID from month label using the timeline dot.
 */
function findMonthCardId(monthLabel) {
    var dot = document.querySelector('.timeline-dot[data-month-label="' + monthLabel + '"]');
    if (dot && dot.dataset.planId) {
        return dot.dataset.planId;
    }
    // Fallback: check detail panels
    var panel = document.getElementById('activities-' + monthLabel);
    if (panel) {
        return monthLabel;
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
