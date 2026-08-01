/**
 * Edit Progress Modal — Baseline Schedule Feature
 * Handles editing and deleting progress records with activity sync
 *
 * Dependencies:
 *   - window.projectId (set by project_detail.blade.php)
 *   - window.userRole  (set by project_detail.blade.php)
 *   - window.routePrefix (set by baseline-schedule.blade.php)
 *   - window.csrfToken (set by project_detail.blade.php)
 *   - buildActivitiesByMonthEndpoint() from baseline-schedule-utils.js
 *   - sortActivitiesByCreationOrder() from baseline-schedule-utils.js
 *   - showToast() from toast.blade.php
 */

/**
 * Build a role-aware progress endpoint URL
 */
function buildProgressEndpoint(recordId) {
    var projectId = window.projectId;
    var role = window.userRole || 'admin';
    var base = '/' + role + '/projects/' + projectId + '/progress';
    return recordId ? base + '/' + recordId : base;
}

/**
 * Open the Edit Progress Record Modal
 * Populates form fields and loads activities for the month
 */
function openEditProgressModal(recordId, monthLabel, isLastMonth) {
    isLastMonth = isLastMonth || false;
    clearEditProgressErrors();

    if (!window._progressRecordData) {
        showEditProgressErrors(['Progress data not loaded. Please refresh the page.']);
        return;
    }

    var record = window._progressRecordData[recordId];
    if (!record) {
        showEditProgressErrors(['Record not found (ID: ' + recordId + '). It may have been deleted.']);
        return;
    }

    document.getElementById('editProgressRecordId').value = recordId;
    document.getElementById('editProgressMonthLabel').value = record.month_label || '';
    document.getElementById('editProgressBaselineScheduleId').value = record.baseline_schedule_id || '';

    // Apply date constraints before setting values
    var inspectionDateInput = document.getElementById('editProgressInspectionDate');
    if (record.month_label) {
        var parts = record.month_label.split('-');
        if (parts.length === 2) {
            var year = parseInt(parts[0]);
            var month = parseInt(parts[1]);
            var monthStart = new Date(year, month - 1, 1);
            var monthEnd = isLastMonth
                ? new Date(year, month + 1, 0)
                : new Date(year, month, 0);
            var msStr = formatDateForInput(monthStart);
            var meStr = formatDateForInput(monthEnd);
            inspectionDateInput.min = msStr;
            inspectionDateInput.max = meStr;
        }
    } else {
        inspectionDateInput.removeAttribute('min');
        inspectionDateInput.removeAttribute('max');
    }

    var monthDisplay = record.month_label ? formatMonthLabel(record.month_label) : '—';
    if (record.month_label) {
        document.getElementById('editProgressModalTitle').textContent = 'Edit Progress Record — ' + monthDisplay;
    } else {
        document.getElementById('editProgressModalTitle').textContent = 'Edit Progress Record';
    }
    document.getElementById('editProgressMonthDisplay').textContent = monthDisplay;

    var plannedPct = record.planned_progress_percent || 0;
    document.getElementById('editProgressPlannedDisplay').textContent = plannedPct + '%';

    document.getElementById('editProgressPercent').value = record.actual_progress_percent || record.progress_percentage || 0;

    var inspDate = record.inspection_date || '';
    // Format date for input
    if (inspDate && typeof inspDate === 'string') {
        var d = new Date(inspDate + 'T00:00:00');
        if (!isNaN(d.getTime())) {
            inspDate = formatDateForInput(d);
        }
    }
    inspectionDateInput.value = inspDate;

    document.getElementById('editProgressRemarks').value = record.remarks || '';

    var variance = record.variance_percent;
    if (variance !== null && variance !== undefined) {
        var vEl = document.getElementById('editProgressVarianceDisplay');
        var vColor = variance >= 0 ? '#10b981' : '#ef4444';
        vEl.textContent = (variance >= 0 ? '+' : '') + variance + '%';
        vEl.style.color = vColor;
    } else {
        // Calculate on the fly if not stored
        var actual = record.actual_progress_percent || record.progress_percentage || 0;
        var v = actual - plannedPct;
        var vEl2 = document.getElementById('editProgressVarianceDisplay');
        var vColor2 = v >= 0 ? '#10b981' : '#ef4444';
        vEl2.textContent = (v >= 0 ? '+' : '') + v.toFixed(2) + '%';
        vEl2.style.color = vColor2;
    }

    // Show audit trail if record has been edited
    var auditSection = document.getElementById('editProgressAuditSection');
    var auditInfo = document.getElementById('editProgressAuditInfo');
    var origValuesContainer = document.getElementById('editProgressOriginalValues');
    var toggleBtn = document.getElementById('toggleOriginalValuesBtn');

    if (record.edit_count > 0) {
        auditSection.style.display = 'block';
        var editedBy = record.edited_by || 'Unknown';
        var editedAt = 'Unknown';
        if (record.edited_at) {
            try {
                var d = new Date(record.edited_at);
                if (!isNaN(d.getTime())) {
                    editedAt = d.toLocaleString();
                }
            } catch(e) {}
        }
        auditInfo.innerHTML = 'Edited <strong>' + record.edit_count + '</strong> time(s) | Last by <strong>' + editedBy + '</strong> on ' + editedAt;

        // Render original values if available
        if (record.original_values) {
            var ov = record.original_values;
            // Fields to display, in order — removed: baseline_schedule_id,
            // inspection_month_date, progress_percentage, month_number
            var fieldList = [
                { key: 'month_label', label: 'Month' },
                { key: 'recorded_by', label: 'Recorded By' },
                { key: 'inspection_date', label: 'Inspection Date' },
                { key: 'actual_progress_percent', label: 'Actual Progress %' },
                { key: 'planned_progress_percent', label: 'Planned Progress %' },
                { key: 'variance_percent', label: 'Variance %' },
                { key: 'previous_progress', label: 'Previous Progress %' },
                { key: 'actual_start_date', label: 'Actual Start Date' },
                { key: 'actual_end_date', label: 'Actual End Date' },
                { key: 'status', label: 'Status' },
                { key: 'remarks', label: 'Remarks' },
                { key: 'activities', label: 'Activities' }
            ];
            var html = '<table style="width:100%; border-collapse:collapse;">';
            html += '<tr><th style="text-align:left;padding:4px 8px;font-weight:600;">Field</th><th style="text-align:left;padding:4px 8px;font-weight:600;">Original Value</th></tr>';
            for (var fi = 0; fi < fieldList.length; fi++) {
                var f = fieldList[fi];
                var val = undefined;
                if (f.key === 'activities') {
                    val = getOriginalActivityDisplay(ov, record);
                } else {
                    val = ov.hasOwnProperty(f.key) ? ov[f.key] : undefined;
                }
                if (val === undefined || val === null || val === '') continue;

                // Format month_label to "MonthName Year"
                if (f.key === 'month_label' && typeof val === 'string' && val.match(/^\d{4}-\d{2}$/)) {
                    var parts = val.split('-');
                    var months = ['January','February','March','April','May','June',
                                  'July','August','September','October','November','December'];
                    var mi = parseInt(parts[1]) - 1;
                    val = (mi >= 0 && mi <= 11) ? months[mi] + ' ' + parts[0] : val;
                }

                // Format percentage fields
                if (f.key === 'actual_progress_percent' || f.key === 'planned_progress_percent' ||
                    f.key === 'variance_percent' || f.key === 'previous_progress') {
                    val = parseFloat(val).toFixed(2) + '%';
                }

                // Show recorded_by name — check original_values first (new records),
                // then fall back to current record's appended attribute, then raw ID
                if (f.key === 'recorded_by') {
                    val = ov.recorded_by_name || record.recorded_by_name || ov.recorded_by || 'System';
                }

                html += '<tr><td style="padding:4px 8px;">' + f.label + '</td><td style="padding:4px 8px;">' + escapeHtml(String(val)) + '</td></tr>';
            }
            html += '</table>';
            origValuesContainer.innerHTML = html;
        }
        toggleBtn.style.display = 'inline';
    } else {
        auditSection.style.display = 'none';
    }

    // Load activities for the month
    if (record.month_label) {
        loadEditActivitiesForMonth(record.month_label);
    } else {
        document.getElementById('editProgressActivitiesContainer').innerHTML =
            '<div style="text-align: center; padding: 20px; color: #9ca3af; font-size: 12px;">No month linked — activities not available.</div>';
    }

    var modal = new bootstrap.Modal(document.getElementById('editProgressModal'));
    modal.show();
}

