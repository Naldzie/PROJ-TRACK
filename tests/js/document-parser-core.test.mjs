import { test } from 'node:test';
import assert from 'node:assert/strict';

import core from '../../public/js/admin/projects/document-parser-core.js';

// ─── The ABC blinding switch ────────────────────────────────────────────────
// L3_TEST_BLIND_ABC empties extractABC's patterns, skips the corroborated
// reassignment, skips the words-shape fallback and puts collectAbcCandidates to
// sleep, so a clean file MISSES the ABC and the teach → approve → fills-free
// round trip can be demonstrated end to end.
// The suite reads the switch and asserts whichever behaviour is actually
// configured. It deliberately does NOT carry "intended failures": a suite that is
// expected to be red cannot tell you when it goes red for a new reason, and this
// project has already paid for that lesson once.
const BLIND = !!core.L3_TEST_BLIND_ABC;

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
    // D1: OCR-misread period for comma ("Date of Completion March 13. 2026", test5).
    assert.equal(core.parseToInputDate('March 13. 2026'), '2026-03-13');
    assert.equal(core.parseToInputDate('July 14. 2025'), '2025-07-14');
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

// ─── N-batch: shared strip + hardened compare ────────────────────────────────

test('N1: single-match path reads unmarked period-thousands in full', () => {
    // The banner track uses extractBudget (first-match), which previously kept the tail.
    assert.equal(core.extractBudget(core.buildLinesFromText('Total Cost 6.495,049.29')), '6495049.29');
    // Narrow safety holds on the shared path too.
    assert.equal(core.extractBudget(core.buildLinesFromText('See Section 1.495 for details')), null);
});

test('N2: trivial same-project variants vote equal (&, spacing, punctuation)', () => {
    const dbg = core.voteCandidatePoolDebug([
        { value: 'JURASSIC CONSTRUCTION & SUPPLIES', labeled: true, weight: 1.0, fileName: 'a.pdf' },
        { value: 'JURASSIC CONSTRUCTION AND SUPPLIES', labeled: true, weight: 1.0, fileName: 'b.pdf' },
    ]);
    assert.equal(dbg.rows.length, 1);
    assert.equal(core.normalizeForPool('Construction of X (College) -Savings'), core.normalizeForPool('Construction of X (College)-Savings'));
    // Real differences still separate.
    const dbg2 = core.voteCandidatePoolDebug([
        { value: 'Construction of Building A', labeled: true, weight: 1.0, fileName: 'a.pdf' },
        { value: 'Construction of Building B', labeled: true, weight: 1.0, fileName: 'b.pdf' },
    ]);
    assert.equal(dbg2.rows.length, 2);
});
// --- P1: mismatch grouping (connected vs outliers) ---------------------------------

test('P1: splitMismatchGroups separates majority files from outliers', () => {
    const g = core.splitMismatchGroups([
        { value: 'Project X', norm: 'project x', files: ['a.pdf', 'b.pdf', 'c.pdf'], count: 3 },
        { value: 'Project Y', norm: 'project y', files: ['d.pdf'], count: 1 },
    ]);
    assert.deepEqual(g.connected, ['a.pdf', 'b.pdf', 'c.pdf']);
    assert.equal(g.outliers.length, 1);
    assert.deepEqual(g.outliers[0].files, ['d.pdf']);
    // Agreement (single variant) yields no outliers - clean runs stay quiet.
    assert.deepEqual(core.splitMismatchGroups([{ value: 'Project X', files: ['a.pdf'] }]), { connected: [], outliers: [] });
    assert.deepEqual(core.splitMismatchGroups([]), { connected: [], outliers: [] });
});

// --- L1: correction label mining --------------------------------------------------

test('L1: mineCorrectionLabel anchors money across separator variants', () => {
    // User-typed commas anchor OCR periods and vice versa.
    const m1 = core.mineCorrectionLabel('Contract Sum: Php 6.495,049.29', '6,495,049.29');
    assert.ok(m1 && /contract sum/i.test(m1.label));
    const m2 = core.mineCorrectionLabel('Contract Sum: Php 6,495,049.29', '6.495,049.29');
    assert.ok(m2 && /contract sum/i.test(m2.label));
});

test('L1: mineCorrectionLabel anchors text case-insensitively, null when absent', () => {
    const m = core.mineCorrectionLabel('Project Name: Completion of Library\nLocation: Nabua', 'Completion of Library');
    assert.ok(m && /project name/i.test(m.label));
    // Typed-from-knowledge value with no document anchor yields null (still recorded).
    assert.equal(core.mineCorrectionLabel('Location: Nabua', 'Something Entirely New Here'), null);
    // Tiny fragments never anchor (no "90"-in-"2024-12-09" false hits).
    assert.equal(core.mineCorrectionLabel('Date: December 9, 2024', '90'), null);
    assert.equal(core.mineCorrectionLabel('', 'Whatever value here'), null);
});

// --- M1/M2: whole-token short numerics + multi-occurrence survey ------------------

