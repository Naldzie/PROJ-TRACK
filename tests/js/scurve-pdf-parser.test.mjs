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
    crossCheckPdfAgainstFile,
    pdfCurvesDisagree,
    pdfSliceRenderScale,
    pdfSliceShrinkNote,
    chartScaleTokens,
    detectPseudoColumns,
    groupItemsIntoLines,
    linesToTokenLines,
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

/* ─── Page-edge marking (2026-09-15) ──────────────────────────────────────── */

// The roof's two-page print cuts its month-6 TOTAL cell at the page edge ("495,173."
// ending at 793.9 on a 792 pt page); the full figure is on page 2. A capture given the
// page boxes lists such cells on an EDGE line so the teach grid can fade them.
const EDGE_ONE_PAGE = () => page([
    [800, [['NO.', 40, 25], ['Amount', 120, 50],
        ['30 days', 220, 45], ['30 days', 300, 45], ['30days', 380, 42], ['30 days', 460, 45]]],
    [760, [['A', 45, 8], ['FACILITIES FOR ENGINEER', 120, 95],
        ['16%', 230, 20], ['17%', 310, 20], ['17%', 390, 20], ['16%', 470, 20]]],
]);

// Strip the EDGE line, to prove nothing else in the block moved.
const withoutEdge = (block) => block.split('\n').filter((l) => !l.startsWith('EDGE:')).join('\n');

test('a capture marks a cell cut off at the right page edge, and changes nothing else', () => {
    const items = EDGE_ONE_PAGE();
    const plain = derivePdfLayout([items]);
    // The last "30 days" label runs 460 -> 505; the visible page ends at 500.
    const marked = derivePdfLayout([items], 15, { pageBoxes: [[0, 0, 500, 900]] });

    const edge = marked.block.split('\n')[1];
    assert.match(edge, /^EDGE: R\d+:[A-Z]+$/, 'one cut cell, listed right under the legend');
    const [, rowNo, letter] = edge.match(/^EDGE: R(\d+):([A-Z]+)$/);
    const row = marked.block.split('\n').find((l) => l.startsWith('R' + rowNo + ' '));
    assert.match(row, /\[HEADER\]/, 'the cut token is the header line’s last label');
    assert.equal(letter, marked.map.pages[0].pseudoCols[marked.map.pages[0].pseudoCols.length - 1].letter);

    assert.equal(withoutEdge(marked.block), plain.block, 'the rest of the block is byte-identical');
    assert.deepEqual(marked.map, plain.map, 'the layout map never changes');
});

test('a capture marks text cut off at the LEFT page edge too', () => {
    // "NO." starts at x 40; a crop box starting at 42 cuts it. "A" at 45 is inside.
    const marked = derivePdfLayout([EDGE_ONE_PAGE()], 15, { pageBoxes: [[42, 0, 1000, 900]] });
    assert.match(marked.block.split('\n')[1], /^EDGE: R\d+:A$/);
});

test('nothing cut, or no page boxes: the block is exactly what it always was', () => {
    const items = EDGE_ONE_PAGE();
    const plain = derivePdfLayout([items]);
    assert.doesNotMatch(plain.block, /^EDGE:/m, 'no boxes -> no marks (block_snapshot’s case)');
    const roomy = derivePdfLayout([items], 15, { pageBoxes: [[0, 0, 792, 612]] });
    assert.equal(roomy.block, plain.block, 'a page wide enough for every token adds no line');
    // Half a point of slack: text ending right at the edge is not "cut".
    const flush = derivePdfLayout([items], 15, { pageBoxes: [[0, 0, 504.6, 612]] });
    assert.equal(flush.block, plain.block);
});

test('a two-page capture marks the cut cell under its run-on letter', () => {
    const first = page([
        [800, [['NO.', 40, 25], ['Amount', 120, 50],
            ['30 days', 220, 45], ['30 days', 300, 45], ['30 days', 380, 45]]],
        [760, [['A', 45, 8], ['ROOFING', 120, 60], ['16%', 230, 20], ['17%', 310, 20], ['17%', 390, 20]]],
    ]);
    const second = page([
        [800, [['30 days', 40, 45], ['7 days', 120, 40]]],
        [760, [['16%', 50, 20], ['2%', 130, 18]]],
    ]);
    const plain = derivePdfLayout([first, second]);
    // Page 1's last label runs 380 -> 425; its visible page ends at 420. Page 2 is roomy.
    const marked = derivePdfLayout([first, second], 15, { pageBoxes: [[0, 0, 420, 900], [0, 0, 792, 612]] });

    const letters = marked.map.pages[0].pseudoCols.map((c) => c.letter);
    assert.equal(marked.block.split('\n')[1], 'EDGE: R' + (plain.block.split('\n').findIndex((l) => /\[HEADER\]/.test(l))) + ':' + letters[letters.length - 1]);
    assert.equal(withoutEdge(marked.block), plain.block);
});

