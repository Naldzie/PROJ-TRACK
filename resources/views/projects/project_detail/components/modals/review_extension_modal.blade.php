<!-- Review Extension Modal (Decision 13) — ADMIN ONLY.
     Dedicated approve/reject/partial-approval surface. The request is shown
     READ-ONLY so the admin decides on exactly what was submitted; the only
     thing sent back is the decision (status + optional granted date / reason).
     Reuses the existing admin update route via window.buildExtensionsEndpoint(). -->
<div class="modal fade" id="reviewExtensionModal" tabindex="-1" aria-labelledby="reviewExtensionLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: #312e81; color: white; padding: 12px 16px;">
                <h5 class="modal-title" id="reviewExtensionLabel">
                    <i class="fas fa-gavel me-2"></i>Review Extension Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <input type="hidden" id="reviewExtensionId" value="">
                <input type="hidden" id="reviewDecision" value="">

                <!-- Request Summary — read-only (approve exactly what was submitted) -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <strong style="font-size: 13px; color: #1e293b;">Extension <span style="color: #667eea;">#<span id="reviewExtensionNumber">—</span></span></strong>
                        <span id="reviewExtensionRequester" style="font-size: 12px; color: #64748b;"><i class="bi bi-person me-1"></i>Requested by —</span>
                    </div>
                    <div class="row g-2" style="font-size: 12px; color: #334155;">
                        <div class="col-4">
                            <small class="text-muted d-block">From (Original)</small>
                            <strong id="reviewFromDate" style="font-weight: 600;">—</strong>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">Requested To</small>
                            <strong id="reviewToDate" style="font-weight: 600; color: #0e7490;">—</strong>
                        </div>
                        <div class="col-4">
                            <small class="text-muted d-block">Days Requested</small>
                            <strong id="reviewDays" style="font-weight: 600; color: #f59e0b;">—</strong>
                        </div>
                        <div class="col-4 mt-2">
                            <small class="text-muted d-block">Category</small>
                            <strong id="reviewCategory" style="font-weight: 600;">—</strong>
                        </div>
                        <div class="col-4 mt-2">
                            <small class="text-muted d-block">Awaiting Review</small>
                            <strong id="reviewAging" style="font-weight: 600; color: #b45309;">—</strong>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted d-block">Reason</small>
                        <div id="reviewReason" style="background: white; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; font-size: 12px; color: #334155;">—</div>
                    </div>
                    <div class="mt-2" id="reviewEvidenceWrapper" style="display: none;">
                        <small class="text-muted d-block">Evidence</small>
                        <div id="reviewEvidence" style="font-size: 12px;"></div>
                    </div>
                </div>

                <!-- Decision Buttons (Decision 13) -->
                <div style="font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 8px;">DECISION</div>
                <div class="d-flex gap-2 mb-2">
                    <button type="button" id="reviewBtnApprove" onclick="setReviewDecision('approved')"
                            style="flex: 1; border: 1px solid #86efac; background: #dcfce7; color: #166534; padding: 9px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                        <i class="bi bi-check-lg me-1"></i>Approve as Requested
                    </button>
                    <button type="button" id="reviewBtnPartial" onclick="setReviewDecision('partial')"
                            style="flex: 1; border: 1px solid #c4b5fd; background: #ede9fe; color: #6d28d9; padding: 9px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                        <i class="bi bi-hourglass-split me-1"></i>Approve with Less Time
                    </button>
                    <button type="button" id="reviewBtnReject" onclick="setReviewDecision('rejected')"
                            style="flex: 1; border: 1px solid #fca5a5; background: #fee2e2; color: #991b1b; padding: 9px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                        <i class="bi bi-x-lg me-1"></i>Reject
                    </button>
                </div>

                <!-- Partial approval (Decision 7 / 13) — two-way: admin can
                     enter the granted date OR the number of approved days. -->
                <div class="mb-2" id="reviewPartialWrapper" style="display: none;">
                    <label class="form-label">
                        Approve with Less Time (Partial Approval)
                    </label>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="reviewGrantedDate" class="form-label">
                                Granted End Date
                            </label>
                            <input type="date" class="form-control form-control-sm" id="reviewGrantedDate"
                                   onchange="syncReviewPartialApproval('date')">
                        </div>
                        <div class="col-md-6">
                            <label for="reviewGrantedDays" class="form-label">
                                Additional Days Approved
                            </label>
                            <input type="number" class="form-control form-control-sm" id="reviewGrantedDays"
                                   min="1" step="1" placeholder="e.g. 14"
                                   oninput="syncReviewPartialApproval('days')">
                        </div>
                    </div>
                    <div class="form-text text-muted">
                        Grant LESS time than requested. Set the granted end date <strong>or</strong> type the
                        approved extra days — they stay in sync. Must be after the current deadline and not after
                        the requested end date. Leave unchanged to approve the full requested amount.
                    </div>
                    <div class="invalid-feedback" id="reviewGrantedDateError">
                        Granted date must be after the current deadline and not after the requested end date.
                    </div>
                </div>

                <!-- Rejection reason (Decision 13) -->
                <div class="mb-2" id="reviewRejectWrapper" style="display: none;">
                    <label for="reviewRejectionReason" class="form-label">
                        <span class="text-danger">*</span> Reason for Rejection
                    </label>
                    <textarea class="form-control form-control-sm" id="reviewRejectionReason" rows="2"
                              placeholder="Explain what needs to change before this request can be approved..." minlength="5"></textarea>
                    <div class="form-text text-muted">
                        This will be shown to the staff member who submitted the request.
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success btn-sm" id="reviewSubmitBtn" onclick="submitReviewExtension()" disabled>
                    <i class="fas fa-check me-1"></i>Submit Decision
                </button>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Parse a date string into a Date (local midnight). Supports Y-m-d and
 * "M d, Y" (the format used by the data attributes on the section wrapper).
 */
