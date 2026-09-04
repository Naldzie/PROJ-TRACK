import { test } from 'node:test';
import assert from 'node:assert/strict';

import core from '../../public/js/admin/projects/document-parser-core.js';

// ─── Helpers ────────────────────────────────────────────────────────────────

// Build a PDF.js-like text item: { str, transform:[1,0,0,1,x,y], width, hasEOL? }
const item = (str, x, y, width = 20, extra = {}) => ({
    str,
    transform: [1, 0, 0, 1, x, y],
    width,
    ...extra,
});

// ─── PDF text reconstruction ────────────────────────────────────────────────

test('pageItemsToText reconstructs multi-column pages in reading order', () => {
    // PDF.js y grows UPWARD (bottom-left origin), so the TOP line has the
    // largest y. Input is deliberately scrambled (bottom line first) to prove
    // the descending y-sort produces true top-to-bottom reading order.
    const items = [
        item('Epsilon', 10, 100), item('Zeta', 200, 100),
        item('Alpha', 10, 120), item('Beta', 200, 120),
        item('Gamma', 10, 110), item('Delta', 200, 110),
    ];
    const text = core.pageItemsToText(items);
    assert.equal(text, 'Alpha Beta\nGamma Delta\nEpsilon Zeta');
});

test('pageItemsToText inserts a space when there is an X-gap (glued-word fix)', () => {
    // 'Hello' ends at x=30 (10+20); 'World' starts at 33 → gap 3 → space.
    const text = core.pageItemsToText([item('Hello', 10, 50), item('World', 33, 50)]);
    assert.equal(text, 'Hello World');
});

test('pageItemsToText honours the hasEOL flag', () => {
    const text = core.pageItemsToText([item('One', 10, 50), item('Two', 10, 50, 20, { hasEOL: true })]);
    assert.equal(text, 'One Two');
});

test('groupItemsIntoLines groups items with a small Y tolerance', () => {
    const lines = core.groupItemsIntoLines([
        item('A', 10, 110),
        item('B', 10, 108.5), // within tolerance of the first line (larger y = top)
        item('C', 10, 100),   // separate line below
    ]);
    assert.equal(lines.length, 2);
    assert.equal(lines[0].items.length, 2); // top line holds A + B
    assert.equal(lines[0].items[0].str, 'A');
    assert.equal(lines[0].items[1].str, 'B');
    assert.equal(lines[1].items[0].str, 'C');
});

// ─── Label → value lookup ───────────────────────────────────────────────────

test('findLabeledValue reads a value on the same line', () => {
    const lines = core.buildLinesFromText('Project Title: Construction of CSPC Building');
    const value = core.findLabeledValue(lines, [/(?:project\s*title)\s*[:\-]\s*(.+)/i], (v) => v);
    assert.equal(value, 'Construction of CSPC Building');
});

test('findLabeledValue reads a value on the NEXT line (table-style)', () => {
    const lines = core.buildLinesFromText('Start Date\nJanuary 15, 2026\nEnd Date\nMarch 30, 2026');
    const start = core.findLabeledValue(lines, [/(?:start\s*date)\s*[:\-]?\s*(.*)/i], (v) => core.parseToInputDate(v));
    assert.equal(start, '2026-01-15');
});

test('findLabeledValue returns null when nothing validates', () => {
    const lines = core.buildLinesFromText('Start Date\nNot a date at all');
    const start = core.findLabeledValue(lines, [/(?:start\s*date)\s*[:\-]?\s*(.*)/i], (v) => core.parseToInputDate(v));
    assert.equal(start, null);
});

// ─── Budget ─────────────────────────────────────────────────────────────────

test('extractBudget handles ₱ on the same line', () => {
    const lines = core.buildLinesFromText('Approved Budget: ₱1,234,567.00');
    assert.equal(core.extractBudget(lines), '1234567.00');
});

test('extractBudget handles the "(ABC)" variant', () => {
    const lines = core.buildLinesFromText('Approved Budget for the Contract (ABC) ₱ 5,000,000.00');
    assert.equal(core.extractBudget(lines), '5000000.00');
});

test('extractBudget reads the amount from the NEXT line', () => {
    const lines = core.buildLinesFromText('Approved Budget\n₱ 2,500,000.00');
    assert.equal(core.extractBudget(lines), '2500000.00');
});

test('extractBudget ignores ambient amounts without a label', () => {
    // No budget label — must NOT pick up the standalone ₱ figure.
    const lines = core.buildLinesFromText('General Conditions 12%\nTOTAL BID 8,750,000.00');
    assert.equal(core.extractBudget(lines), null);
});

