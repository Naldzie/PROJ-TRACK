<!-- Reusable Warning Confirmation Modal --}}
{{-- NOTE: z-index ensures this modal appears above any stacked modals (e.g. setup baseline modal). --}}
<div class="modal fade" id="warningConfirmModal" tabindex="-1" aria-hidden="true" style="z-index: 1080;">
    <div class="modal-dialog">
        <div class="modal-content" style="border: none; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
            <!-- Header -->
            <div class="modal-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; border: none; padding: 20px;">
                <div style="display: flex; align-items: center; gap: 12px; width: 100%;">
                    <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-exclamation-triangle-fill" style="font-size: 22px; color: white;"></i>
                    </div>
                    <h5 class="modal-title" id="warningModalTitle" style="margin: 0; font-weight: 700; font-size: 18px;">Warning</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" onclick="closeWarningConfirmModal()" aria-label="Close" style="filter: brightness(0.8);"></button>
            </div>

            <!-- Body -->
            <div class="modal-body" style="padding: 24px; font-size: 15px; color: #333;">
                <p id="warningModalMessage" style="margin: 0; line-height: 1.6;"></p>
            </div>

            <!-- Footer -->
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e5e7eb; gap: 12px;">
                <button type="button" class="btn" onclick="closeWarningConfirmModal()" style="background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; font-weight: 600; padding: 10px 24px; border-radius: 6px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                    Cancel
                </button>
                <button type="button" id="warningConfirmBtn" class="btn" onclick="confirmWarningAction()" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; border: none; font-weight: 600; padding: 10px 24px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='linear-gradient(135deg, #d97706, #b45309)'" onmouseout="this.style.background='linear-gradient(135deg, #f59e0b, #d97706)'">
                    <i class="bi bi-check-circle" style="font-size: 14px;"></i>
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Global warning handler callback
let warningActionCallback = null;

/** Cached Bootstrap Modal instance — created once, reused across calls. */
let warningConfirmModalInstance = null;

/**
 * Show a warning confirmation dialog with amber theme.
 * @param {string} title - Modal title text
 * @param {string} message - HTML message body
 * @param {function} onConfirm - Callback when user clicks Confirm (sync or Promise)
 */
function showWarningConfirm(title, message, onConfirm) {
    document.getElementById('warningModalTitle').textContent = title;
    document.getElementById('warningModalMessage').innerHTML = message;
    warningActionCallback = onConfirm;

    // Lazy-create and cache the Bootstrap Modal instance
    if (!warningConfirmModalInstance) {
        warningConfirmModalInstance = new bootstrap.Modal(
            document.getElementById('warningConfirmModal'),
            { backdrop: 'static', keyboard: false }
        );
    }
    warningConfirmModalInstance.show();
}

function confirmWarningAction() {
    if (!warningActionCallback) {
        closeWarningConfirmModal();
        return;
    }

    var btn = document.getElementById('warningConfirmBtn');
    var originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Confirming&hellip;';

    var result;
    try {
        result = warningActionCallback();
    } catch (e) {
        closeWarningConfirmModal();
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        return;
    }

    if (result && typeof result.then === 'function') {
        result.then(function() {
            closeWarningConfirmModal();
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }).catch(function() {
            closeWarningConfirmModal();
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
    } else {
        closeWarningConfirmModal();
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

function closeWarningConfirmModal() {
    if (warningConfirmModalInstance) {
        warningConfirmModalInstance.hide();
    }
}
</script>
