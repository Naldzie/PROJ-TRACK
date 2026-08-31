<!-- Reusable Delete Confirmation Modal -->
{{-- NOTE: z-index ensures this modal appears above any stacked modals (e.g. setup baseline modal). --}}
<div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-hidden="true" style="z-index: 1080;">
    <div class="modal-dialog">
        <div class="modal-content" style="border: none; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
            <!-- Header -->
            <div class="modal-header" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; border: none; padding: 20px;">
                <div style="display: flex; align-items: center; gap: 12px; width: 100%;">
                    <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <svg style="width: 24px; height: 24px; color: white;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h5 class="modal-title" id="deleteModalTitle" style="margin: 0; font-weight: 700; font-size: 18px;">Delete Item</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" onclick="closeDeleteConfirmationModal()" aria-label="Close" style="filter: brightness(0.8);"></button>
            </div>
            
            <!-- Body -->
            <div class="modal-body" style="padding: 24px; font-size: 15px; color: #333;">
                <p id="deleteModalMessage" style="margin: 0; line-height: 1.6;"></p>
            </div>
            
            <!-- Footer -->
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb; gap: 12px;">
                <button type="button" class="btn" onclick="closeDeleteConfirmationModal()" style="background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; font-weight: 600; padding: 10px 24px; border-radius: 6px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                    Cancel
                </button>
                <button type="button" id="deleteConfirmBtn" class="btn" onclick="confirmDeleteAction()" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; font-weight: 600; padding: 10px 24px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='linear-gradient(135deg, #dc2626, #b91c1c)'" onmouseout="this.style.background='linear-gradient(135deg, #ef4444, #dc2626)'">
                    <svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                    Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Global delete handler callback
let deleteActionCallback = null;

/** Cached Bootstrap Modal instance — created once, reused across calls. */
let deleteConfirmationModalInstance = null

function showDeleteConfirmation(title, message, onConfirm) {
    document.getElementById('deleteModalTitle').textContent = title;
    document.getElementById('deleteModalMessage').innerHTML = message;
    deleteActionCallback = onConfirm;

    // Lazy-create and cache the Bootstrap Modal instance
    if (!deleteConfirmationModalInstance) {
        deleteConfirmationModalInstance = new bootstrap.Modal(
            document.getElementById('deleteConfirmationModal'),
            { backdrop: 'static', keyboard: false }
        );
    }
    deleteConfirmationModalInstance.show();
}

function confirmDeleteAction() {
    if (!deleteActionCallback) {
        closeDeleteConfirmationModal();
        return;
    }

    var btn = document.getElementById('deleteConfirmBtn');
    var originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Deleting&hellip;';

    var result;
    try {
        result = deleteActionCallback();
    } catch (e) {
        closeDeleteConfirmationModal();
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        return;
    }

    if (result && typeof result.then === 'function') {
        result.then(function() {
            closeDeleteConfirmationModal();
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }).catch(function() {
            closeDeleteConfirmationModal();
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
    } else {
        closeDeleteConfirmationModal();
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

function closeDeleteConfirmationModal() {
    if (deleteConfirmationModalInstance) {
        deleteConfirmationModalInstance.hide();
    }
}
</script>
