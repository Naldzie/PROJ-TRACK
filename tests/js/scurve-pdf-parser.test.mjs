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
    isWeakPdfCurve,
    relaxedMonthLabel,
    derivePdfLayout,
    derivePdfLayoutFromAi,
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

/* ─── P2: desc bound, center assignment, TOTAL fallback, merge, reference ─── */

test('budget values left of the months stay out of activity names', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['QTY', 150, 30], ['Month 1', 300, 55], ['Month 2', 400, 55]]],
        [760, [['1', 55, 8], ['Mobilization', 70, 100], ['LOT', 155, 25], ['100000', 250, 35], ['50000', 310, 30], ['50000', 410, 30]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    // LOT + contract figure excluded by the descRight bound (QTY@150).
    assert.equal(result.months[0].description, 'Mobilization');
});

test('a straddling token counts in exactly one month (center rule)', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['Works', 70, 80], ['1000', 290, 30]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    // Center 305 falls in Month 2 only (old overlap logic read both).
    assert.equal(result.months[0].planned_progress_percent, 0);
    assert.equal(result.months[1].planned_progress_percent, 100);
});

test('off-column TOTAL value becomes the contract fallback', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['Month 1', 300, 55], ['Month 2', 400, 55]]],
        [760, [['Works', 70, 80], ['100000', 310, 35], ['100000', 410, 35]]],
        [720, [['TOTAL', 55, 50], ['400000', 100, 40]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.meta.contractAmount, 400000);
    assert.equal(result.months[0].planned_progress_percent, 25);
    assert.equal(result.months[1].planned_progress_percent, 100);
});

test('wordless summary reprint on a continuation page is excluded', () => {
    const page1 = page([
        [800, [['DESCRIPTION', 50, 90], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['Works', 70, 80], ['3000', 210, 25], ['3000', 310, 25]]],
        [720, [['CUMULATIVE %', 55, 110], ['50', 210, 20], ['100', 310, 25]]],
    ]);
    const page2 = page([
        [800, [['Month 3', 200, 55], ['Month 4', 300, 55], ['Month 5', 400, 55]]],
        [760, [['60', 210, 20], ['80', 310, 20], ['100', 410, 25]]],
    ]);
    const result = parseSCurvePdf([page1, page2]);

    assert.ok(result, 'expected a parse result');
    // The trailing 60/80/100 row is the summary reprinted without labels, not
    // a fourth activity — page 1 showed the labeled original.
    assert.equal(result.meta.activityCount, 1);
    assert.equal(result.meta.monthCount, 5);
});

test('genuine desc-less percent rows still parse without a prior summary', () => {
    const page1 = page([
        [800, [['DESCRIPTION', 50, 90], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['Works', 70, 80], ['3000', 210, 25], ['3000', 310, 25]]],
    ]);
    const page2 = page([
        [800, [['Month 3', 200, 55], ['Month 4', 300, 55], ['Month 5', 400, 55]]],
        [760, [['60', 210, 20], ['80', 310, 20], ['100', 410, 25]]],
    ]);
    const result = parseSCurvePdf([page1, page2]);

    assert.ok(result, 'expected a parse result');
    // No labeled summary anywhere → the exclusion stays shut. Under column-overflow
    // semantics the desc-less row CONTINUES the positional row (Works), so its
    // values land in the curve (Month 3 reads 60) instead of vanishing.
    assert.equal(result.meta.activityCount, 1);
    assert.ok(result.months[2].planned_progress_percent > 0);
    assert.ok(result.months[2].description.includes('Works'));
});

test('summary gaps warn without moving the curve', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['Works', 70, 80], ['3000', 210, 25], ['3000', 310, 25]]],
        [720, [['CUMULATIVE %', 55, 110], ['40', 210, 20], ['90', 310, 20]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    assert.equal(result.months[0].planned_progress_percent, 50);
    assert.equal(result.months[1].planned_progress_percent, 100);
    assert.equal(result.meta.warnings.length, 2);
    assert.equal(result.meta.summaryReference.length, 2);
    assert.equal(result.meta.summaryReference[0].gap, 10);
});

