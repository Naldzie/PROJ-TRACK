<!-- Review Extension Modal — Admin or assigned staff can approve/reject
     pending extensions. The reviewer can optionally adjust the new_end_date
     before approving (no partial approval — approve uses the edited date). -->
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

                <!-- Decision Buttons -->
                <div style="font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 8px;">DECISION</div>
                <div class="d-flex gap-2 mb-2">
                    <button type="button" id="reviewBtnApprove" onclick="setReviewDecision('approved')"
                            style="flex: 1; border: 1px solid #86efac; background: #dcfce7; color: #166534; padding: 9px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                        <i class="bi bi-check-lg me-1"></i>Approve
                    </button>
                    <button type="button" id="reviewBtnReject" onclick="setReviewDecision('rejected')"
                            style="flex: 1; border: 1px solid #fca5a5; background: #fee2e2; color: #991b1b; padding: 9px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer;">
                        <i class="bi bi-x-lg me-1"></i>Reject
                    </button>
                </div>

                <!-- New End Date + Days (editable on approve, bidirectional sync) -->
                <div class="mb-2" id="reviewEndDateWrapper" style="display: none;">
                    <label class="form-label">Grant Extension</label>
                    <div class="row g-2">
                        <div class="col-5">
                            <label for="reviewGrantDays" class="form-label" style="font-size: 11px; color: #6b7280;">Days to Grant</label>
                            <input type="number" class="form-control form-control-sm" id="reviewGrantDays"
                                   min="1" step="1" placeholder="e.g. 30"
                                   onkeydown="if(event.key==='-'||event.key==='e'||event.key==='E'||event.key==='+')event.preventDefault();"
                                   oninput="if(this.value!==''&&Number(this.value)<1)this.value='';" />
                        </div>
                        <div class="col-7">
                            <label for="reviewNewEndDate" class="form-label" style="font-size: 11px; color: #6b7280;">New End Date</label>
                            <input type="date" class="form-control form-control-sm" id="reviewNewEndDate" />
                        </div>
                    </div>
                    <div class="form-text text-muted">
                        Enter days or pick a date — both fields sync automatically.
                    </div>
                </div>

                <!-- Rejection reason -->
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

/** Format a Date as Y-m-d for <input type="date">. */
function reviewToInputDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

let _reviewExtension = null;
let _reviewOriginalDate = null; // Date object of the extension's original_end_date

/**
 * Add days to a Date and return a new Date.
 */
function reviewAddDays(date, days) {
    const result = new Date(date);
    result.setDate(result.getDate() + days);
    return result;
}

/**
 * Compute the number of whole days between two dates.
 */
function reviewDaysBetween(a, b) {
    const msPerDay = 86400000;
    return Math.round((a.getTime() - b.getTime()) / msPerDay);
}

/**
 * Open the Review modal for a pending extension.
 * `extension` is the shape from formatExtension().
 */
window.openReviewExtensionModal = function (extension) {
    _reviewExtension = extension;
    _reviewOriginalDate = reviewParseDate(extension.original_end_date);

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
        window._extensionAttachments = window._extensionAttachments || {};
        evidenceEl.innerHTML = attachments.map(a => {
            window._extensionAttachments[a.id] = a;
            return `<span class="attachment-chip me-1 mb-1" title="${(a.original_name || '').replace(/"/g, '&quot;')}" onclick="openExtensionAttachmentById(${a.id})" style="cursor:pointer;color:#4f46e5;font-size:12px;font-weight:600;"><i class="bi bi-paperclip"></i> ${(a.original_name || 'file').replace(/"/g, '&quot;')}</span>`;
        }).join(' ');
        evidenceWrapper.style.display = 'block';
    } else {
        evidenceEl.innerHTML = '';
        evidenceWrapper.style.display = 'none';
    }

    // Reset decision state.
    setReviewDecision('');
    document.getElementById('reviewRejectionReason').value = '';
    document.getElementById('reviewRejectionReason').classList.remove('is-invalid', 'is-valid');

    // Pre-fill both fields from the requested new_end_date.
    const requestedDate = reviewParseDate(extension.new_end_date);

    // Set min on the date picker — must be strictly after the current deadline
    // (same rule as create/resubmit: no 0-day extensions).
    const dateInput = document.getElementById('reviewNewEndDate');
    const currentDeadline = reviewParseDate(extension.current_deadline);
    if (currentDeadline) {
        const minDate = reviewAddDays(currentDeadline, 1);
        dateInput.setAttribute('min', reviewToInputDate(minDate));
    }

    dateInput.value = extension.new_end_date || '';
    if (_reviewOriginalDate && requestedDate) {
        const days = reviewDaysBetween(requestedDate, _reviewOriginalDate);
        document.getElementById('reviewGrantDays').value = days > 0 ? days : '';
    } else {
        document.getElementById('reviewGrantDays').value = '';
    }

    document.getElementById('reviewSubmitBtn').disabled = true;

    new bootstrap.Modal(document.getElementById('reviewExtensionModal')).show();
};

