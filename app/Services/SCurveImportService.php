<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * SCurveImportService
 *
 * Parses a contractor "CONSTRUCTION SCHEDULE AND S-CURVE" Excel workbook
 * (Layout B — activity-weighted) into baseline months for the Set Up
 * Baseline modal.
 *
 * CONFIRMED CLIENT LAYOUT (sampledocs/Updated-S-Curve-3-Storey-Bldg...xlsx):
 *   - Rows 1-8   metadata block
 *   - Rows 10-11 TWO-ROW header: A=ITEM, C=DESCRIPTION, D=AMOUNT, E=3% DISCOUNT,
 *                F=CONTRACT AMOUNT, G=% WEIGHT, H=NO. OF DAYS, then
 *                "Month 1..12" — each month spans TWO adjacent columns
 *                (I:J=Month 1, K:L=Month 2, ..., AE:AF=Month 12); the value is
 *                duplicated in both cells of a pair.
 *   - Data rows  item code (B) | description (C) | peso cols | % weight (G) |
 *                monthly peso amounts. Section-header rows (PART A-H,
 *                DIVISION I-II) carry NO weight. TOTAL row (C="TOTAL") has
 *                G=100 and the contract total in F. A summary block follows
 *                ("% ACCOMPLISHED" / "CUMULATIVE % ACCOMPLISHED" / "COST").
 *
 * OUTPUT (Option A — schema-light, no migration):
 *   months: [
 *     { month_index: 1..N, planned_progress_percent: <cumulative 0-100>,
 *       description: '<activity names joined>',
 *       activities: [{ activity_name, description }] }
 *   ]
 *   meta: { projectName, location, contractAmount, weightSum, warnings[] }
 *
 * No real dates live in the workbook (only "Month 1..12") — the server emits
 * month_index and the CLIENT derives planned_date from the project's planned
 * start date. This keeps the service pure and unit-testable.
 */
class SCurveImportService
{
    /**
     * Parse an S-curve workbook file into baseline months.
     *
     * @param  string  $path  Absolute path to an .xlsx/.xls file.
     * @return array{months: array, meta: array}
     * @throws RuntimeException  When the workbook can't be read or no S-curve
     *                           layout is found.
     */
    public function parse(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('The file could not be found.');
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('The file is not a valid Excel workbook.');
        }

