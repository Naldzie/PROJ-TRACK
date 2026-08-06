<!-- CONVERT RISK → ISSUE MODAL
     Pre-filled, editable issue fields when a risk materializes. Filled by
     openConvertRiskModal(riskId) in risk-register.js; submits to the
     staff/admin convert route. -->

@php
    $baselines = $project->baselineSchedules()->orderBy('planned_date')->get();
@endphp

<div class="modal fade" id="riskToIssueModal" tabindex="-1" aria-labelledby="riskToIssueModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header risk-modal-header">
                <h5 class="modal-title" id="riskToIssueModalLabel">
                    <i class="bi bi-arrow-left-right" style="margin-right: 8px;"></i>
                    Convert Risk to Issue
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="risk-form-section">
                    <div class="risk-form-section-label">
                        <i class="bi bi-info-circle"></i> Issue Details
                    </div>
                    <small class="text-muted d-block mb-3">
                        This risk has materialized. The fields below are pre-filled from the risk &mdash; edit anything
                        before confirming. Attached risk evidence will move to the issue automatically.
                    </small>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="convertIssueName" class="form-label">Issue Name *</label>
                            <input type="text" class="form-control" id="convertIssueName" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <input type="text" class="form-control" id="convertIssueStatus" value="Open" disabled>
                            <small class="text-muted">Converted issues always start Open</small>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label for="convertIssueDescription" class="form-label">Description *</label>
                        <textarea class="form-control" id="convertIssueDescription" rows="3" required></textarea>
                    </div>
                </div>

                <div class="risk-form-section">
                    <div class="risk-form-section-label">
                        <i class="bi bi-tags"></i> Classification
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="convertIssueType" class="form-label">Issue Type</label>
                            <select class="form-control" id="convertIssueType">
                                <option value="">-- Select Type (Optional) --</option>
                                <option value="material">Material</option>
                                <option value="contractor">Contractor</option>
                                <option value="weather">Weather</option>
                                <option value="design">Design</option>
                                <option value="regulatory">Regulatory</option>
                                <option value="financial">Financial</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="convertIssueSeverity" class="form-label">Severity</label>
                            <select class="form-control" id="convertIssueSeverity">
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                                <option value="Critical">Critical</option>
                            </select>
                            <small class="text-muted">Pre-filled from the risk level</small>
                        </div>
                        <div class="col-md-4">
                            <label for="convertIssueBaselineId" class="form-label">Baseline Month</label>
                            <select class="form-control" id="convertIssueBaselineId">
                                <option value="">-- Select Baseline Month (Optional) --</option>
                                @foreach($baselines as $baseline)
                                    <option value="{{ $baseline->id }}">{{ $baseline->month_display }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">Carried over from the risk</small>
                        </div>
                    </div>
                </div>

                <div class="risk-form-section">
                    <div class="risk-form-section-label">
                        <i class="bi bi-paperclip"></i> Evidence
                    </div>
                    <small class="text-muted d-block mb-3">
                        Risk evidence below will move to the new issue. Remove any that should not be
                        transferred &mdash; removed files are permanently deleted on conversion. Files picked
                        here will be attached to the new issue as evidence.
                    </small>
                    <div class="mb-3">
                        <label class="form-label">Current Risk Evidence</label>
                        <div class="d-flex flex-wrap" id="convertRiskAttachmentList">
                            <small class="text-muted">Loading...</small>
                        </div>
                    </div>
                    <div>
                        <label for="convertRiskAttachmentFiles" class="form-label">Add Evidence Files</label>
                        <input type="file" class="form-control" id="convertRiskAttachmentFiles" multiple accept="image/*,.pdf">
                        <small class="text-muted">jpg, png, pdf &mdash; max 20 MB each. Select one or more.</small>
                        <div class="mt-2 d-flex flex-wrap gap-1" id="convertRiskAttachmentFilesPreview"></div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" id="confirmConvertRiskBtn"
                            style="background: #d97706; border-color: #d97706;">
                        <i class="bi bi-arrow-left-right" style="margin-right: 6px;"></i>
                        Convert to Issue
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>
