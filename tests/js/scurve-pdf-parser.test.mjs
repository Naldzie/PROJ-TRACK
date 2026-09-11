import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    parseSCurvePdf,
    matchMonthLabel,
    isNumericToken,
    parseNumeric,
    isTotalLabel,
    isSummaryLabel,
    isDescriptionLabel,
    headerFuzzyMatch,
} from '../../public/js/admin/projects/scurve-pdf-parser-core.js';

/* ─── Fixture helpers ─────────────────────────────────────────────────────── */

function item(str, x, y, w) {
    return { str, transform: [1, 0, 0, 1, x, y], width: w };
}

function tokensToItems(tokens, y) {
    return tokens.map(([s, x, w]) => item(s, x, y, w));
}

// Build a page's items array from an array of lines, each line being
// [[str, x, width], ...].
function page(itemsPerLine) {
    return itemsPerLine.flatMap(([y, tokens]) => tokensToItems(tokens, y));
}

const HEADER = [
    [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55], ['MONTH 3', 400, 55]]],
];

const ROW1 = [
    [760, [['1', 55, 8], ['Mobilization', 70, 100], ['1000', 210, 30], ['2000', 310, 30], ['3000', 410, 30]]],
];

const ROW2 = [
    [720, [['2', 55, 8], ['Signage', 70, 80], ['1500', 210, 30], ['1500', 310, 30], ['0', 410, 20]]],
];

const TOTAL = [
    [680, [['TOTAL', 55, 45], ['2500', 210, 30], ['3500', 310, 30], ['3000', 410, 30]]],
];

/* ─── Label/number helpers ────────────────────────────────────────────────── */

test('matchMonthLabel recognises month styles', () => {
    assert.equal(matchMonthLabel('Month 1'), 1);
    assert.equal(matchMonthLabel('M12'), 12);
    assert.equal(matchMonthLabel('m 7'), 7);
    assert.equal(matchMonthLabel('JANUARY'), 1);
    assert.equal(matchMonthLabel('Sep'), 9);
    assert.equal(matchMonthLabel('Description'), null);
    assert.equal(matchMonthLabel('No. of Days'), null);
});

/* ─── P1: ordinals, P-family months, loose + fuzzy labels, ##/% ───────────── */

test('matchMonthLabel recognises ordinals and P-family months', () => {
    assert.equal(matchMonthLabel('1st.'), 1);
    assert.equal(matchMonthLabel('2nd'), 2);
    assert.equal(matchMonthLabel('3rd.'), 3);
    assert.equal(matchMonthLabel('12th.'), 12);
    assert.equal(matchMonthLabel('13th'), null);
    assert.equal(matchMonthLabel('P1'), 1);
    assert.equal(matchMonthLabel('P12'), 12);
    assert.equal(matchMonthLabel('Period 3'), 3);
    assert.equal(matchMonthLabel('Phase 2'), 2);
    assert.equal(matchMonthLabel('P13'), null);
});

test('matchMonthLabel recognises stage and quarter months (P1-follow-up)', () => {
    assert.equal(matchMonthLabel('STAGE 1'), 1);
    assert.equal(matchMonthLabel('Stage 6'), 6);
    assert.equal(matchMonthLabel('Q1'), 1);
    assert.equal(matchMonthLabel('QTR 3'), 3);
    assert.equal(matchMonthLabel('Quarter 2'), 2);
    assert.equal(matchMonthLabel('STAGE 13'), null);
});

test('isDescriptionLabel loose synonyms + fuzzy typos, far words rejected', () => {
    assert.equal(isDescriptionLabel('DESCRIPTION'), true);
    assert.equal(isDescriptionLabel('WORK DETAILS'), true);
    assert.equal(isDescriptionLabel('TASK DESCRIPTION'), true);
    assert.equal(isDescriptionLabel('PAY ITEM'), true);
    assert.equal(isDescriptionLabel('SCHEDULED TASKS'), true);
    assert.equal(isDescriptionLabel('DESCRITPION'), true);
    assert.equal(isDescriptionLabel('Mobilization'), false);
    assert.equal(isDescriptionLabel('NO. OF DAYS'), false);
    assert.equal(isDescriptionLabel('P1'), false);
});

