{{--
    upload_document_modal.blade.php
    Standalone modal for uploading a document to an existing project.

    Usage — include inside project_detail.blade.php (outside the form, same level
    as the other modals):

        @can('update', $project)
            @include(
                'projects.project_detail.components.modals.upload_document_modal',
                ['documentTypes' => $documentTypes ?? []]
            )
        @endcan

    Dependencies already available on the page:
        window.projectId   – set in project_detail.blade.php
        window.csrfToken   – set in project_detail.blade.php
        window.userRole    – set in project_detail.blade.php
        window.showToast() – provided by @include('components.toast')

    Hits the EXISTING endpoint:
        POST /admin/documents/upload   (DocumentController@upload)
        POST /staff/documents/upload   (DocumentController@upload)
    with body fields: project_id, document_type_id, file, description
--}}

<div class="modal fade" id="uploadDocumentModal" tabindex="-1"
     aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="uploadDocumentModalLabel">
                    <i class="bi bi-cloud-upload" style="margin-right: 8px;"></i>
                    Upload Document
                </h5>
                <button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                {{-- Document type --}}
                <div class="mb-3">
                    <label for="uploadDocType" class="form-label">
                        Document Type <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <select id="uploadDocType" class="form-select" required>
                            <option value="">-- Select Type --</option>
                            @forelse($documentTypes ?? [] as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @empty
                                <option value="" disabled>No types configured</option>
                            @endforelse
                        </select>
                        @if(auth()->user()->role === 'admin')
                        <button class="btn btn-outline-secondary" type="button"
                                title="Manage document types"
                                onclick="openManageDocTypesFromUpload()">
                            <i class="bi bi-plus-circle"></i>
                        </button>
                        @endif
                    </div>
                    {{-- Inline error --}}
                    <div id="uploadDocTypeError"
                         style="display: none; margin-top: 5px; font-size: 12px;
                                color: #dc2626; align-items: center; gap: 5px;">
                        <i class="bi bi-exclamation-circle" style="flex-shrink: 0;"></i>
                        <span></span>
                    </div>
                </div>

                {{-- File --}}
                <div class="mb-3">
                    <label for="uploadDocFile" class="form-label">
                        File <span class="text-danger">*</span>
                    </label>
                    <input type="file" id="uploadDocFile" class="form-control"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                    <small class="text-muted">Max 20 MB · PDF, DOC, XLS, PPT, Images</small>
                    {{-- Inline error --}}
                    <div id="uploadDocFileError"
                         style="display: none; margin-top: 5px; font-size: 12px;
                                color: #dc2626; align-items: center; gap: 5px;">
                        <i class="bi bi-exclamation-circle" style="flex-shrink: 0;"></i>
                        <span></span>
                    </div>
                </div>

                {{-- Description --}}
                <div class="mb-3">
                    <label for="uploadDocDescription" class="form-label">Description</label>
                    <textarea id="uploadDocDescription" class="form-control" rows="2"
                              placeholder="Optional short description"></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary"
                        id="uploadDocSubmitBtn"
                        onclick="submitUploadDocument()">
                    <i class="bi bi-upload me-1"></i> Upload
                </button>
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    // ── Upload endpoint ───────────────────────────────────────────────────
    function uploadUrl() {
        const role = window.userRole ?? 'admin';
        return `/${role}/documents/upload`;
    }

    // ── Inline error helpers ──────────────────────────────────────────────
    // Show an error message under a specific field.
    // fieldErrorId: the id of the error <div> (e.g. 'uploadDocTypeError')
    // inputEl:      the corresponding input/select element (gets a red border)
    function showFieldError(fieldErrorId, message, inputEl) {
        const errorDiv = document.getElementById(fieldErrorId);
        if (!errorDiv) return;
        errorDiv.querySelector('span').textContent = message;
        errorDiv.style.display = 'flex';
        if (inputEl) {
            inputEl.style.borderColor = '#dc2626';
            inputEl.style.boxShadow   = '0 0 0 0.2rem rgba(220,38,38,0.15)';
        }
    }

    function clearFieldError(fieldErrorId, inputEl) {
        const errorDiv = document.getElementById(fieldErrorId);
        if (!errorDiv) return;
        errorDiv.querySelector('span').textContent = '';
        errorDiv.style.display = 'none';
        if (inputEl) {
            inputEl.style.borderColor = '';
            inputEl.style.boxShadow   = '';
        }
    }

    function clearAllFieldErrors() {
        clearFieldError('uploadDocTypeError', document.getElementById('uploadDocType'));
        clearFieldError('uploadDocFileError', document.getElementById('uploadDocFile'));
    }

    // Clear a field's error as soon as the user interacts with it
    document.getElementById('uploadDocType')?.addEventListener('change', function () {
        clearFieldError('uploadDocTypeError', this);
    });
    document.getElementById('uploadDocFile')?.addEventListener('change', function () {
        clearFieldError('uploadDocFileError', this);
    });

    // ── Reset form when modal closes ──────────────────────────────────────
    const modalEl = document.getElementById('uploadDocumentModal');
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', () => {
            document.getElementById('uploadDocType').value        = '';
            document.getElementById('uploadDocFile').value        = '';
            document.getElementById('uploadDocDescription').value = '';
            clearAllFieldErrors();
            resetBtn();

            // Always clean up after this modal closes — the manage doc types modal
            // stacks on top of this one, which leaves an extra modal-open class and
            // backdrop behind when it closes. Remove them unconditionally here.
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow    = '';
            document.body.style.paddingRight = '';
        });
    }

    // ── Button state helpers ──────────────────────────────────────────────
    function setLoading(on) {
        const btn = document.getElementById('uploadDocSubmitBtn');
        btn.disabled  = on;
        btn.innerHTML = on
            ? '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Uploading…'
            : '<i class="bi bi-upload me-1"></i> Upload';
    }

    function resetBtn() { setLoading(false); }

    // ── Open manage doc types modal (admin only) ──────────────────────────
    window.openManageDocTypesFromUpload = function () {
        const manageModal = document.getElementById('manageDocumentTypesModal');
        if (!manageModal) return;
        // Mark that we came from the upload modal so the manage modal
        // knows to refresh the dropdown (not reopen — we stay open underneath)
        manageModal.dataset.returnModal = 'uploadDocumentModal';
        new bootstrap.Modal(manageModal).show();
    };
    // ── Submit ────────────────────────────────────────────────────────────
    window.submitUploadDocument = function () {
        const typeEl = document.getElementById('uploadDocType');
        const fileEl = document.getElementById('uploadDocFile');
        const descEl = document.getElementById('uploadDocDescription');

        // Clear previous errors before re-validating
        clearAllFieldErrors();

        // ── Client-side validation (inline errors, not toast) ─────────────
        let valid = true;

        if (!typeEl.value) {
            showFieldError('uploadDocTypeError', 'Please select a document type.', typeEl);
            valid = false;
        }

        if (!fileEl.files || fileEl.files.length === 0) {
            showFieldError('uploadDocFileError', 'Please select a file to upload.', fileEl);
            valid = false;
        } else if (fileEl.files[0].size > 20 * 1024 * 1024) {
            showFieldError('uploadDocFileError', 'File size exceeds the 20 MB limit.', fileEl);
            valid = false;
        }

        if (!valid) return;   // stop here — errors are shown inline

        // ── All good — build request ──────────────────────────────────────
        const formData = new FormData();
        formData.append('project_id',       window.projectId);
        formData.append('document_type_id', typeEl.value);
        formData.append('file',             fileEl.files[0]);
        formData.append('description',      descEl.value.trim());

        setLoading(true);

        fetch(uploadUrl(), {
            method:  'POST',
            headers: {
                'X-CSRF-TOKEN': window.csrfToken,
                'Accept':       'application/json',
            },
            body: formData,
        })
        .then(async (res) => {
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                // Laravel validation errors → show inline on the right field if possible,
                // fall back to toast for anything that doesn't map to a field.
                if (json.errors) {
                    if (json.errors.document_type_id) {
                        showFieldError('uploadDocTypeError', json.errors.document_type_id[0], typeEl);
                    }
                    if (json.errors.file) {
                        showFieldError('uploadDocFileError', json.errors.file[0], fileEl);
                    }
                    // Any other server-side error → toast
                    const otherKeys = Object.keys(json.errors).filter(
                        k => k !== 'document_type_id' && k !== 'file'
                    );
                    if (otherKeys.length) {
                        throw new Error(json.errors[otherKeys[0]][0]);
                    }
                    setLoading(false);
                    return; // handled inline — do not continue
                }
                throw new Error(json.message || 'Upload failed. Please try again.');
            }
            if (!json.success) throw new Error(json.message || 'Upload failed.');
            return json;
        })
        .then((json) => {
            if (!json) return; // handled inline above (server validation error)

            // Success — hide modal, toast, reload back on documents tab
            bootstrap.Modal.getInstance(
                document.getElementById('uploadDocumentModal')
            )?.hide();

            showToast('Document uploaded successfully!', 'success', 2000);
            sessionStorage.setItem('activeTab', 'documents');
            setTimeout(() => location.reload(), 1500);
        })
        .catch((err) => {
            // Unexpected / server errors → toast (modal stays open)
            showToast(err.message || 'An unexpected error occurred.', 'error', 4000);
            setLoading(false);
        });
    };
}());
</script>