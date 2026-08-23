<!-- Request / Edit Extension Modal -->
<div class="modal fade" id="requestExtensionModal" tabindex="-1" aria-labelledby="requestExtensionLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h5 class="modal-title" id="requestExtensionLabel">
                    <i class="fas fa-calendar-check me-2"></i><span id="extensionModalTitle">Request Project Extension</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <form id="extensionForm" class="needs-validation" novalidate>
                    @csrf
                    <input type="hidden" id="extensionEditId" value="">
                    <input type="hidden" id="extensionEditMode" value="create">

                    <!-- Current / Original Deadline + Duration Display -->
                    <div class="alert alert-light border-start border-info border-3 mb-2 py-2">
                        <div class="row g-2 align-items-center">
                            <div class="col-4">
                                <small class="text-muted d-block">Current Deadline</small>
                                <strong class="d-block text-info" id="currentDeadlineDisplay">--</strong>
                            </div>
                            <div class="col-4">
                                <small class="text-muted d-block">Original Deadline</small>
                                <strong class="d-block text-secondary" id="originalDeadlineDisplay">--</strong>
                            </div>
                            <div class="col-4">
                                <small class="text-muted d-block">Extension Duration</small>
                                <strong class="d-block text-success" id="durationDisplay">
                                    <i class="fas fa-hourglass-end me-1"></i><span id="durationDays">0</span> days
                                </strong>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2">
                        <!-- New End Date Field -->
                        <div class="col-md-6 mb-2">
                            <label for="newEndDate" class="form-label">
                                <span class="text-danger">*</span> New End Date
                            </label>
                            <input
                                type="date"
                                class="form-control form-control-sm"
                                id="newEndDate"
                                name="new_end_date"
                                required
                            >
                            <div class="form-text text-muted">
                                Must be after current deadline
                            </div>
                            <div class="invalid-feedback" id="newEndDateError">
                                Please provide a valid end date.
                            </div>
                        </div>

                        <!-- Reason Category (Decision 3) -->
                        <div class="col-md-6 mb-2">
                            <label for="reasonCategory" class="form-label">
                                Reason Category
                            </label>
                            <select class="form-select form-select-sm" id="reasonCategory" name="reason_category">
                                <option value="">Select a category (optional)</option>
                                <option value="Weather">Weather</option>
                                <option value="Materials">Materials</option>
                                <option value="Labor">Labor</option>
                                <option value="Site access">Site access</option>
                                <option value="Permits">Permits</option>
                                <option value="Design change">Design change</option>
                                <option value="Other">Other</option>
                            </select>
                            <div class="form-text text-muted">
                                Categorize the ground for this extension request
                            </div>
                        </div>
                    </div>

                    <!-- Status — ADMIN ONLY. -->
                    <div class="row g-2">
                        <div class="col-md-12 mb-2" id="statusFieldWrapper" style="display: none;">
                            <label for="extensionStatus" class="form-label">Status</label>
                            <div class="d-flex align-items-center gap-2">
                                <select class="form-select form-select-sm" id="extensionStatus" name="status" onchange="toggleRejectionReasonField()" style="flex: 1;">
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                            <div class="form-text text-muted">
                                "Approved" applies the new deadline immediately. "Reject" requires a reason.
                            </div>
                        </div>
                    </div>

                    <!-- Rejection Reason — ADMIN ONLY, only shown/required
                         when status is set to "Rejected". Staff needs this
                         to know what to fix before resubmitting. -->
                    <div class="mb-2" id="rejectionReasonWrapper" style="display: none;">
                        <label for="rejectionReason" class="form-label">
                            <span class="text-danger">*</span> Reason for Rejection
                        </label>
                        <textarea
                            class="form-control form-control-sm"
                            id="rejectionReason"
                            name="rejection_reason"
                            rows="2"
                            placeholder="Explain what needs to change before this request can be approved..."
                            minlength="5"
                        ></textarea>
                        <div class="form-text text-muted">
                            This will be shown to the staff member who submitted the request.
                        </div>
                    </div>

                    <!-- Reason Field -->
                    <div class="mb-2">
                        <label for="reason" class="form-label">
                            <span class="text-danger">*</span> Reason for Extension
                        </label>
                        <textarea
                            class="form-control form-control-sm"
                            id="reason"
                            name="reason"
                            rows="3"
                            placeholder="e.g., Delayed material delivery, weather conditions, site access issues..."
                            required
                            minlength="5"
                        ></textarea>
                        <div class="form-text text-muted">
                            Minimum 5 characters required
                        </div>
                        <div class="invalid-feedback" id="reasonError">
                            Please provide a reason (minimum 5 characters).
                        </div>
                    </div>

                    <!-- Evidence Attachments (Decision 4) — two-step upload.
                         Files are picked here, then uploaded AFTER the extension
                         row is created/updated (attachable_id needs the row id). -->
                    <div class="mb-2">
                        <label for="extensionEvidenceFiles" class="form-label">
                            <i class="bi bi-paperclip me-1"></i> Evidence Files (Optional)
                        </label>
                        <input type="file" class="form-control form-control-sm" id="extensionEvidenceFiles" name="extension_evidence_files[]" multiple accept="image/*,.pdf">
                        <div class="mt-2 d-flex flex-wrap gap-1" id="extensionEvidenceFilesPreview"></div>
                        <div class="form-text text-muted">
                            Supporting documents (photos, PDFs) for this request. Uploaded after the request is saved.
                        </div>
                    </div>

                    <!-- Staff-only notice shown when editing a request that's
                         awaiting admin review -->
                    <div class="alert alert-warning py-2 px-3 mb-0" id="pendingNotice" style="display: none;">
                        <i class="fas fa-clock me-1"></i>This request is awaiting admin approval. You can still revise the details below before it's reviewed.
                    </div>

                    <!-- Staff-only display of WHY their request was
                         rejected, when editing a rejected extension -->
                    <div class="alert alert-danger py-2 px-3 mb-0" id="rejectionReasonNotice" style="display: none;">
                        <i class="fas fa-exclamation-circle me-1"></i><strong>Rejected:</strong> <span id="rejectionReasonText"></span>
                    </div>
                </form>
            </div>


            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-info btn-sm"
                    id="submitExtensionBtn"
                    onclick="submitExtension()"
                >
                    <i class="fas fa-check me-1"></i><span id="submitExtensionBtnLabel">Add Extension</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Helper function to parse different date formats