test('M1: short numerics anchor whole tokens only (205, never inside 2025)', () => {
    const m = core.mineCorrectionLabel('8. Total Contract Time 205 Calendar Days', '205');
    assert.ok(m && /total contract time/i.test(m.label));
    // Refusals that protect dates and grouped thousands alike.
    assert.equal(core.mineCorrectionLabel('Original Expiry date March 9, 2025', '205'), null);
    assert.equal(core.mineCorrectionLabel('Amount Php 1,205,000.00', '205'), null);
    assert.equal(core.mineCorrectionLabel('Date: December 9, 2024', '90'), null);
});

test('M2: multi-occurrence survey prefers the labeled neighborhood', () => {
    const text = [
        'Random 6,495,049.29 scribbled here',
        'Other stuff around',
        'Contract Sum: Php 6,495,049.29 end',
    ].join('\n');
    const m = core.mineCorrectionLabel(text, '6,495,049.29');
    assert.ok(m && /contract sum/i.test(m.label));
});

// --- Multi-label desk: ranked survey list per correction (cap 3, F-GATE filtered) ---

test('Multi-label: one correction surfaces up to 3 distinct gated labels in M2 order', () => {
    const text = [
        'Random 6,495,049.29 scribbled here',
        'Contract Sum: Php 6,495,049.29 noted',
        'Total Contract Cost: Php 6,495,049.29 confirmed',
        'Approved Project Price: Php 6,495,049.29 final',
    ].join('\n');
    const rows = core.mineCorrectionLabels(text, '6,495,049.29', 3);
    assert.equal(rows.length, 3);
    assert.ok(/contract sum/i.test(rows[0].label));
    assert.ok(/total contract cost/i.test(rows[1].label));
    assert.ok(/approved project price/i.test(rows[2].label));
    for (const r of rows) assert.ok(r.excerpt.length > 0);
});

test('Multi-label: near-twin labels dedupe, junk never labels, cap holds at 3', () => {
    // Punctuation twins collapse to one row.
    const twins = core.mineCorrectionLabels(
        'Contract Sum: Php 6,495,049.29 x\nContract Sum - Php 6,495,049.29 y',
        '6,495,049.29', 3);
    assert.equal(twins.length, 1);
    // Junk-adjacent fragments yield the single count-only row, never labels.
    const junk = core.mineCorrectionLabels('OF CANTEEN AT Public\n7,483,4297.50', '7,483,4297.50', 3);
    assert.equal(junk.length, 1);
    assert.equal(junk[0].label, null);
    // Five distinct gated labels still cap at 3.
    const many = [
        'Alpha Contract Cost: Php 6,495,049.29 a',
        'Beta Contract Cost: Php 6,495,049.29 b',
        'Gamma Contract Cost: Php 6,495,049.29 c',
        'Delta Contract Cost: Php 6,495,049.29 d',
        'Epsilon Contract Cost: Php 6,495,049.29 e',
    ].join('\n');
    assert.equal(core.mineCorrectionLabels(many, '6,495,049.29', 3).length, 3);
});

test('Multi-label: legacy single-label wrapper still returns the survey winner', () => {
    const text = 'Random 6,495,049.29 scribbled here\nContract Sum: Php 6,495,049.29 end';
    const single = core.mineCorrectionLabel(text, '6,495,049.29');
    const multi = core.mineCorrectionLabels(text, '6,495,049.29', 3);
    assert.ok(single && multi.length >= 1);
    assert.equal(single.label, multi[0].label);
    assert.equal(single.excerpt, multi[0].excerpt);
    assert.equal(core.mineCorrectionLabel('Nowhere to be found here', '6,495,049.29'), null);
});

// --- G-soup: OCR soup scoring (calibrated on production pastes) ---------------------

test('G-soup: symbol salad scores soupy, clean/table text does not', () => {
    const soup = core.scoreOcrSoup('5 5 939529 393 952555 ~ 3 : 5 SERENE SEEERRIEER ~ << ~ s & LEER EE EEE 2 1');
    assert.equal(soupy(soup), true);
    const clean = core.scoreOcrSoup('3. Original Contract Amount Php4,204,128.84 4 Revised Contract Amount Php4,323,196.77');
    assert.equal(soupy(clean), false);
    // Legit table row with pipes stays below the bar (no wasted retry).
    const table = core.scoreOcrSoup('A.1.4 Provision of Progress Photographs | each | 50.00 | 1,443.75 1,443.75');
    assert.equal(soupy(table), false);
    // Empty text is trivially "soupy" (retry-worthy) without crashing.
    assert.equal(soupy(core.scoreOcrSoup('   ')), true);
    function soupy(r) { return !!(r && r.soupy); }
});

// --- Follow-ups: F-GATE + conditional F-B1 ----------------------------------------

