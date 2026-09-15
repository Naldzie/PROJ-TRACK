import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

import {
    splitCsv,
    parseHeaderBlock,
    indexToLetter,
    rowLabel,
    rowSignature,
    suggestedMonthsFor,
    defaultHeaderRows,
    lettersInBand,
    prefillFromMap,
    ROW_FIELD_KEYS,
    ROW_PDF_REASON,
    isRowField,
    isMonthField,
    applyRowPick,
    rowHasMonthValues,
    isEdgeCell,
    cellNumbers,
    taughtTotalCheck,
    taughtTotalMessage,
} from '../../public/js/admin/projects/scurve-teach-core.js';

/*
 * The S-Curve Layouts desk / Teach modal logic (2026-09-15). Before this file the
 * rules below lived in Blade-inline JS with no harness and were checked only by
 * one-off probes. Each block names the defect or decision it pins.
 */

/* ─── Fixtures ─────────────────────────────────────────────────────────────── */

// The 6-stage Excel SPECIMEN's stored block, verbatim from the desk (a synthetic
// test file built for the parser track, not a contractor document).
const SIX_STAGE_BLOCK = [
    'COLUMNS: A,B,C,D,E,F,G,H,I,J,K | META: title block | HEADER: column labels | DATA: activity rows — compute months from these ONLY | SUMMARY: author totals (TOTAL/COST/Cash Flow) — reference only, NEVER copy into months | CUMULATIVE: the printed running curve — reference only, NEVER copy into months',
    'R1 [META]: "CONSTRUCTION SCHEDULE AND S-CURVE (FOURTH-FAMILY STAGE SPECIMEN)"',
    'R2 [HEADER]: ITEM,"SCHEDULED TASKS",QTY,UNIT,"PROJECT COST","STAGE 1","STAGE 2","STAGE 3","STAGE 4","STAGE 5","STAGE 6"',
    'R3 [DATA]: 1,"Mobilization Works",,,120000,60000,60000',
    'R4 [DATA]: 2,"Earthworks and Excavation",,,480000,,120000,240000,120000',
    'R5 [DATA]: 3,"Concrete and Masonry Works",,,360000,,,,120000,240000',
    'R6 [DATA]: 4,"Finishing and Painting Works",,,240000,,,,,,240000',
    'R7 [SUMMARY]: ,TOTAL,,,1200000',
    'R8 [CUMULATIVE]: ,"CUMULATIVE % ACHIEVED",,,,5,20,40,60,80,100',
].join('\n');

// Its taught map as stored (DB row 118 after the reviewer's R8 correction).
const SIX_STAGE_MAP = {
    v: 1, descCol: 1, codeCol: 1, weightCol: null, contractCol: 4,
    monthSpans: { 1: [5], 2: [6], 3: [7], 4: [8], 5: [9], 6: [10] },
    excludeSigs: ['total', 'cumulative % achieved'],
    cumulativeSig: 'cumulative % achieved',
    headerRows: [2],
};

// A fresh teaching state, shaped exactly like the modal's _schTeach.
function freshState(parsed, over = {}) {
    return Object.assign({
        rows: parsed.rows,
        letters: parsed.letters,
        sourceType: 'excel',
        assigns: { desc: null, code: null, weight: null, contract: null, months: {} },
        headerRows: defaultHeaderRows(parsed),
        excludedRows: [],
        cumulativeRow: null,
    }, over);
}

const six = () => parseHeaderBlock(SIX_STAGE_BLOCK);

/* ─── Block parsing ────────────────────────────────────────────────────────── */

test('splitCsv honours quotes, escaped quotes and empty cells', () => {
    assert.deepEqual(splitCsv('1,"Mobilization Works",,,120000'), ['1', 'Mobilization Works', '', '', '120000']);
    assert.deepEqual(splitCsv('"1,000,000","say ""hi"""'), ['1,000,000', 'say "hi"']);
    assert.deepEqual(splitCsv(''), ['']);
});