/**
 * Load activities for the edit progress modal checklist
 */
function loadEditActivitiesForMonth(monthLabel) {
    var container = document.getElementById('editProgressActivitiesContainer');
    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #9ca3af;"><div class="spinner-border spinner-border-sm" role="status"></div> Loading activities...</div>';

    var url = buildActivitiesByMonthEndpoint() + '?month_label=' + encodeURIComponent(monthLabel);

    fetch(url)
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.activities && data.activities.length > 0) {
                var sorted = sortActivitiesByCreationOrder(data.activities);
                var html = '';
                sorted.forEach(function(activity) {
                    html +=
                        '<div style="display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid #e5e7eb;">' +
                            '<input type="checkbox" value="' + activity.id + '" name="edit_activity_ids" ' +
                                'style="width: 18px; height: 18px; cursor: pointer;" ' +
                                (activity.is_completed ? 'checked' : '') + '>' +
                            '<div style="flex: 1;">' +
                                '<div style="font-weight: 600; color: #111827; font-size: 13px;">' + escapeHtml(activity.activity_name) + '</div>' +
                                (activity.description ? '<div style="color: #6b7280; font-size: 12px;">' + escapeHtml(activity.description) + '</div>' : '') +
                            '</div>' +
                            '<span style="background: ' + (activity.is_completed ? '#dcfce7' : '#fef3c7') + '; ' +
                                'color: ' + (activity.is_completed ? '#166534' : '#92400e') + '; ' +
                                'padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">' +
                                (activity.is_completed ? '✓ Done' : 'Pending') +
                            '</span>' +
                        '</div>';
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #9ca3af; font-size: 12px;">No activities for this month.</div>';
            }
        })
        .catch(function(error) {
            container.innerHTML = '<div style="color: #ef4444; font-size: 12px;">Error loading activities: ' + error.message + '</div>';
        });
}

