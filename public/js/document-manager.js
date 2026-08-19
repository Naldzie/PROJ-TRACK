/**
 * === DOCUMENT MANAGER ===
 * Handles dynamic document addition and removal in the create project modal
 * 
 * Maintains a list of pending documents that will be uploaded when the form is saved.
 */

// Store pending documents as FormData entries.
// Use getPendingDocuments() to read this list from other scripts.
let pendingDocuments = [];

/**
 * Public accessor used by document-parser.js for autofill.
 * Always returns the current array, even after reassignment.
 */
function getPendingDocuments() { return pendingDocuments; }

// Initialize document manager when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializeDocumentManager();
});

function initializeDocumentManager() {
    // Load document types if empty
    loadDocumentTypes();
    
    // Files are added automatically when selected — no "Add Document" button.
    // The document type must be chosen FIRST (see handleDocumentFileSelected).
    const fileInput = document.getElementById('documentFileInput');
    if (fileInput) {
        fileInput.addEventListener('change', handleDocumentFileSelected);
    }
}

/**
 * Load document types from API
 */
function loadDocumentTypes() {
    const select = document.getElementById('documentTypeSelect');
    
    // If already has options (from backend), don't fetch
    if (select.options.length > 1) {
        return;
    }
    
    fetch('/api/document-types', {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': window._projectModalConfig?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.document_types) {
            select.innerHTML = '<option value="">-- Select Type --</option>';
            data.document_types.forEach(type => {
                const option = document.createElement('option');
                option.value = type.id;
                option.textContent = type.name;
                select.appendChild(option);
            });
        }
    })
    .catch(error => {
        console.error('Error loading document types:', error);
    });
}

/**
 * Handle automatic document addition when file(s) are selected.
 * The document type MUST be chosen first — if a file is picked without a
 * type, an error is shown and the input is cleared (strict order).
 */
function handleDocumentFileSelected() {
    const fileInput = document.getElementById('documentFileInput');
    const typeSelect = document.getElementById('documentTypeSelect');
    const files = fileInput.files;
    const typeId = typeSelect.value;

    // === STRICT ORDER: document type must be selected before any file ===
    if (!typeId) {
        showDocumentError('Please select a document type first, then choose the file(s).');
        fileInput.value = '';
        return;
    }

    if (!files || files.length === 0) return;

    // Get the type name (applies to every file in this selection)
    const typeOption = typeSelect.options[typeSelect.selectedIndex];
    const typeName = typeOption.text;

    // === VALIDATE EACH FILE ===
    const maxSizeBytes = 20480 * 1024; // 20MB
    const allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];

    for (const file of files) {
        const fileExtension = file.name.split('.').pop().toLowerCase();

        if (file.size === 0) {
            showDocumentError(`"${file.name}" is empty. Please select a valid file.`);
            continue;
        }

        if (file.size > maxSizeBytes) {
            const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
            showDocumentError(`"${file.name}" (${fileSizeMB}MB) exceeds the maximum limit of 20MB`);
            continue;
        }

        if (!allowedExtensions.includes(fileExtension)) {
            showDocumentError(`File type ".${fileExtension}" is not allowed. Accepted types: ${allowedExtensions.join(', ')}`);
            continue;
        }

        const isDuplicate = pendingDocuments.some(doc => doc.fileName === file.name);
        if (isDuplicate) {
            showDocumentError(`File "${file.name}" has already been added to the pending list`);
            continue;
        }

        // Add to pending documents
        const docId = 'doc_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        pendingDocuments.push({
            id: docId,
            file: file,
            typeId: typeId,
            typeName: typeName,
            fileName: file.name,
            fileSize: formatFileSize(file.size)
        });
    }

    // Update UI
    displayPendingDocuments();
    refreshSCurveImportButtonIfAvailable();

    // Reset the file input + type select so the next document requires a fresh type choice
    fileInput.value = '';
    typeSelect.value = '';
}

/**
 * Display pending documents as removable chips (mirrors the issues-modal
 * attachment upload style).
 */
function displayPendingDocuments() {
    const container = document.getElementById('documentsListContainer');
    const tbody = document.getElementById('documentsTableBody');
    
    if (pendingDocuments.length === 0) {
        container.classList.add('d-none');
        tbody.innerHTML = '';
        return;
    }
    
    container.classList.remove('d-none');
    
    tbody.innerHTML = pendingDocuments.map(doc => `
        <span class="doc-chip" title="${doc.fileName} (${doc.fileSize})">
            <span class="doc-chip-info">
                <i class="bi ${getDocumentIcon(doc.fileName)}"></i>
                <span class="doc-chip-name">${doc.fileName}</span>
                <span class="doc-chip-type">${doc.typeName}</span>
            </span>
            <button type="button" class="doc-chip-remove" title="Remove document"
                    onclick="removeDocument('${doc.id}')">
                <i class="bi bi-x-circle-fill"></i>
            </button>
        </span>
    `).join('');
}

/**
 * Pick an icon glyph for a file name based on its extension.
 */
function getDocumentIcon(filename) {
    const ext = (filename || '').split('.').pop().toLowerCase();
    const map = {
        pdf: 'bi-file-earmark-pdf',
        doc: 'bi-file-earmark-word',
        docx: 'bi-file-earmark-word',
        xls: 'bi-file-earmark-excel',
        xlsx: 'bi-file-earmark-excel',
        ppt: 'bi-file-earmark-ppt',
        pptx: 'bi-file-earmark-ppt',
        jpg: 'bi-file-earmark-image',
        jpeg: 'bi-file-earmark-image',
        png: 'bi-file-earmark-image'
    };
    return map[ext] || 'bi-file-earmark-text';
}

/**
 * Remove a document from pending list
 */
function removeDocument(docId) {
    pendingDocuments = pendingDocuments.filter(doc => doc.id !== docId);
    displayPendingDocuments();
    refreshSCurveImportButtonIfAvailable();
}

/**
 * Format file size to human readable format
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

/**
 * Show error message for document upload
 */
function showDocumentError(message) {
    // Try to use existing error display from modal
    const errorDiv = document.getElementById('createProjectError');
    if (errorDiv) {
        document.getElementById('createProjectErrorMessage').textContent = message;
        errorDiv.classList.remove('utility-none');
        errorDiv.className = 'alert alert-danger utility-none'; // Set to danger
        errorDiv.classList.remove('utility-none');
        
        // Auto hide after 5 seconds
        setTimeout(() => {
            errorDiv.classList.add('utility-none');
        }, 5000);
    } else {
        alert(message);
    }
}

/**
 * Get all pending documents as FormData entries
 * This is called by the form submission handler
 */
function getPendingDocumentsFormData() {
    const formData = new FormData();
    
    pendingDocuments.forEach((doc, index) => {
        // Use flat key structure that Laravel can parse
        formData.append(`pending_document_files[${index}]`, doc.file);
        formData.append(`pending_document_types[${index}]`, doc.typeId);
    });
    
    return formData;
}

/**
 * Clear all pending documents
 * Called when modal is closed or after successful save
 */
function clearPendingDocuments() {
    pendingDocuments = [];
    displayPendingDocuments();
    document.getElementById('documentFileInput').value = '';
    document.getElementById('documentTypeSelect').value = '';
}

/**
 * Refresh the S-curve import button visibility if the feature script is loaded.
 * Guarded so document-manager works standalone (e.g. the contractor issue
 * modal, which has no S-curve import).
 */
function refreshSCurveImportButtonIfAvailable() {
    if (typeof refreshSCurveImportButton === 'function') {
        refreshSCurveImportButton();
    }
}