// P3 — pdf.js emits empty text items for padded cells. One sitting just right of
// the description label used to become the description's right edge, collapsing
// the window so every activity name was cut off and rows fell back to
// "Activity N" (proven on the 6-stage and 10-month specimens, where it also let
// the cumulative row in as a phantom activity and inflated the contract).
test('blank header tokens never bound the description column', () => {
    const items = page([
        // The blank at x=150 sits between the names column and QTY.
        [800, [['ITEM', 50, 40], ['', 150, 0], ['SCHEDULED TASKS', 160, 120], ['QTY', 300, 30],
            ['MONTH 1', 400, 55], ['MONTH 2', 500, 55]]],
        [760, [['1', 55, 8], ['Mobilization Works', 160, 110], ['410', 410, 30], ['600', 510, 30]]],
        [720, [['2', 55, 8], ['Earthworks', 160, 80], ['390', 410, 30], ['600', 510, 30]]],
    ]);
    const result = parseSCurvePdf([items]);

    assert.ok(result, 'expected a parse result');
    const names = result.months.map((m) => m.description);
    assert.ok(names[0].includes('Mobilization Works'), 'real name must survive, not "Activity 1"');
    assert.ok(names[0].includes('Earthworks'), 'both rows contribute to month 1');
    assert.ok(!names.some((n) => /Activity \d/.test(n)), 'no row may fall back to a stand-in name');
});

// P3 — a column-overflow page reprints the SAME rows at the SAME heights, so a
// tail page that prints fewer rows must still land on the right activity. Before
// this, rows were paired by ORDER and the late months inherited the first rows'
// names (proven on the SEGMENT specimen: M3-M5 wore M1-M2's names).
test('overflow pages align by row height, not row order', () => {
    const page1 = page([
        [800, [['DESCRIPTION', 50, 90], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['A', 70, 20], ['1000', 210, 25], ['1000', 310, 25]]],
        [720, [['B', 70, 20], ['1000', 210, 25], ['1000', 310, 25]]],
    ]);
    // Only ONE value row, sitting at B's height — it belongs to B, not to A.
    const page2 = page([
        [800, [['Month 3', 200, 55], ['Month 4', 300, 55]]],
        [720, [['500', 210, 25], ['500', 310, 25]]],
    ]);
    const result = parseSCurvePdf([page1, page2]);

    assert.ok(result, 'expected a parse result');
    const m3 = result.months.find((m) => m.month_index === 3);
    assert.equal(m3.description, 'B', 'month 3 must carry B, the row at that height');
    // Alignment is known, not guessed — the positional warning must stay away.
    assert.ok(!result.meta.warnings.some((w) => /aligned by position/.test(w)));
});

