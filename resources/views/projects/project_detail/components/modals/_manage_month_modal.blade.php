<!-- Manage Month Modal — Merged Edit Target + Manage Activities -->
<div class="modal fade" id="manageMonthModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 12px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                <h5 class="modal-title" style="font-weight: 700; display: flex; align-items: center; gap: 10px;">
                    <i class="bi bi-pencil-square" id="manageMonthModalIcon" style="font-size: 20px;"></i>
                    <span>Manage Month — </span><span id="manageMonthLabel">--</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <input type="hidden" id="manageMonthScheduleId" value="">
                <input type="hidden" id="manageMonthMode" value="activities-only">
                <input type="hidden" id="manageMonthRawLabel" value="">
                <input type="hidden" id="manageMonthPrevTarget" value="">
                <input type="hidden" id="manageMonthNextTarget" value="">

                <!-- Section 1: Target Information -->
                <div style="background: #f9fafb; padding: 18px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #e5e7eb;">
                    <h6 style="margin: 0 0 16px 0; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;">
                        <i class="bi bi-info-circle" style="color: #667eea; font-size: 16px;"></i> Target Information
                    </h6>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 12px; color: #374151;">Month</label>
                            <input type="text" id="manageMonthMonthDisplay" readonly
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; background: #f3f4f6; color: #6b7280;">
                        </div>
                        <div>
                            <label for="manageMonthPlannedDate" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 12px; color: #374151;">
                                Target Date <span id="manageMonthDateRequired" style="color: #ef4444;">*</span>
                            </label>
                            <input type="date" id="manageMonthPlannedDate" name="manage_month_planned_date"
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                        </div>
                        <div>
                            <label for="manageMonthProgressPercent" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 12px; color: #374151;">
                                Target % <span id="manageMonthPercentRequired" style="color: #ef4444;">*</span>
                            </label>
                            <input type="number" id="manageMonthProgressPercent" min="0" max="100" step="0.01"
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                            <div id="manageMonthRangeHint" style="font-size: 11px; color: #6b7280; margin-top: 4px;"></div>
                            <div id="manageMonthTargetError" style="font-size: 11px; color: #dc2626; margin-top: 4px; display: none;"></div>
                        </div>
                    </div>

                    <div style="margin-top: 12px;">
                        <label for="manageMonthDescription" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 12px; color: #374151;">
                            Description <span style="color: #9ca3af;">(Optional)</span>
                        </label>
                        <textarea id="manageMonthDescription" rows="2" placeholder="What should be achieved by this month?"
                                  style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; resize: vertical;"></textarea>
                    </div>
                </div>

                <!-- Section 2: Activities -->
                <div style="background: #f0f9ff; padding: 18px; border-radius: 10px; border: 1px solid #bae6fd;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <h6 style="margin: 0; font-weight: 700; color: #0369a1; display: flex; align-items: center; gap: 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="bi bi-list-check" style="font-size: 16px;"></i> Activities
                        </h6>
                    </div>

                    <!-- Current Activities List -->
                    <div id="manageMonthActivitiesList" style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 16px; min-height: 60px;">
                        <div style="text-align: center; padding: 16px; color: #9ca3af; font-size: 12px;">
                            <i class="bi bi-inbox" style="font-size: 20px; display: block; margin-bottom: 6px; opacity: 0.5;"></i>
                            Loading activities...
                        </div>
                    </div>

                    <!-- Add New Activity Form -->
                    <div style="border-top: 1px solid #bae6fd; padding-top: 14px;">
                        <div style="font-weight: 600; font-size: 12px; color: #0369a1; margin-bottom: 10px;">
                            <i class="bi bi-plus-circle" style="margin-right: 4px;"></i> Add New Activity
                        </div>
                        <div style="display: flex; gap: 8px; align-items: start;">
                            <div style="flex: 1;">
                                <input type="text" id="manageMonthNewActivityName" placeholder="Activity name (e.g., Safety inspection)"
                                       style="width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div style="flex: 1;">
                                <input type="text" id="manageMonthNewActivityDesc" placeholder="Description (optional)"
                                       style="width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px;">
                            </div>
                            <button type="button" onclick="addManageMonthActivity()"
                                    style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="bi bi-plus-circle"></i> Add
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb;">
                <button type="button" id="manageMonthSaveBtn" class="btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; font-weight: 600; padding: 8px 20px; display: none;"
                        onclick="saveManageMonthTarget()">
                    <i class="bi bi-check-lg" style="margin-right: 6px;"></i> Save Changes
                </button>
                <button type="button" id="manageMonthCloseBtn" class="btn" style="background: #6b7280; color: white; border: none; font-weight: 600; padding: 8px 20px;"
                        onclick="bootstrap.Modal.getInstance(document.getElementById('manageMonthModal')).hide();">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>
