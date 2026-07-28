{{--
|--------------------------------------------------------------------------
| Reusable Document Viewer Modal
|--------------------------------------------------------------------------
| Usage: @include('components.document-viewer-modal')
|
| To open the modal from anywhere call the global JS function:
|
|   openDocumentViewer({
|       id:           123,              // document ID (required)
|       name:         'contract.pdf',  // display name
|       type:         'Contract',      // document type label
|       size:         '1.2 MB',        // human-readable size
|       uploadedBy:   'Juan dela Cruz', // uploader name
|       uploadedAt:   'May 25, 2026',  // formatted date
|       viewUrl:      '/admin/documents/123/view',
|       downloadUrl:  '/admin/documents/123/download',
|   });
|
--}}

<!-- ===== DOCUMENT VIEWER MODAL ===== -->
<div class="modal fade" id="documentViewerModal" tabindex="-1"
     aria-labelledby="documentViewerModalLabel" aria-hidden="true"
     data-bs-backdrop="static">
    <div class="modal-dialog modal-xl dv-dialog">
        <div class="modal-content dv-content">

            {{-- ── Header ──────────────────────────────────────────────── --}}
            <div class="dv-header">
                <div class="dv-header-left">
                    <div class="dv-file-icon" id="dvFileIcon">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <div class="dv-meta">
                        <div class="dv-filename" id="dvFilename">Document</div>
                        <div class="dv-filemeta">
                            <span class="dv-type-badge" id="dvTypeBadge">—</span>
                            <span id="dvFileSize" class="dv-size">—</span>
                            <span class="dv-sep">·</span>
                            <span id="dvUploadedBy" class="dv-uploader">—</span>
                            <span class="dv-sep">·</span>
                            <span id="dvUploadedAt" class="dv-date">—</span>
                        </div>
                    </div>
                </div>

                <div class="dv-header-actions">
                    <a href="#" id="dvOpenTabBtn" target="_blank"
                       class="dv-btn dv-btn-ghost" title="Open in new tab">
                        <i class="bi bi-box-arrow-up-right"></i>
                        <span>New Tab</span>
                    </a>
                    <a href="#" id="dvDownloadBtn"
                       class="dv-btn dv-btn-primary" title="Download">
                        <i class="bi bi-download"></i>
                        <span>Download</span>
                    </a>
                    <button type="button" class="dv-btn dv-btn-close"
                            data-bs-dismiss="modal" aria-label="Close" title="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>

            {{-- ── Body / Viewer ────────────────────────────────────────── --}}
            <div class="dv-body" id="dvBody">

                {{-- Loading spinner --}}
                <div class="dv-loading" id="dvLoading">
                    <div class="dv-spinner"></div>
                    <p>Loading document…</p>
                </div>

                {{-- PDF / image viewer --}}
                <iframe id="dvIframe" class="dv-iframe" src="" title="Document viewer"
                        style="display:none;" allowfullscreen></iframe>

                {{-- Fallback for unsupported file types --}}
                <div class="dv-fallback" id="dvFallback" style="display:none;">
                    <div class="dv-fallback-icon">
                        <i class="bi bi-file-earmark-arrow-down"></i>
                    </div>
                    <h5>Preview Not Available</h5>
                    <p>This file type cannot be previewed directly in the browser.<br>
                       Use the buttons below to open or download the file.</p>
                    <div class="dv-fallback-actions">
                        <a href="#" id="dvFallbackOpenBtn" target="_blank"
                           class="dv-btn dv-btn-ghost">
                            <i class="bi bi-box-arrow-up-right"></i> Open in New Tab
                        </a>
                        <a href="#" id="dvFallbackDownloadBtn"
                           class="dv-btn dv-btn-primary">
                            <i class="bi bi-download"></i> Download File
                        </a>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>
<!-- ===== END DOCUMENT VIEWER MODAL ===== -->


{{-- ── Styles ───────────────────────────────────────────────────────── --}}
<style>
/* === Dialog sizing === */
.dv-dialog {
    max-width: min(92vw, 1180px);
    margin: 1.5rem auto;
    height: calc(100vh - 3rem);
}

