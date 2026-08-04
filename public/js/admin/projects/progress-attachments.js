/**
 * Progress Attachments — shared helpers for the Record / Edit Progress modals.
 *
 * Mirrors the Phase C issues-modal.js attachment management (selected-files
 * preview, attachment chips, deferred edit deletion) but with a SINGLE context:
 * `inspection`. Progress evidence is always an inspection photo/PDF; there is no
 * issue-vs-resolution grouping.
 *
 * Flow (Phase C separate-endpoint pattern):
 *   1. The record is saved via its existing JSON endpoint (recordMonthlyProgress /
 *      editProgressRecord) — NO file fields ride along.
 *   2. After the save succeeds, each pending file is uploaded via the existing
 *      /{role}/attachments/upload endpoint with
 *      attachable_type=App\Models\ProjectProgressHistory + context='inspection'.
 *   3. Edit-modal removals are queued and flushed to the server only after a
 *      successful update (deferred deletion — cancelling keeps them intact).
 *
 * Dependencies:
 *   - window.projectId, window.userRole, window.csrfToken (project_detail.blade.php)
 *   - window._progressRecordData (baseline-schedule.blade.php / progress.blade.php)
 *   - showDeleteConfirmation() from components/delete-confirmation-modal.blade.php
 *   - window.openDocumentViewer() from components/document-viewer-modal.blade.php
 *   - showToast() from toast.blade.php
 */

window._progressAttachments = window._progressAttachments || {};

// ==== FILE-TYPE HELPERS ====

function getAttachmentIcon(filename) {
    var ext = (filename || '').split('.').pop().toLowerCase();
    var map = {
        pdf: 'bi-file-earmark-pdf',
        jpg: 'bi-file-earmark-image',
        jpeg: 'bi-file-earmark-image',
        png: 'bi-file-earmark-image',
        gif: 'bi-file-earmark-image',
        webp: 'bi-file-earmark-image',
        svg: 'bi-file-earmark-image'
    };
    return map[ext] || 'bi-file-earmark-text';
}

/** Human-readable byte size, e.g. "845 KB" / "2.3 MB" (mirrors Attachment::display_size). */
function formatBytes(bytes) {
    bytes = parseFloat(bytes) || 0;
    if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    return (bytes / 1024).toFixed(1) + ' KB';
}