test('an AI-derived capture marks cut cells the same way', () => {
    const items = EDGE_ONE_PAGE();
    const labels = { 1: '30 days', 2: '30 days', 3: '30days', 4: '30 days' };
    const plain = derivePdfLayoutFromAi([items], labels, null);
    const marked = derivePdfLayoutFromAi([items], labels, null, 15, { pageBoxes: [[0, 0, 500, 900]] });
    assert.ok(plain && marked);
    assert.match(marked.block.split('\n')[1], /^EDGE: R\d+:[A-Z]+$/);
    assert.equal(withoutEdge(marked.block), plain.block);
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
    assert.match(out.block, /^COLUMNS: /, 'the slice is still shown for teaching');

    // PDF-4 Stage 2: an ambiguous capture keeps its GEOMETRY and loses only its
    // NUMBERING. It used to lose both — the map went null and pseudoCols went with
    // it — so the rows this path exists to hand a reviewer arrived with letters and
    // cell text but not one coordinate, and teaching them was impossible.
    assert.ok(out.map, 'the geometry survives so the capture can be taught');
    assert.equal(out.map.kind, 'pdf');
    out.map.pages.forEach((p) => {
        assert.equal(p.unnumbered, true, 'every page is marked as awaiting numbering');
        assert.deepEqual(p.monthCols, [], 'an unknowable numbering must not be invented');
        assert.ok(p.pseudoCols.length >= 2, 'but its columns are recorded, ready to teach');
    });
    // Letters run on across the seam, so one legend describes the whole table.
    const letters = out.map.pages.flatMap((p) => p.pseudoCols.map((c) => c.letter));
    assert.equal(new Set(letters).size, letters.length, 'letters are unique across pages');

    // And it still cannot be READ from until someone teaches it — geometry alone
    // is an offer to the reviewer, never a layout the importer may trust.
    assert.equal(parseSCurvePdf([first, second], { liveMaps: [{ id: 1, map: out.map }] }), null,
        'an untaught capture must not become a baseline');
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

// 2026-09-14 (user-reported, roof PDF) — a layout whose bands do not sit over this
// page's own labels must not read it. An older single-page roof layout stored SEVEN
// equal bands over a page that prints SIX "30 days" labels; the wording matched and
// numbers sat under enough bands, so it applied and read month 4 twice and lost
// 99.14. Every band of a layout that fits holds its own label, so the rule is
// "every band has a label" — the taught layout that skips the stub column still
// applies, and the misfit refuses.
test('a layout whose bands miss the page’s own labels does not apply', () => {
    const W = 745; // pageMaxX: the last label ends at 700 + 45
    const roofLike = page([
        [800, [['NO.', 40, 25], ['Amount', 120, 50],
            ['30 days', 300, 45], ['30 days', 380, 45], ['30 days', 460, 45],
            ['30 days', 540, 45], ['30 days', 620, 45], ['30 days', 700, 45]]],
        [760, [['A', 45, 8], ['ROOFING', 60, 60],
            ['1000', 310, 30], ['2000', 390, 30], ['3000', 470, 30], ['4000', 550, 30], ['2000', 630, 30]]],
        [720, [['B', 45, 8], ['WALLS', 60, 45],
            ['1000', 310, 30], ['1000', 390, 30], ['1000', 470, 30], ['1000', 550, 30], ['2000', 630, 30]]],
    ]);
    assert.equal(parseSCurvePdf([roofLike]), null, 'precondition: the rules must miss');

    const layout = (monthCols) => ({ v: 1, kind: 'pdf', pages: [{
        pageHint: 0, sig: ['no.', 'amount', '30 days'], descX: 40 / W, descRight: 280 / W, monthCols,
    }] });
    // Fits: five bands, each around its own label; the sixth (stub) column unclaimed.
    const fits = layout([0, 1, 2, 3, 4].map((k) => ({ index: k + 1, x: (290 + 80 * k) / W, xEnd: (370 + 80 * k) / W })));
    // Misfit: seven equal bands from 0.38 to the page edge, the shape of DB row 116.
    const step = 0.62 / 7;
    const misfit = layout([0, 1, 2, 3, 4, 5, 6].map((k) => ({ index: k + 1, x: 0.38 + k * step, xEnd: 0.38 + (k + 1) * step })));

    const good = parseSCurvePdf([roofLike], { liveMaps: [{ id: 123, map: fits }] });
    assert.ok(good && good.meta.mapUsed, 'the fitting layout still reads the file');
    assert.equal(good.months.length, 5);

    const bad = parseSCurvePdf([roofLike], { liveMaps: [{ id: 116, map: misfit }] });
    assert.ok(bad === null || !bad.meta.mapUsed,
        'a layout whose band holds no label must leave the file unread, not read it wrong');
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

/* ─── P4 summary-row adoption ─────────────────────────────────────────────── */

// The roof shape, reduced to a fixture: each activity's cells are percentages of
// ITS OWN duration, so every row sums to ~100 and the rows together total far
// more than the contract. Summing them is meaningless; the author's printed
// cumulative row is the only honest curve on the page.
function roofShapedPage(cumulativeTokens) {
    return page([
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55], ['MONTH 3', 400, 55]]],
        [760, [['Roofing', 60, 70], ['50%', 210, 25], ['30%', 310, 25], ['20%', 410, 25]]],
        [720, [['Walls', 60, 60], ['40%', 210, 25], ['40%', 310, 25], ['20%', 410, 25]]],
        [680, [['TOTAL', 55, 45], ['100', 210, 25]]],
        [640, cumulativeTokens],
    ]);
}

const CUMULATIVE_ROW = [['30.00%', 210, 30], ['65.00%', 310, 30], ['100.00%', 410, 32]];

test('a file whose cells cannot be added adopts its printed cumulative row', () => {
    const result = parseSCurvePdf([roofShapedPage(CUMULATIVE_ROW)]);

    assert.ok(result, 'the file must still read');
    assert.equal(result.meta.curveSource, 'summary');
    assert.deepEqual(result.months.map((m) => m.planned_progress_percent), [30, 65, 100],
        'the curve must be the author’s, not the sum of incompatible cells');
    // The activity sum here is 200% of the contract — that overshoot IS the proof
    // the cells do not share the contract's denominator, and it must be spoken.
    assert.ok(result.meta.warnings.some((w) => /cannot be added up/i.test(w)),
        'adoption is never silent');
    // Comparing the adopted curve against the math it replaced compares it with
    // itself, so gaps are reported as absent rather than as zero.
    assert.ok(result.meta.summaryReference.length >= 2);
    assert.equal(result.meta.summaryReference[0].gap, null);
});

// The guard that protects every other file: adoption must be unreachable while
// the activity math is sound. BUHI is the standing reminder — its summary
// disagrees by up to 3 points and the activity math still, correctly, wins.
test('a well-formed weighted file never adopts its summary', () => {
    const result = parseSCurvePdf([page([...HEADER, ...ROW1, ...ROW2, ...TOTAL])]);

    assert.ok(result);
    assert.equal(result.meta.curveSource, 'activities');
    assert.ok(!result.meta.warnings.some((w) => /cumulative row/i.test(w)));
});

// Refuse rather than hand back a curve known to be wrong: the cells cannot be
// added and the author printed no cumulative row to stand in for them.
test('unusable cells with no printed curve refuse the file outright', () => {
    // Same shape, but the cumulative row is a per-month block: it repeats rather
    // than ascending, so it is not a curve and must not be adopted.
    const notACurve = [['30.00%', 210, 30], ['30.00%', 310, 30], ['40.00%', 410, 30]];

    assert.equal(parseSCurvePdf([roofShapedPage(notACurve)]), null,
        'a wrong curve is worse than no curve');
});

// A column-split print puts the tail of the cumulative row on the overflow page,
// where it has no neighbours to prove it ascends. It is tied to the row it
// continues BY HEIGHT — the same shared-Y property P3's matchRowsByY relies on.
test('a continuation page supplies the cumulative tail by row height', () => {
    // A single-month overflow page is invisible to the RULES detector, so this is
    // reached the way the roof reaches it: through a learned layout. Unknown
    // header wording ('STINT n') keeps the rules out, which is what puts the map
    // pass in play.
    const first = page([
        [800, [['DESCRIPTION', 50, 90], ['STINT 1', 200, 55], ['STINT 2', 300, 55]]],
        [760, [['Roofing', 60, 70], ['60%', 210, 25], ['40%', 310, 25]]],
        [720, [['Walls', 60, 60], ['70%', 210, 25], ['30%', 310, 25]]],
        [680, [['TOTAL', 55, 45], ['100', 210, 25]]],
        [640, [['40.00%', 210, 30], ['80.00%', 310, 30]]],
    ]);
    // The overflow page REPEATS the last column and adds the new one, exactly as
    // the roof's does ('30 days' then '7 days'). A header line needs two anchors to
    // be identifiable at all, and the repeat is already-claimed so it takes no month.
    const second = page([
        [800, [['STINT 2', 200, 55], ['STINT 3', 300, 55]]],
        [760, [['30%', 210, 25], ['25%', 310, 25]]],
        // The tail of the cumulative row, at the SAME height as its head on page 1.
        [640, [['80.00%', 210, 30], ['100.00%', 310, 32]]],
    ]);
    const labels = { 1: 'STINT 1', 2: 'STINT 2', 3: 'STINT 3' };
    const derived = derivePdfLayoutFromAi([first, second], labels, null);
    assert.ok(derived, 'the fixture must yield a two-page map');

    const result = parseSCurvePdf([first, second], { liveMaps: [{ id: 1, map: derived.map }] });

    assert.ok(result);
    assert.equal(result.meta.curveSource, 'summary');
    assert.deepEqual(result.months.map((m) => m.planned_progress_percent), [40, 80, 100],
        'the overflow page must supply month 3');
});

// A lone percentage on a continuation page proves nothing on its own. Without a
// cumulative row at the same height on an earlier page it is just a number, and
// the roof's overflow page offers three rival candidates (100%, 50%, 100%) to
// prove the point.
test('a stray tail percentage is not mistaken for a cumulative row', () => {
    const first = page([
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55]]],
        [760, [['Roofing', 60, 70], ['1000', 210, 30], ['1000', 310, 30]]],
    ]);
    const second = page([
        [800, [['MONTH 3', 200, 55]]],
        // Same height as nothing on page 1 that was a cumulative row.
        [500, [['90.00%', 210, 30]]],
    ]);

    const result = parseSCurvePdf([first, second]);
    assert.ok(result);
    assert.equal(result.meta.curveSource, 'activities',
        'one number at an unrelated height must not become the curve');
});

