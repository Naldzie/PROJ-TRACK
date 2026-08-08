<!-- Add Month Target Modal -->
<div class="modal fade" id="addMonthTargetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 12px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                <h5 class="modal-title" style="font-weight: 700; display: flex; align-items: center; gap: 10px;">
                    <i class="bi bi-calendar-plus" id="monthTargetModalIcon" style="font-size: 20px;"></i><span id="monthTargetModalTitleText">Add Monthly Progress Target</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <!-- Error Messages Container -->
                <div id="formErrorContainer" style="display: none; margin-bottom: 16px; padding: 12px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px;">
                    <div style="display: flex; gap: 10px;">
                        <i class="bi bi-exclamation-circle" style="color: #dc2626; font-size: 18px; flex-shrink: 0; margin-top: 2px;"></i>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #991b1b; font-size: 13px; margin-bottom: 6px;">Please fix the following errors:</div>
                            <ul id="errorList" style="margin: 0; padding-left: 20px; color: #7f1d1d; font-size: 12px;"></ul>
                        </div>
                    </div>
                </div>

                <form id="monthTargetForm">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $project->id }}">

                    <!-- Section 1: Key Information (Grid Layout) -->
                    <div style="background: #f9fafb; padding: 18px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #e5e7eb;">
                        <h6 style="margin: 0 0 16px 0; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;">
                            <i class="bi bi-info-circle" style="color: #667eea; font-size: 16px;"></i> Target Information(shared)
                        </h6>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                            <!-- Month Input -->
                            <div>
                                <label for="monthLabel" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 12px; color: #374151;">
                                    Month <span style="color: #ef4444;">*</span>
                                </label>
                                <input type="month" id="monthLabel" name="month_label" class="form-control" required
                                       style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                                <div id="monthLabelError" style="font-size: 11px; color: #dc2626; margin-top: 4px; display: none;"></div>
                            </div>

                            <!-- Target Date Input -->
                            <div>
                                <label for="plannedDate" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 12px; color: #374151;">
                                    Target Date <span style="color: #ef4444;">*</span>
                                </label>
                                <input type="date" id="plannedDate" name="planned_date" class="form-control" required
                                       style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                                <div id="plannedDateError" style="font-size: 11px; color: #dc2626; margin-top: 4px; display: none;"></div>
                            </div>

                            <!-- Progress % Input -->
                            <div>
                                <label for="plannedProgressPercent" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 12px; color: #374151;">
                                    Target % <span style="color: #ef4444;">*</span>
                                </label>
                                <input type="number" id="plannedProgressPercent" name="planned_progress_percent" min="0" max="100" step="0.01" value="50"
                                       required style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;">
                                <div id="plannedProgressPercentError" style="font-size: 11px; color: #dc2626; margin-top: 4px; display: none;"></div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 10px; padding: 10px; background: white; border-radius: 6px; border-left: 3px solid #667eea;">
                            <small style="color: #6b7280; font-size: 11px;">
                                <strong>Tip:</strong> Month and target date auto-sync. Change either field to update the other.
                            </small>
                        </div>
                    </div>

                    <!-- Section 2: Description -->
                    <div style="margin-bottom: 20px;">
                        <label for="description" style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: #111827;">
                            <i class="bi bi-chat-dots" style="margin-right: 6px; color: #667eea;"></i>Description <span style="color: #9ca3af;">(Optional)</span>
                        </label>
                        <textarea id="description" name="description" class="form-control" rows="2" placeholder="e.g., Foundation and groundwork completion. What should be achieved by this month?"
                                  style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; resize: vertical;"></textarea>
                    </div>

                    <!-- Section 3: Activities -->
                    <div style="background: #f0f9ff; padding: 18px; border-radius: 10px; border: 1px solid #bae6fd;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                            <h6 style="margin: 0; font-weight: 700; color: #0369a1; display: flex; align-items: center; gap: 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;">
                                <i class="bi bi-list-check" style="font-size: 16px;"></i>Add Activities
                            </h6>
                            <button type="button" onclick="addActivityRow()" 
                                    style="background: white; color: #0369a1; border: 1px solid #bae6fd; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;"
                                    onmouseover="this.style.background='#dbeafe'; this.style.borderColor='#7dd3fc';"
                                    onmouseout="this.style.background='white'; this.style.borderColor='#bae6fd';">
                                <i class="bi bi-plus-circle" style="font-size: 14px;"></i> Add Row
                            </button>
                        </div>

                        <div id="activitiesContainer" style="display: flex; flex-direction: column; gap: 10px; min-height: 50px; max-height: 300px; overflow-y: auto; padding-right: 4px;">
                            <div style="text-align: center; color: #7dd3fc; font-size: 12px; padding: 16px; background: white; border-radius: 8px; border: 1px dashed #bae6fd;">
                                Click "Add Row" to add activities for this month
                            </div>
                        </div>

                        <small style="color: #0c4a6e; display: block; margin-top: 10px; background: white; padding: 8px 12px; border-radius: 6px; border-left: 3px solid #bae6fd;">
                            <strong>Optional:</strong> Add activities now or manage them later from the month card
                        </small>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-color: #d1d5db;">Cancel</button>
                <button type="button" class="btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; font-weight: 600; padding: 8px 20px;"
                        onclick="saveMonthTarget()">
                    <i class="bi bi-check-lg" style="margin-right: 6px;"></i><span id="monthTargetSaveBtnText">Save Target</span>
                </button>
            </div>
        </div>
    </div>
</div>
