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

// ─── Step 1f: Contract: title label + vote inspector + table tightening ──────

test('Step 1f-1: Contract: label title with Savings tail, cut at located at', () => {
    // Acceptance certificate wording (user Image 1): title inline + location after.
    const lines = core.buildLinesFromText(
        'Contract: Construction of Four-Storey\nAcademic Building (College of Health Sciences) - Savings located at Camarines Sur'
    );
    const title = core.extractTitle(lines, lines.join('\n'));
    assert.ok(title.includes('Construction of Four-Storey'));
    assert.ok(title.toLowerCase().includes('savings'));
    assert.ok(!title.toLowerCase().includes('located at'));
    assert.ok(!title.toLowerCase().includes('camarines'));
});

test('Step 1f-1: Contract Amount/Time lines are NOT titles (guard)', () => {
    // A bare money/time line must not become the project title.
    const money = core.buildLinesFromText('Contract Amount: Php 6,495,049.29');
    assert.equal(core.extractTitle(money, money.join('\n')), null);
    const time = core.buildLinesFromText('Contract Time: 90 calendar days');
    assert.equal(core.extractTitle(time, time.join('\n')), null);
});

test('Step 1f-2/1f-3: inspector debug + contract label beats repeated table guesses', () => {
    // 1x contract-summary hit must beat 3x accomplishment-table repeats of 92025.
    const summary = 'Original Contract Amount: Php 6,495,049.29';
    const table = [
        'Accomplishment Report breakdown table',
        'Line item Total Cost P 92,025.00',
        'Line item Total Cost P 92,025.00',
        'Line item Total Cost P 92,025.00',
    ].join('\n');
    const pooled = core.parseProjectInfoPooled([
        { text: summary, fileName: 'summary.pdf' },
        { text: table, fileName: 'accomplishment.pdf' },
    ]);
    assert.equal(pooled.budget, '6495049.29');
    // Inspector shape: every find listed with the winner on top.
    assert.ok(pooled._debug && Array.isArray(pooled._debug.budget));
    assert.ok(pooled._debug.budget.length >= 2);
    assert.equal(pooled._debug.budget[0].value, '6495049.29');
    // Table section weighs less than contract summary.
    assert.equal(core.getSectionWeight('Original Contract Amount summary'), 1.0);
    assert.ok(core.getSectionWeight('Accomplishment Report breakdown table') < 0.6);
});

// ─── Step 1g: excerpts + Choice B disagree ───────────────────────────────────

test('Step 1g-1: debug rows carry pattern name + source excerpt', () => {
    const text = [
        'Accomplishment Report breakdown table',
        'Line item Total Cost P 92,025.00',
        'Original Contract Amount: Php 6,495,049.29',
    ].join('\n');
    const pooled = core.parseProjectInfoPooled([{ text, fileName: 'bundle.pdf' }]);
    const rows = pooled._debug.budget;
    assert.ok(rows.length >= 2);
    for (const r of rows) {
        assert.ok(typeof r.patterns !== 'undefined' && r.patterns.length > 0);
        assert.ok(typeof r.excerpts !== 'undefined' && r.excerpts.length > 0);
    }
    // The summary row matched a contract label pattern; the table row did not.
    const summary = rows.find(r => r.value === '6495049.29');
    assert.ok(summary.patterns.some(p => /revised|generic|amount-is|final-anchor|discount-anchor/.test(p)));
    assert.ok(summary.excerpts.some(e => /Original Contract Amount/i.test(e)));
});

test('Step 1g-2: close labeled disagreement raises a Choice B flag', () => {
    // 1x summary vs 2x table repeats (the real CHS-bundle shape): winner filled,
    // but disagree flag carries both options for the amber pick-one note.
    const summary = 'Original Contract Amount: Php 6,495,049.29';
    const table = [
        'Accomplishment Report breakdown table',
        'Line item Total Cost P 92,025.00',
        'Line item Total Cost P 92,025.00',
        'Line item Total Cost P 92,025.00',
    ].join('\n');
    const pooled = core.parseProjectInfoPooled([
        { text: summary, fileName: 'summary.pdf' },
        { text: table, fileName: 'accomplishment.pdf' },
    ]);
    assert.equal(pooled.budget, '6495049.29');
    assert.ok(pooled._disagree && pooled._disagree.budget);
    const vals = [pooled._disagree.budget.winner.value, pooled._disagree.budget.runnerUp.value].sort();
    assert.deepEqual(vals, ['6495049.29', '92025.00'].sort());
});

test('Step 1g-2: clear winner raises no flag (clean files see no note)', () => {
    const pooled = core.parseProjectInfoPooled([
        { text: 'Original Contract Amount: Php 6,495,049.29', fileName: 'a.pdf' },
    ]);
    assert.equal(pooled.budget, '6495049.29');
    assert.equal(pooled._disagree.budget, null);
    // Unlabeled runner-up alone never triggers (both sides must look trusted).
    assert.equal(core.pooledDisagree([
        { value: '6495049.29', norm: '6495049.29', count: 1, score: 1.0, labeledCount: 1 },
        { value: '92025.00', norm: '92025.00', count: 3, score: 0.6, labeledCount: 0 },
    ]), null);
});

// ─── Step 1h: date-ghost money fix ───────────────────────────────────────────