/* ─── P5 AI-vs-file cross-check ───────────────────────────────────────────── */

// Unknown header wording keeps the RULES out, which is what puts the AI-derived
// map in play — the same situation a real AI assist runs in.
function crossCheckPage() {
    return page([
        [800, [['DESCRIPTION', 50, 90], ['STINT 1', 200, 55], ['STINT 2', 300, 55], ['STINT 3', 400, 55]]],
        [760, [['Roofing', 60, 70], ['50%', 210, 25], ['30%', 310, 25], ['20%', 410, 25]]],
        [720, [['Walls', 60, 60], ['40%', 210, 25], ['40%', 310, 25], ['20%', 410, 25]]],
        [680, [['TOTAL', 55, 45], ['100', 210, 25]]],
        [640, [['30.00%', 210, 30], ['65.00%', 310, 30], ['100.00%', 410, 32]]],
    ]);
}
const CROSS_LABELS = { 1: 'STINT 1', 2: 'STINT 2', 3: 'STINT 3' };

test('a contradicting AI answer is replaced by the file’s printed curve', () => {
    // Shaped like the real failure: every gap is UNDER a point, so the 1.0
    // layout tolerance would call this agreement — yet the curve completes early.
    const aiMonths = [
        { month_index: 1, planned_progress_percent: 30.4 },
        { month_index: 2, planned_progress_percent: 65.6 },
        { month_index: 3, planned_progress_percent: 100 },
    ];

    const fileRead = crossCheckPdfAgainstFile([crossCheckPage()], CROSS_LABELS, aiMonths);

    assert.ok(fileRead, 'the file must be readable through the AI’s own layout');
    assert.equal(fileRead.meta.curveSource, 'summary');
    assert.deepEqual(fileRead.months.map((m) => m.planned_progress_percent), [30, 65, 100]);
    assert.equal(pdfCurvesDisagree(fileRead.months, aiMonths), true,
        'sub-1% differences still count — the reviewer would read different numbers');
});

test('an AI answer that matches the file is left alone', () => {
    const aiMonths = [
        { month_index: 1, planned_progress_percent: 30 },
        { month_index: 2, planned_progress_percent: 65 },
        { month_index: 3, planned_progress_percent: 100 },
    ];
    const fileRead = crossCheckPdfAgainstFile([crossCheckPage()], CROSS_LABELS, aiMonths);

    assert.ok(fileRead);
    assert.equal(pdfCurvesDisagree(fileRead.months, aiMonths), false,
        'agreement must not trigger a swap, or every import gains a false alarm');
});

// THE CIRCULARITY GATE. The map comes from the AI's own labels, so a replay built
// on activity math shares its reasoning with the answer it would be judging. Only
// the author's printed cumulative row — the document stating its own curve — is
// allowed to overrule the model.
test('a replay resting on activity math never overrules the AI', () => {
    const wellFormed = page([
        [800, [['DESCRIPTION', 50, 90], ['STINT 1', 200, 55], ['STINT 2', 300, 55]]],
        [760, [['Roofing', 60, 70], ['1000', 210, 30], ['1000', 310, 30]]],
        [720, [['Walls', 60, 60], ['1000', 210, 30], ['1000', 310, 30]]],
    ]);
    const aiMonths = [
        { month_index: 1, planned_progress_percent: 12 },
        { month_index: 2, planned_progress_percent: 100 },
    ];

    assert.equal(crossCheckPdfAgainstFile([wellFormed], { 1: 'STINT 1', 2: 'STINT 2' }, aiMonths), null,
        'activity math must stay advisory, never authoritative');
});

