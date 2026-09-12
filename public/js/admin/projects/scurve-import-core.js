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
        var acts = (m.activities || []).map(function (a) {
            if (typeof a === 'string') {
                return { id: null, activity_name: a, description: '' };
            }
            return {
                id: a.id || null,
                activity_name: a.activity_name || '',
                description: a.description || '',
            };
        });
        // AI months arrive description-less (rule months build it from names) —
        // fall back to the joined names so the list shows them. Rule months
        // always carry description, so they never reach the fallback.
        var desc = m.description || acts.map(function (a) {
            return a.activity_name;
        }).filter(function (n) {
            return !!n;
        }).join(', ');
        return {
            month_label: monthLabel,
            planned_date: plannedDate,
            planned_progress_percent: m.planned_progress_percent,
            description: desc,
            activities: acts,
        };
    });
}

/**
 * Duration-shaped month labels ("30 days", "7 days") carry no month index —
 * banking one as a learned month word could never fire. The F-D harvester
 * skips them so the review desk never fills with junk (B guard).
 */
export function isDurationShapedLabel(label) {
    return /^(\d+)\s*days?$/i.test(String(label || '').trim());
}

/* ─── Auto-generate S-curve from start/end dates ──────────────────────────── */

/**
 * Hermite smoothstep: 3x² - 2x³.
 * Industry-standard DHSS/Kenley-Wilson formula.
 * @param {number} t - Normalized time (0 to 1)
 * @returns {number} Cumulative fraction (0 to 1)
 */
function hermite(t) {
    var x = Math.max(0, Math.min(1, t));
    return x * x * (3 - 2 * x);
}

/**
 * Generate monthly S-curve data from project start/end dates.
 *
 * @param {string} startDate - 'YYYY-MM-DD'
 * @param {string} endDate - 'YYYY-MM-DD'
 * @param {Object} [options]
 * @param {string} [options.loading='symmetric'] - 'symmetric' | 'front' | 'back'
 * @returns {Array} [{month_label, planned_date, planned_progress_percent, description, activities}]
 */
export function generateAutoSCurve(startDate, endDate, options) {
    if (!(/^\d{4}-\d{2}-\d{2}$/.test(startDate || '')) || !(/^\d{4}-\d{2}-\d{2}$/.test(endDate || ''))) {
        return [];
    }

    var start = new Date(startDate);
    var end = new Date(endDate);
    if (start >= end) return [];

    var loading = (options && options.loading) || 'symmetric';
    var skew = loading === 'front' ? 0.7 : loading === 'back' ? 1.4 : 1.0;

    var totalMonths = (end.getFullYear() - start.getFullYear()) * 12
                    + (end.getMonth() - start.getMonth()) + 1;
    if (totalMonths < 1) return [];
    if (totalMonths === 1) {
        return [{
            month_label: formatDateInput(start).slice(0, 7),
            planned_date: formatDateInput(start),
            planned_progress_percent: 100,
            description: '',
            activities: []
        }];
    }

    var result = [];
    for (var m = 1; m <= totalMonths; m++) {
        var tEnd = m / totalMonths;
        var tStart = (m - 1) / totalMonths;

        var cEnd = hermite(Math.pow(tEnd, 1.0 / skew));
        var cStart = hermite(Math.pow(tStart, 1.0 / skew));
        var cumulative = cEnd * 100;

        var d = addCalendarMonths(start, m - 1);
        var day = Math.min(15, new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate());
        d.setDate(day);

        result.push({
            month_label: formatDateInput(d).slice(0, 7),
            planned_date: formatDateInput(d),
            planned_progress_percent: Math.round(cumulative * 100) / 100,
            description: '',
            activities: []
        });
    }

    if (result.length > 0) {
        result[result.length - 1].planned_progress_percent = 100;
    }

    for (var i = 1; i < result.length; i++) {
        if (result[i].planned_progress_percent <= result[i - 1].planned_progress_percent) {
            result[i].planned_progress_percent = Math.round((result[i - 1].planned_progress_percent + 0.01) * 100) / 100;
        }
        if (result[i].planned_progress_percent > 100) {
            result[i].planned_progress_percent = 100;
        }
    }
    if (result.length > 0 && result[result.length - 1].planned_progress_percent !== 100) {
        result[result.length - 1].planned_progress_percent = 100;
    }

    return result;
}

/* ─── Attach to window for the classic consumer script ───────────────────── */

if (typeof window !== 'undefined') {
    window.scurveImportCore = {
        isSCurveCandidate,
        isSCurveExcel,
        addCalendarMonths,
        formatDateInput,
        normalizeSCurveMonths,
        generateAutoSCurve,
        isDurationShapedLabel,
    };
}