test('parseHeaderBlock reads letters, R-numbers and roles; refuses junk', () => {
    const p = six();
    assert.equal(p.letters.length, 11);
    assert.deepEqual(p.rows.map(r => r.n + ':' + r.role),
        ['1:META', '2:HEADER', '3:DATA', '4:DATA', '5:DATA', '6:DATA', '7:SUMMARY', '8:CUMULATIVE']);
    assert.equal(p.rows[1].cells[1], 'SCHEDULED TASKS');
    assert.equal(parseHeaderBlock(''), null);
    assert.equal(parseHeaderBlock('no legend here\nR1 [DATA]: x'), null);
    assert.equal(parseHeaderBlock('COLUMNS: A,B\nnot a row line'), null);
});

test('indexToLetter runs past Z like the server letterFor()', () => {
    assert.equal(indexToLetter(0), 'A');
    assert.equal(indexToLetter(25), 'Z');
    assert.equal(indexToLetter(26), 'AA');
    assert.equal(indexToLetter(44), 'AS');
});

test('rowLabel keeps the words and drops the numbers', () => {
    assert.equal(rowLabel(six().rows[6]), 'TOTAL');
    assert.equal(rowLabel(six().rows[7]), 'CUMULATIVE % ACHIEVED');
});

/* ─── Signature parity with PHP ────────────────────────────────────────────── */

// The same file is asserted by SCurveImportTest::test_row_signature_matches_the_
// shared_parity_cases. Change rowSignature on one side only and a suite goes red.
test('rowSignature matches the shared PHP parity cases', () => {
    const file = new URL('../fixtures/scurve_row_signature_cases.json', import.meta.url);
    const { cases } = JSON.parse(fs.readFileSync(file, 'utf8'));
    assert.ok(cases.length >= 10, 'the shared parity file must load');
    for (const c of cases) {
        assert.equal(rowSignature(c.cells), c.sig, 'rowSignature(' + JSON.stringify(c.cells) + ')');
    }
});

/* ─── Opening state ────────────────────────────────────────────────────────── */

test('defaultHeaderRows: tagged HEADER lines, and EMPTY when none is tagged (F6)', () => {
    assert.deepEqual(defaultHeaderRows(six()), [2]);
    const untagged = parseHeaderBlock('COLUMNS: A,B\nR1 [META]: TITLE\nR2 [DATA]: DESCRIPTION,MONTH 1');
    assert.deepEqual(defaultHeaderRows(untagged), [], 'never default to the title row');
});

test('suggestedMonthsFor: PDF numbered, PDF unnumbered, Excel spans, junk', () => {
    assert.equal(suggestedMonthsFor({ kind: 'pdf', pages: [{ monthCols: [{ index: 5 }] }, { monthCols: [{ index: 7 }] }] }), 7);
    const cols = Array.from({ length: 13 }, (_, k) => ({ letter: indexToLetter(k) }));
    assert.equal(suggestedMonthsFor({ kind: 'pdf', pages: [{ monthCols: [], pseudoCols: cols }] }), 11, 'columns minus two');
    assert.equal(suggestedMonthsFor({ kind: 'pdf', pages: [{ monthCols: [], pseudoCols: cols.concat(cols) }] }), 12, 'capped at twelve');
    assert.equal(suggestedMonthsFor(SIX_STAGE_MAP), 6);
    assert.equal(suggestedMonthsFor(null), 0);
});

/* ─── Prefill: Excel (header memory + defect 12) ───────────────────────────── */

test('Excel prefill restores columns, the header row and the row-role picks', () => {
    const p = six();
    const t = freshState(p);
    prefillFromMap(t, SIX_STAGE_MAP, p);
    assert.equal(t.assigns.desc, 'B');
    assert.equal(t.assigns.contract, 'E');
    assert.deepEqual(t.assigns.months, { 1: ['F'], 2: ['G'], 3: ['H'], 4: ['I'], 5: ['J'], 6: ['K'] });
    assert.deepEqual(t.headerRows, [2]);
    assert.deepEqual(t.excludedRows, [7, 8], 'defect 12: the role picks come back');
    assert.equal(t.cumulativeRow, 8);
});

