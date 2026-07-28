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
    
    // Add event listeners
    const addBtn = document.getElementById('addDocumentBtn');
    if (addBtn) {
        addBtn.addEventListener('click', handleAddDocument);
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
 * Handle "Add Document" button click
 * Validates file immediately when user clicks "Add Document"
 */
function handleAddDocument(e) {
    e.preventDefault();
    
    const fileInput = document.getElementById('documentFileInput');
    const typeSelect = document.getElementById('documentTypeSelect');
    const file = fileInput.files[0];
    const typeId = typeSelect.value;
    
    // === VALIDATION WHEN ADDING TO PENDING LIST ===
    
    // 1. Check if file is selected
    if (!file) {
        showDocumentError('Please select a file before clicking Add Document');
        return;
    }
    
    // 2. Check if document type is selected
    if (!typeId) {
        showDocumentError('Please select a document type before clicking Add Document');
        return;
    }
    
    // 3. Check file size (1MB for testing - change to 20480 for 20MB in production)
    const maxSizeKB = 20480; // 1MB(1024) for testing - change to 20480 for 20MB in production
    const maxSizeBytes = maxSizeKB * 1024;
    
    if (file.size === 0) {
        showDocumentError('File is empty. Please select a valid file');
        return;
    }
    
    if (file.size > maxSizeBytes) {
        const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
        const maxSizeMB = (maxSizeBytes / (1024 * 1024)).toFixed(2);
        showDocumentError(`File size (${fileSizeMB}MB) exceeds maximum limit of ${maxSizeMB}MB`);
        return;
    }
    
    // 4. Validate file type
    const allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
    const fileExtension = file.name.split('.').pop().toLowerCase();
    
    if (!allowedExtensions.includes(fileExtension)) {
        showDocumentError(`File type ".${fileExtension}" is not allowed. Accepted types: ${allowedExtensions.join(', ')}`);
        return;
    }
    
    // 5. Check for duplicate files (same filename in pending list)
    const isDuplicate = pendingDocuments.some(doc => doc.fileName === file.name);
    if (isDuplicate) {
        showDocumentError(`File "${file.name}" has already been added to the pending list`);
        return;
    }
    
    // Get the type name
    const typeOption = typeSelect.options[typeSelect.selectedIndex];
    const typeName = typeOption.text;
    
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
    
    // Update UI
    displayPendingDocuments();
    
    // Reset form
    fileInput.value = '';
    typeSelect.value = '';
}

/**
 * Display pending documents in the table
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
        <tr>
            <td>
                <i class="bi bi-file"></i> ${doc.fileName}
            </td>
            <td>
                <span class="badge bg-info">${doc.typeName}</span>
            </td>
            <td>
                <small class="text-muted">${doc.fileSize}</small>
            </td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeDocument('${doc.id}')">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `).join('');
}

/**
 * Remove a document from pending list
 */
function removeDocument(docId) {
    pendingDocuments = pendingDocuments.filter(doc => doc.id !== docId);
    displayPendingDocuments();
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