// ===================================================
// DOCUMENT UPLOAD HANDLING FOR PROJECT MODAL
// ===================================================

/**
 * Initialize document upload handlers
 * Called when the create/edit project modal is opened
 */
function initializeDocumentUploadHandlers() {
    const signedContractInput = document.getElementById('signedContractInput') || document.querySelector('input[name="signed_contract"]');
    const supportingDocsInput = document.getElementById('supportingDocsInput') || document.querySelector('input[name="supporting_documents[]"]');

    if (signedContractInput) {
        signedContractInput.addEventListener('change', handleSignedContractChange);
    }

    if (supportingDocsInput) {
        supportingDocsInput.addEventListener('change', handleSupportingDocumentsChange);
    }

    // Initial update on page load
    updateDocumentDisplays();
}

// ===================================================
// HANDLE SIGNED CONTRACT FILE SELECTION
// ===================================================
function handleSignedContractChange(event) {
    const file = event.target.files[0];
    const displayElement = document.getElementById('signedContractDisplay');

    if (!displayElement) return;

    if (!file) {
        displayElement.innerHTML = '<small class="text-muted">No file selected</small>';
        return;
    }

    // Validate file
    if (!validateFileUpload(file, 'pdf')) {
        event.target.value = ''; // Clear the input
        displayElement.innerHTML = '<small class="text-danger">Invalid file. Only PDF files are allowed.</small>';
        return;
    }

    // Display file info
    const fileName = file.name;
    const fileSize = formatFileSize(file.size);
    const createdDate = new Date(file.lastModified).toLocaleDateString();

    displayElement.innerHTML = `
        <div class="alert alert-info mb-0" role="alert">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small><i class="bi bi-file-pdf text-danger"></i> <strong>${escapeHtml(fileName)}</strong></small><br>
                    <small class="text-muted">${fileSize} • ${createdDate}</small>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearSignedContractFile()">
                    <i class="bi bi-x-circle"></i> Remove
                </button>
            </div>
        </div>
    `;
}

// ===================================================
// HANDLE SUPPORTING DOCUMENTS FILE SELECTION
// ===================================================
function handleSupportingDocumentsChange(event) {
    const files = event.target.files;
    const displayElement = document.getElementById('supportingDocumentsDisplay');

    if (!displayElement) return;

    if (!files || files.length === 0) {
        displayElement.innerHTML = '<small class="text-muted">No files selected</small>';
        return;
    }

    let validFiles = [];
    let errorMessages = [];

    // Validate each file
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        if (validateFileUpload(file)) {
            validFiles.push({
                name: file.name,
                size: file.size,
                date: new Date(file.lastModified).toLocaleDateString()
            });
        } else {
            errorMessages.push(`${file.name} - Invalid file type or size`);
        }
    }

    // Display results
    if (validFiles.length === 0) {
        displayElement.innerHTML = '<small class="text-danger">' + errorMessages.join('<br>') + '</small>';
        event.target.value = '';
        return;
    }

    let html = `<div class="alert alert-info mb-0" role="alert">`;
    html += `<small class="d-block mb-2"><strong>${validFiles.length} file(s) selected:</strong></small>`;
    html += `<ul class="list-unstyled mb-2" style="font-size: 0.85rem;">`;

    validFiles.forEach((file) => {
        html += `<li><i class="bi bi-file-text"></i> ${escapeHtml(file.name)} (${formatFileSize(file.size)}) • ${file.date}</li>`;
    });

    html += `</ul>`;
    if (errorMessages.length > 0) {
        html += `<small class="text-danger d-block mb-2">⚠️ ${errorMessages.length} file(s) were not added (invalid type/size)</small>`;
    }
    html += `<button type="button" class="btn btn-sm btn-outline-danger" onclick="clearSupportingDocumentsFiles()">
        <i class="bi bi-x-circle"></i> Remove All
    </button>`;
    html += `</div>`;

    displayElement.innerHTML = html;
}

// ===================================================
// CLEAR SIGNED CONTRACT FILE
// ===================================================
function clearSignedContractFile() {
    const input = document.querySelector('input[name="signed_contract"]');
    if (input) {
        input.value = '';
        input.dispatchEvent(new Event('change'));
    }
}

// ===================================================
// CLEAR SUPPORTING DOCUMENTS FILES
// ===================================================
function clearSupportingDocumentsFiles() {
    const input = document.querySelector('input[name="supporting_documents[]"]');
    if (input) {
        input.value = '';
        input.dispatchEvent(new Event('change'));
    }
}

// ===================================================
// UPDATE ALL DOCUMENT DISPLAYS
// ===================================================
function updateDocumentDisplays() {
    const signedContractInput = document.querySelector('input[name="signed_contract"]');
    const supportingDocsInput = document.querySelector('input[name="supporting_documents[]"]');

    if (signedContractInput) {
        const displayElement = document.getElementById('signedContractDisplay');
        if (displayElement) {
            if (signedContractInput.files && signedContractInput.files.length > 0) {
                signedContractInput.dispatchEvent(new Event('change'));
            } else {
                displayElement.innerHTML = '<small class="text-muted">No file selected</small>';
            }
        }
    }

    if (supportingDocsInput) {
        const displayElement = document.getElementById('supportingDocumentsDisplay');
        if (displayElement) {
            if (supportingDocsInput.files && supportingDocsInput.files.length > 0) {
                supportingDocsInput.dispatchEvent(new Event('change'));
            } else {
                displayElement.innerHTML = '<small class="text-muted">No files selected</small>';
            }
        }
    }
}

// ===================================================
// VALIDATE FILE UPLOAD
// ===================================================
function validateFileUpload(file, requiredType = null) {
    const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20MB
    const ALLOWED_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'image/jpeg',
        'image/png'
    ];

    // Check file size
    if (file.size > MAX_FILE_SIZE) {
        console.warn(`File ${file.name} exceeds maximum size of 20MB`);
        return false;
    }

    // If specific type required (e.g., 'pdf'), check that
    if (requiredType === 'pdf') {
        return file.type === 'application/pdf';
    }

    // Check allowed types
    if (!ALLOWED_TYPES.includes(file.type)) {
        console.warn(`File type ${file.type} not allowed`);
        return false;
    }

    return true;
}

// ===================================================
// FORMAT FILE SIZE
// ===================================================
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

// ===================================================
// ESCAPE HTML TO PREVENT XSS
// ===================================================
function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// ===================================================
// RESET DOCUMENT UPLOADS WHEN MODAL RESETS
// ===================================================
function resetDocumentUploads() {
    clearSignedContractFile();
    clearSupportingDocumentsFiles();
    updateDocumentDisplays();
}

// ===================================================
// INITIALIZE ON PAGE LOAD
// ===================================================
document.addEventListener('DOMContentLoaded', function() {
    initializeDocumentUploadHandlers();

    // Also initialize when modal is shown
    const modalElement = document.getElementById('addProjectModal');
    if (modalElement) {
        modalElement.addEventListener('show.bs.modal', function() {
            initializeDocumentUploadHandlers();
        });
    }
});
