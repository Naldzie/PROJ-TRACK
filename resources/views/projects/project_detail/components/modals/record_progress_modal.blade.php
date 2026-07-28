<!-- Record Monthly Progress Modal -->
<div class="modal fade" id="recordProgressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 12px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none;">
                <h5 class="modal-title" style="font-weight: 700;">
                    <i class="bi bi-graph-up-arrow" style="margin-right: 8px;"></i>Record Monthly Progress(shared1)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <!-- Error Messages Container -->
                <div id="recordProgressErrorContainer" style="display: none; margin-bottom: 16px; padding: 12px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px;">
                    <div style="display: flex; gap: 10px;">
                        <i class="bi bi-exclamation-circle" style="color: #dc2626; font-size: 18px; flex-shrink: 0; margin-top: 2px;"></i>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #991b1b; font-size: 13px; margin-bottom: 6px;">Please fix the following errors:</div>
                            <ul id="recordProgressErrorList" style="margin: 0; padding-left: 20px; color: #7f1d1d; font-size: 12px;"></ul>
                        </div>
                    </div>
                </div>

                <form id="recordProgressForm">
                    @csrf
                    <input type="hidden" id="progressMonthLabel" name="month_label" value="">

                    <!-- Month Info -->
                    <div style="background: #f0fdf4; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #10b981;">
                        <div style="font-size: 11px; color: #166534; text-transform: uppercase; letter-spacing: 0.05em;">Month Target</div>
                        <div style="display: flex; gap: 20px; margin-top: 8px;">
                            <div>
                                <span style="font-size: 12px; color: #6b7280;">Month:</span>
                                <div id="progressMonthDisplay" style="font-size: 16px; font-weight: 700; color: #111827;">—</div>
                            </div>
                            <div>
                                <span style="font-size: 12px; color: #6b7280;">Planned %:</span>
                                <div id="progressPlannedDisplay" style="font-size: 16px; font-weight: 700; color: #667eea;">—%</div>
                            </div>
                        </div>
                    </div>

                    <!-- Actual Progress % -->
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="actualProgressPercent" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                            Actual Progress % <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="number" id="actualProgressPercent" name="actual_progress_percent" min="0" max="100" value="0" 
                               required style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-align: left; font-weight: 600;">
                    </div>

                    <!-- Inspection Date -->
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="inspectionDate" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                            Inspection Date <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="date" id="inspectionDate" name="inspection_month_date" class="form-control" required
                               style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                    </div>

                    <!-- Activity Checklist -->
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                            Completed Activities
                        </label>
                        <div id="activitiesChecklistContainer" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; min-height: 60px;">
                            <div style="text-align: center; color: #9ca3af; font-size: 12px;">Loading activities...</div>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="form-group">
                        <label for="progressRemarks" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                            Remarks (Optional)
                        </label>
                        <textarea id="progressRemarks" name="remarks" class="form-control" rows="3" placeholder="Inspection observations, issues encountered, etc."
                                  style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px;"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none;"
                        onclick="saveMonthlyProgress()">
                    <i class="bi bi-check-lg" style="margin-right: 6px;"></i>Save Progress
                </button>
            </div>
        </div>
    </div>
</div>