test('F-GATE: junk-adjacent fragments are counted unlabeled, never as labels', () => {
    // Title fragment before a figure carries no label wording — counted, not labeled.
    const junk = core.mineCorrectionLabel('OF CANTEEN AT Public\n7,483,4297.50', '7,483,4297.50');
    assert.ok(junk && junk.label === null && junk.excerpt.length > 0);
    // Genuine label words pass the gate — including bare ABC.
    const abc = core.mineCorrectionLabel('ABC 7,483,4297.50', '7,483,4297.50');
    assert.ok(abc && /abc/i.test(abc.label));
    const m2 = core.mineCorrectionLabel('Contract Sum: Php 6,495,049.29', '6,495,049.29');
    assert.ok(m2 && /contract sum/i.test(m2.label));
});

test('F-B1 conditional: budget-Amount reroutes to ABC only with Original/Revised present', () => {
    // Corroborated file: price from Original, ABC from the budget-Amount line.
    const both = core.buildLinesFromText(
        'Original Contract Amount: Php 4,204,128.84\nTotal budget Amount Php 4,834,297.59'
    );
    assert.equal(core.extractBudget(both), '4204128.84');
    // Blinded: the corroborated budget-Amount → ABC reassignment is skipped.
    assert.equal(core.extractABC(both), BLIND ? null : '4834297.59');
    // Lone budget-Amount line: today's price behavior preserved (no emptying).
    const lone = core.buildLinesFromText('Total budget Amount Php 4,834,297.59');
    assert.equal(core.extractBudget(lone), '4834297.59');
    assert.equal(core.extractABC(lone), null);
    // Pooled mirror: no budget-Amount candidate when Original exists.
    const cands = core.collectBudgetCandidates(both, 'bundle.pdf');
    assert.ok(!cands.some(c => c.value === '4834297.59' && c.pattern === 'generic'));
    assert.ok(cands.some(c => c.value === '4204128.84'));
});

test('Weak-find guard: bare label sliding to a list marker fills nothing (the 2.00 case)', () => {
    // Reproduced byte-identical from production (generic, labeled, weight 0.34): a bare
    // "Project Cost" line borrowing "2. Fringe Benefits" below it minted 2.00 in BOTH tracks.
    // The shared-reader floor stops it in both at once.
    const lines = core.buildLinesFromText('Project Cost\n2. Fringe Benefits');
    assert.equal(core.extractBudget(lines), null);
    assert.deepEqual(core.collectBudgetCandidates(lines, 'r.pdf'), []);
    const pooled = core.parseProjectInfoPooled([{ text: 'Project Cost\n2. Fringe Benefits', fileName: 'r.pdf' }]);
    assert.equal(pooled.budget, null);
});

test('Weak-find guard: marked small figures and labeled large singles still fill', () => {
    // Php-marked small figure carries its own context — trusted.
    assert.equal(core.extractBudget(core.buildLinesFromText('Contract Price: (Php 50.00)')), '50.00');
    // Labeled large single (p6 estimate-total shape) is unaffected by the floor.
    assert.equal(core.extractBudget(core.buildLinesFromText('TOTAL ESTIMATED PROJECT COST 4,834,297.59')), '4834297.59');
});

// --- ABC-pooling: collector + vote + debug + disagree -------------------------------
// RESTORED 2026-09-18 (pilot item N1): L3_TEST_BLIND_ABC is OFF, so these assert the
// real collection behaviour. To re-blind for a demo, flip the flag and these three
// fixtures go back to asserting [] / null.

test('ABC-pooling: collector reads a labelled ABC line', () => {
    const lines = core.buildLinesFromText('Approved Budget for the Contract: Php 4,834,297.59');
    const got = core.collectAbcCandidates(lines, 'demo.pdf');
    if (BLIND) {
        // Blinded: the collector sleeps, which is what makes the demo file miss.
        assert.equal(got.length, 0);
        return;
    }
    assert.equal(got.length, 1);
    assert.equal(got[0].value, '4834297.59');
    assert.equal(got[0].labeled, true);
    assert.equal(got[0].pattern, 'abc-line');
});

test('ABC-pooling: pooled return carries abc vote/debug/disagree/fileCounts keys', () => {
    const pooled = core.parseProjectInfoPooled([
        { text: 'Approved Budget for the Contract: Php 4,834,297.59', fileName: 'demo.pdf' },
    ]);
    // The KEYS must exist either way — only the value depends on the switch.
    assert.equal(pooled.abc, BLIND ? null : '4834297.59');
    assert.ok(pooled._debug && Array.isArray(pooled._debug.abc));
    assert.equal(pooled._disagree.abc, null);
    assert.ok(pooled._fileCounts && typeof pooled._fileCounts.abc === 'object');
    // Budget track untouched by the new keys.
    assert.ok(pooled._debug.budget && pooled._fileCounts.budget);
});

test('ABC-pooling: empty pooled abc never clobbers the learned single-match fill', () => {
    // Synthetic wording only — proves the demo path, not the banked phrasing.
    const text = 'Estimated Project Outlay: Php 4,834,297.59';
    const learned = { labels: { abc: ['Estimated Project Outlay: Php'] } };
    const single = core.parseProjectInfo({ text }, { learned }).abc;
    assert.equal(single, '4834297.59');
    const pooled = core.parseProjectInfoPooled([{ text, fileName: 'demo.pdf' }]);
    assert.equal(pooled.abc, null);
    const kept = pooled.abc ? pooled.abc : single; // mirrors the wrapper override
    assert.equal(kept, '4834297.59');
});