test('a partial file reading cannot stand in for a complete answer', () => {
    const aiMonths = [
        { month_index: 1, planned_progress_percent: 30 },
        { month_index: 2, planned_progress_percent: 65 },
        { month_index: 3, planned_progress_percent: 100 },
        { month_index: 4, planned_progress_percent: 100 },
    ];
    // Month 4 exists in the answer but nowhere in the document.
    assert.equal(crossCheckPdfAgainstFile([crossCheckPage()], CROSS_LABELS, aiMonths), null,
        'missing months must refuse the override, not silently drop them');
});

/* ─── P6 the chart's own scale is not table data ──────────────────────────── */

// Transcribed from the roof's page 2, where the S-curve picture is printed beside
// the table's overflow columns and its scale (100% / 75 / 50% / 25% / 0%) lands
// inside the LAST month band. Geometry kept in proportion to the real file: the
// scale sits right of the last month's values, evenly spaced down the page.
//
// Note the '75' rung is a BARE number with its '%' as a separate token — that is
// how pdf.js emits it on the real file, and a percent-only rule would see four
// rungs and misjudge the spacing.
function chartPage(extraLines = []) {
    return page([
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55]]],
        [760, [['Roofing', 60, 70], ['50%', 210, 25], ['30%', 310, 25]]],
        // The chart's scale: strictly down, 100 -> 0, evenly spaced, on its own x.
        [780, [['100%', 430, 28]]],
        [740, [['75', 432, 14], ['%', 446, 8]]],
        [700, [['50%', 430, 28]]],
        [660, [['25%', 430, 28]]],
        [620, [['0%', 433, 22]]],
        ...extraLines,
    ]);
}

// Symptom 1 on the roof: the scale rung printed on the SAME LINE as a real row
// was added to that row's month, pushing a month with no work above zero. No
// merging is involved here — the two tokens share a text line — which is why
// tightening the page-merge tolerance could never have fixed it.
test('a chart scale rung on a real row’s line is not added to that row', () => {
    // 'Walls' has 20% in month 2, and the scale's 50% sits on the same line.
    const withRow = chartPage([[700, [['Walls', 60, 60], ['30%', 210, 25], ['20%', 310, 25]]]]);
    const result = parseSCurvePdf([withRow]);

    assert.ok(result, 'the file must still read');
    const wallsMonth2 = result.months[1].activities.map((a) => a.activity_name);
    assert.ok(wallsMonth2.includes('Walls'), 'the real row keeps its own month');
    // 20 + 50 would have been counted had the scale been read as a cell; the
    // curve is built from cells, so the month totals prove the skip fired.
    assert.ok(result.meta.contractAmount < 200,
        'the scale must contribute nothing to the month totals');
});

// Symptom 2 on the roof: a rung on its own line became a nameless row, which
// mergePages then glued onto a page-1 row sitting 3.3pt away — inside its
// tolerance — so a peso subtotal line surfaced as an activity.
test('a chart scale rung on its own line never becomes a row', () => {
    const result = parseSCurvePdf([chartPage()]);

    assert.ok(result);
    const everyName = result.months.flatMap((m) => m.activities.map((a) => a.activity_name));
    assert.ok(!everyName.some((n) => /^Activity \d+$/.test(n)),
        'scale rungs must not survive as nameless activities');
    // Roofing is scheduled in both months, so it is listed twice — the point is
    // that NOTHING ELSE is listed.
    assert.deepEqual([...new Set(everyName)], ['Roofing'],
        'only the real row is an activity');
});

// The guard that keeps this from eating data. A genuine column may descend; it
// is the 100 -> 0 span that marks a drawn scale. Without both endpoints the
// numbers stay cells.
test('a descending column that is not a 100-to-0 scale is still read', () => {
    const descending = page([
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55]]],
        [760, [['Roofing', 60, 70], ['600', 210, 30], ['90', 310, 25]]],
        [720, [['Walls', 60, 60], ['300', 210, 30], ['60', 310, 25]]],
        [680, [['Finishing', 60, 80], ['100', 210, 30], ['30', 310, 25]]],
    ]);
    const result = parseSCurvePdf([descending]);

    assert.ok(result, 'a descending column is ordinary data');
    // Month 2's column runs 90 / 60 / 30 — strictly down and evenly spaced, but it
    // neither starts at 100 nor ends at 0, so every cell must still count.
    assert.equal(result.meta.contractAmount, 1180,
        'all three rows must contribute to both months');
});

// The spacing condition earns its place: a drawn axis is evenly divided, a run of
// table rows need not be. Values that hit 100 and 0 but sit at irregular heights
// are not claimed.
test('an unevenly spaced 100-to-0 run is not claimed as a chart scale', () => {
    const uneven = page([
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55]]],
        [760, [['Roofing', 60, 70], ['1000', 210, 30], ['100%', 430, 28]]],
        [600, [['Walls', 60, 60], ['1000', 210, 30], ['50%', 430, 28]]],
        [570, [['Finishing', 60, 80], ['1000', 210, 30], ['0%', 433, 22]]],
    ]);
    const scale = chartScaleTokens(linesToTokenLines(groupItemsIntoLines(uneven)));

    assert.equal(scale.size, 0, 'gaps of 160 then 30 are not an axis');
});

// The last month band used to run to the raw page edge, which is what let the
// chart into the table's column space at all. It now stops a neighbour's width
// past its own label. Measured as a no-op on every sample PDF — this fixture is
// what keeps it that way.
test('the last month band stops a neighbour-width past its label', () => {
    // Months at x=200 and x=300 → 100pt columns, so month 2 must end at 400,
    // not at the page edge. The 700pt token is beyond it and must not be read.
    const wideStray = page([
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55]]],
        [760, [['Roofing', 60, 70], ['1000', 210, 30], ['1000', 310, 30], ['5000', 700, 35]]],
    ]);
    const result = parseSCurvePdf([wideStray]);

    assert.ok(result);
    assert.equal(result.meta.contractAmount, 2000,
        'a value past the last band must not be swept into it');
});
/* ─── PDF-4 Stage 2: the teach grid must picture the real table ───────────── */

