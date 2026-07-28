<!-- RECORD MONTHLY PROGRESS MODAL (Activity Checklist Model) -->
<div id="recordMonthlyProgressModal" class="modal fade" tabindex="-1" style="display: none;">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 12px; border: 1px solid #e5e7eb;">
            <!-- Header -->
            <div class="modal-header" style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <div>
                    <h5 class="modal-title" style="margin: 0; font-weight: 700; font-size: 16px;">
                        <i class="bi bi-clipboard-check" style="margin-right: 8px;"></i>Record Monthly Progress(shared)
                    </h5>
                    <p style="margin: 4px 0 0 0; font-size: 12px; opacity: 0.9;">Activity Checklist & Actual Performance</p>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity: 0.7;"></button>
            </div>

            <!-- Body -->
            <div class="modal-body" style="padding: 24px;">
                <form id="recordProgressForm" method="POST" style="position: relative;">
                    @csrf

                    <input type="hidden" id="projectId" name="project_id" value="{{ $project->id }}">

                    <!-- Month Selection -->
                    <div style="margin-bottom: 24px;">
                        <label for="inspectionMonthDate" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: #111827;">
                            <i class="bi bi-calendar2-event" style="color: #667eea; margin-right: 4px;"></i>Inspection Month <span style="color: #ef4444;">*</span>
                        </label>
                        <div style="display: flex; gap: 12px; align-items: flex-end;">
                            <div style="flex: 1;">
                                <select id="inspectionMonthDate" name="month_label" class="form-control" style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;" required>
                                    <option value="">-- Select Month --</option>
                                    <!-- Will be populated via JavaScript from S_CURVE_PLAN -->
                                </select>
                            </div>
                            <small style="color: #6b7280; white-space: nowrap;">Select from planned schedule</small>
                        </div>
                        <input type="hidden" id="actualInspectionDate" name="inspection_month_date" value="">
                        <small id="monthError" style="display: none; color: #ef4444; margin-top: 4px;"></small>
                    </div>

                    <!-- Inspection Date Picker -->
                    <div style="margin-bottom: 24px;">
                        <label for="inspectionDatePicker" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: #111827;">
                            <i class="bi bi-calendar-event" style="color: #667eea; margin-right: 4px;"></i>Inspection Date <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="date" id="inspectionDatePicker" name="inspection_date_picker" class="form-control" required
                               style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;">
                        <small style="color: #6b7280; margin-top: 4px; display: block;">Select the date of your inspection (auto-populated to the last day of the selected month)</small>
                    </div>

                    <!-- Activity Checklist -->
                    <div style="margin-bottom: 24px;">
                        <label style="display: block; margin-bottom: 12px; font-weight: 600; font-size: 13px; color: #111827;">
                            <i class="bi bi-list-check" style="color: #667eea; margin-right: 4px;"></i>Activity Completion Status <span style="color: #ef4444;">*</span>
                        </label>
                        
                        <!-- Activities Loading Spinner -->
                        <div id="activitiesLoader" style="display: none; text-align: center; padding: 20px;">
                            <div class="spinner-border spinner-border-sm" style="color: #667eea;"></div>
                            <small style="display: block; color: #6b7280; margin-top: 8px;">Loading activities...</small>
                        </div>

                        <!-- Activities Checklist Container -->
                        <div id="activitiesContainer" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; min-height: 100px;">
                            <div style="text-align: center; padding: 20px; color: #9ca3af;">
                                <i class="bi bi-info-circle" style="font-size: 20px; display: block; margin-bottom: 8px;"></i>
                                <small>Select a month above to load activities</small>
                            </div>
                        </div>
                        <small id="activitiesError" style="display: none; color: #ef4444; margin-top: 4px;"></small>
                    </div>

                    <!-- Actual Progress Percentage -->
                    <div style="margin-bottom: 24px;">
                        <label for="actualProgressPercent" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: #111827;">
                            <i class="bi bi-percent" style="color: #667eea; margin-right: 4px;"></i>Actual Progress <span style="color: #ef4444;">*</span>
                        </label>
                        <div style="display: flex; gap: 12px; align-items: center;">
                            <div style="flex: 1;">
                                <input type="range" id="actualProgressPercent" name="actual_progress_percent" min="0" max="100" 
                                       class="form-range" style="height: 6px;" required>
                            </div>
                            <div style="width: 60px;">
                                <input type="number" id="actualProgressNumber" min="0" max="100" placeholder="0%" 
                                       style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center; font-weight: 600; font-size: 14px;" required>
                            </div>
                        </div>
                        <div style="margin-top: 8px; padding: 10px 12px; background: #f0f9ff; border-radius: 6px; border-left: 3px solid #0ea5e9;">
                            <small style="color: #0369a1;">
                                <strong>Planned Target:</strong> <span id="plannedProgressTarget">--</span>% 
                                <span id="varianceIndicator" style="margin-left: 12px; display: none;">
                                    <strong>Variance:</strong> <span id="varianceValue">0</span>% 
                                    <span id="varianceStatus" style="padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block;"></span>
                                </span>
                            </small>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div style="margin-bottom: 24px;">
                        <label for="recordRemarks" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: #111827;">
                            <i class="bi bi-chat-left-text" style="color: #667eea; margin-right: 4px;"></i>Inspection Remarks
                        </label>
                        <textarea id="recordRemarks" name="remarks" class="form-control" rows="3" 
                                  placeholder="e.g., Weather delays, resource constraints, completion notes..."
                                  style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; resize: vertical;"></textarea>
                        <small style="color: #6b7280; margin-top: 4px; display: block;">Optional. Add context about this month's progress.</small>
                    </div>

                    <!-- Summary Preview -->
                    <div style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%); 
                                border: 1px solid #ddd6fe; border-radius: 8px; padding: 12px 15px; margin-bottom: 20px;">
                        <small style="display: block; font-weight: 600; color: #667eea; margin-bottom: 6px;">
                            <i class="bi bi-lightning"></i> Summary
                        </small>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; font-size: 12px;">
                            <div>
                                <span style="color: #6b7280;">Completed Activities</span>
                                <div style="font-weight: 700; font-size: 16px; color: #111827; margin-top: 2px;">
                                    <span id="completedCount">0</span>/<span id="totalCount">0</span>
                                </div>
                            </div>
                            <div>
                                <span style="color: #6b7280;">Planned Progress</span>
                                <div style="font-weight: 700; font-size: 16px; color: #667eea; margin-top: 2px;">
                                    <span id="plannedSummary">--</span>%
                                </div>
                            </div>
                            <div>
                                <span style="color: #6b7280;">Recorded By</span>
                                <div style="font-weight: 700; font-size: 13px; color: #111827; margin-top: 2px;">
                                    {{ auth()->user()->first_name ?? 'Admin' }}
                                </div>
                            </div>
                        </div>
                    </div>

                </form>
            </div>

            <!-- Footer -->
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #fafafa;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius: 6px;">
                    Cancel
                </button>
                <button type="button" id="submitRecordProgressBtn" class="btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-check-circle-fill" style="margin-right: 6px;"></i>Save Monthly Progress
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('recordMonthlyProgressModal');
    const projectId = document.getElementById('projectId').value;
    const monthSelect = document.getElementById('inspectionMonthDate');
    const actualProgressRange = document.getElementById('actualProgressPercent');
    const actualProgressNumber = document.getElementById('actualProgressNumber');
    const activitiesContainer = document.getElementById('activitiesContainer');
    const activitiesLoader = document.getElementById('activitiesLoader');
    const submitBtn = document.getElementById('submitRecordProgressBtn');
    const form = document.getElementById('recordProgressForm');
    const inspectionDatePicker = document.getElementById('inspectionDatePicker');

    // Sync range and number inputs
    actualProgressRange.addEventListener('input', function() {
        actualProgressNumber.value = this.value;
        updateVarianceDisplay();
    });

    actualProgressNumber.addEventListener('input', function() {
        if (this.value >= 0 && this.value <= 100) {
            actualProgressRange.value = this.value;
            updateVarianceDisplay();
        }
    });

    // Month selection changed - auto-populate inspection date
    monthSelect.addEventListener('change', function() {
        const monthLabel = this.value;
        if (!monthLabel) {
            activitiesContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: #9ca3af;"><i class="bi bi-info-circle" style="font-size: 20px; display: block; margin-bottom: 8px;"></i><small>Select a month above to load activities</small></div>';
            document.getElementById('actualInspectionDate').value = '';
            inspectionDatePicker.value = '';
            inspectionDatePicker.min = '';
            inspectionDatePicker.max = '';
            return;
        }

        // Extract year and month from monthLabel (format: YYYY-MM)
        const [year, month] = monthLabel.split('-');
        const monthNum = parseInt(month);
        
        const firstDay = new Date(year, monthNum - 1, 1);
        const options = monthSelect.options;
        const isLastMonth = options[options.length - 1].value === monthLabel;
        const lastDay = isLastMonth
            ? new Date(year, monthNum + 1, 0)
            : new Date(year, monthNum, 0);
        
        const firstDayStr = formatDateForInput(firstDay);
        const lastDayStr = formatDateForInput(lastDay);
        
        // Set constraints on the date picker
        inspectionDatePicker.min = firstDayStr;
        inspectionDatePicker.max = lastDayStr;
        
        // Auto-populate with last day of the month
        inspectionDatePicker.value = lastDayStr;
        
        // Update hidden field
        document.getElementById('actualInspectionDate').value = lastDayStr;

        loadActivitiesForMonth(monthLabel);
        updateVarianceDisplay();
    });

    // Inspection date picker changed - update hidden field and validate it's in the month
    inspectionDatePicker.addEventListener('change', function() {
        if (!this.value) return;
        
        // Update hidden field
        document.getElementById('actualInspectionDate').value = this.value;
        
        // Verify the date is within the allowed range (browser should enforce this, but let's be safe)
        const selectedDate = new Date(this.value + 'T00:00:00');
        const minDate = new Date(this.min + 'T00:00:00');
        const maxDate = new Date(this.max + 'T00:00:00');
        
        if (selectedDate < minDate || selectedDate > maxDate) {
            console.warn('Date outside allowed range, resetting to valid date');
            this.value = this.max; // Reset to last day of month
            document.getElementById('actualInspectionDate').value = this.max;
        }
    });

    /**
     * Format a Date object to YYYY-MM-DD string for input[type="date"]
     */
    function formatDateForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // Load activities for selected month
    function loadActivitiesForMonth(monthLabel) {
        activitiesLoader.style.display = 'block';
        activitiesContainer.style.display = 'none';

        fetch(`/api/projects/${projectId}/activities?month_label=${monthLabel}`)
            .then(response => response.json())
            .then(data => {
                activitiesLoader.style.display = 'none';
                activitiesContainer.style.display = 'block';

                if (data.success && data.activities && data.activities.length > 0) {
                    renderActivitiesList(data.activities, data.planned_progress_percent);
                    document.getElementById('actualInspectionDate').value = data.month_date || '';
                    document.getElementById('plannedProgressTarget').textContent = Math.round(data.planned_progress_percent || 0);
                    updateSummary(data.activities);
                } else {
                    activitiesContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><i class="bi bi-exclamation-triangle" style="font-size: 20px; display: block; margin-bottom: 8px;"></i><small>No activities found for this month</small></div>';
                }
            })
            .catch(error => {
                activitiesLoader.style.display = 'none';
                activitiesContainer.style.display = 'block';
                console.error('Error loading activities:', error);
                activitiesContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><i class="bi bi-exclamation-circle" style="font-size: 20px; display: block; margin-bottom: 8px;"></i><small>Error loading activities</small></div>';
            });
    }

    // Render activities checklist
    function renderActivitiesList(activities, plannedPercent) {
        let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';
        
        activities.forEach((activity, idx) => {
            const isChecked = activity.is_completed ? 'checked' : '';
            html += `
                <div style="display: flex; align-items: center; padding: 10px 12px; background: white; border: 1px solid #e5e7eb; border-radius: 6px; hover:background: #f9fafb; transition: all 0.2s;">
                    <input type="checkbox" id="activity_${activity.id}" name="activity_ids" value="${activity.id}" 
                           class="activityCheckbox" ${isChecked} 
                           style="width: 18px; height: 18px; cursor: pointer; accent-color: #667eea;">
                    <label for="activity_${activity.id}" style="flex: 1; margin: 0 0 0 12px; cursor: pointer; font-size: 13px;">
                        <strong>${activity.activity_name || 'Activity ' + (idx + 1)}</strong>
                        ${activity.description ? '<div style="color: #6b7280; font-size: 12px; margin-top: 2px;">' + activity.description + '</div>' : ''}
                    </label>
                    <small style="color: #9ca3af; font-size: 11px;">
                        <span style="background: #f3f4f6; padding: 3px 8px; border-radius: 4px;">
                            ${activity.month_label || 'Month'}
                        </span>
                    </small>
                </div>
            `;
        });

        html += '</div>';
        activitiesContainer.innerHTML = html;

        // Add event listeners
        document.querySelectorAll('.activityCheckbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSummary.bind(null, activities));
        });
    }

    // Update summary counts
    function updateSummary(activities) {
        const checkedCount = document.querySelectorAll('.activityCheckbox:checked').length;
        document.getElementById('completedCount').textContent = checkedCount;
        document.getElementById('totalCount').textContent = activities.length;
    }

    // Update variance display
    function updateVarianceDisplay() {
        const plannedStr = document.getElementById('plannedProgressTarget').textContent;
        const planned = plannedStr === '--' ? 0 : parseInt(plannedStr);
        const actual = parseInt(actualProgressNumber.value) || 0;
        const variance = actual - planned;

        const varianceIndicator = document.getElementById('varianceIndicator');
        const varianceValue = document.getElementById('varianceValue');
        const varianceStatus = document.getElementById('varianceStatus');

        if (planned > 0) {
            varianceIndicator.style.display = 'inline';
            varianceValue.textContent = (variance >= 0 ? '+' : '') + variance;

            if (variance < -5) {
                varianceStatus.textContent = 'Behind';
                varianceStatus.style.background = '#fee2e2';
                varianceStatus.style.color = '#dc2626';
            } else if (variance > 5) {
                varianceStatus.textContent = 'Ahead';
                varianceStatus.style.background = '#dcfce7';
                varianceStatus.style.color = '#16a34a';
            } else {
                varianceStatus.textContent = 'On Track';
                varianceStatus.style.background = '#dbeafe';
                varianceStatus.style.color = '#0369a1';
            }
        }
    }

    // Submit form
    submitBtn.addEventListener('click', async function() {
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const monthLabel = monthSelect.value;
        const inspectionDate = document.getElementById('actualInspectionDate').value;
        const activityIds = Array.from(document.querySelectorAll('.activityCheckbox:checked')).map(cb => cb.value);
        const actualProgress = parseInt(actualProgressNumber.value) || 0;
        const remarks = document.getElementById('recordRemarks').value;

        if (!monthLabel) {
            alert('Please select a month');
            return;
        }

        if (activityIds.length === 0) {
            alert('Please select at least one activity');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" style="margin-right: 6px;"></span>Saving...';

        try {
            const response = await fetch(`/projects/${projectId}/record-monthly-progress`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    inspection_month_date: inspectionDate,
                    month_label: monthLabel,
                    activity_ids: activityIds,
                    actual_progress_percent: actualProgress,
                    remarks: remarks
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('Monthly progress recorded successfully!');
                modal.close();
                location.reload(); // Reload to refresh charts and data
            } else {
                alert('Error: ' + (result.message || 'Failed to save progress'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error saving progress: ' + error.message);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle-fill" style="margin-right: 6px;"></i>Save Monthly Progress';
        }
    });

    // Populate months from BASELINE_SCHEDULE when modal opens
    modal.addEventListener('show.bs.modal', function() {
        fetch(`/api/projects/${projectId}/baseline-schedule`)
            .then(response => response.json())
            .then(data => {
                monthSelect.innerHTML = '<option value="">-- Select Month --</option>';
                if (data.success && data.months && data.months.length > 0) {
                    data.months.forEach(month => {
                        const option = document.createElement('option');
                        option.value = month.month_label;
                        option.textContent = `${month.month_label} (${month.planned_progress_percent}% planned)`;
                        monthSelect.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading months:', error));
    });
});
</script>