/**
 * Save the edited progress record
 */
function saveProgressEdit() {
    clearEditProgressErrors();

    var recordId = document.getElementById('editProgressRecordId').value;
    var monthLabel = document.getElementById('editProgressMonthLabel').value;
    var actualProgress = parseFloat(document.getElementById('editProgressPercent').value);
    var inspectionDate = document.getElementById('editProgressInspectionDate').value;
    var remarks = document.getElementById('editProgressRemarks').value;

    var errors = [];

    if (!recordId) {
        errors.push('Record ID is missing');
    }
    if (!inspectionDate) {
        errors.push('Please select an inspection date');
    }
    if (isNaN(actualProgress) || actualProgress < 0 || actualProgress > 100) {
        errors.push('Actual progress must be between 0 and 100%');
    }

    // Validate actual progress >= current month's existing (other records same month)
    var record = window._progressRecordData ? window._progressRecordData[recordId] : null;
    if (record && monthLabel) {
        var maxOtherActual = 0;
        for (var key in window._progressRecordData) {
            if (window._progressRecordData.hasOwnProperty(key)) {
                var r = window._progressRecordData[key];
                if (r.id !== parseInt(recordId) && r.month_label === monthLabel) {
                    var pct = parseFloat(r.actual_progress_percent || r.progress_percentage || 0);
                    if (pct > maxOtherActual) maxOtherActual = pct;
                }
            }
        }
        // Also check records from earlier months
        for (var key2 in window._progressRecordData) {
            if (window._progressRecordData.hasOwnProperty(key2)) {
                var r2 = window._progressRecordData[key2];
                if (r2.month_label && r2.month_label < monthLabel) {
                    var pct2 = parseFloat(r2.actual_progress_percent || r2.progress_percentage || 0);
                    if (pct2 > maxOtherActual) maxOtherActual = pct2;
                }
            }
        }
        if (actualProgress < maxOtherActual) {
            errors.push('Actual progress % cannot be lower than the highest recorded progress (' + maxOtherActual + '%).');
        }
    }

    if (errors.length > 0) {
        showEditProgressErrors(errors);
        return;
    }

    var token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        showEditProgressErrors(['Security error: CSRF token not found.']);
        return;
    }

    var checkedActivities = [];
    var checkboxes = document.querySelectorAll('input[name="edit_activity_ids"]:checked');
    checkboxes.forEach(function(cb) {
        checkedActivities.push(parseInt(cb.value));
    });

    var payload = {
        progress_percentage: actualProgress,
        inspection_date: inspectionDate,
        remarks: remarks || null,
        activity_ids: checkedActivities.length > 0 ? checkedActivities : []
    };

    var endpoint = buildProgressEndpoint(recordId);

    fetch(endpoint, {
        method: 'PUT',
        headers: {
            'X-CSRF-TOKEN': token,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(function(response) {
        return response.json().then(function(data) {
            return { status: response.status, ok: response.ok, data: data };
        });
    })
    .then(function(result) {
        if (result.ok && result.data.success) {
            showToast('Progress record updated successfully!', 'success', 2500);

            try {
                var modal = bootstrap.Modal.getInstance(document.getElementById('editProgressModal'));
                if (modal) modal.hide();
            } catch (err) {}

            var activeTab = document.querySelector('.tab-btn.active');
            if (activeTab) {
                sessionStorage.setItem('activeTab', activeTab.getAttribute('data-tab'));
            }
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            var msg = result.data.message || 'Failed to update progress record.';
            showEditProgressErrors([msg]);
        }
    })
    .catch(function(error) {
        showEditProgressErrors(['Error: ' + error.message]);
    });
}

/**
 * Delete a progress record
 */
function deleteProgressRecord(recordId) {
    var record = window._progressRecordData ? window._progressRecordData[recordId] : null;
    var label = record ? (record.month_label || 'record #' + recordId) : 'record #' + recordId;

    showDeleteConfirmation(
        'Delete Progress Record',
        'Are you sure you want to delete the progress record for <strong>' + escapeHtml(label) + '</strong>? This action cannot be undone.',
        function() {
            var token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
            if (!token) {
                showToast('Security error: CSRF token not found.', 'error');
                return;
            }

            var endpoint = buildProgressEndpoint(recordId);

            fetch(endpoint, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json'
                }
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    showToast('Progress record deleted.', 'success', 2500);
                    delete window._progressRecordData[recordId];

                    var activeTab = document.querySelector('.tab-btn.active');
                    if (activeTab) {
                        sessionStorage.setItem('activeTab', activeTab.getAttribute('data-tab'));
                    }
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showToast('Error: ' + (data.message || 'Unable to delete'), 'error');
                }
            })
            .catch(function(error) {
                showToast('Error deleting record: ' + error.message, 'error');
            });
        }
    );
}