function parseDate(dateString) {
    if (!dateString) return null;
    if (dateString instanceof Date) return dateString;

    if (dateString.includes('-') && dateString.length === 10) {
        const date = new Date(dateString + 'T00:00:00');
        if (!isNaN(date.getTime())) return date;
    }

    const date = new Date(dateString);
    if (!isNaN(date.getTime())) return date;

    console.warn('Could not parse date:', dateString);
    return null;
}

/**
 * Build the role-aware extensions endpoint. Mirrors the pattern used by
 * buildBaselineScheduleEndpoint() etc. in baseline-schedule-utils.js.
 * Defined here as a fallback in case utils.js hasn't loaded yet, but will
 * not override an existing global of the same name.
 */
if (typeof window.buildExtensionsEndpoint !== 'function') {
    window.buildExtensionsEndpoint = function (extensionId) {
        const projectId = window.projectId;
        const routePrefix = window.routePrefix;
        const base = routePrefix === 'admin.projects'
            ? `/admin/projects/${projectId}/extensions`
            : (routePrefix === 'staff.projects'
                ? `/staff/projects/${projectId}/extensions`
                : `/contractor/projects/${projectId}/extensions`);
        return extensionId ? `${base}/${extensionId}` : base;
    };
}

/**
 * Is the current user an admin? Determined from routePrefix, which is set
 * per-role by the blade that includes this modal. Used only to decide
 * which fields to SHOW — the backend independently enforces who can
 * actually set status, regardless of what this returns.
 */
function isAdminContext() {
    return window.routePrefix === 'admin.projects';
}

