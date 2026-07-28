<!-- === DOCUMENT TYPE MANAGEMENT MODAL === -->
<div class="modal fade" id="manageDocumentTypesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark"></i> Manage Document Types
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
 
                <!-- Add New Document Type Section -->
                <div class="mb-4 p-3 bg-light border rounded">
                    <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Add New Document Type</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" id="newDocumentTypeName" class="form-control"
                                   placeholder="Document type name" maxlength="255">
                            {{-- Inline error: same pattern as category modal --}}
                            <div id="docTypeNameError" class="invalid-feedback d-none" style="display:block!important;"></div>
                        </div>
                        <div class="col-md-6">
                            <button class="btn btn-primary w-100" onclick="addNewDocumentType()">
                                <i class="bi bi-plus"></i> Add
                            </button>
                        </div>
                    </div>
                    <textarea id="newDocumentTypeDescription" class="form-control form-control-sm mt-2"
                              rows="2" placeholder="Description (optional)"></textarea>
                </div>
 
                <!-- Document Types List -->
                <div>
                    <h6 class="mb-3"><i class="bi bi-list"></i> Existing Document Types</h6>
                    <div id="documentTypesList" class="list-group">
                        <!-- Document types will be loaded here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
 
<script>
// ===================================================
// SHARED HEADERS — includes Accept: application/json
// so Laravel always returns JSON, never an HTML page
// ===================================================
function docTypeHeaders() {
    return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    };
}
 
// ===================================================
// EXTRACT ERROR MESSAGE FROM LARAVEL RESPONSE
// ===================================================
function extractDocTypeError(data, fallback = 'Something went wrong') {
    if (data && data.errors) {
        const firstField = Object.values(data.errors)[0];
        if (Array.isArray(firstField) && firstField.length > 0) {
            return firstField[0];
        }
    }
    return (data && data.message) ? data.message : fallback;
}
 
// ===================================================
// INLINE ERROR — below the name input (same as category)
// ===================================================
function showDocTypeNameError(message) {
    const el = document.getElementById('docTypeNameError');
    if (!el) return;
    el.textContent = message;
    el.classList.remove('d-none');
    const input = document.getElementById('newDocumentTypeName');
    if (input) input.classList.add('is-invalid');
}
 
