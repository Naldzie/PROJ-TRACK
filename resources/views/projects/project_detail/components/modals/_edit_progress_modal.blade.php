<!-- Edit Progress Record Modal -->
<div class="modal fade" id="editProgressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 12px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none;">
                <h5 class="modal-title" style="font-weight: 700;">
                    <i class="bi bi-pencil-square" style="margin-right: 8px;"></i>
                    <span id="editProgressModalTitle">Edit Progress Record</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div id="editProgressErrorContainer" style="display: none; margin-bottom: 16px; padding: 12px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px;">
                    <div style="display: flex; gap: 10px;">
                        <i class="bi bi-exclamation-circle" style="color: #dc2626; font-size: 18px; flex-shrink: 0; margin-top: 2px;"></i>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #991b1b; font-size: 13px; margin-bottom: 6px;">Please fix the following errors:</div>
                            <ul id="editProgressErrorList" style="margin: 0; padding-left: 20px; color: #7f1d1d; font-size: 12px;"></ul>
                        </div>
                    </div>
                </div>

                <form id="editProgressForm">
                    @csrf
                    @method('PUT')
                    <input type="hidden" id="editProgressRecordId" name="record_id" value="">
                    <input type="hidden" id="editProgressMonthLabel" name="month_label" value="">
                    <input type="hidden" id="editProgressBaselineScheduleId" name="baseline_schedule_id" value="">

                    <div style="background: #f0fdf4; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #10b981;">
                        <div style="font-size: 11px; color: #166534; text-transform: uppercase; letter-spacing: 0.05em;">Month Target</div>
                        <div style="display: flex; gap: 20px; margin-top: 8px;">
                            <div>
                                <span style="font-size: 12px; color: #6b7280;">Month:</span>
                                <div id="editProgressMonthDisplay" style="font-size: 16px; font-weight: 700; color: #111827;">—</div>
                            </div>
                            <div>
                                <span style="font-size: 12px; color: #6b7280;">Planned %:</span>
                                <div id="editProgressPlannedDisplay" style="font-size: 16px; font-weight: 700; color: #667eea;">—%</div>
                            </div>
                            <div>
                                <span style="font-size: 12px; color: #6b7280;">Variance:</span>
                                <div id="editProgressVarianceDisplay" style="font-size: 16px; font-weight: 700; color: #10b981;">—%</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="editProgressPercent" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                            Actual Progress % <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="number" id="editProgressPercent" name="progress_percentage" min="0" max="100" step="0.01"
                               required style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-weight: 600;">
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="editProgressInspectionDate" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                            Inspection Date <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="date" id="editProgressInspectionDate" name="inspection_date" class="form-control" required
                               style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                            Completed Activities
                        </label>
                        <div id="editProgressActivitiesContainer" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; min-height: 60px;">
                            <div style="text-align: center; color: #9ca3af; font-size: 12px;">Loading activities...</div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="editProgressRemarks" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                            Remarks (Optional)
                        </label>
                        <textarea id="editProgressRemarks" name="remarks" class="form-control" rows="3"
                                  style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px;"></textarea>
                    </div>

                    <div id="editProgressAuditSection" style="display: none; margin-top: 16px; padding: 12px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;">
                        <div style="font-size: 12px; font-weight: 600; color: #92400e; margin-bottom: 8px;">
                            <i class="bi bi-clock-history" style="margin-right: 4px;"></i> Edit History
                        </div>
                        <div id="editProgressAuditInfo" style="font-size: 12px; color: #78350f;"></div>
                        <div id="editProgressOriginalValues" style="display: none; margin-top: 8px; padding: 8px; background: white; border-radius: 6px; font-size: 11px; color: #6b7280;"></div>
                        <button type="button" id="toggleOriginalValuesBtn" onclick="toggleOriginalValues()" style="margin-top: 6px; background: none; border: none; color: #92400e; font-size: 11px; font-weight: 600; cursor: pointer; text-decoration: underline;">
                            View original values
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none;"
                        onclick="saveProgressEdit()">
                    <i class="bi bi-check-lg" style="margin-right: 6px;"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleOriginalValues() {
    const container = document.getElementById('editProgressOriginalValues');
    const btn = document.getElementById('toggleOriginalValuesBtn');
    if (container.style.display === 'none') {
        container.style.display = 'block';
        btn.textContent = 'Hide original values';
    } else {
        container.style.display = 'none';
        btn.textContent = 'View original values';
    }
}
</script>
