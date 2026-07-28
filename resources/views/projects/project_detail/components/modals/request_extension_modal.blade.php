<!-- Request / Edit Extension Modal -->
<div class="modal fade" id="requestExtensionModal" tabindex="-1" aria-labelledby="requestExtensionLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
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

                    <!-- Current Deadline Display -->
                    <div class="alert alert-light border-start border-info border-3 mb-3">
                        <div class="row g-3">
                            <div class="col-6">
                                <small class="text-muted d-block">Current Deadline</small>
                                <strong class="d-block text-info" id="currentDeadlineDisplay">--</strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Original Deadline</small>
                                <strong class="d-block text-secondary" id="originalDeadlineDisplay">--</strong>
                            </div>
                        </div>
                    </div>

                    <!-- New End Date Field -->
                    <div class="mb-3">
                        <label for="newEndDate" class="form-label">
                            <span class="text-danger">*</span> New End Date
                        </label>
                        <input
                            type="date"
                            class="form-control"
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

                    <!-- Duration Calculation Display -->
                    <div class="mb-3">
                        <div class="alert alert-light border-start border-success border-3 py-2 px-3 mb-0">
                            <small class="text-muted d-block mb-1">Extension Duration</small>
                            <strong class="text-success" id="durationDisplay">
                                <i class="fas fa-hourglass-end me-1"></i><span id="durationDays">0</span> days
                            </strong>
                        </div>
                    </div>

                    <!-- Reason Field -->
                    <div class="mb-3">
                        <label for="reason" class="form-label">
                            <span class="text-danger">*</span> Reason for Extension
                        </label>
                        <textarea
                            class="form-control"
                            id="reason"
                            name="reason"
                            rows="4"
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

                    <!-- Details Field (Optional) -->
                    <div class="mb-3">
                        <label for="details" class="form-label">Additional Details (Optional)</label>
                        <textarea
                            class="form-control"
                            id="details"
                            name="details"
                            rows="2"
                            placeholder="Any additional information about this extension..."
                        ></textarea>
                    </div>

                    <!-- Status Field — ADMIN ONLY. Hidden entirely for staff;
                         even if somehow shown, the backend ignores status
                         from a non-admin payload regardless of this UI. -->
                    <div class="mb-3" id="statusFieldWrapper" style="display: none;">
                        <label for="extensionStatus" class="form-label">Status</label>
                        <select class="form-control" id="extensionStatus" name="status" onchange="toggleRejectionReasonField()">
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <div class="form-text text-muted">
                            Setting this to "Approved" applies the new deadline to the project immediately.
                        </div>
                    </div>

                    <!-- Rejection Reason — ADMIN ONLY, only shown/required
                         when status is set to "Rejected". Staff needs this
                         to know what to fix before resubmitting. -->
                    <div class="mb-4" id="rejectionReasonWrapper" style="display: none;">
                        <label for="rejectionReason" class="form-label">
                            <span class="text-danger">*</span> Reason for Rejection
                        </label>
                        <textarea
                            class="form-control"
                            id="rejectionReason"
                            name="rejection_reason"
                            rows="3"
                            placeholder="Explain what needs to change before this request can be approved..."
                            minlength="5"
                        ></textarea>
                        <div class="form-text text-muted">
                            This will be shown to the staff member who submitted the request.
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


            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-info"
                    id="submitExtensionBtn"
                    onclick="submitExtension()"
                >
                    <i class="fas fa-check me-1"></i><span id="submitExtensionBtnLabel">Request Extension</span>
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
    document.getElementById('submitExtensionBtnLabel').textContent = isAdminContext() ? 'Create Extension' : 'Request Extension';
    document.getElementById('pendingNotice').style.display = 'none';
    document.getElementById('rejectionReasonNotice').style.display = 'none';
    document.getElementById('rejectionReasonWrapper').style.display = 'none';
    document.getElementById('rejectionReason').value = '';

    const statusWrapper = document.getElementById('statusFieldWrapper');
    statusWrapper.style.display = 'none'; // status is meaningless on create
    document.getElementById('durationDays').textContent = '0';
    document.getElementById('newEndDate').value = '';
    document.getElementById('newEndDate').classList.remove('is-valid', 'is-invalid');

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

    document.getElementById('newEndDate').value = extension.new_end_date;
    document.getElementById('reason').value = extension.reason || '';
    document.getElementById('details').value = extension.details || '';

    const statusWrapper = document.getElementById('statusFieldWrapper');
    const pendingNotice = document.getElementById('pendingNotice');
    const rejectionReasonWrapper = document.getElementById('rejectionReasonWrapper');
    const rejectionReasonNotice = document.getElementById('rejectionReasonNotice');

    if (isAdminContext()) {
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

    new bootstrap.Modal(document.getElementById('requestExtensionModal')).show();
};

document.addEventListener('DOMContentLoaded', function() {
    const newEndDateInput = document.getElementById('newEndDate');
    const reasonInput = document.getElementById('reason');
    const detailsInput = document.getElementById('details');
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

        const timeDiff = Math.abs(selectedDate - currentDeadline);
        const daysDiff = Math.ceil(timeDiff / (1000 * 60 * 60 * 24));
        durationDaysSpan.textContent = daysDiff;

        if (selectedDate <= currentDeadline) {
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

        if (!extensionForm.checkValidity()) {
            extensionForm.classList.add('was-validated');
            return;
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

        // Note: for edit-mode this still compares against the project's
        // CURRENT live deadline. Since only one pending extension can
        // exist at a time and approved ones aren't editable, this stays
        // correct for every editable case.
        if (newDate <= currentDate) {
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
            details: detailsInput.value || null
        };

        // Only admin context ever includes status, and only in edit mode.
        if (editMode === 'edit' && isAdminContext()) {
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

        const url = editMode === 'edit'
            ? window.buildExtensionsEndpoint(extensionId)
            : window.buildExtensionsEndpoint();
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
                    throw new Error(err.message || `HTTP Error: ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            showSuccessMessage(data.message || 'Extension saved successfully!');

            extensionForm.reset();
            extensionForm.classList.remove('was-validated');
            newEndDateInput.classList.remove('is-invalid', 'is-valid');
            reasonInput.classList.remove('is-invalid', 'is-valid');

            setTimeout(() => {
                modal.hide();
                if (window.reloadExtensionsTable) {
                    window.reloadExtensionsTable();
                }
                sessionStorage.setItem('activeTab', 's-curve-planning');
                location.reload();
            }, 1500);
        })
        .catch(error => {
            showErrorMessage(error.message || 'Failed to save extension. Please try again.');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    };

    function showSuccessMessage(message) {
        if (window.showToast && typeof window.showToast === 'function') {
            window.showToast(message, 'success', 2500);
        } else {
            alert('Success: ' + message);
        }
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

        let minDate = new Date(currentDeadline);
        if (isNaN(minDate.getTime())) {
            console.warn('Could not parse date:', currentDeadline);
            return;
        }

        minDate.setDate(minDate.getDate() + 1);
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