// --- ABC words-rule (A): PESOS-words fallback for funds-cert lines ------------------
// The shared helper is tested directly (strict shape), and since the 2026-09-18 restore
// (pilot item N1) the WIRED paths are tested end to end below as well.

test('ABC words-shape: strict funds-cert wording reads full without Php numeric', () => {
    // Real shape (test5 funds cert p3) minus the Php figure — garbled-numeric case.
    const line = 'with an approved budget for the contract of FOUR MILLION EIGHT HUNDRED THIRTY FOUR THOUSAND TWO HUNDRED NINETY SEVEN PESOS & 59/100';
    assert.equal(core.abcWordsAtWindow(line, ''), '4834297.59');
    // Split across two lines also reads (2-line window).
    assert.equal(core.abcWordsAtWindow('with an approved budget for the contract of FOUR MILLION EIGHT HUNDRED', 'THIRTY FOUR THOUSAND TWO HUNDRED NINETY SEVEN PESOS & 59/100'), '4834297.59');
});

test('ABC words-shape: strictness guards reject lookalikes', () => {
    const good = 'with an approved budget for the contract of FOUR MILLION EIGHT HUNDRED THIRTY FOUR THOUSAND TWO HUNDRED NINETY SEVEN PESOS & 59/100';
    // Lowercase (not ALL CAPS) → null.
    assert.equal(core.abcWordsAtWindow(good.toLowerCase(), ''), null);
    // Missing & cents → null.
    assert.equal(core.abcWordsAtWindow('with an approved budget for the contract of FOUR MILLION PESOS', ''), null);
    // No ABC context (price words line) → null.
    assert.equal(core.abcWordsAtWindow('THE CONTRACTOR EXECUTES THE WORKS FOR FOUR MILLION PESOS & 59/100', ''), null);
    // No number magnitude → null.
    assert.equal(core.abcWordsAtWindow('with an approved budget for the contract of SUPPLIES PESOS & 59/100', ''), null);
});

// RESTORED 2026-09-18 (pilot item N1): the wired paths are live, so they are asserted
// end to end here rather than asserted asleep.

test('ABC words-shape: wired paths read the words amount, not its cents marker (N1a)', () => {
    // THE DEFECT THE BLINDING WAS HIDING. The label pattern captures the whole tail
    // "… PESOS & 59/100"; asMoney used to read 59 out of that cents marker, return
    // P59.00 as the ABC, and short-circuit the words rule that reads it correctly.
    // Both tracks must now agree on the words amount.
    const real = 'with an approved budget for the contract of FOUR MILLION EIGHT HUNDRED THIRTY FOUR THOUSAND TWO HUNDRED NINETY SEVEN PESOS & 59/100';
    const lines = core.buildLinesFromText(real);
    const short = core.buildLinesFromText('with an approved budget for the contract of FOUR MILLION PESOS & 59/100');
    if (BLIND) {
        // Blinded: the words-shape fallback is skipped and the collector sleeps.
        assert.equal(core.extractABC(lines), null);
        assert.deepEqual(core.collectAbcCandidates(lines, 'funds.pdf'), []);
        assert.equal(core.extractABC(short), null);
        return;
    }
    assert.equal(core.extractABC(lines), '4834297.59');
    assert.deepEqual(core.collectAbcCandidates(lines, 'funds.pdf').map(c => c.value), ['4834297.59']);
    // The shorter synthetic shape reads the same way.
    assert.equal(core.extractABC(short), '4000000.59');
});

test('ABC words-shape: the cents guard never eats a real figure (N1a)', () => {
    // A labelled numeric ABC is untouched by the guard.
    const numeric = core.buildLinesFromText('Approved Budget for the Contract (ABC): Php 4,834,297.59');
    const round = core.buildLinesFromText('approved budget for the contract of TWO MILLION PESOS & 00/100');
    assert.equal(core.extractABC(numeric), BLIND ? null : '4834297.59');
    assert.equal(core.extractABC(round), BLIND ? null : '2000000.00');
    // The guard itself is PURE and the switch does not touch it: digits ONLY inside an
    // NN/100 marker are not an amount, blinded or not.
    assert.equal(core.isCentsFractionOnly('PESOS & 59/100'), true);
    assert.equal(core.isCentsFractionOnly('Php 4,834,297.59'), false);
    assert.equal(core.isCentsFractionOnly('4,000,000.00 & 59/100'), false);
    assert.equal(core.isCentsFractionOnly('no digits here'), false);
});

test('ABC words-shape: end-to-end via the shared helper on the real funds-cert wording', () => {
    // funds-cert p3 shape with the Php figure stripped (garbled-numeric case).
    const norm = core.buildLinesFromText(
        'with an approved budget for the contract of FOUR MILLION EIGHT HUNDRED THIRTY FOUR THOUSAND TWO HUNDRED NINETY SEVEN PESOS & 59/100 (contract funds certified)');
    assert.equal(core.readAbcWordsAmount(norm), '4834297.59');
});