// ─── Dates ──────────────────────────────────────────────────────────────────

test('extractStartDate / extractEndDate next-line style', () => {
    const lines = core.buildLinesFromText(
        'Commencement Date\nJanuary 15, 2026\nCompletion Date\nMarch 30, 2026'
    );
    assert.equal(core.extractStartDate(lines), '2026-01-15');
    assert.equal(core.extractEndDate(lines), '2026-03-30');
});

// ─── Duration ───────────────────────────────────────────────────────────────

test('extractDuration converts months and plain days', () => {
    assert.equal(core.extractDuration(core.buildLinesFromText('Contract Duration: 240')), '240');
    assert.equal(core.extractDuration(core.buildLinesFromText('Contract Duration: 8 months')), '240');
    assert.equal(core.extractDuration(core.buildLinesFromText('Duration\n1 year')), '365');
    assert.equal(core.extractDuration(core.buildLinesFromText('No. of calendar days\n120')), '120');
});

// ─── parseToInputDate ───────────────────────────────────────────────────────

test('parseToInputDate handles common formats', () => {
    assert.equal(core.parseToInputDate('January 15, 2026'), '2026-01-15');
    assert.equal(core.parseToInputDate('Jan. 15, 2026'), '2026-01-15');
    assert.equal(core.parseToInputDate('15/01/2026'), '2026-01-15');
    assert.equal(core.parseToInputDate('01-15-2026'), '2026-01-15');
    assert.equal(core.parseToInputDate('2026-01-15'), '2026-01-15');
    assert.equal(core.parseToInputDate('not a date'), null);
});

// ─── Title / case helpers ───────────────────────────────────────────────────

test('extractTitle falls back to an ALL-CAPS construction line', () => {
    const lines = core.buildLinesFromText('NOTICE TO PROCEED\nCONSTRUCTION OF CSPC ENGINEERING BUILDING');
    assert.equal(core.extractTitle(lines, lines.join('\n')), 'Construction of Cspc Engineering Building');
});

test('toTitleCase keeps minor words lowercase', () => {
    assert.equal(core.toTitleCase('CONSTRUCTION OF THE NEW LIBRARY'), 'Construction of the New Library');
});

// ─── Full parseProjectInfo on a realistic contract snippet ─────────────────

test('parseProjectInfo extracts a full contract form', () => {
    const text = [
        'PROJECT INFORMATION',
        'Name of Project: Renovation of the Main Gate',
        'Location: San Jose, San Mateo, Rizal',
        'Approved Budget: ₱4,000,000.00',
        'Contract Duration: 6 months',
        'Start Date',
        'February 1, 2026',
        'End Date',
        'July 31, 2026',
        'Contractor: ABC Construction Inc.',
    ].join('\n');

    const parsed = core.parseProjectInfo({ text });
    assert.equal(parsed.title, 'Renovation of the Main Gate');
    assert.equal(parsed.location, 'San Jose, San Mateo, Rizal');
    assert.equal(parsed.budget, '4000000.00');
    assert.equal(parsed.duration, '180');
    assert.equal(parsed.startDate, '2026-02-01');
    assert.equal(parsed.endDate, '2026-07-31');
    assert.equal(parsed.contractor, 'ABC Construction Inc');
    assert.equal(core.countFilled(parsed), 7);
});

test('extractDescription stops at the next field label (no blank line needed)', () => {
    const text = [
        'Project title: Test project pdf document',
        'Location: test location',
        'Description: test Pdf document file to test if the pdf document parser is working properly.',
        'Approved budget: 9000',
        'Contractor: testcompany',
        'Start date: may 1, 2026',
        'End date: June 1, 2026',
        'Workers: 50 as is',
    ].join('\n');

    const parsed = core.parseProjectInfo({ text });
    assert.equal(parsed.description, 'test Pdf document file to test if the pdf document parser is working properly');
});

// ─── Regression: PDF.js bottom-up coordinates must not invert page order ────

