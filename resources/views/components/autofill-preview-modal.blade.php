{{--
    AUTO-FILL PREVIEW MODAL
    ======================
    Shown by document-parser.js when project fields are extracted from the
    uploaded PDFs. Lists each field → extracted value (or "Not found") so the
    user can review BEFORE anything is written to the form. Apply fills via
    autofillFormFields() (per-field indicators appear under each input).
    Nothing is saved to the server from this modal.

    LAYOUT (2026-09-18): the rows used to be a two-column TABLE — one field per
    row, every input stretched to the full width whether it held a title or a
    three-digit day count. It is now a responsive GRID filled by
    showAutofillPreview(): long text spans the full width, short fields pair up
    two-per-row, and the dialog is modal-xl so the pairs have room. The container
    id is unchanged (#autofillPreviewRows) — the JS owns what goes inside it.

    JS functions (document-parser.js): showAutofillPreview(), applyAutofillFromPreview()
--}}

<div class="modal fade" id="autofillPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 12px; border: none;">

            <!-- Header -->
            <div class="modal-header" style="background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%); color: white; border: none; padding: 14px 20px;">
                <h5 class="modal-title" style="font-weight: 700; display: flex; align-items: center; gap: 10px; margin: 0; font-size: 16px;">
                    <i class="bi bi-magic" style="font-size: 17px;"></i>
                    Auto-fill from Documents
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Body -->
            <div class="modal-body" style="padding: 16px 20px;">
                <p class="text-muted mb-2" style="font-size: 12.5px;">
                    Values read from your uploaded documents — correct anything here, then
                    <strong>Apply to Form</strong>. You can still edit everything afterwards.
                </p>

                <!-- OCR note: only shown when scanned pages were read with OCR -->
                <div id="autofillPreviewOcrNote" style="display: none; margin-bottom: 10px; padding: 7px 11px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; font-size: 12px; color: #92400e;">
                    <i class="bi bi-camera"></i> One or more pages were scanned images and read using OCR — text from scans is less accurate than typed text.
                </div>

                <!-- Extracted fields — a GRID, filled by showAutofillPreview() -->
                <div id="autofillPreviewRows" class="row g-2 align-items-start"></div>
            </div>

            <!-- Footer -->
            <div class="modal-footer" style="padding: 12px 20px; border-top: 1px solid #e5e7eb; background: #f9fafb;">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal"
                        style="border-color: #d1d5db; font-size: 13px;">
                    Cancel
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="applyAutofillFromPreview()"
                        style="background: #4f46e5; border-color: #4f46e5; font-size: 13px;">
                    <i class="bi bi-check-lg" style="margin-right: 5px;"></i>Apply to Form
                </button>
            </div>

        </div>
    </div>
</div>