// --- AI-3 trigger: offer AI assist only when the local pipeline admits weakness ---

test('AI trigger: weak fills, low confidence, or unresolved disagree offer; clean stays silent', () => {
    const full = (over = {}) => Object.assign({
        title: 'T', location: 'L', description: 'D', budget: '1.00', abc: '2.00',
        budgetSource: 'S', category: 'C', startDate: '2025-01-01', endDate: '2025-02-01',
        duration: '30', contractor: 'K', _confidence: 95,
    }, over);
    // Empty and near-empty runs offer.
    assert.equal(core.shouldOfferAiAssist({ _confidence: 0 }, null), true);
    assert.equal(core.shouldOfferAiAssist({ title: 'T', _confidence: 20 }, null), true);
    // Low confidence offers even with several fills.
    assert.equal(core.shouldOfferAiAssist(full({ _confidence: 30 }), null), true);
    // Unresolved disagree offers on an otherwise decent run.
    const disagree = { budget: { winner: { value: '1.00' }, runnerUp: { value: '2.00' } } };
    assert.equal(core.shouldOfferAiAssist(full({ _confidence: 80 }), disagree), true);
    // Clean run stays silent — including the blinded-clean shape (only ABC missing).
    assert.equal(core.shouldOfferAiAssist(full({ _confidence: 85 }), { budget: null, abc: null }), false);
    assert.equal(core.shouldOfferAiAssist(full({ abc: null, _confidence: 85 }), null), false);
});// --- D-total: Total Contract Time outranks Original ----------------------------------

test('D-total: Total Contract Time wins outright when present (single-match)', () => {
    // The real extension shape: Original 185 vs Total 205 — binding duration wins.
    const lines = core.buildLinesFromText(
        '1. Original Contract Time 185 Calendar days\n8. Total Contract Time 205 Calendar Days'
    );
    assert.equal(core.extractDuration(lines), '205');
    // Original-only files unchanged.
    assert.equal(core.extractDuration(core.buildLinesFromText('Original Contract Time: 90 calendar days')), '90');
});

test('D-total: pooled tags total-contract-time and prefers it', () => {
    const cands = core.collectDurationCandidates(
        core.buildLinesFromText('1. Original Contract Time 185 Calendar days\n8. Total Contract Time 205 Calendar Days'),
        'bundle.pdf'
    );
    const total = cands.find(c => c.value === '205');
    const orig = cands.find(c => c.value === '185');
    assert.ok(total && total.pattern === 'total-contract-time');
    assert.ok(orig && total.weight > orig.weight);
    const pooled = core.parseProjectInfoPooled([
        { text: '1. Original Contract Time 185 Calendar days\n8. Total Contract Time 205 Calendar Days', fileName: 'bundle.pdf' },
    ]);
    assert.equal(pooled.duration, '205');
});

// --- Keep-cleaner minimum-length guard (2026-09-06 yield fix) ---------------------

test('Keep-cleaner guard: long-soupy incumbent survives short-clean challenger', () => {
    // test4 statement page shape: long incumbent the scorer calls soupy holds real
    // amounts; a short clean fragment (header only) must NOT dethrone it. The guard
    // takes the soup flags as inputs (scoring itself is covered by the G-soup test).
    const longIncumbent = 'Original Contract Amount Php 4,204,128.84 Revised Contract Amount Php 4,323,196.77 ' +
        'Date of Effectivity December 9, 2024 Date of Completion March 13, 2026 ' +
        'with additional statement table lines following across the page';
    assert.ok(longIncumbent.trim().length >= 10);
    assert.equal(core.shouldReplaceOcrBest(longIncumbent, true, 'Page 2', false), false);
});

test('Keep-cleaner guard: short-soupy still upgrades to clean 10+; first fill always', () => {
    // Failing page: short soupy retry candidate upgrades normally once substantive.
    assert.equal(core.shouldReplaceOcrBest('~ << ~', true, 'Original Contract Amount Php 4,204,128.84', false), true);
    // Empty best always fills on the first usable read (even short).
    assert.equal(core.shouldReplaceOcrBest('', true, 'Page 2', false), true);
    // Soupy challengers never dethrone; clean-vs-clean keeps first (no churn).
    assert.equal(core.shouldReplaceOcrBest('Original Contract Amount Php 4,204,128.84', false, '~ << ~ soup', true), false);
    assert.equal(core.shouldReplaceOcrBest('Original Contract Amount Php 4,204,128.84', false, 'Another clean line here', false), false);
});

// --- L3: reader consults learned vocabulary (synthetic phrases only) -----------------
// NOTE: fixtures use invented labels ('Estimated Project Outlay', 'Erection of') — never the
// real banked loop-first phrasing — so tests prove the MECHANISM without hand-coding the
// discovery target into the suite.