function reviewParseDate(dateString) {
    if (!dateString) return null;
    if (dateString instanceof Date) return dateString;
    if (dateString.includes('-') && dateString.length === 10) {
        const d = new Date(dateString + 'T00:00:00');
        if (!isNaN(d.getTime())) return d;
    }
    const d = new Date(dateString);
    if (!isNaN(d.getTime())) return d;
    return null;
}

function reviewFormatDate(dateString) {
    const d = reviewParseDate(dateString);
    if (!d) return '—';
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function reviewShowError(message) {
    if (window.showToast && typeof window.showToast === 'function') {
        window.showToast(message, 'error', 3000);
    } else {
        alert('Error: ' + message);
    }
}

let _reviewExtension = null;

/**
 * Open the Review modal for a pending extension (Decision 13).
 * `extension` is the shape from formatExtension().
 */
window.openReviewExtensionModal = function (extension) {
    _reviewExtension = extension;

    document.getElementById('reviewExtensionId').value = extension.id;
    document.getElementById('reviewExtensionNumber').textContent = extension.extension_number;
    document.getElementById('reviewExtensionRequester').innerHTML =
        `<i class="bi bi-person me-1"></i>Requested by ${extension.created_by || 'Unknown'}`;
    document.getElementById('reviewFromDate').textContent = reviewFormatDate(extension.original_end_date);
    document.getElementById('reviewToDate').textContent = reviewFormatDate(extension.new_end_date);
    document.getElementById('reviewDays').textContent = `+${extension.duration_days} days`;
    document.getElementById('reviewCategory').textContent = extension.reason_category || '—';
    document.getElementById('reviewAging').textContent = extension.days_awaiting_review > 0
        ? `${extension.days_awaiting_review} day(s)` : 'Today';
    document.getElementById('reviewReason').textContent = extension.reason || '—';

    const evidenceWrapper = document.getElementById('reviewEvidenceWrapper');
    const evidenceEl = document.getElementById('reviewEvidence');
    const attachments = extension.attachments || [];
    if (attachments.length) {
        evidenceEl.innerHTML = attachments.map(a =>
            `<a href="${a.file_url}" target="_blank" style="color:#4f46e5;text-decoration:none;font-size:12px;"><i class="bi bi-paperclip"></i> ${(a.original_name || a.file_url).replace(/"/g, '&quot;')}</a>`
        ).join('<br>');
        evidenceWrapper.style.display = 'block';
    } else {
        evidenceEl.innerHTML = '';
        evidenceWrapper.style.display = 'none';
    }

    // Reset decision state.
    setReviewDecision('');
    document.getElementById('reviewGrantedDate').value = '';
    document.getElementById('reviewGrantedDate').classList.remove('is-invalid', 'is-valid');
    document.getElementById('reviewGrantedDays').value = '';
    document.getElementById('reviewRejectionReason').value = '';
    document.getElementById('reviewRejectionReason').classList.remove('is-invalid', 'is-valid');
    document.getElementById('reviewSubmitBtn').disabled = true;

    // Prefill partial approval defaults: granted date = requested end date and
    // days = requested duration, so "Approve with Less Time" starts at the full
    // amount and the admin only lowers it (or leaves it for a full grant).
    _prefillReviewPartial();

    new bootstrap.Modal(document.getElementById('reviewExtensionModal')).show();
};

/**
 * Read the project's current (live) deadline from the extensions-section
 * data attributes, as a Date. Used to convert between granted date and
 * approved additional days.
 */
function reviewCurrentDeadline() {
    const projectData = document.querySelector('[data-project-current-deadline]');
    return reviewParseDate(projectData?.dataset.projectCurrentDeadline || null);
}

/**
 * Prefill the two-way partial inputs from the request under review:
 * granted date = requested end date, days = requested duration.
 */
function _prefillReviewPartial() {
    const ext = _reviewExtension;
    if (!ext) return;

    const grantedInput = document.getElementById('reviewGrantedDate');
    const daysInput = document.getElementById('reviewGrantedDays');

    grantedInput.value = ext.new_end_date || '';

    const current = reviewCurrentDeadline();
    const requested = reviewParseDate(ext.new_end_date);
    if (current) {
        grantedInput.min = current.getFullYear() + '-' +
            String(current.getMonth() + 1).padStart(2, '0') + '-' +
            String(current.getDate()).padStart(2, '0');
    }
    if (current && requested) {
        const days = Math.ceil((requested - current) / (1000 * 60 * 60 * 24));
        daysInput.value = days > 0 ? days : '';
    } else {
        daysInput.value = ext.duration_days || '';
    }
}

/**
 * Keep the granted date and the approved additional-days inputs in sync.
 * `from` is 'date' or 'days' — whichever field the admin changed.
 */
window.syncReviewPartialApproval = function (from) {
    const grantedInput = document.getElementById('reviewGrantedDate');
    const daysInput = document.getElementById('reviewGrantedDays');
    const current = reviewCurrentDeadline();
    const ext = _reviewExtension;
    const requested = reviewParseDate(ext?.new_end_date);
    const errorEl = document.getElementById('reviewGrantedDateError');

    if (from === 'days') {
        const days = parseInt(daysInput.value, 10);
        if (current && Number.isFinite(days) && days > 0) {
            const granted = new Date(current.getTime());
            granted.setDate(granted.getDate() + days);
            grantedInput.value = granted.getFullYear() + '-' +
                String(granted.getMonth() + 1).padStart(2, '0') + '-' +
                String(granted.getDate()).padStart(2, '0');
        } else if (!daysInput.value) {
            grantedInput.value = '';
        }
    } else {
        const granted = reviewParseDate(grantedInput.value);
        if (current && granted) {
            const days = Math.ceil((granted - current) / (1000 * 60 * 60 * 24));
            daysInput.value = days > 0 ? days : '';
        } else if (!grantedInput.value) {
            daysInput.value = '';
        }
    }

    // Inline validation, mirroring the backend rules.
    grantedInput.classList.remove('is-invalid');
    const granted = reviewParseDate(grantedInput.value);
    if (current && granted && granted < current) {
        grantedInput.classList.add('is-invalid');
        errorEl.textContent = 'Granted date must be after the current deadline.';
    } else if (requested && granted && granted > requested) {
        grantedInput.classList.add('is-invalid');
        errorEl.textContent = 'Granted date cannot be later than the requested end date.';
    }
};

/**
 * Set the current review decision and reveal the matching input. Buttons act
 * as a 1-of-3 choice: approved (as requested) / partial / rejected.
 */
window.setReviewDecision = function (decision) {
    document.getElementById('reviewDecision').value = decision;

    const btnStyles = {
        approved: ['#16a34a', '#dcfce7'],
        partial: ['#7c3aed', '#ede9fe'],
        rejected: ['#dc2626', '#fee2e2'],
    };
    const base = { approved: ['#dcfce7', '#166534'], partial: ['#ede9fe', '#6d28d9'], rejected: ['#fee2e2', '#991b1b'] };
    const apply = (btnId, key) => {
        const el = document.getElementById(btnId);
        const [bg, color] = decision === key ? btnStyles[key] : base[key];
        el.style.background = bg;
        el.style.color = color;
        el.style.boxShadow = decision === key ? '0 0 0 2px rgba(99,102,241,0.35)' : 'none';
    };
    apply('reviewBtnApprove', 'approved');
    apply('reviewBtnPartial', 'partial');
    apply('reviewBtnReject', 'rejected');

    document.getElementById('reviewPartialWrapper').style.display = decision === 'partial' ? 'block' : 'none';
    document.getElementById('reviewRejectWrapper').style.display = decision === 'rejected' ? 'block' : 'none';
    document.getElementById('reviewSubmitBtn').disabled = decision === '';
    document.getElementById('reviewSubmitBtn').innerHTML = decision === 'rejected'
        ? '<i class="fas fa-times me-1"></i>Reject Request'
        : (decision === 'partial'
            ? '<i class="fas fa-hourglass-split me-1"></i>Approve with Granted Date'
            : '<i class="fas fa-check me-1"></i>Approve Request');
};

/**
 * Submit the admin's decision via the existing admin update route.
 * Only the decision fields are sent — request content is never touched.
 */
window.submitReviewExtension = function () {
    const decision = document.getElementById('reviewDecision').value;
    if (!decision) {
        reviewShowError('Please choose a decision first.');
        return;
    }

    const ext = _reviewExtension;
    const formData = { status: decision };

    if (decision === 'rejected') {
        const reason = document.getElementById('reviewRejectionReason').value.trim();
        if (reason.length < 5) {
            document.getElementById('reviewRejectionReason').classList.add('is-invalid');
            reviewShowError('Please provide a reason for rejection (minimum 5 characters).');
            return;
        }
        formData.rejection_reason = reason;
    } else if (decision === 'partial') {
        const grantedValue = document.getElementById('reviewGrantedDate').value;
        const granted = reviewParseDate(grantedValue);
        const requested = reviewParseDate(ext.new_end_date);
        const current = reviewCurrentDeadline();
        const grantedInput = document.getElementById('reviewGrantedDate');

        if (!granted) {
            grantedInput.classList.add('is-invalid');
            document.getElementById('reviewGrantedDateError').textContent = 'Please choose a granted end date.';
            return;
        }
        if (current && granted < current) {
            grantedInput.classList.add('is-invalid');
            document.getElementById('reviewGrantedDateError').textContent = 'Granted date must be after the current deadline.';
            return;
        }
        if (granted > requested) {
            grantedInput.classList.add('is-invalid');
            document.getElementById('reviewGrantedDateError').textContent = 'Granted date cannot be later than the requested end date.';
            return;
        }
        grantedInput.classList.remove('is-invalid');
        formData.approved_new_end_date = grantedValue;
        formData.status = 'approved';
    }

    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        reviewShowError('Error: CSRF token not found. Please refresh the page and try again.');
        return;
    }

    const submitBtn = document.getElementById('reviewSubmitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';

    fetch(window.buildExtensionsEndpoint(ext.id), {
        method: 'PUT',
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
        if (window.queueToastAfterReload && typeof window.queueToastAfterReload === 'function') {
            window.queueToastAfterReload(data.message || 'Decision recorded', 'success', 2500);
        } else if (window.showToast) {
            window.showToast(data.message || 'Decision recorded', 'success', 2500);
        }
        setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('reviewExtensionModal'));
            if (modal) modal.hide();
            if (window.reloadExtensionsTable) window.reloadExtensionsTable();
            sessionStorage.setItem('activeTab', 's-curve-planning');
            location.reload();
        }, 600);
    })
    .catch(error => {
        reviewShowError(error.message || 'Failed to submit decision. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
};
</script>