/**
 * Get activity display from original_values
 * Checks: 1) original_values.activity_names, 2) current record.activity_names,
 *         3) original_values.activity_id (fallback to IDs)
 */
function getOriginalActivityDisplay(ov, record) {
    if (ov.activity_names && Array.isArray(ov.activity_names) && ov.activity_names.length > 0) {
        return ov.activity_names.join(', ');
    }
    if (record && record.activity_names && Array.isArray(record.activity_names) && record.activity_names.length > 0) {
        return record.activity_names.join(', ');
    }
    if (ov.activity_id && Array.isArray(ov.activity_id) && ov.activity_id.length > 0) {
        return 'Activity #' + ov.activity_id.join(', #');
    }
    return '(none)';
}

/**
 * Format month_label (YYYY-MM) to "MonthName YYYY"
 */
function formatMonthLabel(label) {
    if (!label || typeof label !== 'string') return label;
    var parts = label.split('-');
    if (parts.length !== 2) return label;
    var months = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    var mIndex = parseInt(parts[1]) - 1;
    if (mIndex < 0 || mIndex > 11) return label;
    return months[mIndex] + ' ' + parts[0];
}

/**
 * Display inline error messages
 */
function showEditProgressErrors(errors) {
    var container = document.getElementById('editProgressErrorContainer');
    var errorList = document.getElementById('editProgressErrorList');
    errorList.innerHTML = '';
    errors.forEach(function(error) {
        var li = document.createElement('li');
        li.textContent = error;
        errorList.appendChild(li);
    });
    container.style.display = 'block';
}

/**
 * Clear inline error messages
 */
function clearEditProgressErrors() {
    var container = document.getElementById('editProgressErrorContainer');
    var errorList = document.getElementById('editProgressErrorList');
    errorList.innerHTML = '';
    container.style.display = 'none';
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}