test('Excel prefill restores maps saved BEFORE header memory existed', () => {
    const p = six();
    const t = freshState(p);
    const old = Object.assign({}, SIX_STAGE_MAP);
    delete old.headerRows;
    prefillFromMap(t, old, p);
    assert.deepEqual(t.headerRows, [2], 'falls back to the tagged HEADER line');
    assert.deepEqual(t.excludedRows, [7, 8], 'signatures need no new key');
});

test('Excel prefill ignores unknown header R-numbers and keeps at most two', () => {
    const p = six();
    const t = freshState(p);
    prefillFromMap(t, Object.assign({}, SIX_STAGE_MAP, { headerRows: [99, 0, 'x'] }), p);
    assert.deepEqual(t.headerRows, [2], 'nothing valid saved -> the tagged default stands');
    const t2 = freshState(p);
    prefillFromMap(t2, Object.assign({}, SIX_STAGE_MAP, { headerRows: [1, 3, 4], excludeSigs: [], cumulativeSig: null }), p);
    assert.deepEqual(t2.headerRows, [1, 3]);
});

test('Excel prefill ticks ONE row per signature, so Save is not refused', () => {
    // Two rows reading "total": the server refuses two picks that read the same, so
    // ticking both would make an untouched layout unsaveable (defect 7's class).
    const p = parseHeaderBlock(SIX_STAGE_BLOCK + '\nR9 [SUMMARY]: ," Total ",,,999');
    const t = freshState(p);
    prefillFromMap(t, SIX_STAGE_MAP, p);
    assert.deepEqual(t.excludedRows, [7, 8]);
});

test('Excel prefill never ticks a header row as a role row', () => {
    const p = six();
    const t = freshState(p);
    prefillFromMap(t, Object.assign({}, SIX_STAGE_MAP, {
        excludeSigs: ['item scheduled tasks qty unit project cost stage 1 stage 2 stage 3 stage 4 stage 5 stage 6'],
        cumulativeSig: null,
    }), p);
    assert.deepEqual(t.excludedRows, []);
});

test('prefill survives a malformed map without throwing', () => {
    const p = six();
    const t = freshState(p);
    assert.doesNotThrow(() => prefillFromMap(t, { monthSpans: 'nonsense', excludeSigs: 'nope' }, p));
    assert.doesNotThrow(() => prefillFromMap(freshState(p), null, p));
});

/* ─── Prefill: PDF bands (defects 6 + 7) ───────────────────────────────────── */

test('lettersInBand claims the dominant column, never the neighbour’s sliver (defect 7)', () => {
    // Geometry transcribed from the measured roof coverage: each month band covers
    // its own column ~97% and grazes the next by ~3%.
    const pseudo = [
        { letter: 'F', x: 0.40, xEnd: 0.50 },
        { letter: 'G', x: 0.50, xEnd: 0.60 },
        { letter: 'H', x: 0.60, xEnd: 0.70 },
    ];
    assert.deepEqual(lettersInBand(pseudo, 0.401, 0.503), ['F'], 'F 99%, G 3% -> F only');
    assert.deepEqual(lettersInBand(pseudo, 0.503, 0.604), ['G']);
    // A band narrower than its column is still recognised (band-share).
    assert.deepEqual(lettersInBand([{ letter: 'A', x: 0.0, xEnd: 0.5 }], 0.1, 0.2), ['A']);
    // Two genuinely covered columns both count (a paired month).
    assert.deepEqual(lettersInBand(pseudo, 0.40, 0.60), ['F', 'G']);
    // A continuation page's inherited, empty desc band grazes month 6's column by
    // ~4% of the column and ~4% of the band: BOTH shares small -> not claimed.
    assert.deepEqual(lettersInBand([{ letter: 'L', x: 0.208, xEnd: 0.40 }], 0.0, 0.216), []);
});