/**
 * Show/hide the rejection-reason textarea based on the currently
 * selected status in the admin status dropdown. Only relevant for admin;
 * staff never sees the status dropdown at all.
 */
window.toggleRejectionReasonField = function () {
    const status = document.getElementById('extensionStatus').value;
    document.getElementById('rejectionReasonWrapper').style.display = status === 'rejected' ? 'block' : 'none';
};

/**
 * Open this modal in CREATE mode (new extension request).
 */
window.openRequestExtensionModal = function () {
    document.getElementById('extensionEditMode').value = 'create';
    document.getElementById('extensionEditId').value = '';
    document.getElementById('extensionModalTitle').textContent = 'Request Project Extension';
    document.getElementById('submitExtensionBtnLabel').textContent = 'Add Extension';
    document.getElementById('pendingNotice').style.display = 'none';
    document.getElementById('rejectionReasonNotice').style.display = 'none';
    document.getElementById('rejectionReasonWrapper').style.display = 'none';
    document.getElementById('rejectionReason').value = '';

    const statusWrapper = document.getElementById('statusFieldWrapper');
    const canPickStatus = isAdminContext() || window.isAssignedStaff === true;
    if (canPickStatus) {
        // Admin and assigned staff can create a pending/approved/rejected extension directly —
        // default to approved (auto-approve) so the common path is one click.
        statusWrapper.style.display = 'block';
        document.getElementById('extensionStatus').value = 'approved';
        document.getElementById('rejectionReason').value = '';
        document.getElementById('rejectionReasonWrapper').style.display = 'none';
    } else {
        statusWrapper.style.display = 'none'; // status is meaningless on create for contractor
    }
    window.toggleRejectionReasonField();
    document.getElementById('durationDays').textContent = '0';
    document.getElementById('newEndDate').value = '';
    document.getElementById('newEndDate').classList.remove('is-valid', 'is-invalid');

    // Reset Phase 2/4/7 fields (Decision 3/7 + evidence).
    const reasonCategory = document.getElementById('reasonCategory');
    if (reasonCategory) reasonCategory.value = '';
    window._pendingExtensionEvidence = [];
    const preview = document.getElementById('extensionEvidenceFilesPreview');
    if (preview) preview.innerHTML = '';
    const evidenceInput = document.getElementById('extensionEvidenceFiles');
    if (evidenceInput) evidenceInput.value = '';

    new bootstrap.Modal(document.getElementById('requestExtensionModal')).show();
};

/**
 * Open this modal in EDIT mode for an existing extension.
 * `extension` is the object shape returned by the controller's
 * formatExtension() (id, new_end_date, reason, status,
 * rejection_reason, ...).
 */
window.openEditExtensionModal = function (extension) {
    document.getElementById('extensionEditMode').value = 'edit';
    document.getElementById('extensionEditId').value = extension.id;
    document.getElementById('extensionModalTitle').textContent = 'Edit Extension Request';
    document.getElementById('submitExtensionBtnLabel').textContent = 'Save Changes';

    window._extensionResubmit = false;

    // reset below (shared with resubmit mode)
    _populateExtensionEditFields(extension);
    new bootstrap.Modal(document.getElementById('requestExtensionModal')).show();
};

/**
 * Open the modal in RESUBMIT mode (Decision 6): the rejected row's details are
 * copied into the form so the user can revise and resubmit as a NEW row.
 * The rejected row itself is never mutated.
 */
window.openResubmitExtensionModal = function (extension) {
    document.getElementById('extensionEditMode').value = 'resubmit';
    document.getElementById('extensionEditId').value = extension.id;
    document.getElementById('extensionModalTitle').textContent = 'Resubmit Extension Request';
    document.getElementById('submitExtensionBtnLabel').textContent = 'Resubmit for Review';
    window._extensionResubmit = true;

    _populateExtensionEditFields(extension);

    const pendingNotice = document.getElementById('pendingNotice');
    pendingNotice.style.display = 'block';
    pendingNotice.innerHTML = '<i class="fas fa-clock me-1"></i>This resubmits as a NEW request for admin review. The rejected request stays in the history untouched.';
    const rejectionReasonNotice = document.getElementById('rejectionReasonNotice');
    if (extension.status === 'rejected' && extension.rejection_reason) {
        document.getElementById('rejectionReasonText').textContent = extension.rejection_reason;
        rejectionReasonNotice.style.display = 'block';
    } else {
        rejectionReasonNotice.style.display = 'none';
    }

    new bootstrap.Modal(document.getElementById('requestExtensionModal')).show();
};

