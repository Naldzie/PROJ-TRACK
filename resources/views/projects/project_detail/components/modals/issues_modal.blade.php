<!-- ISSUES MODAL -->
@php
    $baselines = $project->baselineSchedules()->orderBy('planned_date')->get();
    $defaultBaselineId = app(\App\Services\ProjectOverviewService::class)->getDefaultIssueBaseline($project)?->id;
@endphp
<div class="modal fade" id="issuesModal" tabindex="-1" aria-labelledby="issuesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header issue-modal-header">
                <h5 class="modal-title" id="issuesModalLabel">
                    <i class="bi bi-exclamation-circle" style="margin-right: 8px;"></i>
                    Manage Issues
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if(in_array(auth()->user()->role, ['admin', 'staff']))
                    <!-- Add Issue Form -->
                    <div class="card issue-form-card mb-4">
                        <div class="card-header issue-form-header">
                            <h6 class="mb-0"><i class="bi bi-plus-circle" style="margin-right: 6px;"></i>Add New Issue</h6>
                        </div>
                        <div class="card-body">
                            <form id="addIssueForm">
                                @csrf

                                <div class="issue-form-section">
                                    <div class="issue-form-section-label">
                                        <i class="bi bi-info-circle"></i> Issue Details
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label for="issueName" class="form-label">Issue Name *</label>
                                            <input type="text" class="form-control" id="issueName" name="issue_name" 
                                                   placeholder="e.g., Delay in Material Delivery" required>
                                            <small class="text-muted">Brief title for the issue</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="issueStatus" class="form-label">Status *</label>
                                            <select class="form-control" id="issueStatus" name="status" required>
                                                <option value="">Select Status</option>
                                                <option value="Open">Open</option>
                                                <option value="In Progress">In Progress</option>
                                                <option value="Resolved">Resolved</option>
                                                <option value="Closed">Closed</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <label for="issueDescription" class="form-label">Description *</label>
                                        <textarea class="form-control" id="issueDescription" name="description" 
                                                  rows="3" placeholder="Detailed description of the issue..." required></textarea>
                                    </div>
                                </div>

                                <div class="issue-form-section">
                                    <div class="issue-form-section-label">
                                        <i class="bi bi-tags"></i> Classification
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label for="issueType" class="form-label">Issue Type</label>
                                            <select class="form-control" id="issueType" name="issue_type">
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
                                            <label for="issueSeverity" class="form-label">Severity</label>
                                            <select class="form-control" id="issueSeverity" name="severity">
                                                <option value="">-- Select Severity (Optional) --</option>
                                                <option value="Low">Low</option>
                                                <option value="Medium">Medium</option>
                                                <option value="High">High</option>
                                                <option value="Critical">Critical</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="issueBaselineId" class="form-label">Baseline Month</label>
                                            <select class="form-control" id="issueBaselineId" name="baseline_schedule_id">
                                                <option value="">-- Select Baseline Month (Optional) --</option>
                                                @foreach($baselines as $baseline)
                                                    <option value="{{ $baseline->id }}" {{ $baseline->id === $defaultBaselineId ? 'selected' : '' }}>{{ $baseline->month_display }}</option>
                                                @endforeach
                                            </select>
                                            <small class="text-muted">Link the issue to the baseline month it belongs to</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="issue-form-section">
                                    <div class="issue-form-section-label">
                                        <i class="bi bi-paperclip"></i> Evidence
                                    </div>
                                    <div class="mb-3">
                                        <label for="issueAttachmentFiles" class="form-label">Issue Evidence Files</label>
                                        <input type="file" class="form-control" id="issueAttachmentFiles" name="issue_attachment_files[]" multiple accept="image/*,.pdf">
                                        <small class="text-muted">Photos or documents supporting this issue (jpg, png, pdf â€” max 20 MB each). Select one or more.</small>
                                        <div class="mt-2 d-flex flex-wrap gap-1" id="issueAttachmentFilesPreview"></div>
                                    </div>

                                    <div class="issue-evidence-group" id="issueResolutionWrap" style="display: none;">
                                        <div class="issue-evidence-group-label">
                                            <i class="bi bi-check-circle"></i> Resolution
                                        </div>
                                        <div class="mb-3" id="issueResolutionNotesWrap" style="display: none;">
                                            <label for="issueResolutionNotes" class="form-label">Resolution Notes *</label>
                                            <textarea class="form-control" id="issueResolutionNotes" name="resolution_notes" rows="3"
                                                      placeholder="Describe how this issue was resolved (required when status is Resolved or Closed)..."></textarea>
                                        </div>
                                        <div class="mb-3" id="issueResolutionFilesWrap" style="display: none;">
                                            <label for="resolutionAttachmentFiles" class="form-label">Resolution Evidence Files</label>
                                            <input type="file" class="form-control" id="resolutionAttachmentFiles" name="resolution_attachment_files[]" multiple accept="image/*,.pdf">
                                            <small class="text-muted">Proof of resolution (jpg, png, pdf â€” max 20 MB each). Select one or more.</small>
                                            <div class="mt-2 d-flex flex-wrap gap-1" id="resolutionAttachmentFilesPreview"></div>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-plus-circle" style="margin-right: 6px;"></i>
                                        Add Issue
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif

                {{-- Issues List (temporarily commented out â€” makes the modal too tall on small screens) --}}
                {{-- <div class="card">
                    <div class="card-header" style="background-color: #f8f9fa; border-bottom: 1px solid #e0e0e0;">
                        <h6 class="mb-0" style="font-weight: 600;">
                            <i class="bi bi-list-ul" style="margin-right: 6px;"></i>
                            All Issues <span id="issuesCount" class="badge bg-secondary">0</span>
                        </h6>
                    </div>
                    <div class="card-body" id="issuesList">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox" style="font-size: 2rem; margin-bottom: 10px;"></i>
                            <p>No issues yet</p>
                        </div>
                    </div>
                </div> --}}
            </div>
        </div>
    </div>
