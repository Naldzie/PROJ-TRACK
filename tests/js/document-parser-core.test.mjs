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
    const items = [
        item('Alpha', 10, 100), item('Beta', 200, 100),
        item('Gamma', 10, 110), item('Delta', 200, 110),
        item('Epsilon', 10, 120), item('Zeta', 200, 120),
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
        item('A', 10, 100),
        item('B', 10, 101.5), // within tolerance of the first line
        item('C', 10, 110),   // separate line
    ]);
    assert.equal(lines.length, 2);
    assert.equal(lines[0].items.length, 2);
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