.dv-content {
    display: flex;
    flex-direction: column;
    height: 100%;
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid rgba(30, 58, 138, 0.12);
    box-shadow: 0 25px 60px rgba(10, 20, 60, 0.22), 0 8px 20px rgba(0,0,0,0.1);
}

/* === Header === */
.dv-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #1a2540 0%, #1e3a8a 100%);
    flex-shrink: 0;
    flex-wrap: wrap;
}

.dv-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
    flex: 1;
}

.dv-file-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 20px;
    color: #93c5fd;
    transition: background 0.2s;
}

.dv-meta {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.dv-filename {
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: min(50vw, 480px);
    line-height: 1.3;
}

.dv-filemeta {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.dv-type-badge {
    display: inline-block;
    background: rgba(96,165,250,0.22);
    color: #93c5fd;
    border: 1px solid rgba(96,165,250,0.3);
    border-radius: 20px;
    padding: 1px 9px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.2px;
}

.dv-size, .dv-uploader, .dv-date {
    font-size: 12px;
    color: rgba(255,255,255,0.55);
}

.dv-sep {
    color: rgba(255,255,255,0.25);
    font-size: 12px;
}

/* === Header Buttons === */
.dv-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.dv-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    border: none;
    transition: all 0.18s;
    white-space: nowrap;
    line-height: 1;
}

.dv-btn i {
    font-size: 14px;
}

.dv-btn-ghost {
    background: rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.85);
    border: 1px solid rgba(255,255,255,0.15);
}

.dv-btn-ghost:hover {
    background: rgba(255,255,255,0.2);
    color: #fff;
    border-color: rgba(255,255,255,0.3);
}

.dv-btn-primary {
    background: #2563eb;
    color: #fff;
    border: 1px solid #1d4ed8;
}

.dv-btn-primary:hover {
    background: #1d4ed8;
    color: #fff;
    box-shadow: 0 4px 12px rgba(37,99,235,0.35);
}

.dv-btn-close {
    background: rgba(239,68,68,0.15);
    color: rgba(255,255,255,0.75);
    border: 1px solid rgba(239,68,68,0.25);
    padding: 7px 10px;
}

.dv-btn-close:hover {
    background: rgba(239,68,68,0.35);
    color: #fff;
    border-color: rgba(239,68,68,0.5);
}

/* === Body / Viewer === */
.dv-body {
    flex: 1;
    min-height: 0;
    position: relative;
    background: #1e1e2e;
    display: flex;
    align-items: center;
    justify-content: center;
}

.dv-iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
    background: #fff;
}

/* === Loading spinner === */
.dv-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    color: rgba(255,255,255,0.55);
    font-size: 14px;
}

.dv-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid rgba(255,255,255,0.12);
    border-top-color: #60a5fa;
    border-radius: 50%;
    animation: dvSpin 0.8s linear infinite;
}

@keyframes dvSpin {
    to { transform: rotate(360deg); }
}

/* === Fallback === */
.dv-fallback {
    text-align: center;
    padding: 48px 32px;
    color: rgba(255,255,255,0.65);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}

.dv-fallback-icon {
    font-size: 52px;
    color: rgba(96,165,250,0.5);
    line-height: 1;
}

.dv-fallback h5 {
    color: rgba(255,255,255,0.85);
    font-size: 17px;
    font-weight: 700;
    margin: 0;
}

.dv-fallback p {
    font-size: 13px;
    line-height: 1.6;
    margin: 0;
    max-width: 360px;
}

.dv-fallback-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    margin-top: 8px;
}

/* === Responsive === */
@media (max-width: 600px) {
    .dv-btn span { display: none; }
    .dv-btn { padding: 7px 10px; }
    .dv-filename { max-width: 42vw; }
    .dv-size, .dv-uploader, .dv-sep { display: none; }
}
</style>