test('L3: learned ABC label fills when hardcoded labels miss', () => {
    const text = 'Estimated Project Outlay: Php 4,834,297.59';
    assert.equal(core.parseProjectInfo({ text }).abc, null);
    const learned = { labels: { abc: ['Estimated Project Outlay'] } };
    assert.equal(core.parseProjectInfo({ text }, { learned }).abc, '4834297.59');
});

test('L3: hardcoded ABC beats learned label regardless of line order', () => {
    // Learned-labeled line FIRST proves priority is structural, not positional.
    const text = 'Estimated Project Outlay: Php 9,999,999.99\nABC: Php 4,834,297.59';
    const learned = { labels: { abc: ['Estimated Project Outlay'] } };
    // Blinded, the hardcoded rule has nothing to win WITH, so the learned label fills
    // instead — which IS the demonstration the switch exists for.
    assert.equal(core.parseProjectInfo({ text }, { learned }).abc, BLIND ? '9999999.99' : '4834297.59');
});

test('L3: learned title value-keyword fills when hardcoded phrases miss', () => {
    const text = 'Erection of Barangay Hall';
    assert.equal(core.parseProjectInfo({ text }).title, null);
    const learned = { labels: { title: ['erection of'] } };
    assert.equal(core.parseProjectInfo({ text }, { learned }).title, 'Erection of Barangay Hall');
});

test('L3: learned contractor suffix fills when hardcoded suffixes miss', () => {
    const text = 'Apex Infrastructures Incorporated';
    assert.equal(core.parseProjectInfo({ text }).contractor, null);
    const learned = { labels: { contractor: ['infrastructures'] } };
    assert.equal(core.parseProjectInfo({ text }, { learned }).contractor, 'Apex Infrastructures Incorporated');
});

test('L3: learned label with swallowed separator+marker still fills', () => {
    // Mined slices may include the terminator and money marker (e.g. "...: Php").
    // Synthetic wording only — proves the mechanism, not the real banked phrasing.
    const text = 'Estimated Project Outlay: Php 4,834,297.59';
    const learned = { labels: { abc: ['Estimated Project Outlay: Php'] } };
    assert.equal(core.parseProjectInfo({ text }, { learned }).abc, '4834297.59');
});

test('L3: empty learned table reproduces default behavior exactly', () => {
    const text = 'Project: Completion of Health Center\nABC: Php 4,834,297.59';
    assert.deepEqual(
        core.parseProjectInfo({ text }, { learned: { labels: {} } }),
        core.parseProjectInfo({ text }));
});


// ─── Budget source shape (B-1) ──────────────────────────────────────────────
// The preview offers an unmatched budget source for CREATION as a budget_sources
// row, so what this validator lets through can become a permanent database record.
// It is deliberately biased towards rejecting: a rejected value leaves the field
// empty for the reviewer (and the L1-L3 loop lets them teach it), while an accepted
// misread invites a junk row nobody unmakes.

test('asSourceName accepts real funding sources', () => {
    for (const ok of [
        'GAA', 'CHED', 'DPWH', 'LGU', 'GAD',
        'Trust Fund', 'Special Trust Fund', 'CHED-HEDF', 'DPWH/LGU',
        'General Appropriations Act (GAA)', 'GAA 2025', '2025 GAA',
        '20% Development Fund',            // a real LGU source, hence the leading digit
    ]) {
        assert.equal(core.asSourceName(ok), ok, `should accept ${ok}`);
    }
});

test('asSourceName rejects OCR debris', () => {
    // The one that prompted this: a misread tail from a scanned funds certificate,
    // which the old lenRange(2, 100) accepted because it is 13 characters long.
    assert.equal(core.asSourceName('Accor, Fost ='), null);
    for (const bad of [
        '=', 'a', '-- ', '...,,,--',      // punctuation and scraps
        '05142005', '3 1', 'A-1',         // no real word in them
        'of Funds', 'the contract in accordance with the provisions of', // sentence fragments
        'Source | Fund_2',                // characters that never appear in a name
        'a'.repeat(61),                   // longer than any source is
    ]) {
        assert.equal(core.asSourceName(bad), null, `should reject ${JSON.stringify(bad)}`);
    }
});

test('extractBudgetSource takes the name and refuses the misread', () => {
    const from = (t) => core.extractBudgetSource(core.buildLinesFromText(t));
    assert.equal(from('Source of Funds: GAA'), 'GAA');
    assert.equal(from('Funding Source: General Appropriations Act (GAA)'), 'General Appropriations Act (GAA)');
    // Was accepted before B-1, and was then offered for creation as a database row.
    assert.equal(from('Source of Funds: Accor, Fost ='), null);
});

// ─── Contractor shape (B-4) ─────────────────────────────────────────────────
// An Abstract of Bids prints every bidder across ONE row. Once the contrast fix
// made that page readable, the row outranked the contract page's real contractor,
// because the old rule asked only "is this 5-150 characters with a construction
// word in it" — and a row of four construction companies answers yes loudly.

const BID_ROW = "DONBER CONSTRUCTION AND SUPPLY | 301 ENGINEERS | SKYSCRAPER CONS. & ENGINEERING SERVICES | CHRISTINAJOY CONSTRUCTION AND DEV'T CORP";
const REAL_CONTRACTOR = 'SKYSCRAPER CONSTRUCTION & ENGINEERING SERVICES';