</div>

<!-- EDIT ISSUE MODAL -->
<div class="modal fade" id="editIssueModal" tabindex="-1" aria-labelledby="editIssueModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header issue-modal-header">
                <h5 class="modal-title" id="editIssueModalLabel">
                    <i class="bi bi-pencil-square" style="margin-right: 8px;"></i>
                    Edit Issue
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="issue-form-section" id="editIssueFromRiskWrap" style="display: none;">
                    <small style="color: #5b21b6; font-weight: 600;">
                        <i class="bi bi-shield-exclamation"></i> Converted from risk:
                        <span id="editIssueFromRiskName"></span>
                    </small>
                </div>
                <form id="editIssueForm">
                    @csrf
                    @method('PUT')
                    <input type="hidden" id="editIssueId" name="issue_id">

                    <div class="issue-form-section">
                        <div class="issue-form-section-label">
                            <i class="bi bi-info-circle"></i> Issue Details
                        </div>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="editIssueName" class="form-label">Issue Name *</label>
                                <input type="text" class="form-control" id="editIssueName" name="issue_name" required>
                            </div>
                            <div class="col-md-4">
                                <label for="editIssueStatus" class="form-label">Status *</label>
                                <select class="form-control" id="editIssueStatus" name="status" required>
                                    <option value="">Select Status</option>
                                    <option value="Open">Open</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Resolved">Resolved</option>
                                    <option value="Closed">Closed</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label for="editIssueDescription" class="form-label">Description *</label>
                            <textarea class="form-control" id="editIssueDescription" name="description" rows="3" required></textarea>
                        </div>
                    </div>

                    <div class="issue-form-section">
                        <div class="issue-form-section-label">
                            <i class="bi bi-tags"></i> Classification
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="editIssueType" class="form-label">Issue Type</label>
                                <select class="form-control" id="editIssueType" name="issue_type">
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
                                <label for="editIssueSeverity" class="form-label">Severity</label>
                                <select class="form-control" id="editIssueSeverity" name="severity">
                                    <option value="">-- Select Severity (Optional) --</option>
                                    <option value="Low">Low</option>
                                    <option value="Medium">Medium</option>
                                    <option value="High">High</option>
                                    <option value="Critical">Critical</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="editIssueBaselineId" class="form-label">Baseline Month</label>
                                <select class="form-control" id="editIssueBaselineId" name="baseline_schedule_id">
                                    <option value="">-- Select Baseline Month (Optional) --</option>
                                    @foreach($baselines as $baseline)
                                        <option value="{{ $baseline->id }}">{{ $baseline->month_display }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="issue-form-section">
                        <div class="issue-form-section-label">
                            <i class="bi bi-paperclip"></i> Evidence
                        </div>
                        <div class="mb-3">
                            <label for="editIssueAttachmentFiles" class="form-label">Add Issue Evidence Files</label>
                            <input type="file" class="form-control" id="editIssueAttachmentFiles" name="issue_attachment_files[]" multiple accept="image/*,.pdf">
                            <small class="text-muted">New photos or documents to attach (jpg, png, pdf â€” max 20 MB each). Select one or more.</small>
                            <div class="mt-2 d-flex flex-wrap gap-1" id="editIssueAttachmentFilesPreview"></div>
                        </div>

                        <div class="issue-evidence-group" id="editIssueResolutionWrap" style="display: none;">
                            <div class="issue-evidence-group-label">
                                <i class="bi bi-check-circle"></i> Resolution
                            </div>
                            <div class="mb-3" id="editIssueResolutionNotesWrap" style="display: none;">
                                <label for="editIssueResolutionNotes" class="form-label">Resolution Notes *</label>
                                <textarea class="form-control" id="editIssueResolutionNotes" name="resolution_notes" rows="3"
                                          placeholder="Describe how this issue was resolved (required when status is Resolved or Closed)..."></textarea>
                            </div>
                            <div class="mb-3" id="editIssueResolutionFilesWrap" style="display: none;">
                                <label for="editResolutionAttachmentFiles" class="form-label">Add Resolution Evidence Files</label>
                                <input type="file" class="form-control" id="editResolutionAttachmentFiles" name="resolution_attachment_files[]" multiple accept="image/*,.pdf">
                                <small class="text-muted">New proof-of-resolution files (jpg, png, pdf â€” max 20 MB each). Select one or more.</small>
                                <div class="mt-2 d-flex flex-wrap gap-1" id="editResolutionAttachmentFilesPreview"></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Existing Attachments</label>
                            <div id="editIssueAttachmentList">
                                <small class="text-muted">Loading...</small>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle" style="margin-right: 6px;"></i>
                            Update Issue
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="{{ asset('css/admin/projects/issues-modal.css') }}">

<script src="{{ asset('js/admin/projects/issues-modal.js') }}"></script>