// The defect this whole stage exists for. A contractor header prints its labels
// across two lines and skips columns entirely — the roof's prints "NO." then jumps
// to "Amount" — so reading columns off the HEADER LINE alone produced one enormous
// band that swallowed DESCRIPTION, UNIT and QTY together. The reviewer was shown a
// table the document does not contain.
test('columns come from the whole table, not the header line alone', () => {
    const sheet = page([
        // Header line names only two of the five columns, exactly like the roof.
        [800, [['NO.', 40, 22], ['Amount', 250, 45], ['Month 1', 340, 50], ['Month 2', 430, 50]]],
        // ... but the body prints all of them, aligned.
        [760, [['A', 42, 8], ['ROOFING WORKS', 70, 110], ['LOT', 190, 22], ['1.00', 220, 24],
            ['14,306.44', 255, 44], ['16%', 350, 20], ['17%', 440, 20]]],
        [720, [['B', 42, 8], ['EARTHWORKS', 70, 90], ['LOT', 190, 22], ['1.00', 220, 24],
            ['142,417.00', 251, 48], ['20%', 350, 20], ['27%', 440, 20]]],
    ]);
    const lines = linesToTokenLines(groupItemsIntoLines(sheet));
    const cols = detectPseudoColumns(lines, 0, lines.length, null);

    // item code | description | unit | qty | amount | month 1 | month 2
    assert.ok(cols.length >= 6,
        'the header line names 4 columns; the table has at least 6 — got ' + cols.length);

    const cellOf = (x) => cols.findIndex((c, k) => x >= c.x && x < (cols[k + 1] ? cols[k + 1].x : Infinity));
    assert.notEqual(cellOf(70), cellOf(190), 'DESCRIPTION and UNIT are different columns');
    assert.notEqual(cellOf(190), cellOf(220), 'UNIT and QTY are different columns');
    assert.notEqual(cellOf(220), cellOf(255), 'QTY and the amount are different columns');
});

// A right-aligned money column drifts left as its figures get wider — 14,306.44
// starts at 255 while 142,417.00 starts at 251 — yet it is ONE column. The strips
// sit almost exactly on top of each other, which is what says so.
test('a right-aligned money column stays one column', () => {
    const sheet = page([
        [800, [['Amount', 250, 45], ['Month 1', 340, 50], ['Month 2', 430, 50]]],
        [760, [['14,306.44', 255, 44], ['16%', 350, 20], ['17%', 440, 20]]],
        [720, [['142,417.00', 251, 48], ['20%', 350, 20], ['27%', 440, 20]]],
        [680, [['1,890,436.78', 247, 52], ['6%', 350, 20], ['19%', 440, 20]]],
    ]);
    const lines = linesToTokenLines(groupItemsIntoLines(sheet));
    const cols = detectPseudoColumns(lines, 0, lines.length, null);
    const cellOf = (x) => cols.findIndex((c, k) => x >= c.x && x < (cols[k + 1] ? cols[k + 1].x : Infinity));

    assert.equal(cellOf(255), cellOf(251), 'the widest and narrowest figures share a column');
    assert.equal(cellOf(255), cellOf(247), 'and so does the widest of all');
    assert.notEqual(cellOf(255), cellOf(350), 'but the months remain separate');
});

// The S-curve picture's own scale is not data. Left in, it drew phantom rows
// holding nothing but "100%" or "25%", and put a stray "50%" in the same cell as a
// real value. Same detector the reader already uses (P6).
test('the chart scale never reaches the teaching grid', () => {
    const sheet = page([
        [800, [['DESCRIPTION', 40, 80], ['Month 1', 200, 50], ['Month 2', 300, 50]]],
        [770, [['100%', 430, 28]]],
        [760, [['Roofing', 45, 55], ['60%', 210, 22], ['40%', 310, 22]]],
        [730, [['75%', 430, 28]]],
        [720, [['Walls', 45, 45], ['70%', 210, 22], ['30%', 310, 22]]],
        [690, [['50%', 430, 28]]],
        [650, [['25%', 430, 28]]],
        [610, [['0%', 433, 22]]],
    ]);
    const out = derivePdfLayout([sheet]);
    assert.ok(out && out.block, 'the capture still produces a slice');
    assert.ok(!/100%|75%|25%/.test(out.block),
        'no scale rung may appear as a cell:\n' + out.block);
    assert.match(out.block, /Roofing/, 'while the real rows are untouched');
});

// The 15-row window used to stop one row before the CUMULATIVE line — the very
// figure a baseline is adopted from — so a reviewer decided whether to trust a
// layout with the evidence for it just below the fold.
test('summary rows survive past the row budget', () => {
    const rows = [
        [800, [['DESCRIPTION', 40, 80], ['Month 1', 200, 50], ['Month 2', 300, 50]]],
    ];
    // Twelve activity rows: more than enough to spend a small budget.
    for (let i = 0; i < 12; i++) {
        rows.push([760 - i * 20, [['Task ' + i, 45, 50], ['5%', 210, 18], ['5%', 310, 18]]]);
    }
    rows.push([500, [['CUMULATIVE %', 40, 90], ['40.00%', 205, 32], ['100.00%', 303, 36]]]);
    const out = derivePdfLayout([page(rows)], 6);

    assert.match(out.block, /\[SUMMARY\]/, 'the summary block is shown');
    assert.match(out.block, /100\.00%/, 'including the cumulative row past the budget');
});

