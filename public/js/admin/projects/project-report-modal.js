/**
 * ============================================================================
 * Project Report modal
 * ============================================================================
 *
 * Wires the "Project Monitoring Report" modal in the project header:
 *   Preview       → the shared document viewer (openDocumentViewer), PDF inline
 *   Download PDF  → …/reports/summary.pdf?period=YYYY-MM
 *   Download Word → …/reports/report.docx?period=YYYY-MM
 *
 * The URLs and project code come from data-* attributes on #projectReportModal
 * (see components/modals/_project_report_modal.blade.php); the period is the
 * modal's select (latest month preselected).
 *
 * Plan: textfiles/project_report_overhaul_plan.md
 */
(function () {
    'use strict';

    // Append ?period= to a route URL
    function withPeriod(url, period) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'period=' + encodeURIComponent(period);
    }

    // Start a file download without leaving the page
    function download(url) {
        var a = document.createElement('a');
        a.href = url;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    function init() {
        var modal = document.getElementById('projectReportModal');
        if (!modal || modal.dataset.wired === '1') return;
        modal.dataset.wired = '1';

        // Bootstrap modals must sit directly under <body>; the header markup
        // may be inside positioned containers that break the backdrop.
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }

        var select = modal.querySelector('#projectReportPeriod');

        modal.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-report-action]');
            if (!btn) return;

            var period = select ? select.value : '';
            var action = btn.getAttribute('data-report-action');
            var pdfUrl = withPeriod(modal.dataset.pdfUrl, period);

            if (action === 'docx') {
                download(withPeriod(modal.dataset.docxUrl, period));
                return;
            }
            if (action === 'pdf') {
                download(pdfUrl);
                return;
            }
            if (action === 'preview' && typeof window.openDocumentViewer === 'function') {
                // Close this modal first, then open the viewer (one modal at a time)
                var bsModal = bootstrap.Modal.getOrCreateInstance(modal);
                modal.addEventListener('hidden.bs.modal', function onHidden() {
                    modal.removeEventListener('hidden.bs.modal', onHidden);
                    window.openDocumentViewer({
                        name: modal.dataset.code + '_project-report_' + period + '.pdf',
                        type: 'Report',
                        size: 'PDF',
                        viewUrl: withPeriod(modal.dataset.viewUrl, period),
                        downloadUrl: pdfUrl
                    });
                });
                bsModal.hide();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