test('asCompanyName refuses a table row and keeps a company', () => {
    assert.equal(core.asCompanyName(BID_ROW), null);          // a pipe is a cell boundary
    assert.equal(core.asCompanyName('A | B'), null);
    assert.equal(core.asCompanyName(REAL_CONTRACTOR), REAL_CONTRACTOR);
    assert.equal(core.asCompanyName('ACME BUILDERS'), 'ACME BUILDERS');
    assert.equal(core.asCompanyName('ACME'), null);           // too short to be a name
    assert.equal(core.asCompanyName('x'.repeat(101)), null);  // longer than any company
});

test('the bid-abstract row never becomes the contractor', () => {
    assert.equal(core.isContractorValueCandidate(BID_ROW), null);
    assert.equal(core.extractContractor(core.buildLinesFromText('Contractor: ' + BID_ROW)), null);
});

test('the real contractor still reads, with its hereinafter tail stripped', () => {
    const withTail = REAL_CONTRACTOR + ' (hereinafter called the "CONTRACTOR")';
    assert.equal(core.isContractorValueCandidate(withTail), REAL_CONTRACTOR);
    assert.equal(core.extractContractor(core.buildLinesFromText('Contractor: ' + withTail)), REAL_CONTRACTOR);
    assert.equal(core.extractContractor(core.buildLinesFromText('Awarded to: ACME BUILDERS')), 'ACME BUILDERS');
});

// ─── Near-match against existing rows (B-2) ─────────────────────────────────
// The preview can CREATE a category or budget source. B-1 stopped values that LOOK
// like debris; this catches the ones that look fine and are still wrong — OCR turns
// GAA into CAA, which passes every shape check and becomes a near-duplicate row that
// silently splits every report grouping by source.

const EXISTING = ['GAA', 'CHED', 'DPWH', 'LGU', 'GAD', 'Trust Fund', 'Special Trust Fund'];

test('editDistance counts single edits', () => {
    assert.equal(core.editDistance('GAA', 'GAA'), 0);
    assert.equal(core.editDistance('CAA', 'GAA'), 1);      // substitution — the OCR case
    assert.equal(core.editDistance('GA', 'GAA'), 1);       // deletion
    assert.equal(core.editDistance('GAAX', 'GAA'), 1);     // insertion
    assert.equal(core.editDistance('', 'GAA'), 3);
    assert.equal(core.editDistance('Bananas', 'GAA'), 7);
});

test('nearestName finds the row a misread was meant to be', () => {
    assert.deepEqual(core.nearestName('CAA', EXISTING, 2), { name: 'GAA', distance: 1 });
    assert.deepEqual(core.nearestName('Speclal Trust Fund', EXISTING, 2), { name: 'Special Trust Fund', distance: 1 });
    // Case and spacing are not differences a human would accept as two separate rows.
    assert.deepEqual(core.nearestName('TRUST FUND', EXISTING, 2), { name: 'Trust Fund', distance: 0 });
    assert.deepEqual(core.nearestName('Trust  fund', EXISTING, 2), { name: 'Trust Fund', distance: 0 });
});

test('nearestName stays quiet when nothing is close', () => {
    assert.equal(core.nearestName('Bananas', EXISTING, 2), null);
    assert.equal(core.nearestName('', EXISTING, 2), null);
    assert.equal(core.nearestName('GAA', [], 2), null);
    assert.equal(core.nearestName('GAA', null, 2), null);
});

test('a real neighbouring source is still only a WARNING, not a block', () => {
    // GAD (Gender and Development) is one edit from GAA and is a genuinely different
    // fund. The caller must warn and let the reviewer proceed — which is why this
    // returns a distance rather than a verdict.
    const near = core.nearestName('GAD', ['GAA', 'CHED'], 2);
    assert.equal(near.name, 'GAA');
    assert.equal(near.distance, 1);
    assert.ok(near.distance > 0, 'a non-zero distance is a question, not an answer');
});

// ─── Category shape (B-1b) ──────────────────────────────────────────────────
// Same hole as B-1, next door. A category is not an ordinary label: CategorySeeder
// defines exactly four and the dashboards GROUP BY this column, so a junk fifth
// category does not sit quietly in a table — it splits every figure counting
// projects by type.

test('asCategoryName accepts the seeded categories and their kin', () => {
    for (const ok of [
        'New Construction', 'Renovation', 'Repair', 'Maintenance',   // the seeded four
        'Rehabilitation/Refurbishment', 'Repair & Maintenance',
    ]) {
        assert.equal(core.asCategoryName(ok), ok, `should accept ${ok}`);
    }
});

test('asCategoryName rejects debris', () => {
    for (const bad of [
        'Accor, Fost =',      // the budget-source misread, for good measure
        '05142005',           // no word in it
        'A | B',              // a table row — B-4's cell boundary
        'of the project', 'the Works',   // sentence fragments
        'x',                  // too short
        'Cat: 3',             // a label bled into the value
    ]) {
        assert.equal(core.asCategoryName(bad), null, `should reject ${JSON.stringify(bad)}`);
    }
});