// The P2-c guess-warning survives for pages that genuinely do NOT line up: the
// tail page's row sits at no known row height, so position is all we have.
test('row-count drift across overflow pages warns when heights do not line up', () => {
    const page1 = page([
        [800, [['DESCRIPTION', 50, 90], ['Month 1', 200, 55], ['Month 2', 300, 55]]],
        [760, [['A', 70, 20], ['1000', 210, 25], ['1000', 310, 25]]],
        [720, [['B', 70, 20], ['1000', 210, 25], ['1000', 310, 25]]],
    ]);
    const page2 = page([
        [800, [['Month 3', 200, 55], ['Month 4', 300, 55]]],
        [600, [['500', 210, 25], ['500', 310, 25]]],
    ]);
    const result = parseSCurvePdf([page1, page2]);

    assert.ok(result, 'expected a parse result');
    assert.ok(result.meta.warnings.some((w) => /different row counts/.test(w)));
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

/* ─── F-D: learned month-header words ─────────────────────────────────────── */

test('matchMonthLabel learns taught words with new numbers, hardcoded wins ties', () => {
    assert.equal(matchMonthLabel('SEGMENT 3', ['SEGMENT 1']), 3);
    assert.equal(matchMonthLabel('SEGMENT 3'), null);
    assert.equal(matchMonthLabel('P1', ['P1']), 1);
    assert.equal(matchMonthLabel('JANUARY', ['JANUARY']), 1);
    assert.equal(matchMonthLabel('SEGMENT 13', ['SEGMENT 1']), null);
});

test('parseSCurvePdf reads a taught-word header end to end', () => {
    const sheet = page([
        [800, [['DESCRIPTION', 50, 90], ['SEGMENT 1', 200, 70], ['SEGMENT 2', 300, 70], ['SEGMENT 3', 400, 70]]],
        [760, [['1', 55, 8], ['Mobilization', 70, 100], ['1000', 210, 30], ['2000', 310, 30], ['3000', 410, 30]]],
        [720, [['TOTAL', 55, 45], ['6000', 210, 30]]],
    ]);

    assert.equal(parseSCurvePdf([sheet]), null);
    const result = parseSCurvePdf([sheet], { monthWords: ['SEGMENT 1'] });
    assert.ok(result, 'expected a parse result');
    assert.equal(result.months.length, 3);
    assert.equal(result.months[2].planned_progress_percent, 100);
});

/* ─── F-C: weak-read trigger ─────────────────────────────────────────────── */

function weakMonths(namesPerMonth, gaps) {
    return {
        months: namesPerMonth.map((names, i) => ({
            month_index: i + 1,
            planned_progress_percent: (i + 1) * 10,
            activities: names.map((n) => ({ activity_name: n })),
        })),
        meta: {
            summaryReference: (gaps || []).map((gap, i) => ({
                month: i + 1, summary_percent: 0, activity_percent: gap, gap,
            })),
        },
    };
}

test('isWeakPdfCurve stays silent on strong and empty parses', () => {
    const strong = weakMonths([['Mobilization Works'], ['Earthworks']], [0.2]);
    assert.equal(isWeakPdfCurve(strong).weak, false);
    assert.equal(isWeakPdfCurve(null).weak, false);
    assert.equal(isWeakPdfCurve({ months: [], meta: {} }).weak, false);
});

test('isWeakPdfCurve flags generic-name floods but not nameless weekly curves', () => {
    const generic = weakMonths([['Activity 1', 'Activity 3'], ['Activity 2', 'Activity 4']], []);
    const found = isWeakPdfCurve(generic);
    assert.equal(found.weak, true);
    assert.ok(found.reasons.includes('generic-names'));

    const weekly = weakMonths([[], []], []);
    assert.equal(isWeakPdfCurve(weekly).weak, false);
});

test('isWeakPdfCurve flags short curves and summary gaps', () => {
    const short = weakMonths([['Mobilization Works']], []);
    const s = isWeakPdfCurve(short);
    assert.equal(s.weak, true);
    assert.ok(s.reasons.includes('months-short'));

    const gapped = weakMonths([['Mobilization Works'], ['Earthworks']], [0.2, 4.5]);
    const g = isWeakPdfCurve(gapped);
    assert.equal(g.weak, true);
    assert.ok(g.reasons.includes('summary-gap'));
});
/* ─── E1-PDF capture (PDF-1) ──────────────────────────────────────────────── */

test('relaxedMonthLabel reads any word-plus-index wording', () => {
    assert.deepEqual(relaxedMonthLabel('SEGMENT 1'), { stem: 'segment', index: 1 });
    assert.deepEqual(relaxedMonthLabel('P3'), { stem: 'p', index: 3 });
    assert.deepEqual(relaxedMonthLabel('Phase-2'), { stem: 'phase', index: 2 });
    // Out of month range, or not a word+index at all.
    assert.equal(relaxedMonthLabel('SEGMENT 13'), null);
    assert.equal(relaxedMonthLabel('50,000'), null);
    assert.equal(relaxedMonthLabel('Mobilization Works'), null);
    assert.equal(relaxedMonthLabel(''), null);
});

// A layout the reader cannot parse becomes a capture CANDIDATE: unknown wording
// is read by shape, and offered for approval rather than used.
test('derivePdfLayout proposes a map for unknown indexed wording', () => {
    const items = page([
        [800, [['ITEM NO.', 40, 50], ['DESCRIPTION', 120, 80], ['SEGMENT 1', 300, 55], ['SEGMENT 2', 400, 55]]],
        [760, [['1', 45, 8], ['Mobilization Works', 120, 100], ['50000', 310, 30], ['0', 410, 10]]],
        [720, [['2', 45, 8], ['Earthworks', 120, 70], ['0', 310, 10], ['150000', 410, 30]]],
    ]);

    // The rules must still miss it — otherwise this is not a capture case at all.
    assert.equal(parseSCurvePdf([items]), null);

    const out = derivePdfLayout([items]);
    assert.ok(out, 'expected a capture candidate');
    const first = out.map.pages[0];
    assert.deepEqual(first.monthCols.map((m) => m.index), [1, 2]);
    assert.equal(out.map.kind, 'pdf');
    assert.equal(out.map.v, 1);
    // Bands are FRACTIONS of the page width, so another page size still matches.
    assert.ok(first.monthCols.every((m) => m.x > 0 && m.x < 1 && m.xEnd <= 1.01));
    assert.ok(first.descX < first.monthCols[0].x, 'the desc band sits left of month 1');
    // The block must be the Excel slice's shape, or the desk cannot render it.
    assert.match(out.block.split('\n')[0], /^COLUMNS: A,B,C,D \|/);
    assert.match(out.block, /^R\d+ \[HEADER\]: ITEM NO\.,DESCRIPTION,SEGMENT 1,SEGMENT 2$/m);
    assert.match(out.block, /^R\d+ \[DATA\]: 1,Mobilization Works,50000,0$/m);
});

// The roof family labels every month identically ("30 days" x6) and its cells are
// percentages, so months are positional and values are not pesos.
test('derivePdfLayout reads positional months from repeated labels', () => {
    const items = page([
        [800, [['NO.', 40, 25], ['Amount', 120, 50],
            ['30 days', 220, 45], ['30 days', 300, 45], ['30days', 380, 42], ['30 days', 460, 45]]],
        [760, [['A', 45, 8], ['FACILITIES FOR ENGINEER', 120, 95],
            ['16%', 230, 20], ['17%', 310, 20], ['17%', 390, 20], ['16%', 470, 20]]],
    ]);

    const out = derivePdfLayout([items]);
    assert.ok(out, 'repeated labels must still propose a grid');
    assert.deepEqual(out.map.pages[0].monthCols.map((m) => m.index), [1, 2, 3, 4]);
    // Percent-valued activity rows are DATA — judging a row by its numeric cells
    // tagged every one of them SUMMARY, because isSummaryLabel matches a bare "%".
    assert.match(out.block, /^R\d+ \[DATA\]: A,FACILITIES FOR ENGINEER,16%,/m);
});

// Permissive detection is paid for here: a repeated word with nothing numeric
// beneath it is not a schedule, and must never propose one.
test('derivePdfLayout refuses a grid with no numbers under it', () => {
    const items = page([
        [800, [['Prepared by', 40, 60], ['Note', 220, 30], ['Note', 300, 30], ['Note', 380, 30]]],
        [760, [['Engineer', 45, 50], ['approved', 220, 50], ['approved', 300, 50], ['approved', 380, 50]]],
    ]);

    assert.equal(derivePdfLayout([items]), null);
    assert.equal(derivePdfLayout([]), null);
});

// The roof's last column reads "7 days" where the rest read "30 days" (6x30 + 7
// = the 187 days printed on the page). Keying on identical TEXT made that tail
// month invisible, capturing 6 months for a 7-month schedule.
test('positional grouping unites duration labels of different lengths', () => {
    const items = page([
        [800, [['NO.', 40, 25], ['Amount', 120, 50],
            ['30 days', 220, 45], ['30 days', 300, 45], ['30days', 380, 42], ['7 days', 460, 40]]],
        [760, [['A', 45, 8], ['ROOFING WORKS', 120, 95],
            ['16%', 230, 20], ['17%', 310, 20], ['17%', 390, 20], ['2%', 470, 18]]],
    ]);

    const out = derivePdfLayout([items]);
    assert.ok(out, 'expected a capture candidate');
    assert.equal(out.map.pages[0].monthCols.length, 4, 'the short tail column is a month too');
});

// Column-split tables are the norm: the roof spreads 7 months over 2 pages and
// SEGMENT spreads 5. A one-page map would teach a short schedule.
test('derivePdfLayout carries every table page of a split layout', () => {
    const first = page([
        [800, [['ITEM NO.', 40, 50], ['DESCRIPTION', 120, 80], ['SEGMENT 1', 300, 55], ['SEGMENT 2', 400, 55]]],
        [760, [['1', 45, 8], ['Mobilization Works', 120, 100], ['50000', 310, 30], ['0', 410, 10]]],
    ]);
    // The overflow page carries value columns only — no description column, which
    // is exactly why it must be allowed to have none.
    const second = page([
        [800, [['SEGMENT 3', 50, 55], ['SEGMENT 4', 150, 55], ['SEGMENT 5', 250, 55]]],
        [760, [['200000', 60, 30], ['250000', 160, 30], ['350000', 260, 30]]],
    ]);

    const out = derivePdfLayout([first, second]);
    assert.ok(out, 'expected a capture candidate');
    assert.equal(out.ambiguous, false, 'indexed labels number themselves');
    assert.equal(out.map.pages.length, 2);
    assert.deepEqual(out.map.pages[0].monthCols.map((m) => m.index), [1, 2]);
    // Page 2's labels carry their OWN indices, so the split merges without a guess.
    assert.deepEqual(out.map.pages[1].monthCols.map((m) => m.index), [3, 4, 5]);
});

// Positional wording restarts at 1 on every page, and the roof's page 2 even
// repeats its last column — nothing in the bytes says which month that is. The
// capture is still offered, but with NO map: a reviewer supplies the numbering
// rather than the machine guessing a schedule into existence.
test('a positional split is captured WITHOUT a map instead of guessed', () => {
    const first = page([
        [800, [['NO.', 40, 25], ['Amount', 120, 50],
            ['30 days', 220, 45], ['30 days', 300, 45], ['30 days', 380, 45]]],
        [760, [['A', 45, 8], ['ROOFING', 120, 60], ['16%', 230, 20], ['17%', 310, 20], ['17%', 390, 20]]],
    ]);
    const second = page([
        [800, [['30 days', 40, 45], ['7 days', 120, 40]]],
        [760, [['16%', 50, 20], ['2%', 130, 18]]],
    ]);

    const out = derivePdfLayout([first, second]);
    assert.ok(out, 'the capture must still reach the desk for review');
    assert.equal(out.ambiguous, true);
    assert.equal(out.map, null, 'an unknowable numbering must not be invented');
    assert.match(out.block, /^COLUMNS: /, 'the slice is still shown for teaching');
});

/* ─── E1-PDF consult (PDF-2) ──────────────────────────────────────────────── */

// The whole point of the loop: a file the rules cannot read becomes readable
// once its captured layout is approved — with no AI call.
test('an approved layout parses a file the rules cannot read', () => {
    const unreadable = page([
        [800, [['ITEM NO.', 40, 50], ['DESCRIPTION', 120, 80], ['SEGMENT 1', 300, 55], ['SEGMENT 2', 400, 55]]],
        [760, [['1', 45, 8], ['Mobilization Works', 120, 100], ['30000', 310, 30]]],
        [720, [['2', 45, 8], ['Earthworks', 120, 70], ['10000', 410, 30]]],
    ]);

    assert.equal(parseSCurvePdf([unreadable]), null, 'precondition: the rules must miss');

    const captured = derivePdfLayout([unreadable]);
    const liveMaps = [{ id: 7, map: captured.map }];
    const result = parseSCurvePdf([unreadable], { liveMaps });

    assert.ok(result, 'the learned layout must make it readable');
    assert.equal(result.meta.mapUsed, true);
    assert.equal(result.meta.mapId, 7);
    // 30000 then 10000 of 40000 → 75 / 100, names read from the desc band.
    assert.deepEqual(result.months.map((m) => m.planned_progress_percent), [75, 100]);
    assert.equal(result.months[0].description, 'Mobilization Works');
    assert.ok(
        result.meta.warnings.some((w) => w.includes('learned layout')),
        'a map-built curve must say so'
    );
});

// Maps EXTEND the reader, they never override it: a file the rules can read is
// byte-identical whatever has been approved.
test('rules win outright when they can read the file', () => {
    const readable = page([...HEADER, ...ROW1, ...ROW2, ...TOTAL]);
    const decoy = derivePdfLayout([page([
        [800, [['NO.', 40, 25], ['Amount', 120, 50],
            ['30 days', 220, 45], ['30 days', 300, 45], ['30 days', 380, 45]]],
        [760, [['A', 45, 8], ['ROOFING', 120, 60], ['16%', 230, 20], ['17%', 310, 20], ['17%', 390, 20]]],
    ])]);

    const plain = parseSCurvePdf([readable]);
    const withMaps = parseSCurvePdf([readable], { liveMaps: [{ id: 9, map: decoy.map }] });

    assert.deepEqual(withMaps.months, plain.months, 'the curve must not move');
    assert.ok(!withMaps.meta.mapUsed, 'no map may claim a rules-read file');
});

// Stored maps are gated on version, kind and SHAPE. The shape gate is not
// theoretical: captures taken before maps went multi-page carry a single-page
// layout that would read a column-split table short, so they must be ignored
// even when a row says "live".
test('unusable maps are ignored rather than trusted', () => {
    const unreadable = page([
        [800, [['ITEM NO.', 40, 50], ['DESCRIPTION', 120, 80], ['SEGMENT 1', 300, 55], ['SEGMENT 2', 400, 55]]],
        [760, [['1', 45, 8], ['Mobilization Works', 120, 100], ['30000', 310, 30]]],
        [720, [['2', 45, 8], ['Earthworks', 120, 70], ['10000', 410, 30]]],
    ]);
    const good = derivePdfLayout([unreadable]).map;

    const stale = { v: 1, kind: 'pdf', sig: good.pages[0].sig, ...good.pages[0] };
    delete stale.pages;

    for (const bad of [
        stale,                                   // pre-correction single-page shape
        { ...good, v: 999 },                     // version we do not understand
        { ...good, kind: 'excel' },              // an Excel map must never apply
        { ...good, pages: [] },                  // nothing to match
    ]) {
        assert.equal(
            parseSCurvePdf([unreadable], { liveMaps: [{ id: 1, map: bad }] }),
            null,
            'an unusable map must leave the file unread, not half-read'
        );
    }
});

// A signature can match on wording alone; the bands still have to hold numbers.
// Without this a same-worded cover page could pose as the table.
test('a matching signature over empty bands does not apply', () => {
    const unreadable = page([
        [800, [['ITEM NO.', 40, 50], ['DESCRIPTION', 120, 80], ['SEGMENT 1', 300, 55], ['SEGMENT 2', 400, 55]]],
        [760, [['1', 45, 8], ['Mobilization Works', 120, 100], ['30000', 310, 30]]],
        [720, [['2', 45, 8], ['Earthworks', 120, 70], ['10000', 410, 30]]],
    ]);
    const map = derivePdfLayout([unreadable]).map;

    // Same header wording, but the rows below carry no figures at all.
    const wordsOnly = page([
        [800, [['ITEM NO.', 40, 50], ['DESCRIPTION', 120, 80], ['SEGMENT 1', 300, 55], ['SEGMENT 2', 400, 55]]],
        [760, [['see attached schedule', 120, 140]]],
    ]);

    assert.equal(parseSCurvePdf([wordsOnly], { liveMaps: [{ id: 3, map }] }), null);
});

/* ─── E1-PDF learn-from-AI (PDF-3) ────────────────────────────────────────── */

// The AI states WHICH month each header is; the page says WHERE it sits. That
// reaches what capture alone cannot — identically labelled months, which capture
// must refuse to number.
test('an AI answer places identically labelled months by order', () => {
    const items = page([
        [800, [['NO.', 40, 25], ['Amount', 120, 50],
            ['30 days', 220, 45], ['30 days', 300, 45], ['30 days', 380, 45]]],
        [760, [['A', 45, 8], ['ROOFING', 120, 60], ['4000', 230, 30], ['4000', 310, 30], ['2000', 390, 30]]],
    ]);
    const labels = { 1: '30 days', 2: '30 days', 3: '30 days' };
    const aiMonths = [
        { month_index: 1, planned_progress_percent: 40 },
        { month_index: 2, planned_progress_percent: 80 },
        { month_index: 3, planned_progress_percent: 100 },
    ];

    const out = derivePdfLayoutFromAi([items], labels, aiMonths);
    assert.ok(out, 'the AI numbering must place the columns');
    assert.deepEqual(out.months, [1, 2, 3]);
    // Month N takes the Nth occurrence, left to right.
    const cols = out.map.pages[0].monthCols;
    assert.ok(cols[0].x < cols[1].x && cols[1].x < cols[2].x);
});

// THE GUARD THAT MATTERS: finding the columns is not the same as reading the
// table. The roof's cells are per-activity PERCENTAGES needing cost weighting,
// so its bands can be perfect while the curve comes out wrong — and a layout
// that cannot reproduce the AI's own answer must never be offered for approval.
test('a layout that cannot reproduce the AI curve teaches nothing', () => {
    const items = page([
        [800, [['NO.', 40, 25], ['Amount', 120, 50],
            ['30 days', 220, 45], ['30 days', 300, 45], ['30 days', 380, 45]]],
        [760, [['A', 45, 8], ['ROOFING', 120, 60], ['16%', 230, 20], ['17%', 310, 20], ['17%', 390, 20]]],
    ]);
    const labels = { 1: '30 days', 2: '30 days', 3: '30 days' };
    // The real answer for a percent table; the reader sums raw cells and cannot
    // get here, so the self-check must refuse.
    const aiMonths = [
        { month_index: 1, planned_progress_percent: 6.32 },
        { month_index: 2, planned_progress_percent: 24.31 },
        { month_index: 3, planned_progress_percent: 42.3 },
    ];

    assert.equal(derivePdfLayoutFromAi([items], labels, aiMonths), null);
});

// A label the page never prints yields no column — the AI cannot conjure one.
test('AI labels absent from the page place nothing', () => {
    const items = page([
        [800, [['DESCRIPTION', 50, 90], ['SEGMENT 1', 300, 55], ['SEGMENT 2', 400, 55]]],
        [760, [['Works', 70, 80], ['30000', 310, 30], ['10000', 410, 30]]],
    ]);

    // Only two of the four claimed months exist on the page.
    const out = derivePdfLayoutFromAi([items],
        { 1: 'SEGMENT 1', 2: 'SEGMENT 2', 3: 'SEGMENT 3', 4: 'SEGMENT 4' },
        [{ month_index: 1, planned_progress_percent: 75 }, { month_index: 2, planned_progress_percent: 100 }]);

    assert.ok(out);
    assert.deepEqual(out.months, [1, 2], 'unprinted labels must not become columns');
    // Too few labels to be a schedule at all.
    assert.equal(derivePdfLayoutFromAi([items], { 1: 'SEGMENT 1' }, []), null);
    assert.equal(derivePdfLayoutFromAi([], { 1: 'A', 2: 'B' }, []), null);
});

// THE SHAPE THE BROWSER ACTUALLY HANDS derivePdfLayoutFromAi.
//
// Every other fixture here feeds RAW pdf.js items ({str, transform, width}),
// because that is what the reader receives during a parse. But PDF-3 runs LATER
// — the user clicks "Try AI assist" long after the parse — so it reads a compact
// stash instead, built by parseSCurvePdfFile in scurve-pdf-parser.js.
//
// The first cut of that stash emitted {str, x, xEnd} and shipped completely dead:
// with no `y` every token on a page collapsed onto one line, bandsHoldNumbers
// found no rows beneath the header, and the derivation returned null on EVERY
// file — silently, because a refusal is a legitimate answer. No fixture caught it
// because no fixture had ever fed this shape.
//
// KEEP stashToken() BYTE-FOR-BYTE IN STEP WITH scurve-pdf-parser.js. It exists to
// make the stash contract executable, and it is worth nothing if the two drift.
function stashToken(it) {
    return {
        str: (it && it.str) || '',
        x: it && it.transform ? it.transform[4] : ((it && it.x) || 0),
        y: it && it.transform ? it.transform[5] : ((it && it.y) || 0),
        width: (it && it.width) || 0,
    };
}

const asStashed = (byPage) => byPage.map((items) => (items || []).map(stashToken));

test('the stashed token shape derives exactly what raw pdf.js items do', () => {
    const items = page([
        [800, [['ITEM NO.', 40, 50], ['DESCRIPTION', 120, 80],
            ['SEGMENT 1', 300, 55], ['SEGMENT 2', 400, 55]]],
        [760, [['1', 45, 8], ['Mobilization Works', 120, 100], ['30000', 310, 30]]],
        [720, [['2', 45, 8], ['Earthworks', 120, 70], ['10000', 410, 30]]],
    ]);
    const labels = { 1: 'SEGMENT 1', 2: 'SEGMENT 2' };
    const aiMonths = [
        { month_index: 1, planned_progress_percent: 75 },
        { month_index: 2, planned_progress_percent: 100 },
    ];

    const fromRaw = derivePdfLayoutFromAi([items], labels, aiMonths);
    const fromStash = derivePdfLayoutFromAi(asStashed([items]), labels, aiMonths);

    assert.ok(fromRaw, 'the raw-item baseline must derive a layout');
    assert.ok(fromStash, 'the browser feeds the stash shape — it must derive one too');
    // Not merely "both truthy": the stash must lose NOTHING. Bands are stored as
    // fractions of the page width, so an identical map means identical geometry.
    assert.deepEqual(fromStash.months, fromRaw.months);
    assert.deepEqual(fromStash.map, fromRaw.map);
    assert.equal(fromStash.block, fromRaw.block);
});

// Guard the two keys whose absence fails SILENTLY, each on its own, so a future
// edit to the stash is told exactly which half it broke. They fail DIFFERENTLY,
// and the quieter one is the dangerous one: losing y refuses outright, while
// losing width still produces a map — just a skewed one, which would teach a
// wrong layout rather than teach nothing.
test('dropping y or width from the stash breaks the derivation', () => {
    const items = page([
        [800, [['ITEM NO.', 40, 50], ['DESCRIPTION', 120, 80],
            ['SEGMENT 1', 300, 55], ['SEGMENT 2', 400, 55]]],
        [760, [['1', 45, 8], ['Mobilization Works', 120, 100], ['30000', 310, 30]]],
        [720, [['2', 45, 8], ['Earthworks', 120, 70], ['10000', 410, 30]]],
    ]);
    const labels = { 1: 'SEGMENT 1', 2: 'SEGMENT 2' };
    const aiMonths = [
        { month_index: 1, planned_progress_percent: 75 },
        { month_index: 2, planned_progress_percent: 100 },
    ];
    const stashed = asStashed([items]);
    const truth = derivePdfLayoutFromAi([items], labels, aiMonths);

    // No y: groupItemsIntoLines puts every token on one line at y=0, so no row
    // ever sits beneath the header and bandsHoldNumbers refuses.
    const noY = [stashed[0].map(({ str, x, width }) => ({ str, x, width }))];
    assert.equal(derivePdfLayoutFromAi(noY, labels, aiMonths), null,
        'a stash without y must not silently half-work');

    // No width: linesToTokenLines gives every token xEnd === x. The derivation
    // still SUCCEEDS, which is the trap — but every band has drifted, because the
    // page width and the band edges are both measured from token extents. A
    // skewed map that reaches approval is worse than no map at all, so assert the
    // geometry, never merely that something came back.
    const noW = [stashed[0].map(({ str, x, y }) => ({ str, x, y }))];
    const skewed = derivePdfLayoutFromAi(noW, labels, aiMonths);
    assert.ok(skewed, 'width loss is silent — it does not refuse');
    assert.notDeepEqual(skewed.map.pages[0].monthCols, truth.map.pages[0].monthCols,
        'a stash without width must not be mistaken for lossless');
});