// 2026-09-15 — the CUMULATIVE promotion was an unconditional `if`, so any line whose
// month cells read as rising percentages was re-tagged — including a title-block
// line ABOVE the header. A META or HEADER line losing its tag is how the teach
// picker ends up with nothing to pre-select. The genuine running-total row below
// must still be promoted: the guard excludes HEADER/META, it is not an `else if`.
test('a META line shaped like a running total keeps its tag; the real curve row is still promoted', () => {
    const out = derivePdfLayout([page([
        [840, [['PLANNED', 50, 60], ['25%', 210, 20], ['60%', 310, 20], ['100%', 410, 25]]],
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55], ['MONTH 3', 400, 55]]],
        [760, [['Mobilization', 55, 90], ['1000', 210, 30], ['2000', 310, 30], ['3000', 410, 30]]],
        [720, [['Signage', 55, 60], ['1500', 210, 30], ['1500', 310, 30], ['1000', 410, 30]]],
        [680, [['CUMULATIVE %', 50, 90], ['25%', 210, 20], ['60%', 310, 20], ['100%', 410, 25]]],
    ])]);
    assert.ok(out && out.block, 'the capture must produce a slice');
    const lines = out.block.split('\n');
    const planned = lines.find((l) => l.includes('PLANNED'));
    const curve = lines.find((l) => l.includes('CUMULATIVE %'));
    assert.match(planned || '', /\[META\]/, 'the title-block line stays META:\n' + out.block);
    assert.match(lines.find((l) => l.includes('DESCRIPTION')) || '', /\[HEADER\]/, 'the header stays HEADER');
    assert.match(curve || '', /\[CUMULATIVE\]/, 'the running-total row is still promoted:\n' + out.block);
});

// A column-split print is ONE table cut down the middle. Showing only the first
// half meant the months on the second could never be pointed at, which is why
// teaching the roof was impossible rather than merely wrong.
test('both printed pages render as one grid with letters running on', () => {
    const first = page([
        [800, [['DESCRIPTION', 40, 80], ['Month 1', 200, 50], ['Month 2', 300, 50]]],
        [760, [['ROOFING', 45, 60], ['16%', 210, 20], ['17%', 310, 20]]],
        [720, [['WALLS', 45, 45], ['20%', 210, 20], ['27%', 310, 20]]],
    ]);
    // The overflow sheet: the same rows at the same heights, carrying the columns
    // that did not fit. Indexed wording numbers itself, so the seam is unambiguous.
    const second = page([
        [800, [['Month 3', 40, 50], ['Month 4', 130, 50]]],
        [760, [['16%', 50, 20], ['2%', 140, 18]]],
        [720, [['30%', 50, 20], ['4%', 140, 18]]],
    ]);

    const out = derivePdfLayout([first, second]);
    assert.ok(out && out.block, 'the capture must produce a slice');
    const legend = out.block.split('\n')[0];
    const letters = legend.replace('COLUMNS: ', '').split(' |')[0].split(',');
    assert.equal(new Set(letters).size, letters.length, 'letters are unique across the seam');

    // The page-two values must appear on the SAME row as their page-one twins —
    // the rows share their height, which is what makes the pairing deterministic.
    const roofRow = out.block.split('\n').find((l) => l.includes('ROOFING'));
    assert.ok(roofRow, 'the activity row is present');
    assert.equal((roofRow.match(/16%/g) || []).length, 2,
        'page one and page two both contribute to one row:\n' + roofRow);
    assert.match(roofRow, /2%/, 'including the tail month printed on page two');
});

// 2026-09-14 — THE AI-DERIVED TWIN NEVER GOT §2/§3.
//
// derivePdfLayout learned to merge the printed pages into one grid with letters
// running on across the seam; derivePdfLayoutFromAi kept building a block from
// the FIRST page only and restarting its letters at A on every page. On the roof
// that meant an ai-derived row whose MAP spanned both pages but whose BLOCK
// stopped at month 6, with page two's columns wearing the same letters as the
// description column — so month 6 rendered on top of month 1 in the teach modal
// and the row could not be taught at all. Two derivations of one table must not
// disagree about what a letter means.
test('an AI-derived layout merges its pages and never reuses a letter', () => {
    const first = page([
        [800, [['NO.', 40, 25], ['Amount', 120, 50],
            ['30 days', 220, 45], ['30 days', 300, 45], ['30 days', 380, 45]]],
        [760, [['A', 45, 8], ['ROOFING', 120, 60],
            ['4000', 230, 30], ['4000', 310, 30], ['2000', 390, 30]]],
    ]);
    // The overflow sheet at the SAME row heights, carrying the tail columns.
    const second = page([
        [800, [['30 days', 40, 45], ['7 days', 130, 40]]],
        [760, [['1500', 50, 25], ['500', 140, 20]]],
    ]);
    const labels = { 1: '30 days', 2: '30 days', 3: '30 days', 4: '30 days', 5: '7 days' };
    // The self-check replays the file through the map it is about to teach, so the
    // AI's curve has to be the one these cells actually produce (12,000 total).
    const aiMonths = [4000, 8000, 10000, 11500, 12000]
        .map((v, i) => ({ month_index: i + 1, planned_progress_percent: +(v / 12000 * 100).toFixed(2) }));

    const out = derivePdfLayoutFromAi([first, second], labels, aiMonths);
    assert.ok(out && out.block, 'the AI derivation must produce a slice');
    assert.equal(out.map.pages.length, 2, 'both printed pages ride in the map');

    const letters = out.block.split('\n')[0].replace('COLUMNS: ', '').split(' |')[0].split(',');
    assert.equal(new Set(letters).size, letters.length,
        'letters must be unique across the seam, not restart at A: ' + letters.join(','));

    const pageOne = out.map.pages[0].pseudoCols.map((c) => c.letter);
    const pageTwo = out.map.pages[1].pseudoCols.map((c) => c.letter);
    assert.equal(pageOne.filter((l) => pageTwo.includes(l)).length, 0,
        'no letter may name a column on both pages — that is what put month 6 on top '
        + 'of the description column in the teach modal');

    // The grid is the whole table, not its first sheet: the tail months printed on
    // page two must be visible to the reviewer who has to point at them.
    const roofRow = out.block.split('\n').find((l) => l.includes('ROOFING'));
    assert.ok(roofRow, 'the activity row is present');
    assert.match(roofRow, /1500/, 'page-two values reach the grid:\n' + roofRow);
    assert.match(roofRow, /500/, 'including the final tail month');
});

/* ─── Read guard: a reading the document contradicts is refused (2026-09-15) ─── */

// Teach-grid plan §13, findings C + D and the guard measurement pass. Two printed
// BUHI schedules read WRONG and looked like any other preview. The guard leaves
// such a file unread and says why, instead of offering a baseline to lock.

// Runs a read and captures the refusal the guard reports (if any).
function readWithRefusal(pages, opts) {
    let refusal = null;
    const result = parseSCurvePdf(pages, { ...(opts || {}), onRefusal: (r) => { refusal = r; } });
    return { result, refusal };
}

