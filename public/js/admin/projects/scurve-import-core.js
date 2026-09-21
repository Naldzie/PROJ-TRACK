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
 * Month labels that ended up on the same date after normalization.
 *
 * normalizeSCurveMonths dates each month as start + (index - 1) and PINS any
 * month that would fall past the project's end date onto that end date. So a
 * schedule file carrying more months than the project's dates allow silently
 * lands two months on one label — seen live 2026-09-12: a 10-month file on a
 * project ending March 2026 rendered #9 and #10 both as "March 2026".
 *
 * That matters beyond looks: month labels are keys downstream (activity
 * reloads, the manage-month modal, progress recording) and the S-curve would
 * plot two points on one x, so applying such a baseline stores a collision.
 * Callers warn on a non-empty result instead of letting the clamp pass in
 * silence — the real fix is the project's dates, which only the user can set.
 */
export function duplicateMonthLabels(months) {
    var seen = {};
    var duplicates = [];
    (Array.isArray(months) ? months : []).forEach(function (m) {
        var label = (m && m.month_label) ? String(m.month_label) : '';
        if (!label) return;
        if (seen[label] && duplicates.indexOf(label) === -1) duplicates.push(label);
        seen[label] = true;
    });

    return duplicates;
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

/* ─── Choosing the file (S-curve picker) ──────────────────────────────────── */

/**
 * Rank candidate files for the S-curve picker, best first. SAME scores as the
 * server's App\Services\SCurveSourceFinder (keep them in step):
 *   +4 type named "Schedule", +2 name looks like an S-curve, +1 spreadsheet;
 *   ties keep the given order. Only .xls/.xlsx/.pdf are candidates — the type
 *   ranks a file, it never hides one (plan scurve_import_original_baseline P2).
 * Input items: { fileName, typeName, … } (the wizard's pending documents).
 * Returns new objects with { score, isScheduleType, nameHint } added.
 * Called by: scurve-import-ui.js (create-wizard picker).
 */
export function rankSCurveCandidates(docs) {
    if (!Array.isArray(docs)) return [];
    return docs
        .map(function (doc, i) {
            var name = String((doc && doc.fileName) || '');
            var ext = (name.split('.').pop() || '').toLowerCase();
            if (['xlsx', 'xls', 'pdf'].indexOf(ext) === -1) return null;
            var isScheduleType = String((doc && doc.typeName) || '').toLowerCase() === 'schedule';
            var nameHint = /s[-_\s]?curve|scurve|schedule/i.test(name);
            var score = (isScheduleType ? 4 : 0) + (nameHint ? 2 : 0) + (ext === 'pdf' ? 0 : 1);
            return Object.assign({}, doc, { ext: ext, score: score, isScheduleType: isScheduleType, nameHint: nameHint, _order: i });
        })
        .filter(function (d) { return d !== null; })
        .sort(function (a, b) { return (b.score - a.score) || (a._order - b._order); })
        .map(function (d) { delete d._order; return d; });
}

/* ─── Import into the ORIGINAL baseline (Edit Original Baseline mode) ─────── */

/**
 * Split raw server months (month_index based) into those inside the project's
 * planned end month and those past it — BEFORE normalizeSCurveMonths, which
 * would clamp every late month onto the end month (duplicate labels).
 * Decision S-D2: months past the end are REFUSED (moving the end date is the
 * extension flow's job), so they are returned separately for a note.
 * Returns { inRange: [raw months], refused: [{ month_index, month_label,
 * planned_progress_percent }] }. Without a start or end date nothing is refused.
 */
export function splitSCurveMonthsAtEnd(months, startDate, endDate) {
    var out = { inRange: [], refused: [] };
    if (!Array.isArray(months)) return out;
    var start = /^\d{4}-\d{2}-\d{2}$/.test(startDate || '') ? new Date(startDate) : null;
    var endMonth = /^\d{4}-\d{2}-\d{2}$/.test(endDate || '') ? endDate.slice(0, 7) : null;
    months.forEach(function (m) {
        if (!start || !endMonth) { out.inRange.push(m); return; }
        var label = formatDateInput(addCalendarMonths(start, (m.month_index || 1) - 1)).slice(0, 7);
        if (label > endMonth) {
            out.refused.push({ month_index: m.month_index, month_label: label, planned_progress_percent: m.planned_progress_percent });
        } else {
            out.inRange.push(m);
        }
    });
    return out;
}

/**
 * Merge a (revised) S-curve into the rows of the Edit Original Baseline modal.
 * Nothing is saved here — the result is shown for review and saved with the
 * existing "Save Original Baseline" endpoint and its guards.
 *
 * existing: the modal's rows { scheduleId, month_label, planned_date,
 *   planned_progress_percent, description, has_actual_progress,
 *   activities: [{ id, activity_name, description }], … }
 * imported: normalizeSCurveMonths() output (already inside the end month)
 *
 * Rules (decisions S-D1 and S-D3, 2026-09-21):
 *   · a month with recorded progress (has_actual_progress) is KEPT exactly as
 *     recorded; a differing file value is reported in summary.keptInspected;
 *   · same month, not inspected → planned % from the file; the row keeps its id
 *     and planned date; description from the file when it has one; activities
 *     matched by NAME (case/space-insensitive) keep their id — so their done
 *     flag survives on the server — new names are added, missing ones dropped;
 *   · a month only in the file → added as a new row;
 *   · a non-inspected month missing from the file → removed (its id returned
 *     in removedIds for the save payload).
 * Returns { rows (sorted by month), removedIds, summary: { updated, added,
 *   removed, unchanged, keptInspected: [{ month_label, kept, file }] } }.
 */
export function mergeImportIntoOriginal(existing, imported) {
    var byLabel = {};
    (imported || []).forEach(function (m) { if (m && m.month_label) byLabel[m.month_label] = m; });

    var norm = function (s) { return String(s || '').trim().replace(/\s+/g, ' ').toLowerCase(); };
    var num = function (v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; };

    var rows = [];
    var removedIds = [];
    var summary = { updated: [], added: [], removed: [], unchanged: [], keptInspected: [] };
    var seen = {};

    (existing || []).forEach(function (row) {
        var label = row.month_label;
        var fileMonth = byLabel[label];
        seen[label] = true;

        if (row.has_actual_progress) {
            rows.push(row);
            if (fileMonth && num(fileMonth.planned_progress_percent) !== num(row.planned_progress_percent)) {
                summary.keptInspected.push({ month_label: label, kept: num(row.planned_progress_percent), file: num(fileMonth.planned_progress_percent) });
            }
            return;
        }

        if (!fileMonth) {
            if (row.scheduleId) removedIds.push(row.scheduleId);
            summary.removed.push(label);
            return;
        }

        // Activities: reuse the existing row's id for every name that reappears
        var oldByName = {};
        (row.activities || []).forEach(function (a) { if (a && a.activity_name) oldByName[norm(a.activity_name)] = a; });
        var activities = (fileMonth.activities || [])
            .filter(function (a) { return a && a.activity_name; })
            .map(function (a) {
                var old = oldByName[norm(a.activity_name)];
                return { id: old ? old.id : null, activity_name: a.activity_name, description: a.description || (old ? old.description || '' : '') };
            });

        var merged = Object.assign({}, row, {
            planned_progress_percent: num(fileMonth.planned_progress_percent),
            description: fileMonth.description ? fileMonth.description : (row.description || ''),
            activities: activities,
        });

        var changed = num(row.planned_progress_percent) !== merged.planned_progress_percent
            || (row.description || '') !== merged.description
            || JSON.stringify((row.activities || []).map(function (a) { return norm(a.activity_name); }))
               !== JSON.stringify(activities.map(function (a) { return norm(a.activity_name); }));
        (changed ? summary.updated : summary.unchanged).push(label);
        rows.push(merged);
    });

    (imported || []).forEach(function (m) {
        if (!m || !m.month_label || seen[m.month_label]) return;
        seen[m.month_label] = true;
        rows.push({
            scheduleId: null,
            month_label: m.month_label,
            planned_date: m.planned_date,
            planned_progress_percent: num(m.planned_progress_percent),
            original_planned_progress_percent: null,
            original_planned_date: null,
            description: m.description || '',
            has_actual_progress: false,
            activities: (m.activities || []).filter(function (a) { return a && a.activity_name; })
                .map(function (a) { return { id: null, activity_name: a.activity_name, description: a.description || '' }; }),
            isNew: true,
        });
        summary.added.push(m.month_label);
    });

    rows.sort(function (a, b) { return a.month_label < b.month_label ? -1 : (a.month_label > b.month_label ? 1 : 0); });

    return { rows: rows, removedIds: removedIds, summary: summary };
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
        duplicateMonthLabels,
        rankSCurveCandidates,
        splitSCurveMonthsAtEnd,
        mergeImportIntoOriginal,
    };
}