function _populateExtensionEditFields(extension) {
    document.getElementById('newEndDate').value = extension.new_end_date;
    document.getElementById('reason').value = extension.reason || '';

    const reasonCategory = document.getElementById('reasonCategory');
    if (reasonCategory) reasonCategory.value = extension.reason_category || '';

    // Reset pending evidence picks (existing attachments stay on the row).
    window._pendingExtensionEvidence = [];
    const evidencePreview = document.getElementById('extensionEvidenceFilesPreview');
    if (evidencePreview) evidencePreview.innerHTML = '';
    const evidenceInput = document.getElementById('extensionEvidenceFiles');
    if (evidenceInput) evidenceInput.value = '';

    const statusWrapper = document.getElementById('statusFieldWrapper');
    const pendingNotice = document.getElementById('pendingNotice');
    const rejectionReasonWrapper = document.getElementById('rejectionReasonWrapper');
    const rejectionReasonNotice = document.getElementById('rejectionReasonNotice');

    if ((isAdminContext() || window.isAssignedStaff === true) && document.getElementById('extensionEditMode').value !== 'resubmit') {
        statusWrapper.style.display = 'block';
        document.getElementById('extensionStatus').value = extension.status;
        pendingNotice.style.display = 'none';
        rejectionReasonNotice.style.display = 'none';
        // Pre-fill in case admin is re-viewing an already-rejected one,
        // but the field only actually SHOWS if status is still 'rejected'
        // when the dropdown is checked just below.
        document.getElementById('rejectionReason').value = extension.rejection_reason || '';
        window.toggleRejectionReasonField();
    } else {
        statusWrapper.style.display = 'none';
        rejectionReasonWrapper.style.display = 'none';
        pendingNotice.style.display = extension.status === 'pending' ? 'block' : 'none';
        // Staff sees WHY it was rejected, read-only, so they know what
        // to fix before resubmitting.
        if (extension.status === 'rejected' && extension.rejection_reason) {
            document.getElementById('rejectionReasonText').textContent = extension.rejection_reason;
            rejectionReasonNotice.style.display = 'block';
        } else {
            rejectionReasonNotice.style.display = 'none';
        }
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const newEndDateInput = document.getElementById('newEndDate');
    const reasonInput = document.getElementById('reason');
    const extensionForm = document.getElementById('extensionForm');
    const durationDaysSpan = document.getElementById('durationDays');
    const modal = new bootstrap.Modal(document.getElementById('requestExtensionModal'));

    let toastCheckInterval = setInterval(() => {
        if (typeof window.showToast === 'function') {
            clearInterval(toastCheckInterval);
        }
    }, 100);
    setTimeout(() => clearInterval(toastCheckInterval), 5000);

    // Date calculation and validation — extracted into a reusable
    // function so it can run both on user-driven 'change' events AND
    // programmatically right after edit-mode prefills newEndDate's value
    // (setting .value in JS does NOT fire 'change', so that path needs an
    // explicit call once the current-deadline display is populated).
    window.recalculateExtensionDuration = function() {
        if (!newEndDateInput.value) return;

        const selectedDate = parseDate(newEndDateInput.value);
        const currentDeadlineText = document.getElementById('currentDeadlineDisplay').textContent.trim();
        const currentDeadline = parseDate(currentDeadlineText);

        if (!selectedDate || !currentDeadline) {
            console.error('Failed to parse dates');
            return;
        }

        // Edit-mode allows the extension's own date to equal the live deadline
        // (the active approved extension IS the deadline); new requests still
        // require a strictly later date.
        const isEditing = document.getElementById('extensionEditMode').value === 'edit';
        const invalid = isEditing ? selectedDate < currentDeadline : selectedDate <= currentDeadline;

        const timeDiff = Math.abs(selectedDate - currentDeadline);
        const daysDiff = Math.ceil(timeDiff / (1000 * 60 * 60 * 24));
        durationDaysSpan.textContent = daysDiff;

        if (invalid) {
            newEndDateInput.classList.add('is-invalid');
            document.getElementById('newEndDateError').textContent = 'New end date must be after current deadline.';
        } else {
            newEndDateInput.classList.remove('is-invalid');
            newEndDateInput.classList.add('is-valid');
        }
    };

    newEndDateInput.addEventListener('change', window.recalculateExtensionDuration);

    reasonInput.addEventListener('input', function() {
        if (this.value.length >= 5) {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
        } else if (this.value.length > 0) {
            this.classList.add('is-invalid');
        } else {
            this.classList.remove('is-invalid', 'is-valid');
        }
    });

    window.submitExtension = function() {
        const editMode = document.getElementById('extensionEditMode').value;
        const extensionId = document.getElementById('extensionEditId').value;

        // When rejecting, skip HTML5 form validation — the date is irrelevant.
        const canPickStatus = isAdminContext() || window.isAssignedStaff === true;
        const selectedStatusForCheck = canPickStatus ? (document.getElementById('extensionStatus')?.value || '') : '';
        if (selectedStatusForCheck !== 'rejected') {
            if (!extensionForm.checkValidity()) {
                extensionForm.classList.add('was-validated');
                return;
            }
        }

        const newDateValue = newEndDateInput.value;
        const newDate = parseDate(newDateValue);
        const currentDeadlineText = document.getElementById('currentDeadlineDisplay').textContent.trim();
        const currentDate = parseDate(currentDeadlineText);

        if (!newDate || !currentDate) {
            newEndDateInput.classList.add('is-invalid');
            document.getElementById('newEndDateError').textContent = 'Invalid date format';
            return;
        }

        // Editing an existing extension may legitimately keep a date equal to
        // the CURRENT live deadline — the active approved extension IS the
        // deadline (its own new_end_date set planned_end_date). Only block a
        // date strictly BEFORE it in edit mode; new/resubmit still require
        // strictly after. Rejection skips the date check entirely.
        const isEditing = editMode === 'edit';
        const selectedStatus = (isAdminContext() || window.isAssignedStaff === true) ? (document.getElementById('extensionStatus')?.value || '') : '';
        const isRejectingStatus = selectedStatus === 'rejected';
        if (!isRejectingStatus && (isEditing ? newDate < currentDate : newDate <= currentDate)) {
            newEndDateInput.classList.add('is-invalid');
            document.getElementById('newEndDateError').textContent = 'New date must be after current deadline';
            return;
        }

        if (reasonInput.value.length < 5) {
            reasonInput.classList.add('is-invalid');
            return;
        }

        const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
        if (!token) {
            alert('Error: CSRF token not found. Please refresh the page and try again.');
            return;
        }

        const formData = {
            new_end_date: newDateValue,
            reason: reasonInput.value,
            // Decision 3 — structured category (optional).
            reason_category: document.getElementById('reasonCategory')?.value || null
        };

        // Admin and assigned staff contexts include status. Applies in BOTH
        // create and edit; resubmit never carries status — the new row is always pending.
        if ((isAdminContext() || window.isAssignedStaff === true) && editMode !== 'resubmit') {
            const selectedStatus = document.getElementById('extensionStatus').value;
            formData.status = selectedStatus;

            if (selectedStatus === 'rejected') {
                const rejectionReasonValue = document.getElementById('rejectionReason').value.trim();
                if (rejectionReasonValue.length < 5) {
                    document.getElementById('rejectionReason').classList.add('is-invalid');
                    showErrorMessage('Please provide a reason for rejection (minimum 5 characters).');
                    return;
                }
                formData.rejection_reason = rejectionReasonValue;
            }
        }

        const submitBtn = document.getElementById('submitExtensionBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';

        // Resubmit (Decision 6) posts a new row to the resubmit route.
        const isResubmit = editMode === 'resubmit';
        const url = isResubmit
            ? `${window.buildExtensionsEndpoint(extensionId)}/resubmit`
            : (editMode === 'edit'
                ? window.buildExtensionsEndpoint(extensionId)
                : window.buildExtensionsEndpoint());
        const method = editMode === 'edit' ? 'PUT' : 'POST';

        fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(formData)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => {
                    // Surface the first real validation error, not the generic
                    // "Validation failed" wrapper — e.g. "Granted date must be
                    // after the current deadline".
                    let message = err.message || `HTTP Error: ${response.status}`;
                    if (err.errors && typeof err.errors === 'object') {
                        const first = Object.values(err.errors).flat().find(Boolean);
                        if (first) message = first;
                    }
                    throw new Error(message);
                });
            }
            return response.json();
        })
        .then(data => {
            queueSuccessMessage(data.message || 'Extension saved successfully!');

            const extension = data.extension;
            const pendingEvidence = (window._pendingExtensionEvidence || []).filter(Boolean);

            extensionForm.reset();
            extensionForm.classList.remove('was-validated');
            newEndDateInput.classList.remove('is-invalid', 'is-valid');
            reasonInput.classList.remove('is-invalid', 'is-valid');

            // Two-step evidence upload (Decision 4): the extension row now has
            // an id, so pending evidence files can be attached to it.
            const uploadEvidence = extension && pendingEvidence.length
                ? Promise.all(pendingEvidence.map(file =>
                    uploadExtensionEvidence(extension.id, file)))
                : Promise.resolve();

            uploadEvidence
                .then(() => {
                    setTimeout(() => {
                        modal.hide();
                        if (window.reloadExtensionsTable) {
                            window.reloadExtensionsTable();
                        }
                        sessionStorage.setItem('activeTab', 's-curve-planning');
                        location.reload();
                    }, 600);
                })
                .catch(() => {
                    // Evidence failed but the extension itself saved — still
                    // reload so the request shows, then let the user retry
                    // the upload from the Edit modal.
                    showErrorMessage('Extension saved, but one or more evidence files failed to upload. You can re-add them by editing the request.');
                    setTimeout(() => {
                        modal.hide();
                        sessionStorage.setItem('activeTab', 's-curve-planning');
                        location.reload();
                    }, 1200);
                });
        })
        .catch(error => {
            showErrorMessage(error.message || 'Failed to save extension. Please try again.');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    };

    function queueSuccessMessage(message) {
        if (window.queueToastAfterReload && typeof window.queueToastAfterReload === 'function') {
            window.queueToastAfterReload(message, 'success', 2500);
        } else if (window.showToast && typeof window.showToast === 'function') {
            window.showToast(message, 'success', 2500);
        } else {
            alert('Success: ' + message);
        }
    }

    // === Evidence upload (Decision 4) ===
    // Files are held in window._pendingExtensionEvidence until the extension
    // row is created/updated, then uploaded against its id via the shared
    // attachment endpoint (context 'extension').
    window._pendingExtensionEvidence = [];

    const evidenceInput = document.getElementById('extensionEvidenceFiles');
    const evidencePreview = document.getElementById('extensionEvidenceFilesPreview');

    function renderExtensionEvidencePreview() {
        if (!evidencePreview) return;
        evidencePreview.innerHTML = '';
        (window._pendingExtensionEvidence || []).forEach((file, i) => {
            const chip = document.createElement('span');
            chip.style.cssText = 'background:#eef2ff;color:#4f46e5;border:1px solid #c7d2fe;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:6px;';
            const label = document.createElement('span');
            label.textContent = file.name;
            const rm = document.createElement('button');
            rm.type = 'button';
            rm.innerHTML = '<i class="bi bi-x"></i>';
            rm.style.cssText = 'border:none;background:transparent;color:#dc2626;cursor:pointer;font-size:13px;line-height:1;';
            rm.onclick = () => {
                window._pendingExtensionEvidence.splice(i, 1);
                renderExtensionEvidencePreview();
            };
            chip.appendChild(label);
            chip.appendChild(rm);
            evidencePreview.appendChild(chip);
        });
    }

    if (evidenceInput) {
        evidenceInput.addEventListener('change', function () {
            Array.from(this.files || []).forEach(file => {
                window._pendingExtensionEvidence.push(file);
            });
            this.value = '';
            renderExtensionEvidencePreview();
        });
    }

    function uploadExtensionEvidence(extensionId, file) {
        const fd = new FormData();
        fd.append('project_id', window.projectId);
        fd.append('attachable_type', 'App\\Models\\ProjectExtension');
        fd.append('attachable_id', extensionId);
        fd.append('context', 'extension');
        fd.append('file', file);
        const role = window.userRole || (isAdminContext() ? 'admin' : 'staff');
        return fetch(`/${role}/attachments/upload`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': window.csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: fd
        })
        .then(response => response.json().catch(() => ({})))
        .then(data => {
            if (!data.success) throw new Error((data && data.message) || 'Attachment upload failed');
            return data;
        });
    }

    function showErrorMessage(message) {
        if (window.showToast && typeof window.showToast === 'function') {
            window.showToast(message, 'error', 3000);
        } else {
            alert('Error: ' + message);
        }
    }

    // Reset display fields whenever the modal opens (create OR edit —
    // the open*Modal() functions above set field values AFTER this fires
    // is not guaranteed, so deadline display refresh stays here, and
    // field population stays in the open*Modal functions themselves)
    document.getElementById('requestExtensionModal').addEventListener('show.bs.modal', function() {
        // Use the MORE SPECIFIC selector — data-project-id alone may match
        // a different wrapper element elsewhere on the page that doesn't
        // carry the deadline data attributes. This must stay scoped to
        // the extensions-section div specifically.
        let projectData = document.querySelector('[data-project-current-deadline]');
        let currentDeadline = null;
        let originalDeadline = null;

        if (projectData) {
            currentDeadline = projectData.dataset.projectCurrentDeadline;
            originalDeadline = projectData.dataset.projectOriginalDeadline;
        }

        if (currentDeadline && originalDeadline) {
            updateModalDeadlines(currentDeadline, originalDeadline);
            // Edit mode already set newEndDate.value before this event
            // fired, but .value assignment doesn't trigger 'change' — so
            // duration must be recalculated explicitly here, now that
            // currentDeadlineDisplay text is finally populated too.
            window.recalculateExtensionDuration();
        }
    });

    function updateModalDeadlines(currentDeadline, originalDeadline) {
        document.getElementById('currentDeadlineDisplay').textContent = currentDeadline;
        document.getElementById('originalDeadlineDisplay').textContent = originalDeadline;

        // Edit mode may keep a date equal to the live deadline (the active
        // approved extension IS the deadline) — so min = deadline. New
        // requests (create/resubmit) must be strictly after — min = +1 day.
        const isEditing = document.getElementById('extensionEditMode').value === 'edit';
        let minDate = new Date(currentDeadline);
        if (isNaN(minDate.getTime())) {
            console.warn('Could not parse date:', currentDeadline);
            return;
        }

        if (!isEditing) {
            minDate.setDate(minDate.getDate() + 1);
        }
        const minDateString = minDate.getFullYear() + '-' +
                            String(minDate.getMonth() + 1).padStart(2, '0') + '-' +
                            String(minDate.getDate()).padStart(2, '0');
        newEndDateInput.min = minDateString;
    }
});
</script>

<style>
#requestExtensionModal .form-label {
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 0.5rem;
}

#requestExtensionModal .form-control:focus,
#requestExtensionModal .form-control.is-valid:focus,
#requestExtensionModal .form-control.is-invalid:focus {
    border-color: #17a2b8;
    box-shadow: 0 0 0 0.2rem rgba(23, 162, 184, 0.25);
}

#requestExtensionModal .is-valid {
    border-color: #198754;
}

#requestExtensionModal .is-invalid {
    border-color: #dc3545;
}

#requestExtensionModal .alert {
    margin-bottom: 0.5rem;
}
</style>