        // Find the sheet that carries the S-curve header block.
        $sheetData = null;
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, false, true);
            $rows = $this->resolveFormulas($sheet, $rows);
            $mergeMap = $this->buildMergeSpanMap($sheet);
            $header = $this->findHeader($rows, $mergeMap);
            if ($header) {
                $sheetData = ['rows' => $rows, 'header' => $header];
                break;
            }
        }

        if (!$sheetData) {
            throw new RuntimeException(
                'No S-curve table found. Expected a header row with a description ' .
                'column ("DESCRIPTION"/"PARTICULARS") and at least two month ' .
                'columns ("Month 1..12", "M1..M12" or month names).'
            );
        }

        return $this->buildMonths($sheetData['rows'], $sheetData['header']);
    }

    // ─── HEADER DETECTION ─────────────────────────────────────────────────────

    /**
     * Locate the header block and its key columns.
     *
     * Header detection is deliberately tolerant of contractor layouts: the item
     * and weight columns are optional, the description column and at least two
     * month columns are the only hard requirements. Supports a single header
     * row or a two-row split header, merged month-header cells, and several
     * month-label styles ("Month 1", "M1", "JAN", bare numbers 1..12).
     *
     * @return array{row: int, codeCol: ?int, descCol: int, weightCol: ?int,
     *               contractCol: ?int, monthSpans: array<int,array<int>>}|null
     */
    private function findHeader(array $rows, array $mergeMap = []): ?array
    {
        $best = null;

        // Single-row pass.
        foreach ($rows as $rowIndex => $row) {
            $det = $this->detectHeaderRow(array_values($row));
            if ($det === null) continue;
            $det['firstRow'] = $rowIndex;
            $det['lastRow'] = $rowIndex;
            $det['mergeMap'] = $mergeMap;
            if ($this->betterHeader($det, $best)) $best = $det;
        }

        // Two-row split-header pass: merge two adjacent rows column-by-column.
        $rowList = array_values($rows);
        foreach ($rowList as $i => $row) {
            if (!isset($rowList[$i + 1])) break;
            $merged = $this->mergeHeaderRows($row, $rowList[$i + 1]);
            $det = $this->detectHeaderRow(array_values($merged));
            if ($det === null) continue;
            $det['firstRow'] = $i;
            $det['lastRow'] = $i + 1;
            $det['mergeMap'] = $mergeMap;
            if ($this->betterHeader($det, $best)) $best = $det;
        }

        if ($best === null) return null;

        return $this->finalizeHeader($best);
    }

    /**
     * Inspect one (possibly merged) header row and return the columns it
     * advertises, or null when it cannot be a header row.
     */
    private function detectHeaderRow(array $cells): ?array
    {
        $hasItem = false;
        $codeCol = null;
        $descCol = null;
        $weightCol = null;
        $contractCol = null;
        $monthCols = [];
        $numericCols = [];

        foreach ($cells as $colIndex => $value) {
            $label = strtolower(trim((string) ($value ?? '')));
            if ($label === '') continue;

            if ($this->isItemLabel($label)) {
                $hasItem = true;
                $codeCol = $colIndex + 1; // item codes sit one column right
            } elseif ($this->isDescriptionLabel($label)) {
                $descCol = $colIndex;
            } elseif ($this->isWeightLabel($label)) {
                $weightCol = $colIndex;
            } elseif ($this->isContractLabel($label)) {
                $contractCol = $colIndex;
            }

            $month = $this->matchMonthLabel($label);
            if ($month !== null) {
                $monthCols[$month] = $colIndex;
            } elseif (preg_match('/^(\d{1,2})$/', $label, $m)) {
                $n = (int) $m[1];
                if ($n >= 1 && $n <= 12) $numericCols[$n] = $colIndex;
            }
        }

        // Bare-number months are ambiguous ("1", "2", ...) — only accept them
        // when they form a contiguous ascending run of at least two months.
        if (count($monthCols) < 2 && count($numericCols) >= 2) {
            $run = $this->longestNumberRun($numericCols);
            if (count($run) >= 2) {
                foreach ($run as $month => $col) $monthCols[$month] = $col;
            }
        }

        // Hard requirements: a description column and at least two month columns.
        if ($descCol === null || count($monthCols) < 2) return null;

        ksort($monthCols);

        return [
            'score' => ($hasItem ? 1 : 0) + ($weightCol !== null ? 1 : 0)
                + ($contractCol !== null ? 1 : 0) + count($monthCols),
            'codeCol' => $codeCol,
            'descCol' => $descCol,
            'weightCol' => $weightCol,
            'contractCol' => $contractCol,
            'monthCols' => $monthCols,
        ];
    }

    private function betterHeader(array $candidate, ?array $best): bool
    {
        return $best === null || $candidate['score'] > $best['score'];
    }

    /**
     * Turn a detected header row into the column map used by buildMonths.
     * A month spans two columns when it is merged across them (from the
     * workbook) or when the next month sits two columns away.
     */
    private function finalizeHeader(array $det): array
    {
        $sorted = $det['monthCols'];
        ksort($sorted);
        $keys = array_keys($sorted);

        // Uniformly-paired layout ("Month 1" at I, "Month 2" at K, ...): every
        // month — including the last — spans [col, col+1] (the client writes a
        // month's peso twice, and tail values may land in the even cell).
        $uniformPaired = count($keys) >= 2;
        foreach ($keys as $i => $monthIndex) {
            if ($i + 1 < count($keys) && $sorted[$keys[$i + 1]] - $sorted[$monthIndex] !== 2) {
                $uniformPaired = false;
                break;
            }
        }

        $sheetRows = range($det['firstRow'] + 1, $det['lastRow'] + 1);
        $monthSpans = [];
        foreach ($keys as $i => $monthIndex) {
            $col = $sorted[$monthIndex];
            $span = $this->mergedSpan($det['mergeMap'], $sheetRows, $col);
            if ($span === null) {
                $span = [$col];
                $next = $keys[$i + 1] ?? null;
                if (($next !== null && $sorted[$next] - $col === 2) || ($next === null && $uniformPaired)) {
                    $span[] = $col + 1;
                }
            }
            $monthSpans[$monthIndex] = $span;
        }

        // The item-code column must not collide with a month column.
        $codeCol = $det['codeCol'];
        if ($codeCol !== null) {
            foreach ($monthSpans as $span) {
                if (in_array($codeCol, $span, true)) {
                    $codeCol = null;
                    break;
                }
            }
        }

        return [
            'row' => $det['lastRow'],
            'codeCol' => $codeCol,
            'descCol' => $det['descCol'],
            'weightCol' => $det['weightCol'],
            'contractCol' => $det['contractCol'],
            'monthSpans' => $monthSpans,
        ];
    }

    /**
     * If a month's header cell is merged across columns, return the covered
     * column indices (0-based) from the workbook merge map.
     */
    private function mergedSpan(array $mergeMap, array $sheetRows, int $colIndex): ?array
    {
        $sheetCol = $colIndex + 1;
        foreach ($sheetRows as $sheetRow) {
            if (isset($mergeMap[$sheetRow][$sheetCol])) {
                $r = $mergeMap[$sheetRow][$sheetCol];
                return range($r['start'] - 1, $r['end'] - 1);
            }
        }
        return null;
    }

    private function isItemLabel(string $label): bool
    {
        return (bool) preg_match('/^(item|item\s*no\.?|no\.?|code|activity\s*no\.?)$/i', $label);
    }

    private function isDescriptionLabel(string $label): bool
    {
        // Step 2a note: "PARTICULARS" and "WORK ITEMS" are already covered below (locked by
        // test). "ITEM NO." is intentionally NOT added here — isItemLabel() claims it first
        // (elseif order in detectHeaderRow) and it is semantically an item-code column.
        if (preg_match('/^(description|particulars|work\s*items?|activity|activities|scope|scope\s*of\s*work)$/i', $label)) {
            return true;
        }

        // R2a: typo tolerance for contractor misspellings ("DESCRITPION" on the roof file).
        // Long words only — fuzzy on short words ("scope", "item") would hallucinate.
        return $this->headerFuzzyMatch($label, ['description', 'particulars', 'scope of work']);
    }

    /**
     * R2a: typo-tolerant header match (Levenshtein ≤ 2, anchored full-cell by the caller).
     *
     * Catches contractor misspellings like DESCRITPION→description or Commulative→cumulative
     * while far words ("NO. OF DAYS") stay rejected by distance. Words under 6 chars are
     * never fuzzed (exact regexes own them). ASCII labels only in practice; guarded length.
     */
    private function headerFuzzyMatch(string $label, array $words, int $maxDist = 2): bool
    {
        $label = strtolower(trim($label));

        if ($label === '' || strlen($label) > 100) {
            return false;
        }

        foreach ($words as $word) {
            if (strlen($word) < 6) {
                continue;
            }

            if (levenshtein($label, $word) <= $maxDist) {
                return true;
            }
        }

        return false;
    }

    private function isWeightLabel(string $label): bool
    {
        // Step 2a: contractor synonyms — "WEIGHTED %", "ALLOCATION" alongside the
        // original weight/weightage/share/gravity/distribution/percent vocabulary.
        return (bool) preg_match('/\b(weight|weighted|weightage|allocation|share|gravity|distribution|percent(?:age)?)\b/i', $label);
    }

    private function isContractLabel(string $label): bool
    {
        return (bool) preg_match('/\b(contract|amount|price|cost)\b/i', $label);
    }

    /**
     * Step 2a: detect author-entered cumulative summary rows ("CUMULATIVE %
     * ACCOMPLISHED", "PLANNED CUMULATIVE %") by label.
     *
     * Detection ONLY in 2a — the row index is recorded in meta['cumulativeRow']
     * for the Step 2b Validated reader; emitted months are untouched. Never
     * required: label-less files (e.g. the weekly outlier) simply report null.
     *
     * R2a: also matches the roof family's "Commulative" typo (fuzzy).
     */
    private function isCumulativeLabel(string $label): bool
    {
        if (preg_match('/cumulative.*%|planned.*cumulative|%\s*accomplished/i', $label)) {
            return true;
        }

        return $this->headerFuzzyMatch($label, ['cumulative']);
    }

    /**
     * R2a: is this data-row a cumulative summary row? Exact patterns run on the
     * whole row text (labels may sit outside descCol, e.g. BUHI column A); the
     * typo-tolerant match runs per cell, since whole-row Levenshtein can never
     * match by distance. Numeric cells are skipped before fuzzing.
     *
     * Called by: buildMonths() meta detection (Step 2a; curve untouched).
     */
    private function isCumulativeRow(mixed $row): bool
    {
        $cells = $this->rowTexts($row);

        if (count($cells) === 0) {
            return false;
        }

        if (preg_match('/cumulative.*%|planned.*cumulative|%\s*accomplished/i', implode(' ', $cells))) {
            return true;
        }

        foreach ($cells as $t) {
            if (!is_numeric($t) && $this->headerFuzzyMatch($t, ['cumulative'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * R2a: non-empty trimmed cell texts of a row (shared by full-row label checks —
     * cumulative detection, off-column TOTAL). One place, one trimming rule.
     *
     * Called by: isCumulativeRow(), buildMonths() TOTAL fallback.
     */
    private function rowTexts(mixed $row): array
    {
        if (!is_array($row)) {
            return [];
        }

        $out = [];
        foreach (array_values($row) as $c) {
            $t = trim((string) $c);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * Map a header cell to a month index (1..12), or null when it is not a
     * month label. Accepts "Month N", "M N", full month names and 3-letter
     * abbreviations, plus ordinals ("1st."–"12th", roof family). Bare numbers
     * are handled separately by longestNumberRun.
     */
    private function matchMonthLabel(string $label): ?int
    {
        if (preg_match('/^(?:month|m)\s*(\d{1,2})$/i', $label, $m)) {
            $n = (int) $m[1];
            return ($n >= 1 && $n <= 12) ? $n : null;
        }

        // R2a: ordinal month labels ("1st.", "2nd.", "3rd." … — roof family AM:AS).
        if (preg_match('/^(\d{1,2})(st|nd|rd|th)\.?$/i', $label, $m)) {
            $n = (int) $m[1];
            return ($n >= 1 && $n <= 12) ? $n : null;
        }

        $months = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
            'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
            'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
            'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];
        $key = strtolower($label);

        return $months[$key] ?? null;
    }

    /**
     * Find the longest contiguous run of ascending month numbers (1..12).
     */
    private function longestNumberRun(array $numericCols): array
    {
        $best = [];
        $current = [];
        $prev = null;
        foreach ($numericCols as $month => $col) {
            if ($prev !== null && $month === $prev + 1) {
                $current[$month] = $col;
            } else {
                $current = [$month => $col];
            }
            if (count($current) > count($best)) $best = $current;
            $prev = $month;
        }
        return $best;
    }

    /**
     * Combine two adjacent header rows into one: a non-empty cell wins; two
     * different non-empty cells are concatenated ("MONTH" + "1" → "MONTH 1").
     */
    private function mergeHeaderRows(array $top, array $bottom): array
    {
        $merged = [];
        foreach (array_keys($top) as $col) {
            $t = trim((string) ($top[$col] ?? ''));
            $b = trim((string) ($bottom[$col] ?? ''));
            if ($t === '' && $b === '') {
                $merged[$col] = '';
            } elseif ($t === '' || $b === '') {
                $merged[$col] = $t !== '' ? $t : $b;
            } elseif (strtolower($t) === strtolower($b)) {
                $merged[$col] = $t;
            } else {
                $merged[$col] = $t . ' ' . $b;
            }
        }
        return $merged;
    }

    /**
     * Build a map of horizontal (single-row) merged-cell spans, keyed by
     * sheet row then by starting column: { row => { col => {start, end} } }.
     * Column indexes here are 1-based (PhpSpreadsheet cell coordinates).
     */
    private function buildMergeSpanMap(Worksheet $sheet): array
    {
        $map = [];
        foreach ($sheet->getMergeCells() as $range) {
            $bounds = Coordinate::rangeBoundaries($range);
            if ($bounds === false || count($bounds) < 2) continue;
            $start = $bounds[0];
            $end = $bounds[1];
            if ($start[1] === $end[1]) { // horizontal merge on a single row
                $map[$start[1]][$start[0]] = ['start' => $start[0], 'end' => $end[0]];
            }
        }
        return $map;
    }

    /**
     * Replace any formula cells ("=...") that toArray could not resolve (no
     * cached value) with their calculated values via the calculation engine.
     */
    private function resolveFormulas(Worksheet $sheet, array $rows): array
    {
        $hasFormulas = false;
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_string($value) && str_starts_with($value, '=')) {
                    $hasFormulas = true;
                    break 2;
                }
            }
        }
        if (!$hasFormulas) return $rows;

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colLetter => $value) {
                if (is_string($value) && str_starts_with($value, '=')) {
                    try {
                        $rows[$rowIndex][$colLetter] = $sheet
                            ->getCell($colLetter . ($rowIndex + 1))
                            ->getCalculatedValue();
                    } catch (\Throwable $e) {
                        $rows[$rowIndex][$colLetter] = null;
                    }
                }
            }
        }

        return $rows;
    }

    // ─── MONTH BUILDING ───────────────────────────────────────────────────────

    private function buildMonths(array $rows, array $header): array
    {
        $dataStart = $header['row'] + 1;

        $descCol = $header['descCol'];
        $codeCol = $header['codeCol'];
        $weightCol = $header['weightCol'];
        $contractCol = $header['contractCol'];
        $monthSpans = $header['monthSpans'];

        $maxMonth = max(array_keys($monthSpans));
        $monthPesoTotal = array_fill(1, $maxMonth, 0.0);
        $activitiesByMonth = array_fill(1, $maxMonth, []);
        $contractTotal = 0.0;
        $weightSum = 0.0;
        $warnings = [];
        $activities = 0;
        $dataRowCount = 0;

        $totalRowContract = null;

        // R2a: roof-family contract placement — a labeled section row carries the month
        // formulas while its subtotal sits on the FOLLOWING desc-less row. Track whether the
        // previous data row counted as an activity so orphans attribute tightly (never pool).
        $prevWasActivity = false;

        // Step 2a: first data-row naming a cumulative summary row anywhere in its cells
        // (BUHI keeps "% ACCOMPLISHED" labels in column A, outside descCol C).
        // 1-based sheet row for the Step 2b reader; null when the file has none.
        // Detection ONLY — the emitted curve is untouched.
        // R2a: exact patterns run on the whole row text; fuzzy ("Commulative") runs
        // per-cell, because Levenshtein on a whole row can never match by distance.
        $cumulativeRow = null;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex < $dataStart) continue;

            $description = trim((string) ($row[$this->letter($descCol)] ?? ''));
            if ($cumulativeRow === null && $this->isCumulativeRow($row)) {
                $cumulativeRow = $rowIndex;
            }
            $weightRaw = $weightCol !== null ? ($row[$this->letter($weightCol)] ?? null) : null;
            $weight = is_numeric($weightRaw) ? (float) $weightRaw : null;
            $contractRaw = $contractCol !== null ? ($row[$this->letter($contractCol)] ?? null) : null;
            $contractAmt = is_numeric($contractRaw) ? (float) $contractRaw : null;

            // Read the month values. Each month spans [col, col+1]: the client
            // writes the month's peso TWICE (each cell = half the month), so the
            // month total is the SUM of the pair. Proven against the sample: the
            // TOTAL row's paired columns (I+J, K+L, ...) sum to the exact
            // contract amount, and items like the field office (F=179,256 with
            // 7469 in all 24 cells) only reconcile as 7469+7469 = 14,938/month.
            // Some items fill only one cell at the tail of a month span.
            $monthValues = [];
            $hasMonthValue = false;
            foreach ($monthSpans as $monthIndex => $cols) {
                $sum = 0.0;
                foreach ($cols as $col) {
                    $raw = $row[$this->letter($col)] ?? null;
                    if (is_numeric($raw)) {
                        $sum += (float) $raw;
                        $hasMonthValue = true;
                    }
                }
                $monthValues[$monthIndex] = $sum;
            }

            // Skip summary-block rows ("% ACCOMPLISHED", "CUMULATIVE % ACCOMPLISHED",
            // "COST") — they carry their own month values, so the label match is
            // enough. A row is spared only if it also has a % weight or contract
            // amount, i.e. a real activity whose name happens to contain "cost".
            // R2a: roof-family summary vocabulary — bare "%", "Cash Flow" (real pesos!),
            // "Project Duration", and the "Commulative" typo. Without these the cash-flow
            // row absorbs as an activity and distorts the curve (diagnosis-proven).
            if (preg_match('/%?\s*(accomplished|complete)|cumulat\w*|commulat\w*|cost\b|cash\s*flow|project\s*duration|^%\s*$/i', $description)
                && $weight === null && $contractAmt === null) {
                $prevWasActivity = false;
                continue;
            }

            // TOTAL row — keep its contract amount for validation. Accepts
            // "TOTAL", "TOTALS", "GRAND TOTAL", etc.
            // R2a: the label may sit outside descCol (roof TOTAL lives in another column),
            // so fall back to the full row text — but ONLY when the row carries a contract
            // amount (prevents absorbing a "Total ..." activity into the fallback bucket).
            // Hyphen-guarded so "Sub-Total" rows never match.
            $isTotalRow = (bool) preg_match('/^(grand\s*)?total/i', $description);
            if (!$isTotalRow && $contractAmt !== null) {
                $isTotalRow = (bool) preg_match('/(?<![\w-])(grand\s*)?totals?(?![\w-])/i', implode(' ', $this->rowTexts($row)));
            }
            if ($isTotalRow) {
                $totalRowContract = $contractAmt;
                $prevWasActivity = false;
                continue;
            }

            // Section-header rows (PART A-H / DIVISION I-II), the sub-header row
            // under the two-row header (day markers / "(IN PESOS)"), and other
            // non-activity rows carry no weight and no description → skip.
            $isActivity = $weight !== null
                || ($hasMonthValue && $description !== '')
                || ($contractAmt !== null && $description !== '');

            if (!$isActivity) {
                // R2a orphan subtotal: a desc-less row carrying ONLY a contract amount,
                // immediately after a counted activity row (roof even rows: F subtotal under
                // the labeled formula row). Attributed to the running total so the curve has
                // a denominator; rows with month values never take this path (ambiguous).
                if ($contractAmt !== null && !$hasMonthValue && $prevWasActivity) {
                    $contractTotal += $contractAmt;
                }
                $prevWasActivity = false;
                continue;
            }

            // ── Activity row ──
            $activities++;
            $dataRowCount++;
            if ($contractAmt !== null) $contractTotal += $contractAmt;
            if ($weight !== null) $weightSum += $weight;

            $activityName = $description !== '' ? $description : ('Activity ' . $activities);
            $activityCode = ($codeCol !== null && trim((string) ($row[$this->letter($codeCol)] ?? '')) !== '')
                ? trim((string) $row[$this->letter($codeCol)])
                : null;

            foreach ($monthValues as $monthIndex => $value) {
                if ($value > 0) {
                    $monthPesoTotal[$monthIndex] += $value;
                    $activitiesByMonth[$monthIndex][] = [
                        'activity_name' => $activityName,
                        'description' => $activityCode,
                    ];
                }
            }

            // R2a: this counted row qualifies a following orphan subtotal row.
            $prevWasActivity = true;
        }

        if ($activities === 0) {
            throw new RuntimeException('No activity rows were found after the header row.');
        }

        // Resolve the contract total: prefer the sum of activity contract
        // amounts, fall back to the TOTAL row, else weights.
        if ($contractTotal <= 0) {
            $contractTotal = (float) ($totalRowContract ?? 0);
        }
        if ($contractTotal <= 0) {
            $warnings[] = 'Could not determine the total contract amount; percentages are based on activity weights only.';
        }

        // ── Monthly cumulative % ──
        $months = [];
        $running = 0.0;
        $prevCumulative = 0.0;
        $lastNonZero = 0;
        foreach ($monthSpans as $monthIndex => $cols) {
            if ($monthPesoTotal[$monthIndex] > 0) $lastNonZero = $monthIndex;
        }

        foreach ($monthSpans as $monthIndex => $cols) {
            $monthPct = $contractTotal > 0
                ? ($monthPesoTotal[$monthIndex] / $contractTotal) * 100
                : 0.0;

            $running += $monthPct;
            $cumulative = $running;

            // Monotonic clamp (never decreases).
            if ($cumulative < $prevCumulative) {
                $warnings[] = "Month {$monthIndex} cumulative % was clamped to keep the curve monotonic.";
                $cumulative = $prevCumulative;
            }
            $cumulative = min($cumulative, 100.0);
            $running = $cumulative;
            $prevCumulative = $cumulative;

            $names = array_map(fn ($a) => $a['activity_name'], $activitiesByMonth[$monthIndex]);
            $description = count($names) > 0 ? implode(', ', $names) : '';

            $months[$monthIndex] = [
                'month_index' => $monthIndex,
                'planned_progress_percent' => round($cumulative, 2),
                'description' => mb_strlen($description) > 200
                    ? mb_substr($description, 0, 200) . '…'
                    : $description,
                'activities' => $activitiesByMonth[$monthIndex],
            ];
        }

        // Force the final month with data (or the last header month) to 100%.
        $finalMonth = $lastNonZero > 0 ? $lastNonZero : $maxMonth;
        $months[$finalMonth]['planned_progress_percent'] = 100.0;
        $running = 100.0;

        // Weight sanity check (weights should sum to ~100).
        if ($weightSum > 0 && abs($weightSum - 100) > 1) {
            $warnings[] = 'Sum of activity % weights is ' . round($weightSum, 2) . '% (expected ~100%).';
        }

        $meta = [
            'projectName' => $this->extractMetadata($rows, '/contract\s*name/i'),
            'location' => $this->extractMetadata($rows, '/location/i'),
            'contractAmount' => round($contractTotal, 2),
            'weightSum' => round($weightSum, 2),
            'monthCount' => $maxMonth,
            'activityCount' => $activities,
            'warnings' => $warnings,
            // Step 2a: cumulative summary-row detection for the Step 2b reader.
            'cumulativeRow' => $cumulativeRow,
        ];

        return [
            'months' => array_values($months),
            'meta' => $meta,
        ];
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    /**
     * Pull a metadata value (project name / location) from the header block:
     * finds the first cell whose value matches $pattern and returns the next
     * non-empty cell in the same row.
     */
    private function extractMetadata(array $rows, string $pattern): ?string
    {
        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex > 12) break;
            $cells = array_values($row);
            foreach ($cells as $i => $value) {
                if (preg_match($pattern, (string) $value)) {
                    for ($j = $i + 1; $j < count($cells); $j++) {
                        $v = trim((string) ($cells[$j] ?? ''));
                        if ($v !== '') return mb_substr($v, 0, 250);
                    }
                }
            }
        }
        return null;
    }

    /**
     * Convert a 0-based column index to its letter(s): 0→A, 1→B, 26→AA.
     */
    private function letter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - 1, 26);
        }
        return $letter;
    }
}
