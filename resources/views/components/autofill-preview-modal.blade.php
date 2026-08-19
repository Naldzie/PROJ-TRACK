{{--
    AUTO-FILL PREVIEW MODAL
    ======================
    Shown by document-parser.js when project fields are extracted from the
    uploaded PDFs. Lists each field → extracted value (or "Not found") so the
    user can review BEFORE anything is written to the form. Apply fills via
    autofillFormFields() (per-field indicators appear under each input).
    Nothing is saved to the server from this modal.

    JS functions (document-parser.js): showAutofillPreview(), applyAutofillFromPreview()
--}}

<div class="modal fade" id="autofillPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border: none;">

            <!-- Header -->
            <div class="modal-header" style="background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%); color: white; border: none; padding: 16px 22px;">
                <h5 class="modal-title" style="font-weight: 700; display: flex; align-items: center; gap: 10px; margin: 0;">
                    <i class="bi bi-magic" style="font-size: 18px;"></i>
                    Auto-fill from Documents
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Body -->
            <div class="modal-body" style="padding: 20px 22px;">
                <p class="text-muted mb-3" style="font-size: 13px;">
                    The following values were read from your uploaded documents. You can correct any of them
                    right here, then click <strong>Apply to Form</strong> — you can still edit anything after.
                </p>

                <!-- OCR note: only shown when scanned pages were read with OCR -->
                <div id="autofillPreviewOcrNote" style="display: none; margin-bottom: 12px; padding: 8px 12px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; font-size: 12px; color: #92400e;">
                    <i class="bi bi-camera"></i> One or more pages were scanned images and read using OCR — text from scans is less accurate than typed text.
                </div>

                <!-- Extracted fields table -->
                <div style="border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden;">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr style="background: #f9fafb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280;">
                                <th scope="col" style="width: 35%; padding: 10px 14px;">Field</th>
                                <th scope="col" style="padding: 10px 14px;">Extracted value</th>
                            </tr>
                        </thead>
                        <tbody id="autofillPreviewRows" style="font-size: 13px;"></tbody>
                    </table>
                </div>
            </div>

            <!-- Footer -->
            <div class="modal-footer" style="padding: 14px 22px; border-top: 1px solid #e5e7eb; background: #f9fafb;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"
                        style="border-color: #d1d5db; font-size: 13px;">
                    Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="applyAutofillFromPreview()"
                        style="background: #4f46e5; border-color: #4f46e5; font-size: 13px;">
                    <i class="bi bi-check-lg" style="margin-right: 5px;"></i>Apply to Form
                </button>
            </div>

        </div>
    </div>
</div>