/** Pretty-print an ISO/raw created_at value for the document viewer header. */
function formatAttachmentDate(value) {
    if (!value) return '';
    var d = new Date(value);
    if (isNaN(d.getTime())) return String(value);
    return d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// ==== ATTACHMENT CHIPS ====

/**
 * Render read-only, clickable attachment chips (view via document viewer).
 * Stores each attachment in window._progressAttachments for the opener.
 */
function renderAttachmentChips(attachments) {
    return (attachments || []).map(function(att) {
        window._progressAttachments[att.id] = att;
        return '<span class="attachment-chip me-1 mb-1" title="' + escapeHtml(att.original_name) + '"' +
               ' onclick="openProgressAttachmentById(' + att.id + ')">' +
               '<i class="bi ' + getAttachmentIcon(att.original_name) + '"></i>' +
               '<span>' + escapeHtml(att.original_name) + '</span>' +
               '</span>';
    }).join('');
}

/**
 * Open the document viewer for a progress attachment using role-scoped routes.
 * Type label is always "Inspection Evidence" (single-context design).
 */
function openProgressAttachmentById(id) {
    var att = window._progressAttachments[id];
    if (!att || typeof openDocumentViewer !== 'function') return;
    var role = window.userRole;
    openDocumentViewer({
        id: att.id,
        name: att.original_name,
        type: 'Inspection Evidence',
        size: att.display_size || formatBytes(att.file_size),
        uploadedBy: att.uploaded_by || '',
        uploadedAt: formatAttachmentDate(att.created_at),
        viewUrl: '/' + role + '/attachments/' + att.id + '/view',
        downloadUrl: '/' + role + '/attachments/' + att.id + '/download'
    });
}

// ==== PENDING FILE SELECTION (multi-file) ====
// Files the user has picked but not yet uploaded are accumulated per input id.
// A later pick APPENDS to earlier picks instead of replacing them (browser default).

window._pendingProgressFiles = window._pendingProgressFiles || {};

function getPendingFiles(inputId) {
    if (!window._pendingProgressFiles[inputId]) window._pendingProgressFiles[inputId] = [];
    return window._pendingProgressFiles[inputId];
}

function addPendingFiles(inputId, fileList) {
    var pending = getPendingFiles(inputId);
    Array.from(fileList || []).forEach(function(file) {
        var dup = pending.some(function(p) { return p.file.name === file.name && p.file.size === file.size; });
        if (!dup) {
            pending.push({
                file: file,
                url: file.type && file.type.startsWith('image/') ? URL.createObjectURL(file) : null
            });
        }
    });
    var input = document.getElementById(inputId);
    if (input) input.value = '';
    renderPendingFiles(inputId);
}

function removePendingFile(inputId, index) {
    var pending = getPendingFiles(inputId);
    var removed = pending.splice(index, 1)[0];
    if (removed && removed.url) URL.revokeObjectURL(removed.url);
    renderPendingFiles(inputId);
}

function clearPendingFiles(inputId) {
    getPendingFiles(inputId).forEach(function(p) { if (p.url) URL.revokeObjectURL(p.url); });
    window._pendingProgressFiles[inputId] = [];
    var container = document.getElementById(inputId + 'Preview');
    if (container) container.innerHTML = '';
    var input = document.getElementById(inputId);
    if (input) input.value = '';
}

function renderPendingFiles(inputId) {
    var container = document.getElementById(inputId + 'Preview');
    if (!container) return;
    var pending = getPendingFiles(inputId);
    if (pending.length === 0) {
        container.innerHTML = '';
        return;
    }
    container.innerHTML = pending.map(function(p, i) {
        return '<span class="attachment-chip edit-chip me-1 mb-1" style="cursor: default;" title="' + escapeHtml(p.file.name) + '">' +
            '<span style="display: inline-flex; align-items: center; gap: 5px;">' +
                (p.url
                    ? '<img src="' + p.url + '" alt="" style="width: 22px; height: 22px; object-fit: cover; border-radius: 3px;">'
                    : '<i class="bi ' + getAttachmentIcon(p.file.name) + '"></i>') +
                '<span class="att-name">' + escapeHtml(p.file.name) + '</span>' +
            '</span>' +
            '<button type="button" class="attachment-remove-btn" title="Remove file"' +
                ' onclick="removePendingFile(\'' + inputId + '\', ' + i + ')">' +
                '<i class="bi bi-x-circle-fill"></i>' +
            '</button>' +
        '</span>';
    }).join('');
}

function collectPendingFiles(inputId) {
    return getPendingFiles(inputId).map(function(p) { return p.file; });
}

// ==== UPLOAD (via the existing /{role}/attachments/upload endpoint) ====

/**
 * Upload one file bound to the given progress record (context 'inspection').
 * Throws on failure so the caller can surface the real message.
 */
function uploadProgressAttachment(recordId, file) {
    var fd = new FormData();
    fd.append('project_id', window.projectId);
    fd.append('attachable_type', 'App\\Models\\ProjectProgressHistory');
    fd.append('attachable_id', recordId);
    fd.append('context', 'inspection');
    fd.append('file', file);
    var role = window.userRole;
    return fetch('/' + role + '/attachments/upload', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': window.csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: fd
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (!data.success) {
            throw new Error((data && data.message) || 'Attachment upload failed (inspection)');
        }
        return data;
    })
    .catch(function(error) {
        console.error('Attachment upload error (inspection):', error);
        throw error;
    });
}

/** Upload all pending files for a record; resolves when every file is stored. */
function uploadProgressFiles(recordId, files) {
    return Promise.all((files || []).map(function(file) {
        return uploadProgressAttachment(recordId, file);
    }));
}

// ==== EDIT MODAL: EXISTING ATTACHMENTS LIST ====

/**
 * Render the existing attachments of a record inside the Edit Progress modal.
 * Reads from window._progressRecordData[id].attachments, excluding any ids
 * queued for deletion. NEVER mutates the cached array — a cancelled edit
 * session leaves the source data intact.
 */