function clearDocTypeNameError() {
    const el = document.getElementById('docTypeNameError');
    if (el) {
        el.textContent = '';
        el.classList.add('d-none');
    }
    const input = document.getElementById('newDocumentTypeName');
    if (input) input.classList.remove('is-invalid');
}
 
 
// ===================================================
// LOAD ALL DOCUMENT TYPES FOR MANAGEMENT
// ===================================================
async function loadDocumentTypesForManagement() {
    try {
        const response = await fetch('{{ route("admin.document-types.all") }}', {
            headers: { 'Accept': 'application/json' }
        });
        const documentTypes = await response.json();
 
        const listContainer = document.getElementById('documentTypesList');
        listContainer.innerHTML = '';
 
        if (!documentTypes || documentTypes.length === 0) {
            listContainer.innerHTML = '<p class="text-muted">No document types yet. Add one above.</p>';
            return;
        }
 
        let html = '';
        documentTypes.forEach((docType, index) => {
            try {
                html += `
                    <div class="list-group-item d-flex justify-content-between align-items-center p-3 border-bottom">
                        <div style="flex: 1;">
                            <h6 class="mb-1"><i class="bi bi-file-earmark"></i> ${escapeHtmlDocType(docType.name)}</h6>
                            ${docType.description ? `<small class="text-muted">${escapeHtmlDocType(docType.description)}</small>` : ''}
                            <small class="text-muted d-block">${docType.documents_count || 0} document(s)</small>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary"
                                onclick="editDocumentType(${docType.id}, '${escapeHtmlDocType(docType.name)}', '${escapeHtmlDocType(docType.description || '')}')">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="deleteDocumentType(${docType.id}, '${escapeHtmlDocType(docType.name)}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            } catch (e) {
                console.error('Error rendering document type ' + index + ':', e, docType);
            }
        });
 
        listContainer.innerHTML = html;
    } catch (error) {
        console.error('Error loading document types:', error);
        showToast('Failed to load document types', 'error');
    }
}
 
// ===================================================
// ADD NEW DOCUMENT TYPE
// ===================================================
async function addNewDocumentType() {
    clearDocTypeNameError();
 
    const name = document.getElementById('newDocumentTypeName').value.trim();
    const description = document.getElementById('newDocumentTypeDescription').value.trim();
 
    if (!name) {
        showDocTypeNameError('Please enter a document type name');
        return;
    }
 
    try {
        const response = await fetch('{{ route("admin.document-types.store") }}', {
            method: 'POST',
            headers: docTypeHeaders(),
            body: JSON.stringify({ name, description })
        });
 
        let data = {};
        try {
            data = await response.json();
        } catch {
            // Laravel returned non-JSON (e.g. HTML error page)
        }
 
        console.log('Add document type response:', data);
 
        if (!response.ok) {
            showDocTypeNameError(extractDocTypeError(data, 'Failed to create document type'));
            return;
        }
 
        document.getElementById('newDocumentTypeName').value = '';
        document.getElementById('newDocumentTypeDescription').value = '';
        showToast('Document type added successfully', 'success');
 
        setTimeout(() => {
            loadDocumentTypesForManagement();
            if (typeof loadDocumentTypesForSelect === 'function') loadDocumentTypesForSelect();
        }, 300);
 
    } catch (error) {
        console.error('Error adding document type:', error);
        showDocTypeNameError('Network error — failed to add document type');
    }
}
 
// ===================================================
// EDIT DOCUMENT TYPE
// ===================================================
async function editDocumentType(docTypeId, name, description) {
    const newName = prompt('Enter new document type name:', name);
    if (newName === null) return;
 
    if (!newName.trim()) {
        showToast('Document type name cannot be empty', 'error');
        return;
    }
 
    try {
        const response = await fetch(`/admin/document-types/${docTypeId}`, {
            method: 'PUT',
            headers: docTypeHeaders(),
            body: JSON.stringify({ name: newName.trim(), description })
        });
 
        let data = {};
        try {
            data = await response.json();
        } catch {}
 
        console.log('Edit document type response:', data);
 
        if (!response.ok) {
            showToast(extractDocTypeError(data, 'Failed to update document type'), 'error');
            return;
        }
 
        showToast('Document type updated successfully', 'success');
        loadDocumentTypesForManagement();
        if (typeof loadDocumentTypesForSelect === 'function') loadDocumentTypesForSelect();
 
    } catch (error) {
        console.error('Error updating document type:', error);
        showToast('Network error — failed to update document type', 'error');
    }
}
 
// ===================================================
// DELETE DOCUMENT TYPE
// ===================================================
async function deleteDocumentType(docTypeId, docTypeName) {
    if (!confirm(`Delete document type "${docTypeName}"? This cannot be undone.`)) {
        return;
    }
 
    try {
        const response = await fetch(`/admin/document-types/${docTypeId}`, {
            method: 'DELETE',
            headers: docTypeHeaders()
        });
 
        let data = {};
        try {
            data = await response.json();
        } catch {}
 
        console.log('Delete document type status:', response.status, response.ok);
        console.log('Delete document type body:', data);
 
        if (!response.ok) {
            showToast(extractDocTypeError(data, 'Failed to delete document type'), 'error');
            return;
        }
 
        showToast(data.message || 'Document type deleted successfully', 'success');
        loadDocumentTypesForManagement();
        if (typeof loadDocumentTypesForSelect === 'function') loadDocumentTypesForSelect();
 
    } catch (error) {
        console.error('Error deleting document type:', error);
        showToast('Network error — failed to delete document type', 'error');
    }
}
 
// ===================================================
// LOAD DOCUMENT TYPES FOR SELECT DROPDOWN(S)
// Refreshes every document-type <select> on the page:
//   #documentTypeSelect  — inside the create/edit project modal
//   #uploadDocType       — inside the upload document modal
// ===================================================
async function loadDocumentTypesForSelect() {
    try {
        const response = await fetch('{{ route("admin.documents.types") }}', {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
 
        if (!data.success) {
            console.error('Failed to load document types for select');
            return;
        }
 
        // Refresh all selects that display document types
        ['documentTypeSelect', 'uploadDocType'].forEach(selectId => {
            const selectElement = document.getElementById(selectId);
            if (!selectElement) return;
 
            const currentValue = selectElement.value;
            selectElement.innerHTML = '<option value="">-- Select Type --</option>';
 
            data.document_types.forEach(type => {
                const option = document.createElement('option');
                option.value = type.id;
                option.textContent = type.name;
                selectElement.appendChild(option);
            });
 
            selectElement.value = currentValue;
        });
    } catch (error) {
        console.error('Error loading document types for select:', error);
    }
}
 
// ===================================================
// ESCAPE HTML
// ===================================================
function escapeHtmlDocType(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}
 
// Clear inline error when user starts typing
document.addEventListener('DOMContentLoaded', function () {
    const nameInput = document.getElementById('newDocumentTypeName');
    if (nameInput) {
        nameInput.addEventListener('input', clearDocTypeNameError);
    }
});
 
// Load document types when modal opens
document.addEventListener('show.bs.modal', function (e) {
    if (e.target.id === 'manageDocumentTypesModal') {
        clearDocTypeNameError();
        loadDocumentTypesForManagement();
    }
});
 
// Reopen the modal that triggered this one when it closes.
// The triggering code sets data-return-modal on #manageDocumentTypesModal
// before opening it (e.g. 'addProjectModal' or 'uploadDocumentModal').
// Falls back to 'addProjectModal' for backward compatibility.
document.addEventListener('hidden.bs.modal', function (e) {
    if (e.target.id === 'manageDocumentTypesModal') {
        const returnModalId = e.target.dataset.returnModal || 'addProjectModal';
        // Clear returnModal so it doesn't persist to next open
        e.target.dataset.returnModal = '';
        const returnModal = document.getElementById(returnModalId);
        if (!returnModal) return;

        // Stacking flow: return modal is still open underneath — just refresh dropdowns
        if (returnModal.classList.contains('show')) {
            if (typeof loadDocumentTypesForSelect === 'function') {
                loadDocumentTypesForSelect();
            }
            return;
        }

        // Chaining flow (create project modal): clean up and reopen
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow    = '';
        document.body.style.paddingRight = '';

        if (typeof loadDocumentTypesForSelect === 'function') {
            loadDocumentTypesForSelect().finally(() => {
                new bootstrap.Modal(returnModal).show();
            });
        } else {
            new bootstrap.Modal(returnModal).show();
        }
    }
});
</script>