test('PDF prefill walks EVERY page and takes the description from the first (defect 6)', () => {
    const map = {
        kind: 'pdf',
        pages: [
            {
                descX: 0.04, descRight: 0.30,
                pseudoCols: [
                    { letter: 'A', x: 0.04, xEnd: 0.10 }, { letter: 'B', x: 0.10, xEnd: 0.30 },
                    { letter: 'F', x: 0.40, xEnd: 0.50 }, { letter: 'G', x: 0.50, xEnd: 0.60 },
                ],
                monthCols: [{ index: 1, x: 0.401, xEnd: 0.503 }, { index: 2, x: 0.503, xEnd: 0.604 }],
            },
            {
                // A continuation page: inherited empty desc band that grazes L by 4%.
                descX: 0.0, descRight: 0.216,
                pseudoCols: [{ letter: 'L', x: 0.208, xEnd: 0.40 }, { letter: 'M', x: 0.40, xEnd: 0.60 }],
                monthCols: [{ index: 3, x: 0.21, xEnd: 0.40 }, { index: 4, x: 0.40, xEnd: 0.60 }],
            },
        ],
    };
    const t = { assigns: { desc: null, code: null, weight: null, contract: null, months: {} }, headerRows: [], excludedRows: [], cumulativeRow: null };
    prefillFromMap(t, map, { rows: [] });
    // The desc band spans A and B (like the roof's A..D); the first claimed letter
    // names it, exactly as the reviewer's own teaching reads back.
    assert.equal(t.assigns.desc, 'A', 'the page-one description band; page two has none');
    assert.deepEqual(t.assigns.months, { 1: ['F'], 2: ['G'], 3: ['L'], 4: ['M'] }, 'months from both pages, one letter each');
});

// 2026-09-15 — the reopen bug. BUHI's centred "Month N" label is its own column BETWEEN
// the month's two cells (H | I label | J). Taught as H+J, the stored band H..J reopened as
// H+I+J, and the server refuses more than two letters a month, so saving it unchanged
// failed. The prefill keeps the outer two letters, which the server turns back into the
// same band (min x .. max xEnd).
test('PDF prefill never offers more than two letters a month, and keeps the same band', () => {
    const pseudoCols = [
        { letter: 'B', x: 0.05, xEnd: 0.28 },
        { letter: 'H', x: 0.292, xEnd: 0.306 }, { letter: 'I', x: 0.306, xEnd: 0.320 }, { letter: 'J', x: 0.320, xEnd: 0.347 },
        { letter: 'K', x: 0.347, xEnd: 0.360 }, { letter: 'L', x: 0.360, xEnd: 0.374 }, { letter: 'M', x: 0.374, xEnd: 0.401 },
        { letter: 'N', x: 0.401, xEnd: 0.414 }, { letter: 'O', x: 0.414, xEnd: 0.455 },
    ];
    const band = (letters) => ({ x: Math.min(...letters.map(l => pseudoCols.find(p => p.letter === l).x)), xEnd: Math.max(...letters.map(l => pseudoCols.find(p => p.letter === l).xEnd)) });
    // The map as buildTaughtPdfMap stored it from the reviewer's H+J, K+M, N+O.
    const taught = { 1: ['H', 'J'], 2: ['K', 'M'], 3: ['N', 'O'] };
    const map = { kind: 'pdf', pages: [{ descX: 0.05, descRight: 0.28, pseudoCols,
        monthCols: Object.entries(taught).map(([m, ls]) => ({ index: +m, ...band(ls) })) }] };

    const t = { assigns: { desc: null, code: null, weight: null, contract: null, months: {} }, headerRows: [], excludedRows: [], cumulativeRow: null };
    prefillFromMap(t, map, { rows: [] });

    assert.deepEqual(t.assigns.months, { 1: ['H', 'J'], 2: ['K', 'M'], 3: ['N', 'O'] }, 'the outer letters, as taught');
    Object.values(t.assigns.months).forEach(ls => assert.ok(ls.length <= 2, 'the save validation allows two'));
    // Saving the prefill rebuilds the exact stored bands.
    Object.entries(t.assigns.months).forEach(([m, ls]) => assert.deepEqual(band(ls), band(taught[m])));
});

