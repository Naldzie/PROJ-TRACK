import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    addCalendarMonths,
    duplicateMonthLabels,
    formatDateInput,
    isDurationShapedLabel,
    isSCurveCandidate,
    isSCurveExcel,
    normalizeSCurveMonths,
} from '../../public/js/admin/projects/scurve-import-core.js';

// ─── normalizeSCurveMonths ────────────────────────────────────────────────────

test('normalizeSCurveMonths assigns planned_date from start date, one month per index', () => {
    const serverMonths = [
        { month_index: 1, planned_progress_percent: 5, description: 'Mob', activities: [] },
        { month_index: 2, planned_progress_percent: 20, description: 'Foundations', activities: [] },
        { month_index: 3, planned_progress_percent: 45, description: 'Superstructure', activities: [] },
    ];

    const months = normalizeSCurveMonths(serverMonths, '2026-01-15', '');

    assert.equal(months.length, 3);
    assert.equal(months[0].planned_date, '2026-01-15');
    assert.equal(months[0].month_label, '2026-01');
    assert.equal(months[1].planned_date, '2026-02-15');
    assert.equal(months[1].month_label, '2026-02');
    assert.equal(months[2].planned_date, '2026-03-15');
    assert.equal(months[2].month_label, '2026-03');
    assert.equal(months[0].planned_progress_percent, 5);
    assert.equal(months[2].planned_progress_percent, 45);
});

test('normalizeSCurveMonths clamps dates past the planned end date', () => {
    const months = normalizeSCurveMonths(
        [
            { month_index: 1, planned_progress_percent: 10, description: 'A', activities: [] },
            { month_index: 2, planned_progress_percent: 90, description: 'B', activities: [] },
            { month_index: 3, planned_progress_percent: 100, description: 'C', activities: [] },
        ],
        '2026-11-01',
        '2026-12-31'
    );

    assert.equal(months[0].planned_date, '2026-11-01');
    assert.equal(months[1].planned_date, '2026-12-01');
    // Month 3 would be 2027-01-01 → clamped to the end date.
    assert.equal(months[2].planned_date, '2026-12-31');
});

test('normalizeSCurveMonths without a start date keeps Month N labels and empty dates', () => {
    const months = normalizeSCurveMonths(
        [{ month_index: 4, planned_progress_percent: 60, description: 'D', activities: [] }],
        '',
        ''
    );

    assert.equal(months[0].month_label, 'Month 4');
    assert.equal(months[0].planned_date, '');
});

test('normalizeSCurveMonths passes activities through with normalized keys', () => {
    const months = normalizeSCurveMonths(
        [
            {
                month_index: 1,
                planned_progress_percent: 5,
                description: '',
                activities: [
                    { id: 9, activity_name: 'Mobilization', description: '1' },
                    { id: null, activity_name: 'Office', description: null },
                ],
            },
        ],
        '2026-01-01',
        ''
    );

    assert.deepEqual(months[0].activities, [
        { id: 9, activity_name: 'Mobilization', description: '1' },
        { id: null, activity_name: 'Office', description: '' },
    ]);
});

test('normalizeSCurveMonths accepts bare AI name strings as activities', () => {
    const months = normalizeSCurveMonths(
        [
            {
                month_index: 1,
                planned_progress_percent: 5,
                activities: ['Mobilization Works', 'Earthworks'],
            },
        ],
        '',
        ''
    );

    assert.deepEqual(months[0].activities, [
        { id: null, activity_name: 'Mobilization Works', description: '' },
        { id: null, activity_name: 'Earthworks', description: '' },
    ]);
    // Description-less AI months fall back to the joined names for the list.
    assert.equal(months[0].description, 'Mobilization Works, Earthworks');
});

test('isDurationShapedLabel spots index-less duration labels only', () => {
    assert.equal(isDurationShapedLabel('30 days'), true);
    assert.equal(isDurationShapedLabel('7 days'), true);
    assert.equal(isDurationShapedLabel('187 days'), true);
    assert.equal(isDurationShapedLabel('STAGE 1'), false);
    assert.equal(isDurationShapedLabel('P1'), false);
    assert.equal(isDurationShapedLabel('Month 1'), false);
    assert.equal(isDurationShapedLabel(''), false);
    assert.equal(isDurationShapedLabel(null), false);
});