test('parseProjectInfo handles PDF.js bottom-up y coordinates (top of page = largest y)', () => {
    // Same document as above, but as PDF.js would emit it: each logical line is
    // one text item, the TOP line (Project title) has the LARGEST y, and the
    // items arrive in scrambled order (bottom lines first).
    const items = [
        item('Workers: 50 as is', 10, 100, 120),
        item('End date: June 1, 2026', 10, 110, 120),
        item('Start date: may 1, 2026', 10, 120, 120),
        item('Contractor: testcompany', 10, 130, 120),
        item('Approved budget: 9000', 10, 140, 120),
        item('Description: test Pdf document file to test if the pdf document parser is working properly.', 10, 150, 120),
        item('Location: test location', 10, 160, 120),
        item('Project title: Test project pdf document', 10, 170, 120),
    ];

    const text = core.pageItemsToText(items);
    const firstLine = text.split('\n')[0];
    assert.equal(firstLine, 'Project title: Test project pdf document');

    const parsed = core.parseProjectInfo({ text });
    assert.equal(parsed.title, 'Test project pdf document');
    assert.equal(parsed.location, 'test location');
    assert.equal(parsed.budget, '9000.00');
    assert.equal(parsed.contractor, 'testcompany');
    assert.equal(parsed.startDate, '2026-05-01');
    assert.equal(parsed.endDate, '2026-06-01');
    assert.equal(parsed.description, 'test Pdf document file to test if the pdf document parser is working properly');
});

// ─── Step 1e: CHS-Savings bundle (Original/Revised, Effectivity, Contract Time) ─

test('Step 1e-1: Original/Revised Contract Amount labels (revised wins)', () => {
    // Revised present → revised fills Contract Price.
    const both = core.buildLinesFromText(
        'Original Contract Amount: Php 6,495,049.29\nRevised Contract Amount: Php 6,600,000.00'
    );
    assert.equal(core.extractBudget(both), '6600000.00');
    // Original only → original fills Contract Price.
    const only = core.buildLinesFromText('Original Contract Amount: Php 6,495,049.29');
    assert.equal(core.extractBudget(only), '6495049.29');
});

test('Step 1e-1: Date of Effectivity + Original Contract Time labels', () => {
    // CHS bundle wording for start length.
    const start = core.buildLinesFromText('Date of Effectivity of Contract\nJanuary 15, 2026');
    assert.equal(core.extractStartDate(start), '2026-01-15');
    assert.equal(core.extractStartDate(core.buildLinesFromText('Effectivity: January 15, 2026')), '2026-01-15');
    assert.equal(core.extractDuration(core.buildLinesFromText('Original Contract Time: 90 calendar days')), '90');
    assert.equal(core.extractDuration(core.buildLinesFromText('Contract Time: 90 calendar days')), '90');
});

test('Step 1e-2: split money glue (tail 5049.29 → full amount)', () => {
    // Scan split "6,495,0 49.29" across a cell gap glues back before parsing.
    assert.equal(core.glueMoneyFragments('P 6,495,0 49.29'), 'P 6,495,049.29');
    assert.equal(core.glueMoneyFragments('Php 6,495,049 .29'), 'Php 6,495,049.29');
    const lines = core.buildLinesFromText('Original Contract Amount: Php 6,495,0 49.29');
    assert.equal(core.extractBudget(lines), '6495049.29');
});

test('Step 1e-3: ALL-CAPS two-line title join with Savings tail', () => {
    const lines = core.buildLinesFromText(
        'Project name: CONSTRUCTION OF FOUR-STOREY\nACADEMIC BUILDING (COLLEGE OF HEALTH SCIENCES)-SAVINGS'
    );
    const title = core.extractTitle(lines, lines.join('\n'));
    assert.ok(title.includes('CONSTRUCTION OF FOUR-STOREY'));
    assert.ok(title.includes('ACADEMIC BUILDING'));
    assert.ok(title.toLowerCase().includes('savings'));
});

test('Step 1e-4/1e-5: pooled vote — repeats beat a wrong first hit', () => {
    // File A: wrong tail first, correct amount 3x after (bundle repeats).
    // File B: correct amount 2x. Pooled 5x correct must beat 1x wrong.
    const fileA = [
        'Inspection Report',
        'Amount: P 49.29',
        'Original Contract Amount: Php 6,495,049.29',
        'Revised Contract Amount: Php 6,495,049.29',
        'Summary Total: Php 6,495,049.29',
    ].join('\n');
    const fileB = [
        'Original Contract Amount: Php 6,495,049.29',
        'Accomplishment: Php 6,495,049.29',
    ].join('\n');
    const pooled = core.parseProjectInfoPooled([
        { text: fileA, fileName: 'CHS - Savings.pdf' },
        { text: fileB, fileName: 'Contract - CHS Savings.pdf' },
    ]);
    assert.equal(pooled.budget, '6495049.29');
});