/* ─── Row fields: keys ─────────────────────────────────────────────────────── */

test('row field keys are never month-shaped, and match the modal palette', () => {
    for (const k of ROW_FIELD_KEYS) {
        assert.equal(isRowField(k), true);
        assert.equal(isMonthField(k), false, k + ' must not look like m<N>');
    }
    assert.equal(isMonthField('m1'), true);
    assert.equal(isMonthField('m12'), true);
    assert.equal(isMonthField('mark'), false, 'the m-prefix trap');
    assert.equal(isRowField('desc'), false);
    // The modal declares the buttons; its keys must be exactly the core's.
    const blade = fs.readFileSync(new URL('../../resources/views/admin/_manage_scurve_headers_modal.blade.php', import.meta.url), 'utf8');
    const block = blade.slice(blade.indexOf('const SCH_ROW_FIELDS = ['), blade.indexOf('];', blade.indexOf('const SCH_ROW_FIELDS = [')));
    const keys = [...block.matchAll(/key: '([^']+)'/g)].map(m => m[1]);
    assert.deepEqual(keys, ROW_FIELD_KEYS);
});

/* ─── Row fields: click rules (replayed on the stored 6-stage block) ───────── */

test('the intended teaching: R8 as the curve, R7 as not an activity', () => {
    const t = freshState(six());
    assert.deepEqual(applyRowPick(t, 'rowCurve', 8), { changed: true, message: null });
    assert.deepEqual(applyRowPick(t, 'rowSkip', 7), { changed: true, message: null });
    assert.deepEqual(t.excludedRows, [7, 8]);
    assert.equal(t.cumulativeRow, 8);
});

test('moving the curve R7 -> R8 leaves R7 visibly excluded', () => {
    const t = freshState(six());
    applyRowPick(t, 'rowCurve', 7);
    applyRowPick(t, 'rowCurve', 8);
    assert.equal(t.cumulativeRow, 8);
    assert.deepEqual(t.excludedRows, [7, 8]);
});

test('clicking the curve row again clears the curve but keeps it excluded', () => {
    const t = freshState(six());
    applyRowPick(t, 'rowCurve', 8);
    applyRowPick(t, 'rowCurve', 8);
    assert.equal(t.cumulativeRow, null);
    assert.deepEqual(t.excludedRows, [8]);
});

test('un-excluding the curve row also clears the curve (it must never be summed)', () => {
    const t = freshState(six());
    applyRowPick(t, 'rowCurve', 8);
    applyRowPick(t, 'rowSkip', 8);
    assert.equal(t.cumulativeRow, null);
    assert.deepEqual(t.excludedRows, []);
});

test('a row tagged HEADER is refused for both role fields', () => {
    const t = freshState(six(), { headerRows: [] });
    const res = applyRowPick(t, 'rowSkip', 2);
    assert.equal(res.changed, false);
    assert.match(res.message, /tagged HEADER/);
    assert.equal(applyRowPick(t, 'rowCurve', 2).changed, false);
    assert.deepEqual(t.excludedRows, []);
});

test('a picked header row cannot take a role until released', () => {
    const t = freshState(six(), { headerRows: [2, 3] });
    const res = applyRowPick(t, 'rowSkip', 3);
    assert.equal(res.changed, false);
    assert.match(res.message, /header row you picked/);
});