test('Step 1h-1: comma glue only for thousands groups, never years', () => {
    // Real table split still glues.
    assert.equal(core.glueMoneyFragments('P 6, 495,049.29'), 'P 6,495,049.29');
    // Date commas are left alone (was glued to 9,2025 = 92025).
    assert.equal(core.glueMoneyFragments('December 9, 2025'), 'December 9, 2025');
    assert.equal(core.glueMoneyFragments('Date of Effectivity of Contract December 9, 2025'), 'Date of Effectivity of Contract December 9, 2025');
});

test('Step 1h-2: dates never become budget candidates', () => {
    // Revised label followed by a date line (the real bundle shape) yields nothing.
    const cands = core.collectBudgetCandidates(
        core.buildLinesFromText('6. Revised Contract Amount\nDecember 9, 2025'), 'bundle.pdf'
    );
    assert.ok(!cands.some(c => c.value === '92025.00'));
    // Pooled: 1x summary vs date-ghost lines → summary wins with NO disagree flag.
    const pooled = core.parseProjectInfoPooled([
        { text: 'Original Contract Amount: Php 6,495,049.29', fileName: 'summary.pdf' },
        { text: '2. Date of Effectivity of Contract December 9, 2025\n6. Revised Contract Amount', fileName: 'bundle.pdf' },
    ]);
    assert.equal(pooled.budget, '6495049.29');
    assert.equal(pooled._disagree.budget, null);
});

test('Step 1h-3: OCR period-thousands reads full in money-marked strings', () => {
    // Labeled shape (the real bundle: Revised line + period-thousands figure).
    assert.equal(core.extractBudget(core.buildLinesFromText('Revised Contract Amount: (Php 6.495,049.29)')), '6495049.29');
    // Bare fallback shape also covered.
    assert.equal(core.extractBudget(core.buildLinesFromText('Total (Php 6.495,049.29)')), '6495049.29');
});

// ─── Step 1i: placeholders + same-line priority + total-row ──────────────────

test('Step 1i-1: placeholder value stops the slide (xxx never borrows a date)', () => {
    // The real bundle shape: Revised = xxx, expiry date two lines below.
    const lines = core.buildLinesFromText(
        '4. Revised Contract Amount xxx\n5. Original Expiry date March 9, 2025'
    );
    // Single-match path: revised yields nothing (no slide into the date).
    assert.equal(core.extractBudget(lines), null);
    // Pooled path: no revised candidate at all.
    const cands = core.collectBudgetCandidates(lines, 'bundle.pdf');
    assert.ok(!cands.some(c => c.pattern === 'revised'));
});

test('Step 1i-2: same-line price beats slid lookalike at equal counts', () => {
    const pooled = core.parseProjectInfoPooled([
        { text: 'Original Contract Amount: Php 6,495,049.29', fileName: 'summary.pdf' },
        { text: 'Total Cost\nP 92,025.00', fileName: 'table.pdf' },
    ]);
    assert.equal(pooled.budget, '6495049.29');
});

test('Step 1i-3: total-row yields only its first figure (Item Cost column)', () => {
    const line = 'TOTAL CONTRACT AMOUNT 6,495,049.29 100.00 52.18 47.82 100.00 3,389,350.95 3,105,689.37 6,495,040.32';
    const cands = core.collectBudgetCandidates(core.buildLinesFromText(line), 'table.pdf');
    const vals = cands.map(c => c.value);
    assert.ok(vals.includes('6495049.29'));
    assert.ok(!vals.includes('6495040.32'));
    assert.ok(cands.some(c => c.pattern === 'total-row'));
});

// ─── 1i follow-up: labeled dot-strip + bare-Php coverage + file counts ───────

test('Follow-up F1: labeled value with OCR thousand-dots reads full (no marker needed)', () => {
    // Matched label is money context by itself: "Total Cost 6.495,049.29".
    const cands = core.collectBudgetCandidates(
        core.buildLinesFromText('Total Cost 6.495,049.29'), 'table.pdf'
    );
    assert.ok(cands.some(c => c.value === '6495049.29'));
    // Ordinary dotted text is untouched (narrow pattern needs digit-dot-3digits-digit).
    assert.equal(core.glueMoneyFragments('See Section 1.495 for details'), 'See Section 1.495 for details');
});

test('Follow-up F2: bare Php figure joins the pool (crop coverage gap)', () => {
    // "Php 6,495,049.29" with no parens/NET-TOTAL/P-prefix (recreated crops).
    const cands = core.collectBudgetCandidates(
        core.buildLinesFromText('Contract Amount: Php 6,495,049.29'), 'crop.pdf'
    );
    assert.ok(cands.some(c => c.pattern === 'bare-php' && c.value === '6495049.29'));
    // Ambient bare figures without Php still ignored (old guard holds).
    assert.equal(core.extractBudget(core.buildLinesFromText('General Conditions 12%\nTOTAL BID 8,750,000.00')), null);
});

test('Follow-up O3: pooled return reports finds per file per field', () => {
    const pooled = core.parseProjectInfoPooled([
        { text: 'Original Contract Amount: Php 6,495,049.29', fileName: 'a.pdf' },
        { text: 'Nothing relevant here', fileName: 'b.pdf' },
    ]);
    assert.ok(pooled._fileCounts && pooled._fileCounts.budget);
    assert.ok((pooled._fileCounts.budget['a.pdf'] || 0) >= 1);
    assert.equal(pooled._fileCounts.budget['b.pdf'] || 0, 0);
});