/**
 * Set the current review decision and reveal the matching input. Buttons act
 * as a 1-of-2 choice: approved / rejected.
 */
window.setReviewDecision = function (decision) {
    document.getElementById('reviewDecision').value = decision;

    const btnStyles = {
        approved: ['#16a34a', '#dcfce7'],
        rejected: ['#dc2626', '#fee2e2'],
    };
    const base = { approved: ['#dcfce7', '#166534'], rejected: ['#fee2e2', '#991b1b'] };
    const apply = (btnId, key) => {
        const el = document.getElementById(btnId);
        const [bg, color] = decision === key ? btnStyles[key] : base[key];
        el.style.background = bg;
        el.style.color = color;
        el.style.boxShadow = decision === key ? '0 0 0 2px rgba(99,102,241,0.35)' : 'none';
    };
    apply('reviewBtnApprove', 'approved');
    apply('reviewBtnReject', 'rejected');

    document.getElementById('reviewRejectWrapper').style.display = decision === 'rejected' ? 'block' : 'none';
    document.getElementById('reviewEndDateWrapper').style.display = decision === 'approved' ? 'block' : 'none';
    document.getElementById('reviewSubmitBtn').disabled = decision === '';
    document.getElementById('reviewSubmitBtn').innerHTML = decision === 'rejected'
        ? '<i class="fas fa-times me-1"></i>Reject Request'
        : '<i class="fas fa-check me-1"></i>Approve Request';
};

/**
 * Bidirectional sync: when Days changes → update Date; when Date changes → update Days.
 */
(function initReviewSync() {
    const daysInput = document.getElementById('reviewGrantDays');
    const dateInput = document.getElementById('reviewNewEndDate');

    daysInput.addEventListener('input', function () {
        const days = parseInt(this.value, 10);
        if (!_reviewOriginalDate || isNaN(days) || days < 1) {
            dateInput.value = '';
            return;
        }
        const newDate = reviewAddDays(_reviewOriginalDate, days);
        dateInput.value = reviewToInputDate(newDate);
    });

    dateInput.addEventListener('change', function () {
        const picked = reviewParseDate(this.value);
        if (!_reviewOriginalDate || !picked) {
            daysInput.value = '';
            return;
        }
        const minAttr = this.getAttribute('min');
        const minDate = reviewParseDate(minAttr);
        if (minDate && picked < minDate) {
            this.value = minAttr;
            daysInput.value = reviewDaysBetween(minDate, _reviewOriginalDate) || '';
            return;
        }
        const days = reviewDaysBetween(picked, _reviewOriginalDate);
        daysInput.value = days > 0 ? days : '';
    });
})();

/**
 * Submit the reviewer's decision via the existing admin update route.
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

    if (decision === 'approved') {
        const newEndDate = document.getElementById('reviewNewEndDate').value.trim();
        if (newEndDate) {
            // Block 0-day or negative extensions — must be strictly after current deadline.
            const picked = reviewParseDate(newEndDate);
            const deadline = reviewParseDate(_reviewExtension.current_deadline);
            if (picked && deadline && picked <= deadline) {
                reviewShowError('New end date must be after the current deadline.');
                return;
            }
            formData.new_end_date = newEndDate;
        }
    }

    if (decision === 'rejected') {
        const reason = document.getElementById('reviewRejectionReason').value.trim();
        if (reason.length < 5) {
            document.getElementById('reviewRejectionReason').classList.add('is-invalid');
            reviewShowError('Please provide a reason for rejection (minimum 5 characters).');
            return;
        }
        formData.rejection_reason = reason;
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