function renderEditProgressAttachmentList(record) {
    var container = document.getElementById('editProgressAttachmentList');
    if (!container) return;

    var pending = window._pendingDeleteAttachmentIds || [];
    var attachments = ((record && record.attachments) || []).filter(function(a) {
        return pending.indexOf(a.id) === -1;
    });

    if (attachments.length === 0) {
        container.innerHTML = '<small class="text-muted">No attachments yet.</small>';
        return;
    }

    container.innerHTML = attachments.map(function(att) {
        window._progressAttachments[att.id] = att;
        return '<div class="attachment-chip edit-chip me-1 mb-1" style="cursor: default;" title="' + escapeHtml(att.original_name) + '">' +
            '<span class="att-name" onclick="openProgressAttachmentById(' + att.id + ')" title="View attachment"' +
                ' style="cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">' +
                '<i class="bi ' + getAttachmentIcon(att.original_name) + '"></i>' +
                '<span>' + escapeHtml(att.original_name) + '</span>' +
            '</span>' +
            '<button type="button" class="attachment-remove-btn" title="Remove attachment"' +
                ' onclick="deleteProgressAttachment(' + att.id + ')">' +
                '<i class="bi bi-x-circle-fill"></i>' +
            '</button>' +
        '</div>';
    }).join('');
}

// ==== EDIT MODAL: DEFERRED DELETION ====
// Removals are queued and visually removed immediately, but no DELETE request
// fires until the user clicks "Update". Cancelling/closing keeps them intact.

window._pendingDeleteAttachmentIds = [];

function deleteProgressAttachment(attachmentId) {
    if (typeof showDeleteConfirmation !== 'function') {
        showErrorMessage('Delete confirmation component is not available');
        return;
    }
    showDeleteConfirmation(
        'Remove Attachment',
        'This attachment will be removed when you save the progress record. It will not be deleted unless you click "Update".',
        function() { queueProgressAttachmentForDeletion(attachmentId); }
    );
}

function queueProgressAttachmentForDeletion(attachmentId) {
    if (window._pendingDeleteAttachmentIds.indexOf(attachmentId) === -1) {
        window._pendingDeleteAttachmentIds.push(attachmentId);
    }
    // Re-render from the cache (renderer filters queued ids) — cache untouched.
    var recordId = document.getElementById('editProgressRecordId')?.value;
    if (recordId && window._progressRecordData && window._progressRecordData[recordId]) {
        renderEditProgressAttachmentList(window._progressRecordData[recordId]);
    }
    delete window._progressAttachments[attachmentId];
}

/**
 * Delete all queued attachments on the server. Called only after the record
 * update has been persisted successfully — never on a plain "remove" click.
 */
function flushPendingAttachmentDeletions() {
    var toDelete = window._pendingDeleteAttachmentIds.slice();
    if (toDelete.length === 0) return Promise.resolve();

    var role = window.userRole;
    var requests = toDelete.map(function(attachmentId) {
        return fetch('/' + role + '/attachments/' + attachmentId, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': window.csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (!data.success) {
                throw new Error((data && data.message) || 'Failed to delete attachment #' + attachmentId);
            }
        });
    });

    window._pendingDeleteAttachmentIds = [];
    return Promise.all(requests);
}

// ==== UTILITIES ====

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to show success message with toast
function showSuccessMessage(message) {
    if (window.showToast && typeof window.showToast === 'function') {
        window.showToast(message, 'success', 2500);
    } else {
        console.warn('showToast not available, using alert');
        alert('Success: ' + message);
    }
}

// Helper function to show error message with toast
function showErrorMessage(message) {
    if (window.showToast && typeof window.showToast === 'function') {
        window.showToast(message, 'error', 3000);
    } else {
        console.warn('showToast not available, using alert');
        alert('Error: ' + message);
    }
}

// Accumulate multiple file picks on every progress file input (record + edit modals).
// The inputs live inside modals that are always present on project_detail.blade.php,
// so a DOMContentLoaded wire-up is safe; absent inputs are skipped gracefully.
document.addEventListener('DOMContentLoaded', function() {
    ['progressAttachmentFiles', 'editProgressAttachmentFiles'].forEach(function(inputId) {
        var input = document.getElementById(inputId);
        if (input) {
            input.addEventListener('change', function() {
                addPendingFiles(inputId, this.files);
            });
        }
    });
});
