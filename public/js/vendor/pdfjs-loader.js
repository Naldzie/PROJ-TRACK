// ===================================================
// PDF.JS LOADER
// Loads PDF.js (ESM build) for document-parser.js autofill,
// trying multiple CDNs in order, and exposes the library as
// window.pdfjsLib. Classic script — uses dynamic import() so
// no type="module" tag is required at the call site.
// ===================================================

(function () {
    'use strict';

    const SOURCES = [
        {
            lib: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.min.mjs',
            worker: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.worker.min.mjs',
        },
        {
            lib: 'https://unpkg.com/pdfjs-dist@4.4.168/build/pdf.min.mjs',
            worker: 'https://unpkg.com/pdfjs-dist@4.4.168/build/pdf.worker.min.mjs',
        },
    ];

    let loadPromise = null;

    /**
     * Promise that resolves to the PDF.js module (also stored on window.pdfjsLib).
     * Safe to call multiple times — the first call's promise is reused.
     */
    window.loadPdfJs = function () {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);

        if (!loadPromise) {
            loadPromise = (async () => {
                let lastErr = null;
                for (const src of SOURCES) {
                    try {
                        const mod = await import(/* @vite-ignore */ src.lib);
                        mod.GlobalWorkerOptions.workerSrc = src.worker;
                        window.pdfjsLib = mod;
                        return mod;
                    } catch (err) {
                        lastErr = err;
                    }
                }
                console.error('[pdfjs-loader] PDF.js could not be loaded from any CDN.', lastErr);
                throw lastErr;
            })();
        }

        return loadPromise;
    };
})();