// The left block (names, amounts, weights) printed on one page, the months on
// another — the 12-page BUHI print in miniature.
const SPLIT_LABEL_PAGE = [
    [800, [['ITEM', 40, 30], ['DESCRIPTION', 120, 80], ['AMOUNT', 300, 50], ['% WEIGHT', 380, 55]]],
    [760, [['1', 45, 8], ['Mobilization', 120, 80], ['30000', 300, 40], ['30', 380, 20]]],
    [720, [['2', 45, 8], ['Earthworks', 120, 70], ['70000', 300, 40], ['70', 380, 20]]],
];
const SPLIT_MONTH_PAGE = [
    [800, [['MONTH 1', 200, 55], ['MONTH 2', 300, 55], ['MONTH 3', 400, 55]]],
    [760, [['10000', 210, 40], ['20000', 310, 40], ['0', 410, 10]]],
    [720, [['20000', 210, 40], ['30000', 310, 40], ['20000', 410, 40]]],
];

test('a table whose names and months are printed on separate pages is refused', () => {
    const { result, refusal } = readWithRefusal([page(SPLIT_LABEL_PAGE), page(SPLIT_MONTH_PAGE)]);

    assert.equal(result, null, 'no baseline may be offered from a split-down print');
    assert.ok(refusal, 'the refusal must be reported');
    assert.equal(refusal.code, 'split-down');
    assert.match(refusal.message, /separate pages/);
    assert.deepEqual(refusal.detail.labelOnlyPages, [1]);
});

// BOTH facts are required — each alone is plausible on a legitimate print.
test('the split-down refusal needs the label page AND nameless activities', () => {
    // Months page alone: nameless, but nothing shows the names live elsewhere.
    const alone = readWithRefusal([page(SPLIT_MONTH_PAGE)]);
    assert.ok(alone.result, 'a nameless read with no label page is not refused');
    assert.equal(alone.refusal, null);

    // Label page present, but the month page names its own rows.
    const named = page([
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55], ['MONTH 3', 400, 55]]],
        [760, [['Mobilization', 70, 80], ['10000', 210, 40], ['20000', 310, 40], ['0', 410, 10]]],
        [720, [['Earthworks', 70, 70], ['20000', 210, 40], ['30000', 310, 40], ['20000', 410, 40]]],
    ]);
    const withNames = readWithRefusal([page(SPLIT_LABEL_PAGE), named]);
    assert.ok(withNames.result, 'a label page beside a named table is not refused');
    assert.equal(withNames.refusal, null);
});

// The one-page BUHI print in miniature: a month cell sits left of Month 1's band,
// so no month reads it, and the TOTAL row's months fall short of its own total.
function totalRowPage(printed, leftOutCell) {
    const leftOut = leftOutCell ? [['500', 160, 25]] : [];
    return page([
        [800, [['DESCRIPTION', 50, 90], ['MONTH 1', 200, 55], ['MONTH 2', 300, 55], ['MONTH 3', 400, 55]]],
        [760, [['Mobilization', 70, 80], ...leftOut, ['1000', 210, 30], ['2000', 310, 30], ['3000', 410, 30]]],
        [720, [['Signage', 70, 60], ['1500', 210, 30], ['1500', 310, 30], ['0', 410, 10]]],
        [680, [['TOTAL', 55, 45], [printed, 100, 35], ...leftOut, ['2500', 210, 30], ['3500', 310, 30], ['3000', 410, 30]]],
    ]);
}

test('a reading whose months do not add up to the file’s own TOTAL row is refused', () => {
    const { result, refusal } = readWithRefusal([totalRowPage('9500', true)]);

    assert.equal(result, null, 'a read the TOTAL row contradicts must not become a baseline');
    assert.ok(refusal);
    assert.equal(refusal.code, 'total-mismatch');
    // The RULES' reading was refused — no learned layout to blame.
    assert.equal(refusal.viaMap, false);
    assert.equal(refusal.mapId, undefined);
    // The message names both figures, so the reviewer sees the contradiction itself.
    assert.match(refusal.message, /9,000\.00/);
    assert.match(refusal.message, /9,500\.00/);
});

test('a TOTAL row that adds up, or has nothing to compare, leaves the read alone', () => {
    const adds = readWithRefusal([totalRowPage('9000', false)]);
    assert.ok(adds.result, 'a reconciling TOTAL row reads normally');
    assert.equal(adds.refusal, null);
    assert.deepEqual(adds.result.months.map((m) => m.planned_progress_percent), [27.78, 66.67, 100]);

    // No printed figure left of the months (the 10-month / 6-stage / SEGMENT shape):
    // nothing to reconcile against, so the guard abstains.
    const nothing = readWithRefusal([page([...HEADER, ...ROW1, ...ROW2, ...TOTAL])]);
    assert.ok(nothing.result);
    assert.equal(nothing.refusal, null);
});

// Measured trap: judged page by page, the correct two-page roof read looked short —
// its TOTAL row carries months 6-7 on the overflow page, on a label-less line. The
// row is followed onto a page holding OTHER months at the same header-relative height.
function splitTotalPages(printed) {
    const first = page([
        [800, [['DESCRIPTION', 50, 90], ['STINT 1', 200, 55], ['STINT 2', 300, 55]]],
        [760, [['Roofing', 60, 70], ['60%', 210, 25], ['40%', 310, 25]]],
        [720, [['Walls', 60, 60], ['70%', 210, 25], ['30%', 310, 25]]],
        [680, [['TOTAL', 55, 45], [printed, 120, 30], ['100', 210, 25], ['100', 310, 25]]],
        [640, [['40.00%', 210, 30], ['80.00%', 310, 30]]],
    ]);
    const second = page([
        [800, [['STINT 2', 200, 55], ['STINT 3', 300, 55]]],
        [760, [['30%', 210, 25], ['25%', 310, 25]]],
        [680, [['100', 310, 25]]],
        [640, [['80.00%', 210, 30], ['100.00%', 310, 32]]],
    ]);
    return [first, second];
}

