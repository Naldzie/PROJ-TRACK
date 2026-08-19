/**
 * scurve-import-core.js  (ESM module)
 *
 * Pure helpers for the S-curve Excel baseline import. Kept free of DOM access
 * so they are unit-testable from Node (see tests/js/scurve-import.test.mjs).
 *
 * Loaded with <script type="module">; attaches itself to window.scurveImportCore
 * for the classic-script consumer (scurve-import-ui.js).
 */

/* ─── Candidate detection (create wizard pending documents) ──────────────── */

export function isSCurveCandidate(doc) {
    if (!doc || !doc.fileName) return false;
    var ext = (doc.fileName.split('.').pop() || '').toLowerCase();
    if (['xlsx', 'xls', 'pdf'].indexOf(ext) === -1) return false;
    var nameLooksLike = /s[-_\s]?curve|schedule|scurve/i.test(doc.fileName);
    var typeLooksLike = String(doc.typeName || '').toLowerCase() === 'schedule';
    return typeLooksLike || nameLooksLike;
}

export function isSCurveExcel(doc) {
    var ext = (doc.fileName.split('.').pop() || '').toLowerCase();
    return ext === 'xlsx' || ext === 'xls';
}

/* ─── Server month conversion → setup-modal months ────────────────────────── */

export function addCalendarMonths(date, n) {
    var d = new Date(date.getFullYear(), date.getMonth(), 1);
    d.setMonth(d.getMonth() + n);
    var dim = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
    d.setDate(Math.min(date.getDate(), dim));
    return d;
}

export function formatDateInput(d) {
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

/**
 * Convert server months [{month_index, planned_progress_percent, description,
 * activities}] into the setup-modal month shape, assigning planned_date from
 * the project's planned start date (startDate + (N-1) months, clamped to the
 * planned end date). Without a start date, months keep a "Month N" label and
 * an empty planned_date (the user picks dates in the modal).
 */
export function normalizeSCurveMonths(months, startDate, endDate) {
    if (!Array.isArray(months)) return [];
    var start = /^\d{4}-\d{2}-\d{2}$/.test(startDate || '') ? new Date(startDate) : null;
    var end = /^\d{4}-\d{2}-\d{2}$/.test(endDate || '') ? new Date(endDate) : null;

    return months.map(function (m) {
        var monthLabel = 'Month ' + m.month_index;
        var plannedDate = '';
        if (start) {
            var d = addCalendarMonths(start, (m.month_index || 1) - 1);
            if (end && d > end) d = new Date(end.getTime());
            plannedDate = formatDateInput(d);
            monthLabel = plannedDate.slice(0, 7);
        }
        return {
            month_label: monthLabel,
            planned_date: plannedDate,
            planned_progress_percent: m.planned_progress_percent,
            description: m.description || '',
            activities: (m.activities || []).map(function (a) {
                return {
                    id: a.id || null,
                    activity_name: a.activity_name || '',
                    description: a.description || '',
                };
            }),
        };
    });
}

/* ─── Attach to window for the classic consumer script ───────────────────── */

if (typeof window !== 'undefined') {
    window.scurveImportCore = {
        isSCurveCandidate,
        isSCurveExcel,
        addCalendarMonths,
        formatDateInput,
        normalizeSCurveMonths,
    };
}