test('picking a header row steals it from the role picks', () => {
    const t = freshState(six(), { headerRows: [] });
    applyRowPick(t, 'rowCurve', 8);
    applyRowPick(t, 'rowHeader', 8);
    assert.deepEqual(t.headerRows, [8]);
    assert.deepEqual(t.excludedRows, []);
    assert.equal(t.cumulativeRow, null);
});

test('header row toggles off, and a third one is refused', () => {
    const t = freshState(six(), { headerRows: [1, 2] });
    const res = applyRowPick(t, 'rowHeader', 3);
    assert.equal(res.changed, false);
    assert.match(res.message, /At most two header rows/);
    applyRowPick(t, 'rowHeader', 1);
    assert.deepEqual(t.headerRows, [2]);
});

test('PDF rows refuse both role fields but still take a header row', () => {
    const t = freshState(six(), { sourceType: 'pdf', headerRows: [] });
    const res = applyRowPick(t, 'rowSkip', 7);
    assert.equal(res.changed, false);
    assert.equal(res.message, ROW_PDF_REASON);
    assert.equal(applyRowPick(t, 'rowCurve', 8).changed, false);
    assert.equal(applyRowPick(t, 'rowHeader', 2).changed, true);
});

test('a numbers-only row is refused (empty signature)', () => {
    const p = parseHeaderBlock(SIX_STAGE_BLOCK + '\nR9 [DATA]: ,1200000,5,20');
    const res = applyRowPick(freshState(p), 'rowSkip', 9);
    assert.equal(res.changed, false);
    assert.match(res.message, /no label text/);
});

test('two rows that read the same cannot both be picked', () => {
    const p = parseHeaderBlock(SIX_STAGE_BLOCK + '\nR9 [SUMMARY]: ," Total ",,,999');
    const t = freshState(p);
    applyRowPick(t, 'rowSkip', 7);
    const res = applyRowPick(t, 'rowSkip', 9);
    assert.equal(res.changed, false);
    assert.match(res.message, /reads the same as R7/);
    assert.deepEqual(t.excludedRows, [7]);
});

test('unknown row and unknown field change nothing', () => {
    const t = freshState(six());
    assert.equal(applyRowPick(t, 'rowSkip', 42).changed, false);
    assert.equal(applyRowPick(t, 'desc', 7).changed, false);
    assert.deepEqual(t.excludedRows, []);
});

/* ─── Cumulative advisory ──────────────────────────────────────────────────── */

test('rowHasMonthValues: the curve row has month values, TOTAL does not, no months -> null', () => {
    const p = six();
    const t = freshState(p);
    prefillFromMap(t, SIX_STAGE_MAP, p);
    assert.equal(rowHasMonthValues(t, 8), true);
    assert.equal(rowHasMonthValues(t, 7), false, 'the row-118 mis-pick the advisory exists for');
    assert.equal(rowHasMonthValues(freshState(p), 8), null);
});

/* ─── Page-edge marks + the PDF "adds up" check (2026-09-15) ───────────────── */

// The two-page roof print's stored capture (DB row 135), trimmed to the header, one
// activity and the TOTAL / % rows — verbatim cells, plus the EDGE line a capture now
// writes: page 1's month-6 TOTAL cell prints "495,173." cut at the page edge; the full
// 495,173.83 is page 2's first column (L). F..J = months 1-5, L = 6, M = 7.
const ROOF_TWO_PAGE_BLOCK = [
    'COLUMNS: A,B,C,D,E,F,G,H,I,J,K,L,M | META: title block | HEADER: column labels',
    'EDGE: R14:K',
    'R3 [HEADER]: NO.,,,,Amount,30 days,30 days,30 days,30 days,30days,30 days,30 days,7 days',
    'R4 [DATA]: A,FACILITIES FOR ENGINEER,,,,16%,17%,17%,16%,17%,,16%,2%',
    'R14 [SUMMARY]: ,,TOTAL,₱,"2,239,599.72 ₱","141,466.26 ₱","402,960.50 ₱","402,960.50 ₱","389,961.77 ₱","387,731.14 ₱","495,173.","₱ 495,173.83 ₱","19,345.73"',
    'R16 [CUMULATIVE]: ,,,,%,6.32%,24.31%,42.30%,59.71%,77.03%,99.14%,99.14%,100.00%',
].join('\n');

