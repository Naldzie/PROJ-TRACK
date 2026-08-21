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

                {{-- PDF viewer (iframe) --}}
                <iframe id="dvIframe" class="dv-iframe" src="" title="Document viewer"
                        style="display:none;" allowfullscreen></iframe>

                {{-- Image viewer (uses iframe for StreamedResponse compatibility) --}}
                <iframe id="dvImageIframe" class="dv-iframe dv-image-iframe" src=""
                        title="Image viewer" style="display:none;"></iframe>

                {{-- HTML viewer for DOCX/Excel preview --}}
                <div id="dvHtmlContainer" class="dv-html-container" style="display:none;">
                </div>

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

/* === Image viewer (iframe variant with dark background) === */
.dv-image-iframe {
    background: #1e1e2e;
}

/* === HTML viewer (DOCX / Excel preview) === */
.dv-html-container {
    width: 100%;
    height: 100%;
    overflow: auto;
    background: #fff;
    padding: 20px;
}

.dv-html-container table {
    border-collapse: collapse;
    width: 100%;
    font-size: 12px;
}

.dv-html-container td,
.dv-html-container th {
    border: 1px solid #d1d5db;
    padding: 4px 8px;
    text-align: left;
    white-space: nowrap;
}

.dv-html-container th {
    background: #f3f4f6;
    font-weight: 600;
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
    var IMAGE_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    var EXCEL_EXTS  = ['xls', 'xlsx'];
    var DOCX_EXTS   = ['docx'];
    var PDF_EXTS    = ['pdf'];
    var PREVIEWABLE = IMAGE_EXTS.concat(EXCEL_EXTS).concat(DOCX_EXTS).concat(PDF_EXTS);

    function getExtension(filename) {
        return (filename || '').split('.').pop().toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    function isPreviewable(filename) {
        return PREVIEWABLE.includes(getExtension(filename));
    }

    function getFileIcon(filename) {
        var ext = getExtension(filename);
        var map = {
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

    function getViewerType(filename) {
        var ext = getExtension(filename);
        if (PDF_EXTS.includes(ext))    return 'pdf';
        if (EXCEL_EXTS.includes(ext))  return 'excel';
        if (DOCX_EXTS.includes(ext))   return 'docx';
        return 'none';
    }

    // ── Mammoth.js lazy loader (for DOCX preview) ─────────────────
    var _mammothLoaded = false;
    var _mammothLoading = false;
    var _mammothCallbacks = [];

    function loadMammoth(callback) {
        if (_mammothLoaded) { callback(); return; }
        _mammothCallbacks.push(callback);
        if (_mammothLoading) return;
        _mammothLoading = true;

        var cdnUrls = [
            'https://cdn.jsdelivr.net/npm/mammoth@1.8.0/mammoth.browser.min.js',
            'https://unpkg.com/mammoth@1.8.0/mammoth.browser.min.js'
        ];
        var idx = 0;

        function tryNext() {
            if (idx >= cdnUrls.length) {
                console.error('[DocumentViewer] Failed to load mammoth.js from all CDNs');
                _mammothCallbacks.forEach(function(cb) { cb(new Error('mammoth.js load failed')); });
                _mammothCallbacks = [];
                return;
            }
            var s = document.createElement('script');
            s.src = cdnUrls[idx++];
            s.onload = function () {
                _mammothLoaded = true;
                _mammothCallbacks.forEach(function(cb) { cb(); });
                _mammothCallbacks = [];
            };
            s.onerror = tryNext;
            document.head.appendChild(s);
        }
        tryNext();
    }

    // ── DOM refs (resolved lazily after DOMContentLoaded) ────────────
    var _modal, _bsModal;
    function getModal() {
        if (!_modal) _modal = document.getElementById('documentViewerModal');
        return _modal;
    }
    function getBsModal() {
        if (!_bsModal) _bsModal = new bootstrap.Modal(getModal());
        return _bsModal;
    }

    function hideAllViewers(modal) {
        var iframeEl      = modal.querySelector('#dvIframe');
        var imageIframe   = modal.querySelector('#dvImageIframe');
        var htmlContainer = modal.querySelector('#dvHtmlContainer');
        var loadingEl     = modal.querySelector('#dvLoading');
        var fallbackEl    = modal.querySelector('#dvFallback');

        if (iframeEl)      { iframeEl.style.display = 'none'; iframeEl.src = ''; }
        if (imageIframe)   { imageIframe.style.display = 'none'; imageIframe.src = ''; }
        if (htmlContainer) { htmlContainer.style.display = 'none'; htmlContainer.innerHTML = ''; }
        if (loadingEl)     { loadingEl.style.display = 'none'; }
        if (fallbackEl)    { fallbackEl.style.display = 'none'; }
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
     * @param {string}  [options.previewUrl]
     */
    window.openDocumentViewer = function (options) {
        var modal = getModal();

        // ── Populate header meta
        modal.querySelector('#dvFilename').textContent     = options.name       || 'Document';
        modal.querySelector('#dvTypeBadge').textContent    = options.type       || '—';
        modal.querySelector('#dvFileSize').textContent     = options.size       || '—';
        modal.querySelector('#dvUploadedBy').textContent   = options.uploadedBy || '—';
        modal.querySelector('#dvUploadedAt').textContent   = options.uploadedAt || '—';

        // ── File icon
        var iconEl = modal.querySelector('#dvFileIcon');
        iconEl.innerHTML = '<i class="bi ' + getFileIcon(options.name) + '"></i>';

        // ── Wire action buttons
        var openTabBtn   = modal.querySelector('#dvOpenTabBtn');
        var downloadBtn  = modal.querySelector('#dvDownloadBtn');
        var fallbackOpen = modal.querySelector('#dvFallbackOpenBtn');
        var fallbackDl   = modal.querySelector('#dvFallbackDownloadBtn');

        openTabBtn.href   = options.viewUrl;
        downloadBtn.href  = options.downloadUrl;
        if (fallbackOpen) fallbackOpen.href = options.viewUrl;
        if (fallbackDl)   fallbackDl.href   = options.downloadUrl;

        // ── Reset all viewer containers
        hideAllViewers(modal);

        var loadingEl = modal.querySelector('#dvLoading');
        loadingEl.style.display = 'flex';

        getBsModal().show();

        // ── Decide viewer strategy per file type
        var viewerType = getViewerType(options.name);

        if (viewerType === 'image') {
            // Images: load in iframe (StreamedResponse-compatible, same as PDF)
            var imageIframe = modal.querySelector('#dvImageIframe');
            imageIframe.onload = function () {
                loadingEl.style.display = 'none';
                imageIframe.style.display = 'block';
            };
            imageIframe.onerror = function () {
                loadingEl.style.display = 'none';
                modal.querySelector('#dvFallback').style.display = 'flex';
            };
            imageIframe.src = options.viewUrl;

        } else if (viewerType === 'pdf') {
            // PDF: use iframe (browser built-in viewer)
            var iframeEl = modal.querySelector('#dvIframe');
            iframeEl.onload = function () {
                loadingEl.style.display = 'none';
                iframeEl.style.display = 'block';
            };
            iframeEl.onerror = function () {
                loadingEl.style.display = 'none';
                modal.querySelector('#dvFallback').style.display = 'flex';
            };
            iframeEl.src = options.viewUrl;

        } else if (viewerType === 'excel') {
            // Excel: server-side PhpSpreadsheet → HTML in iframe
            var iframeEl = modal.querySelector('#dvIframe');
            var previewUrl = options.previewUrl || options.viewUrl;
            iframeEl.onload = function () {
                loadingEl.style.display = 'none';
                iframeEl.style.display = 'block';
            };
            iframeEl.onerror = function () {
                loadingEl.style.display = 'none';
                modal.querySelector('#dvFallback').style.display = 'flex';
            };
            iframeEl.src = previewUrl;

        } else if (viewerType === 'docx') {
            // DOCX: client-side mammoth.js → HTML in div
            loadMammoth(function (err) {
                if (err || typeof mammoth === 'undefined') {
                    loadingEl.style.display = 'none';
                    modal.querySelector('#dvFallback').style.display = 'flex';
                    return;
                }
                // Fetch the file as ArrayBuffer
                fetch(options.downloadUrl, { credentials: 'same-origin' })
                    .then(function (res) {
                        if (!res.ok) throw new Error('Fetch failed');
                        return res.arrayBuffer();
                    })
                    .then(function (arrayBuffer) {
                        return mammoth.convertToHtml({ arrayBuffer: arrayBuffer });
                    })
                    .then(function (result) {
                        var htmlContainer = modal.querySelector('#dvHtmlContainer');
                        htmlContainer.innerHTML = result.value || '<p style="color:#999;">Empty document</p>';
                        loadingEl.style.display = 'none';
                        htmlContainer.style.display = 'block';
                    })
                    .catch(function () {
                        loadingEl.style.display = 'none';
                        modal.querySelector('#dvFallback').style.display = 'flex';
                    });
            });

        } else {
            // Unsupported: show fallback immediately
            loadingEl.style.display = 'none';
            modal.querySelector('#dvFallback').style.display = 'flex';
        }
    };

    // ── Clean up when modal is hidden (avoids memory/audio/video leaks)
    document.addEventListener('DOMContentLoaded', function () {
        var modal = getModal();
        if (!modal) return;
        modal.addEventListener('hidden.bs.modal', function () {
            hideAllViewers(modal);
        });
    });
})();
</script>