test('a column-split TOTAL row is reconciled across its pages, not page by page', () => {
    const labels = { 1: 'STINT 1', 2: 'STINT 2', 3: 'STINT 3' };

    const good = splitTotalPages('300');   // 100 + 100 on page 1, 100 on page 2
    const derived = derivePdfLayoutFromAi(good, labels, null);
    assert.ok(derived, 'the fixture must yield a two-page map');
    const ok = readWithRefusal(good, { liveMaps: [{ id: 1, map: derived.map }] });
    assert.ok(ok.result, 'page one alone sums to 200 — the continuation cell must be counted');
    assert.equal(ok.refusal, null);
    assert.deepEqual(ok.result.months.map((m) => m.planned_progress_percent), [40, 80, 100]);

    const bad = splitTotalPages('999');
    const derivedBad = derivePdfLayoutFromAi(bad, labels, null);
    const refused = readWithRefusal(bad, { liveMaps: [{ id: 1, map: derivedBad.map }] });
    assert.equal(refused.result, null);
    assert.equal(refused.refusal && refused.refusal.code, 'total-mismatch');
    assert.match(refused.refusal.message, /add up to 300\.00/);
});

// The guard is what makes such a file LEARNABLE: a refused rules read falls through
// to the learned layouts (before 2026-09-15 they were consulted only when the rules
// found no table at all), and a layout faces the very same check.
test('a refused rules read falls through to a learned layout that adds up', () => {
    const misread = totalRowPage('9500', true);
    const captured = derivePdfLayout([misread]).map;

    // The capture repeats the rules' mistake (bands start at the labels) — refused too.
    const same = readWithRefusal([misread], { liveMaps: [{ id: 5, map: captured }] });
    assert.equal(same.result, null, 'a learned layout that does not add up is refused as well');
    assert.equal(same.refusal && same.refusal.code, 'total-mismatch');
    // The layout's refusal is the one reported, and it names the layout, so the message
    // can send the reviewer to S-Curve Layouts rather than only to the Excel file.
    assert.equal(same.refusal.viaMap, true);
    assert.equal(same.refusal.mapId, 5);

    // A taught layout whose month 1 reaches the left-out cell reconciles and reads.
    const W = 455; // pageMaxX: MONTH 3 ends at 400 + 55
    const taught = { ...captured, pages: [{
        ...captured.pages[0],
        monthCols: captured.pages[0].monthCols.map((mc, k) => (k === 0 ? { ...mc, x: 150 / W } : mc)),
    }] };
    const learned = readWithRefusal([misread], { liveMaps: [{ id: 6, map: taught }] });
    assert.ok(learned.result, 'the layout that adds up must rescue the file');
    assert.equal(learned.result.meta.mapUsed, true);
    assert.equal(learned.result.meta.mapId, 6);
    assert.equal(learned.refusal, null);
    // 1500 then 3500 then 3000 over 9500 — month 1 now carries the left-out cell.
    assert.deepEqual(learned.result.months.map((m) => m.planned_progress_percent), [31.58, 68.42, 100]);
});

/* ─── AI assist page images: shrink-to-fit (2026-09-15) ───────────────────── */

// An ordinary page keeps the usual ~150 DPI render and is not reported as shrunk.
test('pdfSliceRenderScale keeps ~150 DPI for an ordinary page', () => {
    const fit = pdfSliceRenderScale(792, 612);
    assert.equal(fit.scale, 2.08);
    assert.equal(fit.shrunk, false);
    assert.equal(fit.dpi, 150);
});

// The one-page BUHI print (2592 x 1728 pt) used to be SKIPPED — 5391 px at 2.08 —
// so AI assist had no image to send. It must now render, shrunk to the 4000 px budget.
test('pdfSliceRenderScale shrinks an oversized page to fit instead of skipping it', () => {
    const fit = pdfSliceRenderScale(2592, 1728);
    assert.ok(fit, 'an oversized page must still get a render scale');
    assert.equal(fit.shrunk, true);
    assert.ok(Math.floor(2592 * fit.scale) <= 4000, 'the longer side fits the canvas budget');
    assert.ok(Math.floor(2592 * fit.scale) >= 3999, 'and is not shrunk further than needed');
    assert.equal(fit.dpi, 111);
    // Portrait: the longer side decides, whichever dimension it is.
    assert.equal(pdfSliceRenderScale(1728, 2592).scale, fit.scale);
    // Right at the budget nothing shrinks; one point past it, it does.
    assert.equal(pdfSliceRenderScale(1923, 1000).shrunk, false);
    assert.equal(pdfSliceRenderScale(1924, 1000).shrunk, true);
});

test('pdfSliceRenderScale refuses a page with no usable size', () => {
    assert.equal(pdfSliceRenderScale(0, 0), null);
    assert.equal(pdfSliceRenderScale(NaN, 612), null);
    assert.equal(pdfSliceRenderScale(undefined, undefined), null);
});

// The note exists only when a page was shrunk, names the lowest DPI, and points at
// the Excel file as the dependable source.
test('pdfSliceShrinkNote warns only for shrunk pages and names the lowest DPI', () => {
    assert.equal(pdfSliceShrinkNote([]), null);
    assert.equal(pdfSliceShrinkNote(undefined), null);
    assert.equal(pdfSliceShrinkNote([{ page: 0, dpi: 0 }]), null);

    const one = pdfSliceShrinkNote([{ page: 0, dpi: 111 }]);
    assert.match(one, /page was too large/);
    assert.match(one, /about 111 DPI/);
    assert.match(one, /may be inaccurate/);
    assert.match(one, /original Excel \(\.xlsx\)/);
    // Second choice after the Excel: a left-to-right two-page split with every row and
    // the header on each page (a top-to-bottom split is what the read guard refuses).
    assert.match(one, /left-to-right onto two pages, with every row and the header rows repeated/);
    assert.ok(one.indexOf('Excel') < one.indexOf('two pages'), 'the Excel file is advised first');

    // A failed call has no months to call inaccurate: it warns about a retry instead.
    const failed = pdfSliceShrinkNote([{ page: 0, dpi: 111 }], { failed: true });
    assert.match(failed, /about 111 DPI/);
    assert.match(failed, /even if a retry succeeds/);
    assert.doesNotMatch(failed, /these months/);
    assert.match(failed, /original Excel \(\.xlsx\)/);
    assert.equal(pdfSliceShrinkNote([], { failed: true }), null);

    const two = pdfSliceShrinkNote([{ page: 0, dpi: 140 }, { page: 2, dpi: 98 }]);
    assert.match(two, /2 pages were too large/);
    assert.match(two, /about 98 DPI/);
});