// Live 2026-09-12: a 10-month file on a project ending March 2026 rendered #9 and
// #10 both as "March 2026" — normalizeSCurveMonths pins any month past the end
// date ONTO it. Applying that stores two rows on one label, and labels are keys
// downstream, so the clamp must stop being silent.
test('duplicateMonthLabels catches months clamped onto the project end date', () => {
    const months = normalizeSCurveMonths(
        [
            { month_index: 1, planned_progress_percent: 50 },
            { month_index: 2, planned_progress_percent: 80 },
            { month_index: 3, planned_progress_percent: 100 },
        ],
        '2026-01-01',
        // Two months of room for a three-month file: month 3 lands on month 2.
        '2026-02-28'
    );

    assert.equal(months[1].month_label, months[2].month_label, 'the clamp itself still happens');
    assert.deepEqual(duplicateMonthLabels(months), ['2026-02']);
});

test('duplicateMonthLabels stays quiet when every month has its own slot', () => {
    const months = normalizeSCurveMonths(
        [
            { month_index: 1, planned_progress_percent: 50 },
            { month_index: 2, planned_progress_percent: 100 },
        ],
        '2026-01-01',
        '2026-06-30'
    );

    assert.deepEqual(duplicateMonthLabels(months), []);
    // No dates at all (labels fall back to "Month N") must not read as a clash.
    assert.deepEqual(duplicateMonthLabels(normalizeSCurveMonths(
        [{ month_index: 1, planned_progress_percent: 100 }], '', ''
    )), []);
    assert.deepEqual(duplicateMonthLabels(null), []);
});

test('normalizeSCurveMonths guards against non-array input', () => {
    assert.deepEqual(normalizeSCurveMonths(undefined, '', ''), []);
    assert.deepEqual(normalizeSCurveMonths(null, '2026-01-01', ''), []);
});

// ─── Date helpers ─────────────────────────────────────────────────────────────

test('addCalendarMonths rolls a 31st-of-month date to the last day of shorter months', () => {
    assert.equal(formatDateInput(addCalendarMonths(new Date(2026, 0, 31), 1)), '2026-02-28');
    assert.equal(formatDateInput(addCalendarMonths(new Date(2026, 2, 31), 1)), '2026-04-30');
    assert.equal(formatDateInput(addCalendarMonths(new Date(2026, 4, 31), 1)), '2026-06-30');
    // 2028 is a leap year.
    assert.equal(formatDateInput(addCalendarMonths(new Date(2028, 0, 31), 1)), '2028-02-29');
});

test('formatDateInput zero-pads month and day', () => {
    assert.equal(formatDateInput(new Date(2026, 0, 5)), '2026-01-05');
    assert.equal(formatDateInput(new Date(2026, 11, 31)), '2026-12-31');
});

// ─── Candidate detection ──────────────────────────────────────────────────────

test('isSCurveCandidate accepts Schedule-typed Excel/PDF and curve-named files', () => {
    assert.equal(isSCurveCandidate({ fileName: 's-curve.xlsx', typeName: 'Schedule' }), true);
    assert.equal(isSCurveCandidate({ fileName: 'CONSTRUCTION SCHEDULE.pdf', typeName: 'Misc' }), true);
    assert.equal(isSCurveCandidate({ fileName: 'contract.pdf', typeName: 'Contract' }), false);
    // Non-S-curve extensions are never candidates, even with a Schedule type.
    assert.equal(isSCurveCandidate({ fileName: 'notes.docx', typeName: 'Schedule' }), false);
    // The real chart-only sample — "CONSCHEDSAVINGS" contains no keyword.
    assert.equal(isSCurveCandidate({ fileName: 'CONSCHEDSAVINGS.pdf', typeName: 'Misc' }), false);
});

test('isSCurveExcel only accepts Excel extensions', () => {
    assert.equal(isSCurveExcel({ fileName: 's-curve.xlsx', typeName: 'Schedule' }), true);
    assert.equal(isSCurveExcel({ fileName: 's-curve.xls', typeName: 'Schedule' }), true);
    assert.equal(isSCurveExcel({ fileName: 's-curve.pdf', typeName: 'Schedule' }), false);
});