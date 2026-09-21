/**
 * S-curve import into the ORIGINAL baseline + the file picker ranking
 * (textfiles/scurve_import_original_baseline_plan.md, decisions S-D1..S-D5).
 * Pure functions from scurve-import-core.js.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    mergeImportIntoOriginal,
    normalizeSCurveMonths,
    rankSCurveCandidates,
    splitSCurveMonthsAtEnd,
} from '../../public/js/admin/projects/scurve-import-core.js';

// An existing original-baseline row as the Edit Original Baseline modal holds it
const row = (id, label, pct, opts = {}) => ({
    scheduleId: id,
    month_label: label,
    planned_date: label + '-28',
    planned_progress_percent: pct,
    original_planned_progress_percent: pct,
    original_planned_date: label + '-28',
    description: opts.description ?? '',
    has_actual_progress: !!opts.inspected,
    activities: opts.activities ?? [],
    isNew: false,
});
const month = (label, pct, activities = [], description = '') =>
    ({ month_label: label, planned_date: label + '-01', planned_progress_percent: pct, description, activities });

// ─── rankSCurveCandidates ─────────────────────────────────────────────────────

test('ranking: Schedule type first, then S-curve-looking names, then spreadsheets; non-S-curve extensions dropped', () => {
    const ranked = rankSCurveCandidates([
        { fileName: 'contract.pdf', typeName: 'Contract' },
        { fileName: 'photo.jpg', typeName: 'Schedule' },
        { fileName: 'revised s-curve.xlsx', typeName: 'Supporting Document' },
        { fileName: 'baseline.xlsx', typeName: 'Schedule' },
        { fileName: 'budget.xlsx', typeName: 'Supporting Document' },
    ]);
    assert.deepEqual(ranked.map((d) => d.fileName), ['baseline.xlsx', 'revised s-curve.xlsx', 'budget.xlsx', 'contract.pdf']);
    assert.deepEqual(ranked.map((d) => d.score), [5, 3, 1, 0]);
});

test('ranking: a renamed/removed "Schedule" type only changes the order — nothing disappears', () => {
    const ranked = rankSCurveCandidates([
        { fileName: 'preliminary.xlsx', typeName: 'S-Curve' },
        { fileName: 'revised.pdf', typeName: 'Timeline' },
    ]);
    assert.equal(ranked.length, 2);
});

test('ranking: equal scores keep the given order', () => {
    const ranked = rankSCurveCandidates([
        { fileName: 'a.xlsx', typeName: 'X' },
        { fileName: 'b.xlsx', typeName: 'X' },
    ]);
    assert.deepEqual(ranked.map((d) => d.fileName), ['a.xlsx', 'b.xlsx']);
});

// ─── splitSCurveMonthsAtEnd (S-D2) ────────────────────────────────────────────

test('split: months past the planned end month are refused, not clamped', () => {
    const raw = [1, 2, 3, 4].map((i) => ({ month_index: i, planned_progress_percent: i * 25 }));
    const { inRange, refused } = splitSCurveMonthsAtEnd(raw, '2026-04-01', '2026-06-30');

    assert.deepEqual(inRange.map((m) => m.month_index), [1, 2, 3]);
    assert.deepEqual(refused, [{ month_index: 4, month_label: '2026-07', planned_progress_percent: 100 }]);
    // normalize after the split gives distinct labels (before, month 4 was clamped onto June)
    const labels = normalizeSCurveMonths(inRange, '2026-04-01', '2026-06-30').map((m) => m.month_label);
    assert.deepEqual(labels, ['2026-04', '2026-05', '2026-06']);
});

test('split: without project dates nothing is refused', () => {
    const raw = [{ month_index: 1, planned_progress_percent: 100 }];
    assert.equal(splitSCurveMonthsAtEnd(raw, '', '').inRange.length, 1);
});

// ─── mergeImportIntoOriginal (S-D1, S-D3) ─────────────────────────────────────

test('merge: inspected months are kept as recorded and the difference is reported', () => {
    const existing = [row(1, '2026-04', 10, { inspected: true }), row(2, '2026-05', 30)];
    const { rows, removedIds, summary } = mergeImportIntoOriginal(existing, [month('2026-04', 15), month('2026-05', 40)]);

    assert.equal(rows[0].planned_progress_percent, 10, 'inspected month untouched');
    assert.equal(rows[1].planned_progress_percent, 40, 'open month takes the file value');
    assert.deepEqual(summary.keptInspected, [{ month_label: '2026-04', kept: 10, file: 15 }]);
    assert.deepEqual(summary.updated, ['2026-05']);
    assert.deepEqual(removedIds, []);
});

test('merge: an updated month keeps its row id and planned date', () => {
    const { rows } = mergeImportIntoOriginal([row(7, '2026-05', 30)], [month('2026-05', 45)]);
    assert.equal(rows[0].scheduleId, 7);
    assert.equal(rows[0].planned_date, '2026-05-28');
});

test('merge: months only in the file are added; open months missing from it are removed', () => {
    const existing = [row(1, '2026-04', 20), row(2, '2026-05', 60), row(3, '2026-06', 100)];
    const imported = [month('2026-04', 25), month('2026-05', 70), month('2026-07', 100)];
    const { rows, removedIds, summary } = mergeImportIntoOriginal(existing, imported);

    assert.deepEqual(rows.map((r) => r.month_label), ['2026-04', '2026-05', '2026-07']);
    assert.deepEqual(removedIds, [3]);
    assert.deepEqual(summary.removed, ['2026-06']);
    assert.deepEqual(summary.added, ['2026-07']);
    assert.equal(rows[2].scheduleId, null);
    assert.equal(rows[2].isNew, true);
});

test('merge: an inspected month missing from the file is never removed', () => {
    const { rows, removedIds } = mergeImportIntoOriginal([row(1, '2026-04', 10, { inspected: true })], [month('2026-05', 100)]);
    assert.deepEqual(rows.map((r) => r.month_label), ['2026-04', '2026-05']);
    assert.deepEqual(removedIds, []);
});

test('merge: activities matched by name keep their id (done flag survives); new added; missing dropped', () => {
    const existing = [row(1, '2026-05', 30, {
        activities: [
            { id: 11, activity_name: 'Foundation Works', description: 'old note' },
            { id: 12, activity_name: 'Site Clearing', description: '' },
        ],
    })];
    const imported = [month('2026-05', 35, [
        { id: null, activity_name: '  foundation   works ', description: '' },
        { id: null, activity_name: 'Column Forms', description: 'new' },
    ])];
    const { rows, summary } = mergeImportIntoOriginal(existing, imported);

    assert.deepEqual(rows[0].activities, [
        { id: 11, activity_name: '  foundation   works ', description: 'old note' },
        { id: null, activity_name: 'Column Forms', description: 'new' },
    ]);
    assert.deepEqual(summary.updated, ['2026-05']);
});

test('merge: an identical file changes nothing', () => {
    const existing = [row(1, '2026-04', 50, { description: 'A' }), row(2, '2026-05', 100, { description: 'B' })];
    const { summary, removedIds } = mergeImportIntoOriginal(existing, [month('2026-04', 50, [], 'A'), month('2026-05', 100, [], 'B')]);
    assert.deepEqual(summary.updated, []);
    assert.deepEqual(summary.unchanged, ['2026-04', '2026-05']);
    assert.deepEqual(removedIds, []);
});

test('merge: a file month without a description keeps the existing one', () => {
    const { rows } = mergeImportIntoOriginal([row(1, '2026-04', 50, { description: 'Mobilization' })], [month('2026-04', 60)]);
    assert.equal(rows[0].description, 'Mobilization');
});