test('extractCategory takes the name and refuses the misread', () => {
    const from = (t) => core.extractCategory(core.buildLinesFromText(t));
    assert.equal(from('Category: Renovation'), 'Renovation');
    assert.equal(from('Type of Project: New Construction'), 'New Construction');
    assert.equal(from('Category: Accor, Fost ='), null);
});

// ─── Values that ran into their neighbours (B-3 / B-5) ──────────────────────
// A scanned page has no columns, only a picture of them, so OCR reads a printed ROW
// as one line and whatever sat to the right is glued on. B-4 caught the version
// carrying a '|'; these carry only a space, so something else has to mark the edge.

test('stripEdgeDebris drops specks without touching the name', () => {
    assert.equal(core.stripEdgeDebris(']  Rehabilitation/Refurbishment of Canteen'), 'Rehabilitation/Refurbishment of Canteen');
    assert.equal(core.stripEdgeDebris('i SKYSCRAPER CONSTRUCTION'), 'SKYSCRAPER CONSTRUCTION');
    assert.equal(core.stripEdgeDebris('ACME BUILDERS'), 'ACME BUILDERS');
    assert.equal(core.stripEdgeDebris('New Construction'), 'New Construction');   // keeps a real first word
    assert.equal(core.stripEdgeDebris('ACME BUILDERS —'), 'ACME BUILDERS');
});

test('cutAtEmbeddedLabel stops where the next field starts', () => {
    assert.equal(
        core.cutAtEmbeddedLabel('Nabua Campus, Camarines Sur Polytechnic Colleges Date: 05142005'),
        'Nabua Campus, Camarines Sur Polytechnic Colleges');
    assert.equal(
        core.cutAtEmbeddedLabel('SKYSCRAPER CONSTRUCTION & ENGINEERING NES : ELA fom'),
        'SKYSCRAPER CONSTRUCTION & ENGINEERING');
    // Nothing to cut.
    assert.equal(core.cutAtEmbeddedLabel('Nabua Campus, Camarines Sur'), 'Nabua Campus, Camarines Sur');
    // A leading label is the value's own, not an embedded one — index 0 never cuts.
    assert.equal(core.cutAtEmbeddedLabel('Location: Nabua'), 'Location: Nabua');
});

test('cutAtEmbeddedLabel matches ONE word, because two swallowed real content', () => {
    // With an optional second word the pattern read "& ENGINEERING NES :" as the label
    // and cut at ENGINEERING, and "Colleges Date:" and cut at Colleges — each losing a
    // word of the answer. These two assertions are what pin that down.
    assert.ok(core.cutAtEmbeddedLabel('A B & ENGINEERING NES : x').endsWith('ENGINEERING'));
    assert.ok(core.cutAtEmbeddedLabel('X Y Colleges Date: 1').endsWith('Colleges'));
});

test('the contractor is recovered rather than refused', () => {
    const from = (t) => core.extractContractor(core.buildLinesFromText('Contractor: ' + t));
    assert.equal(from('i SKYSCRAPER CONSTRUCTION & ENGINEERING NES : ELA fom'),
        'SKYSCRAPER CONSTRUCTION & ENGINEERING');
    // A clean name is untouched by the tidying.
    assert.equal(from('SKYSCRAPER CONSTRUCTION & ENGINEERING SERVICES'),
        'SKYSCRAPER CONSTRUCTION & ENGINEERING SERVICES');
    assert.equal(from('ACME BUILDERS'), 'ACME BUILDERS');
});

test('parseProjectInfo cleans all three fields that ran into neighbours', () => {
    const text = [
        'Project: ]  Rehabilitation/Refurbishment of Canteen at Food Laboratory Building',
        'Location: Nabua Campus, Camarines Sur Polytechnic Colleges Date: 05142005',
        'Contractor: i SKYSCRAPER CONSTRUCTION & ENGINEERING NES : ELA fom',
    ].join('\n');
    const p = core.parseProjectInfo({ text });
    assert.equal(p.title, 'Rehabilitation/Refurbishment of Canteen at Food Laboratory Building');
    assert.equal(p.location, 'Nabua Campus, Camarines Sur Polytechnic Colleges');
    assert.equal(p.contractor, 'SKYSCRAPER CONSTRUCTION & ENGINEERING');
});

test('a clean document is not altered by the tidying', () => {
    const text = [
        'Project: Construction of Two-Storey Academic Building',
        'Location: Nabua, Camarines Sur',
        'Contractor: ACME BUILDERS AND CONSTRUCTION SUPPLY',
    ].join('\n');
    const p = core.parseProjectInfo({ text });
    assert.equal(p.title, 'Construction of Two-Storey Academic Building');
    assert.equal(p.location, 'Nabua, Camarines Sur');
    assert.equal(p.contractor, 'ACME BUILDERS AND CONSTRUCTION SUPPLY');
});
