<!-- Carry Over Activity Modal -->
<div class="modal fade" id="carryOverActivityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 12px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border: none;">
                <h5 class="modal-title" style="font-weight: 700;">
                    <i class="bi bi-arrow-right-circle" style="margin-right: 8px;"></i>Carry Over Activities
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div id="carryOverInfo" style="background: #fffbeb; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #f59e0b;">
                    <div style="font-size: 13px; color: #92400e;">
                        <strong><i class="bi bi-info-circle"></i> Note:</strong> Carrying over an activity moves it to a future month. The activity's completion status and history are preserved in the audit trail.
                    </div>
                </div>

                <!-- Error Messages -->
                <div id="carryOverErrorContainer" style="display: none; margin-bottom: 16px; padding: 12px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px;">
                    <div style="display: flex; gap: 10px;">
                        <i class="bi bi-exclamation-circle" style="color: #dc2626; font-size: 18px; flex-shrink: 0; margin-top: 2px;"></i>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #991b1b; font-size: 13px; margin-bottom: 6px;">Cannot carry over:</div>
                            <ul id="carryOverErrorList" style="margin: 0; padding-left: 20px; color: #7f1d1d; font-size: 12px;"></ul>
                        </div>
                    </div>
                </div>

                <!-- Activities List -->
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label style="font-weight: 600; font-size: 13px;">
                            Activities to Carry Over
                        </label>
                        <label style="font-size: 12px; color: #6b7280; cursor: pointer; user-select: none;">
                            <input type="checkbox" id="carryOverSelectAll" onchange="toggleAllCarryOverActivities(this.checked)" style="margin-right: 4px;">
                            Select All
                        </label>
                    </div>
                    <div id="carryOverActivitiesContainer" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; min-height: 60px;">
                        <div style="text-align: center; color: #9ca3af; font-size: 12px;">No activities selected.</div>
                    </div>
                </div>

                <!-- Target Month Selection -->
                <div class="form-group" style="margin-bottom: 16px;">
                    <label for="carryOverTargetMonth" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                        Target Month <span style="color: #ef4444;">*</span>
                    </label>
                    <div style="position: relative;">
                        <select id="carryOverTargetMonth" class="form-control" required
                                style="width: 100%; padding: 10px 32px 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-weight: 500; appearance: none; -webkit-appearance: none; -moz-appearance: none; background: white; cursor: pointer;">
                            <option value="">— Select target month —</option>
                        </select>
                        <i class="bi bi-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #6b7280; pointer-events: none; font-size: 14px;"></i>
                    </div>
                    <div style="font-size: 11px; color: #6b7280; margin-top: 6px;">
                        <i class="bi bi-clock-history"></i> The selected activities will be moved to this month's schedule.
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb;">
                <button type="button" class="btn btn-outline-secondary" onclick="closeCarryOverModal()">
                    <i class="bi bi-x-lg" style="margin-right: 4px;"></i> Skip
                </button>
                <button type="button" class="btn" id="carryOverSubmitBtn" 
                        style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border: none;"
                        onclick="submitCarryOver()">
                    <i class="bi bi-arrow-right-circle" style="margin-right: 6px;"></i> Carry Over
                </button>
            </div>
        </div>
    </div>
</div>