// A PDF teaching state over that block with the given months ({1: ['F'], …}).
function roofTeach(months, over = {}) {
    const p = parseHeaderBlock(ROOF_TWO_PAGE_BLOCK);
    return Object.assign(freshState(p, { sourceType: 'pdf', edgeCells: p.edgeCells }), { assigns: { desc: 'B', code: null, weight: null, contract: null, months } }, over);
}
const CORRECT = { 1: ['F'], 2: ['G'], 3: ['H'], 4: ['I'], 5: ['J'], 6: ['L'], 7: ['M'] };

test('parseHeaderBlock reads the EDGE line; rows and letters are unaffected', () => {
    const p = parseHeaderBlock(ROOF_TWO_PAGE_BLOCK);
    assert.deepEqual(p.edgeCells, ['14:K']);
    assert.deepEqual(p.rows.map(r => r.n), [3, 4, 14, 16], 'the EDGE line is not a row');
    assert.equal(isEdgeCell(p, 14, 'K'), true);
    assert.equal(isEdgeCell(p, 14, 'L'), false);
    // A block without the line (every capture with nothing cut) parses as before.
    assert.deepEqual(six().edgeCells, []);
    // Junk entries are ignored rather than half-read.
    assert.deepEqual(parseHeaderBlock('COLUMNS: A,B\nEDGE: R2:B, x, R:C, 3:D\nR2 [DATA]: a,b').edgeCells, ['2:B']);
});

test('cellNumbers reads peso cells, cut-off cells and merged cells', () => {
    assert.deepEqual(cellNumbers('₱ 495,173.83 ₱'), [495173.83]);
    assert.deepEqual(cellNumbers('495,173.'), [495173]);
    assert.deepEqual(cellNumbers('1,000.00 2,500.50'), [1000, 2500.5]);
    assert.deepEqual(cellNumbers('LOT'), []);
    assert.deepEqual(cellNumbers(null), []);
});

test('the corrected two-page roof teaching adds up to its printed total', () => {
    const c = taughtTotalCheck(roofTeach(CORRECT));
    assert.equal(c.status, 'adds-up');
    assert.equal(c.row, 14);
    assert.equal(c.printed, 2239599.72);
    assert.match(taughtTotalMessage(c), /^Adds up: .*2,239,599\.73 .*\(R14\).*2,239,599\.72/);
});

// The live case that motivated the check: page 1's months taught one column right.
test('a teaching one column off does not add up, and the warning says Save anyway', () => {
    const c = taughtTotalCheck(roofTeach({ 1: ['G'], 2: ['H'], 3: ['I'], 4: ['J'], 5: ['K'], 6: ['L'], 7: ['M'] }));
    assert.equal(c.status, 'mismatch');
    assert.equal(c.sum, 2593306.47);
    assert.equal(c.printed, 2239599.72);
    assert.ok(c.gap < 0, 'too much, not short');
    const msg = taughtTotalMessage(c);
    assert.match(msg, /add up to 2,593,306\.47 .*prints 2,239,599\.72 — 353,706\.75 too much\./);
    assert.match(msg, /Save anyway/);
});

test('a page-split month counted twice names the cut-off copy', () => {
    const c = taughtTotalCheck(roofTeach({ ...CORRECT, 6: ['K', 'L'] }));
    assert.equal(c.status, 'mismatch');
    assert.deepEqual(c.culprit, { letter: 'K', cell: '495,173.', taught: true, edge: true });
    assert.match(taughtTotalMessage(c), /Column K \(495,173\.\) accounts for the difference — it was cut off at the page edge/);
});