test('headerFuzzyMatch catches typos, never short or far words', () => {
    assert.equal(headerFuzzyMatch('DESCRITPION', ['description']), true);
    assert.equal(headerFuzzyMatch('Commulative', ['cumulative']), true);
    assert.equal(headerFuzzyMatch('NO. OF DAYS', ['description']), false);
    assert.equal(headerFuzzyMatch('scope', ['description']), false);
    assert.equal(headerFuzzyMatch('', ['description']), false);
});

test('parses ordinal month columns end to end', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['1st.', 200, 35], ['2nd.', 300, 35], ['3rd.', 400, 35]]],
        [760, [['Works', 70, 80], ['1000', 210, 25], ['2000', 310, 25], ['3000', 410, 25]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 3);
    assert.equal(result.months[0].month_index, 1);
    assert.equal(result.months[0].planned_progress_percent, 16.67);
    assert.equal(result.months[1].planned_progress_percent, 50);
    assert.equal(result.months[2].planned_progress_percent, 100);
});

test('parses P-family months with a loose description header', () => {
    const items = page([
        [800, [['TASK DETAILS', 50, 110], ['P1', 200, 25], ['P2', 300, 25]]],
        [760, [['Works', 70, 80], ['3000', 210, 25], ['3000', 310, 25]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 2);
    assert.equal(result.months[0].planned_progress_percent, 50);
    assert.equal(result.months[1].planned_progress_percent, 100);
});

test('parses STAGE months with a TASKS description header (P1-follow-up)', () => {
    const items = page([
        [800, [['SCHEDULED TASKS', 50, 140], ['STAGE 1', 220, 60], ['STAGE 2', 330, 60], ['STAGE 3', 440, 60]]],
        [760, [['Mobilization', 70, 110], ['60000', 230, 35], ['60000', 340, 35]]],
        [720, [['Earthworks', 70, 100], ['120000', 340, 35], ['240000', 450, 35]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 3);
    // S1 = 60000, S2 = 60000 + 120000, S3 = 240000 → total 480000 →
    // 12.5 / 50 / 100.
    assert.equal(result.months[0].planned_progress_percent, 12.5);
    assert.equal(result.months[1].planned_progress_percent, 50);
    assert.equal(result.months[2].planned_progress_percent, 100);
});

test('parses a typo description header via fuzzy match', () => {
    const items = page([
        [800, [['DESCRITPION', 50, 100], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['Works', 70, 80], ['1000', 210, 25], ['1000', 310, 25]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 2);
    assert.equal(result.months[0].description, 'Works');
});

test('## overflow marks presence without moving the curve', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['Foundation', 70, 100], ['##', 210, 20], ['1000', 310, 25]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.activityCount, 1);
    // Presence lists the activity under Month 1 at 0%; the readable 1000
    // under Month 2 is the whole contract, so Month 1 stays 0%.
    assert.equal(result.months[0].planned_progress_percent, 0);
    assert.equal(result.months[0].description, 'Foundation');
    assert.equal(result.months[1].planned_progress_percent, 100);
});

test('weighted % values sum as numbers', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['Works', 70, 80], ['1000', 210, 25], ['25%', 310, 25]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.months[0].planned_progress_percent, 97.56);
    assert.equal(result.months[1].planned_progress_percent, 100);
});

test('Commulative typo summary rows are skipped', () => {
    const items = page([
        ...HEADER,
        [760, [['1', 55, 8], ['Mobilization', 70, 100], ['1000', 210, 30], ['2000', 310, 30], ['3000', 410, 30]]],
        [720, [['Commulative %', 55, 110], ['10', 210, 20], ['20', 310, 20], ['30', 410, 20]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.activityCount, 1);
    assert.equal(result.months[0].description, 'Mobilization');
});

test('far-word headers still fail detection', () => {
    const items = page([
        [800, [['NO. OF DAYS', 50, 100], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['Works', 70, 80], ['1000', 210, 25], ['1000', 310, 25]]],
    ]);
    // NO. OF DAYS is not a description label (exact, loose, or fuzzy), and with
    // only 2 months the no-desc fallback (needs 3+) stays shut.
    assert.equal(parseSCurvePdf([items]), null);
});

test('isNumericToken / parseNumeric handle peso formatting', () => {
    assert.equal(isNumericToken('1,234.56'), true);
    assert.equal(isNumericToken('₱5000'), true);
    assert.equal(isNumericToken('Mobilization'), false);
    assert.equal(parseNumeric('1,234.56'), 1234.56);
    assert.equal(parseNumeric('₱5,000'), 5000);
});

test('isTotalLabel / isSummaryLabel', () => {
    assert.equal(isTotalLabel('TOTAL'), true);
    assert.equal(isTotalLabel('GRAND TOTAL'), true);
    assert.equal(isTotalLabel('Mobilization'), false);
    assert.equal(isSummaryLabel('% ACCOMPLISHED'), true);
    assert.equal(isSummaryLabel('CUMULATIVE'), true);
    assert.equal(isSummaryLabel('Signage'), false);
});

/* ─── Single page ─────────────────────────────────────────────────────────── */

test('parses a single-page S-curve with TOTAL row', () => {
    const items = page([...HEADER, ...ROW1, ...ROW2, ...TOTAL]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 3);
    assert.equal(result.meta.activityCount, 2);
    assert.equal(result.meta.contractAmount, 9000);

    // Month totals: M1 = 1000 + 1500 = 2500; M2 = 2000 + 1500 = 3500;
    // M3 = 3000 + 0 = 3000. Total 9000 (TOTAL row). Cumulatives:
    // 27.78 / 66.67 / 100.
    assert.equal(result.months[0].month_index, 1);
    assert.equal(result.months[0].planned_progress_percent, 27.78);
    assert.equal(result.months[1].planned_progress_percent, 66.67);
    assert.equal(result.months[2].planned_progress_percent, 100);
    assert.equal(result.months[0].description, 'Mobilization, Signage');
});

/* ─── Two-page column overflow (CONSCHEDSAVINGS style) ───────────────────── */

test('merges a two-page column overflow by row position', () => {
    const page1 = page([
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55], ['MONTH 3', 400, 55]]],
        [760, [['1', 55, 8], ['Mobilization', 70, 100], ['500', 210, 25], ['500', 310, 25], ['500', 410, 25]]],
        [720, [['2', 55, 8], ['Signage', 70, 80], ['500', 210, 25], ['500', 310, 25], ['500', 410, 25]]],
    ]);
    const page2 = page([
        [800, [['MONTH 4', 200, 55], ['MONTH 5', 300, 55], ['MONTH 6', 400, 55]]],
        [760, [['500', 210, 25], ['500', 310, 25], ['500', 410, 25]]],
        [720, [['500', 210, 25], ['500', 310, 25], ['500', 410, 25]]],
    ]);

    const result = parseSCurvePdf([page1, page2]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 6);
    assert.equal(result.meta.activityCount, 2);
    assert.equal(result.meta.contractAmount, 6000);

    // Each month totals 1000 → cumulative 16.67 … 100.
    assert.equal(result.months[0].planned_progress_percent, 16.67);
    assert.equal(result.months[5].month_index, 6);
    assert.equal(result.months[5].planned_progress_percent, 100);
});

/* ─── Two-page row continuation (repeated header) ────────────────────────── */

test('appends rows when the second page repeats the header', () => {
    const page1 = page([...HEADER, ...ROW1]);
    const page2 = page([...HEADER, ...ROW2, ...TOTAL]);

    const result = parseSCurvePdf([page1, page2]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 3);
    assert.equal(result.meta.activityCount, 2);
    assert.equal(result.meta.contractAmount, 9000);
    assert.equal(result.months[0].description, 'Mobilization, Signage');
});

/* ─── Variant headers ─────────────────────────────────────────────────────── */

test('detects bare-number month columns (1, 2, 3)', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['1', 200, 15], ['2', 300, 15], ['3', 400, 15]]],
        [760, [['Works', 70, 80], ['1000', 210, 25], ['2000', 310, 25], ['3000', 410, 25]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 3);
    assert.equal(result.months[0].planned_progress_percent, 16.67);
    assert.equal(result.months[2].planned_progress_percent, 100);
});

test('detects month-name headers (JANUARY, FEBRUARY, MARCH)', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['JANUARY', 200, 60], ['FEBRUARY', 300, 60], ['MARCH', 400, 60]]],
        [760, [['Works', 70, 80], ['1000', 210, 25], ['2000', 310, 25], ['3000', 410, 25]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 3);
    assert.equal(result.months[0].month_index, 1);
    assert.equal(result.months[2].month_index, 3);
});

/* ─── Negative cases ──────────────────────────────────────────────────────── */

test('returns null when no S-curve table is present', () => {
    const items = page([
        [800, [['JUST', 50, 30], ['SOME', 200, 30], ['TEXT', 300, 30]]],
        [760, [['Hello', 50, 50], ['World', 200, 50]]],
    ]);
    assert.equal(parseSCurvePdf([items]), null);
});

test('skips summary rows and strips leading item numbers', () => {
    const items = page([
        ...HEADER,
        [760, [['1', 55, 8], ['Mobilization', 70, 100], ['1000', 210, 30], ['0', 310, 20], ['0', 410, 20]]],
        [720, [['% ACCOMPLISHED', 55, 110], ['10', 210, 20], ['20', 310, 20], ['30', 410, 20]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.activityCount, 1);
    assert.equal(result.months[0].description, 'Mobilization');
});

/* ─── Weekly schedule layout (CONSCHEDSAVINGS style) ──────────────────────── */

test('parses a weekly schedule with month-span boundaries and cumulative %', () => {
    const page1 = page([
        [664, [['DECEMBER', 573, 80], ['JANUARY', 886, 70]]],
        [167, [
            ['WEEK 1', 490, 45], ['WEEK 2', 583, 45], ['WEEK 3', 670, 45],
            ['WEEK 4', 754, 45], ['WEEK 5', 842, 45], ['WEEK 6', 936, 45],
            ['WEEK 7', 1030, 45], ['WEEK 8', 1118, 45],
        ]],
        [158, [
            ['3.33%', 494, 30], ['6.30%', 587, 30], ['8.61%', 675, 30],
            ['8.61%', 757, 30], ['9.84%', 845, 30], ['10.88%', 938, 30],
            ['10.88%', 1032, 30], ['10.39%', 1120, 30],
        ]],
        [149, [
            ['3.33%', 494, 30], ['9.63%', 587, 30], ['18.24%', 673, 30],
            ['26.86%', 755, 30], ['36.70%', 844, 30], ['47.58%', 938, 30],
            ['58.46%', 1032, 30], ['68.85%', 1120, 30],
        ]],
    ]);

    const page2 = page([
        [665, [['FEBRUARY', 105, 75], ['MARCH', 330, 60]]],
        [168, [
            ['WEEK 9', 78, 45], ['WEEK 10', 166, 45], ['WEEK 11', 245, 45],
            ['WEEK 12', 329, 45],
        ]],
        [150, [
            ['78.81%', 80, 30], ['84.71%', 170, 30], ['91.16%', 249, 30],
            ['100.00%', 331, 30],
        ]],
    ]);

    const result = parseSCurvePdf([page1, page2]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.monthCount, 4);
    assert.equal(result.months[0].month_index, 1);
    assert.equal(result.months[0].planned_progress_percent, 36.70);
    assert.equal(result.months[0].description, 'December');

    assert.equal(result.months[1].month_index, 2);
    assert.equal(result.months[1].planned_progress_percent, 68.85);
    assert.equal(result.months[1].description, 'January');

    assert.equal(result.months[2].month_index, 3);
    assert.equal(result.months[2].planned_progress_percent, 91.16);
    assert.equal(result.months[2].description, 'February');

    assert.equal(result.months[3].month_index, 4);
    assert.equal(result.months[3].planned_progress_percent, 100);
    assert.equal(result.months[3].description, 'March');
});