{{-- ── Script ───────────────────────────────────────────────────────── --}}
<script>
(function () {
    // ── File-type helpers ────────────────────────────────────────────
    const PREVIEWABLE = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    function getExtension(filename) {
        return (filename || '').split('.').pop().toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    function isPreviewable(filename) {
        return PREVIEWABLE.includes(getExtension(filename));
    }

    function getFileIcon(filename) {
        const ext = getExtension(filename);
        const map = {
            pdf:  'bi-file-earmark-pdf',
            doc:  'bi-file-earmark-word',
            docx: 'bi-file-earmark-word',
            xls:  'bi-file-earmark-excel',
            xlsx: 'bi-file-earmark-excel',
            ppt:  'bi-file-earmark-ppt',
            pptx: 'bi-file-earmark-ppt',
            jpg:  'bi-file-earmark-image',
            jpeg: 'bi-file-earmark-image',
            png:  'bi-file-earmark-image',
            gif:  'bi-file-earmark-image',
            webp: 'bi-file-earmark-image',
            svg:  'bi-file-earmark-image',
        };
        return map[ext] || 'bi-file-earmark-text';
    }

    // ── DOM refs (resolved lazily after DOMContentLoaded) ────────────
    let _modal, _bsModal;
    function getModal() {
        if (!_modal) _modal = document.getElementById('documentViewerModal');
        return _modal;
    }
    function getBsModal() {
        if (!_bsModal) _bsModal = new bootstrap.Modal(getModal());
        return _bsModal;
    }

    // ── Public API ───────────────────────────────────────────────────
    /**
     * openDocumentViewer(options)
     *
     * @param {object} options
     * @param {number}  options.id
     * @param {string}  options.name
     * @param {string}  [options.type]
     * @param {string}  [options.size]
     * @param {string}  [options.uploadedBy]
     * @param {string}  [options.uploadedAt]
     * @param {string}  options.viewUrl
     * @param {string}  options.downloadUrl
     */
    window.openDocumentViewer = function (options) {
        const modal = getModal();

        // ── Populate header meta
        modal.querySelector('#dvFilename').textContent     = options.name       || 'Document';
        modal.querySelector('#dvTypeBadge').textContent    = options.type       || '—';
        modal.querySelector('#dvFileSize').textContent     = options.size       || '—';
        modal.querySelector('#dvUploadedBy').textContent   = options.uploadedBy || '—';
        modal.querySelector('#dvUploadedAt').textContent   = options.uploadedAt || '—';

        // ── File icon
        const iconEl = modal.querySelector('#dvFileIcon');
        iconEl.innerHTML = `<i class="bi ${getFileIcon(options.name)}"></i>`;

        // ── Wire action buttons
        const openTabBtn   = modal.querySelector('#dvOpenTabBtn');
        const downloadBtn  = modal.querySelector('#dvDownloadBtn');
        const fallbackOpen = modal.querySelector('#dvFallbackOpenBtn');
        const fallbackDl   = modal.querySelector('#dvFallbackDownloadBtn');

        openTabBtn.href   = options.viewUrl;
        downloadBtn.href  = options.downloadUrl;
        if (fallbackOpen) fallbackOpen.href = options.viewUrl;
        if (fallbackDl)   fallbackDl.href   = options.downloadUrl;

        // ── Reset viewer state
        const iframeEl   = modal.querySelector('#dvIframe');
        const loadingEl  = modal.querySelector('#dvLoading');
        const fallbackEl = modal.querySelector('#dvFallback');

        iframeEl.style.display   = 'none';
        fallbackEl.style.display = 'none';
        loadingEl.style.display  = 'flex';
        iframeEl.src = '';

        getBsModal().show();

        // ── Decide viewer strategy
        if (isPreviewable(options.name)) {
            iframeEl.onload = function () {
                loadingEl.style.display = 'none';
                iframeEl.style.display  = 'block';
            };
            iframeEl.onerror = function () {
                loadingEl.style.display  = 'none';
                fallbackEl.style.display = 'flex';
            };
            iframeEl.src = options.viewUrl;
        } else {
            // Non-previewable: show fallback immediately
            loadingEl.style.display  = 'none';
            fallbackEl.style.display = 'flex';
        }
    };

    // ── Clean up iframe src when modal is hidden (avoids audio/video leaks)
    document.addEventListener('DOMContentLoaded', function () {
        const modal = getModal();
        if (!modal) return;
        modal.addEventListener('hidden.bs.modal', function () {
            const iframeEl = modal.querySelector('#dvIframe');
            if (iframeEl) {
                iframeEl.src = '';
                iframeEl.style.display = 'none';
            }
        });
    });
})();
</script>