test('a forgotten month names the untaught column holding the shortfall', () => {
    const months = { ...CORRECT };
    delete months[7];
    const c = taughtTotalCheck(roofTeach(months));
    assert.equal(c.status, 'mismatch');
    assert.ok(c.gap > 0, 'short');
    assert.equal(c.culprit.letter, 'M');
    assert.equal(c.culprit.taught, false);
    assert.match(taughtTotalMessage(c), /19,345\.72 short\. Column M holds 19,345\.73 in that row, but no month is on it\./);
});

test('no usable TOTAL row -> "Not checked", with the reason', () => {
    // No TOTAL row at all (the 10-month / 6-stage / SEGMENT shape).
    const noTotal = roofTeach(CORRECT, { rows: parseHeaderBlock(ROOF_TWO_PAGE_BLOCK).rows.filter(r => r.n !== 14) });
    assert.deepEqual(taughtTotalCheck(noTotal), { status: 'unchecked', reason: 'no-total' });
    assert.match(taughtTotalMessage(taughtTotalCheck(noTotal)), /^Not checked: this grid has no TOTAL row/);
    // Same, but the stored block hit its 4000-char limit: the row may have been cut.
    const cut = { ...noTotal, blockTruncated: true };
    assert.equal(taughtTotalCheck(cut).reason, 'truncated');
    assert.match(taughtTotalMessage(taughtTotalCheck(cut)), /cut off at its storage limit/);
    // A TOTAL row with nothing under the taught months (the 6-stage specimen's shape).
    const empty = roofTeach({ 1: ['A'], 2: ['B'] });
    // A and B are left of every label, so there is no "left of the months" at all:
    // the TOTAL label cannot be found, which is the honest answer.
    assert.equal(taughtTotalCheck(empty).status, 'unchecked');
    const noAmounts = parseHeaderBlock('COLUMNS: A,B,C,D\nR1 [HEADER]: ITEM,TOTAL COST,M1,M2\nR2 [SUMMARY]: ,TOTAL,,\nR3 [DATA]: 1,x,5,5');
    const t = freshState(noAmounts, { sourceType: 'pdf', assigns: { months: { 1: ['C'], 2: ['D'] } } });
    assert.deepEqual(taughtTotalCheck(t), { status: 'unchecked', reason: 'no-month-amounts', row: 2 });
    // Month amounts but no total printed left of them.
    const noPrinted = parseHeaderBlock('COLUMNS: A,B,C,D\nR1 [HEADER]: ITEM,DESC,M1,M2\nR2 [SUMMARY]: ,TOTAL,40,60');
    const t2 = freshState(noPrinted, { sourceType: 'pdf', assigns: { months: { 1: ['C'], 2: ['D'] } } });
    assert.deepEqual(taughtTotalCheck(t2), { status: 'unchecked', reason: 'no-printed-total', row: 2 });
    assert.match(taughtTotalMessage(taughtTotalCheck(t2)), /prints no total left of the months/);
});

test('the check stays out of Excel rows and half-finished teachings', () => {
    assert.equal(taughtTotalCheck(roofTeach(CORRECT, { sourceType: 'excel' })), null, 'PDF only (user decision)');
    assert.equal(taughtTotalCheck(roofTeach({ 1: ['F'] })), null, 'fewer than two months: nothing to judge yet');
    assert.equal(taughtTotalCheck(null), null);
    assert.equal(taughtTotalMessage(null), '');
});

test('a TOTAL label under a month column is a value, not the TOTAL row', () => {
    // "TOTAL" printed in month column C must not make R2 the TOTAL row.
    const p = parseHeaderBlock('COLUMNS: A,B,C,D\nR1 [HEADER]: ITEM,DESC,M1,M2\nR2 [DATA]: ,x,TOTAL,5\nR3 [SUMMARY]: ,TOTAL,40,60');
    const t = freshState(p, { sourceType: 'pdf', assigns: { months: { 1: ['C'], 2: ['D'] } } });
    assert.equal(taughtTotalCheck(t).row, 3);
});
