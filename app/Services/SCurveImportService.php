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
     * E1-3 — learned layout map schema version.
     *
     * Stored inside every auto-derived column_map as ['v' => 1]. The reader
     * applies a map ONLY on exact version match, so a future span-semantics
     * change (e.g. the parked 2c SUM-vs-MAX rule) can bump this const and old
     * maps safely stop applying instead of silently corrupting curves.
     *
     * Called by: deriveAutoColumnMap() (writes), findHeaderViaMaps() (gates).
     */
    public const MAP_VERSION = 1;
    /**
     * Serialize a workbook to compact CSV text for the E2 AI fallback.
     *
     * Dumps the first non-trivial sheet (header block + data rows, capped) so Gemini
     * reads layout + figures as text — no page rendering exists for Excel by design.
     * Minimized: one legend line + at most $maxRows rows × 80 columns, trailing
     * empty cells trimmed.
     *
     * Slice reform (2026-09-10): rows carry [ROLE] tags from the server's own
     * detectors (META title block / HEADER column labels / DATA activity rows /
     * SUMMARY author totals) plus a column-letter legend — the flat unlabeled CSV
     * made copying the summary row the rational move (proven live: webapp Gemini
     * computed correctly from the native sheet while our slice elicited a copy).
     * Header detection tries strict then relaxed (novel wordings still get roles
     * even when the main path 422s). NOTE: tags change the capture hash — files
     * captured pre-reform may re-capture once as a second proposed row (approve
     * one, reject the other; E1-3 signature matching is unaffected).
     *
     * @param  string  $path  Absolute path to an .xlsx/.xls file.
     * @throws RuntimeException  When the workbook can't be read.
     *
     * Called by: SCurveImportController::parseWithGemini() (planned E2 endpoint).
     */
    public function serializeForAi(string $path, int $maxRows = 40): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('The file could not be found.');
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('The file is not a valid Excel workbook.');
        }

        $lines = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            // Letter-keyed grid for role detection (keys are sheet rows).
            $grid = $sheet->toArray(null, true, false, true);
            $grid = $this->resolveFormulas($sheet, $grid);
            $roles = $this->labelSliceRows($grid, $sheet);

            // OFF-BY-ONE FIX (2026-09-10): the CSV MUST come from the SAME grid the
            // roles were keyed on. A previous revision read a second, numeric-keyed
            // toArray (0-based) while roles were keyed 1-based sheet rows — every kept
            // line wore the previous row's role (header labeled META, first data row
            // labeled HEADER), silently teaching the AI wrong roles. Single source of
            // truth below: array_values() on the letter-keyed rows is positionally
            // identical to the old numeric view, so CSV output is byte-compatible.
            // ONE WALK, NOT TWO (2026-09-14). This loop and keptRowKeys() were
            // duplicates of the same rule, and keptRowKeys is what turns an
            // AI-reported R# back into a sheet row — so the moment the two
            // disagree about which rows are kept, every R# resolves to the wrong
            // row, silently. They cannot drift now because there is one rule.
            $rows = $grid;
            $width = 0;
            $kept = 0;
            $keptKeys = [];
            foreach ($this->keptRowKeys($grid, $maxRows, $roles) as $rowKey) {
                $cells = array_slice(array_values($rows[$rowKey] ?? []), 0, 80);
                while (count($cells) > 0 && trim((string) end($cells)) === '') {
                    array_pop($cells);
                }
                if (count($cells) === 0) {
                    continue;
                }
                $width = max($width, count($cells));
                $fp = fopen('php://memory', 'r+');
                fputcsv($fp, array_map(fn ($c) => trim((string) ($c ?? '')), $cells));
                rewind($fp);
                $kept++;
                $keptKeys[] = ['key' => $rowKey, 'csv' => trim((string) stream_get_contents($fp)), 'n' => $kept];
                fclose($fp);
            }
            if ($kept >= 2) {
                // Column-letter legend + role key (one line; row tags below).
                $letters = [];
                for ($c = 0; $c < $width; $c++) {
                    $letters[] = Coordinate::stringFromColumnIndex($c + 1);
                }
                $lines[] = 'COLUMNS: ' . implode(',', $letters)
                    . ' | META: title block | HEADER: column labels'
                    . ' | DATA: activity rows — compute months from these ONLY'
                    . ' | SUMMARY: author totals (TOTAL/COST/Cash Flow) — reference only, NEVER copy into months'
                    . ' | CUMULATIVE: the printed running curve — reference only, NEVER copy into months';
                foreach ($keptKeys as $k) {
                    $lines[] = 'R' . $k['n'] . ' [' . ($roles[$k['key']] ?? 'DATA') . ']: ' . $k['csv'];
                }
                break;
            }
            $lines = [];
        }

        if (count($lines) < 3) {
            throw new RuntimeException('The workbook holds no readable rows to send.');
        }

        return implode("\n", $lines);
    }

    /**
     * Slice reform — label each grid row META/HEADER/DATA/SUMMARY for the AI slice.
     *
     * Header comes from the detectors (strict, then relaxed so novel wordings
     * still get structure); summary rows reuse isCumulativeRow plus the TOTAL
     * fallback shape from buildMonths. Rows before the header are META.
     * Without any header, everything is DATA except detected summary rows.
     *
     * Called by: serializeForAi().
     */
    private function labelSliceRows(array $grid, Worksheet $sheet): array
    {
        $mergeMap = $this->buildMergeSpanMap($sheet);
        $det = $this->findHeaderWith($grid, $mergeMap, fn (array $cells) => $this->detectHeaderRow($cells), false);
        if ($det === null) {
            $det = $this->findHeaderWith($grid, $mergeMap, fn (array $cells) => $this->detectHeaderRow($cells, true), false);
        }

        // Resolve header keys (single-row detections store keys, two-row store
        // positions — same duality as headerSignature).
        $headerKeys = [];
        if ($det !== null) {
            if ($det['lastRow'] === $det['firstRow']) {
                $headerKeys = [$det['firstRow']];
            } else {
                $keys = array_keys($grid);
                foreach ([$det['firstRow'], $det['lastRow']] as $pos) {
                    if (isset($keys[$pos])) {
                        $headerKeys[] = $keys[$pos];
                    }
                }
            }
        }
        $firstHeader = count($headerKeys) > 0 ? min($headerKeys) : null;

        $roles = [];
        foreach ($grid as $rowKey => $row) {
            if (in_array($rowKey, $headerKeys, true)) {
                $roles[$rowKey] = 'HEADER';
            } elseif ($firstHeader !== null && $rowKey < $firstHeader) {
                $roles[$rowKey] = 'META';
            } elseif ($this->isCumulativeRow($row)) {
                // 2026-09-14: split out of the folded SUMMARY branch. The printed
                // cumulative row is the one summary row that carries a CURVE, and
                // both the reviewer and the AI need to tell it apart from a TOTAL.
                // Tested BEFORE isSliceTotalRow because a row reading
                // "CUMULATIVE TOTAL %" satisfies both and the curve is the
                // stronger fact. Sits after HEADER/META on purpose — unlike the
                // PDF builders, this can never steal a header's tag.
                $roles[$rowKey] = 'CUMULATIVE';
            } elseif ($this->isSliceTotalRow($row)) {
                $roles[$rowKey] = 'SUMMARY';
            } else {
                $roles[$rowKey] = 'DATA';
            }
        }

        return $roles;
    }

    /**
     * Slice reform — TOTAL-row shape for slice labeling (mirrors the buildMonths
     * fallback: TOTAL label anywhere in the row; hyphen-guarded so Sub-Total
     * never matches).
     *
     * Called by: labelSliceRows().
     */
    private function isSliceTotalRow(mixed $row): bool
    {
        $texts = $this->rowTexts($row);
        if (count($texts) === 0) {
            return false;
        }

        return (bool) preg_match('/(?<![\w-])(grand\s*)?totals?(?![\w-])/i', implode(' ', $texts));
    }

    /**
     * Parse an S-curve workbook file into baseline months.
     *
     * E1-3: optional $liveMaps (learned layouts, contractor-first then global
     * from the controller) act as the LOWEST-priority fallback — every sheet
     * is tried with rule detection first, and maps are consulted only when no
     * sheet yields a rule header. Omitted/empty maps reproduce today's
     * behavior exactly.
     *
     * @param  string  $path  Absolute path to an .xlsx/.xls file.
     * @param  array|null  $liveMaps  [{id, contractor_id, column_map}] or null.
     * @return array{months: array, meta: array}
     * @throws RuntimeException  When the workbook can't be read or no S-curve
     *                           layout is found.
     */
    public function parse(string $path, ?array $liveMaps = null): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('The file could not be found.');
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('The file is not a valid Excel workbook.');
        }

        // Pass 1 — rule detection on every sheet (unchanged behavior; rules
        // always win ties against learned maps).
        $sheetData = null;
        $sheets = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, false, true);
            $rows = $this->resolveFormulas($sheet, $rows);
            $mergeMap = $this->buildMergeSpanMap($sheet);
            $sheets[] = ['rows' => $rows, 'mergeMap' => $mergeMap];
            $header = $this->findHeader($rows, $mergeMap);
            if ($header) {
                $sheetData = ['rows' => $rows, 'header' => $header];
                break;
            }
        }

        // Pass 2 — E1-3 learned maps, only when rules missed everywhere.
        $mapId = null;
        if (!$sheetData && !empty($liveMaps)) {
            foreach ($sheets as $cached) {
                $hit = $this->findHeaderViaMaps($cached['rows'], $liveMaps);
                if ($hit !== null) {
                    $mapId = $hit['_mapId'] ?? null;
                    unset($hit['_mapId']);
                    $sheetData = ['rows' => $cached['rows'], 'header' => $hit];
                    break;
                }
            }
        }

        if (!$sheetData) {
            throw new RuntimeException(
                'No S-curve table found. Expected a header row with a description ' .
                'column ("DESCRIPTION"/"PARTICULARS") and at least two month ' .
                'columns ("Month 1..12", "M1..M12" or month names).'
            );
        }

        $result = $this->buildMonths($sheetData['rows'], $sheetData['header']);

        // E1-3 provenance: flag map-built curves (additive keys; rule-built
        // results keep mapUsed=false with zero new warnings).
        $result['meta']['mapUsed'] = $mapId !== null;
        $result['meta']['mapId'] = $mapId;
        if ($mapId !== null) {
            $result['meta']['warnings'][] = 'Heads-up: the table was read with a'
                . ' learned layout for this contractor — the months below came from'
                . ' that mapping. No action needed unless something looks off.';
        }

        return $result;
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
        return $this->findHeaderWith($rows, $mergeMap, fn (array $cells) => $this->detectHeaderRow($cells));
    }

    /**
     * E1-3 — shared header search behind both detectors.
     *
     * Runs the given single-row detector over every row plus every adjacent
     * merged pair, keeping the best score. findHeader() passes the strict
     * detector (unchanged behavior); deriveAutoColumnMap() passes the relaxed
     * one (novel description wordings only — month rules identical).
     *
     * Called by: findHeader(), deriveAutoColumnMap().
     */
    private function findHeaderWith(array $rows, array $mergeMap, callable $detect, bool $finalize = true): ?array
    {
        $best = null;

        // Single-row pass.
        foreach ($rows as $rowIndex => $row) {
            $det = $detect(array_values($row));
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
            $det = $detect(array_values($merged));
            if ($det === null) continue;
            $det['firstRow'] = $i;
            $det['lastRow'] = $i + 1;
            $det['mergeMap'] = $mergeMap;
            if ($this->betterHeader($det, $best)) $best = $det;
        }

        if ($best === null) return null;

        return $finalize ? $this->finalizeHeader($best) : $best;
    }

    /**
     * Inspect one (possibly merged) header row and return the columns it
     * advertises, or null when it cannot be a header row.
     *
     * E1-3: $relaxed widens ONLY the description match (novel contractor
     * wordings like "TASK DESCRIPTION"); month/item/weight/contract rules and
     * the hard gate (desc + ≥2 months) are identical. Relaxed hits never enter
     * the main path — they become scoped auto-maps via deriveAutoColumnMap().
     */
    private function detectHeaderRow(array $cells, bool $relaxed = false): ?array
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
            } elseif ($this->isDescriptionLabel($label)
                || ($relaxed && $this->isLooseDescriptionLabel($label))) {
                $descCol = $colIndex;
            } elseif ($this->isWeightLabel($label)) {
                $weightCol = $colIndex;
            } elseif ($this->isContractLabel($label)) {
                $contractCol = $colIndex;
            }

            $month = $this->matchMonthLabel($label, $relaxed);
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
     * E1-3 — loose description wording for auto-map derivation (relaxed path only).
     *
     * Catches novel contractor phrasings the strict full-cell match rejects
     * ("TASK DESCRIPTION", "PAY ITEM", "SCOPE OF WORKS"). Never runs in the
     * main path — hits become contractor/hash-scoped maps, so looseness here
     * cannot false-positive on other contractors' files.
     *
     * Called by: detectHeaderRow() when $relaxed is true.
     */
    private function isLooseDescriptionLabel(string $label): bool
    {
        // P1-follow-up adds bare "tasks" (SCHEDULED TASKS matched no tier at all).
        return (bool) preg_match('/\b(descript\w*|detail\w*|particular\w*|activit\w*|tasks?|pay\s*item|bill\s*of|work\s*items?|scope|specification|desc)\b/i', $label);
    }

    /**
     * E1-3 — auto-derive a versioned layout map from a failed workbook.
     *
     * Runs the relaxed detector (novel description wording + standard month
     * rules, still ≥2 months) over every sheet and packs the best header into
     * a v:1 column_map with a fuzzy signature of the header row. Returns null
     * when nothing derivable exists — the capture then stores a map-less row
     * (reviewable, but never consulted) instead of a guess.
     *
     * Called by: ScurveHeaderProposalController::collect() (best-effort).
     */
    public function deriveAutoColumnMap(string $path): ?array
    {
        try {
            if (!is_file($path)) return null;
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, false, true);
            $rows = $this->resolveFormulas($sheet, $rows);
            $mergeMap = $this->buildMergeSpanMap($sheet);
            $det = $this->findHeaderWith($rows, $mergeMap, fn (array $cells) => $this->detectHeaderRow($cells, true), false);
            if ($det === null) continue;
            $header = $this->finalizeHeader($det);
            if (count($header['monthSpans']) < 2) continue;

            return [
                'v' => self::MAP_VERSION,
                'rowHint' => $header['row'],
                'codeCol' => $header['codeCol'],
                'descCol' => $header['descCol'],
                'weightCol' => $header['weightCol'],
                'contractCol' => $header['contractCol'],
                'monthSpans' => $header['monthSpans'],
                'sig' => $this->headerSignature($rows, $det),
            ];
        }

        return null;
    }

    /**
     * E1-3 — signature of a detected header block for typo-tolerant matching.
     *
     * Stores the merged header cells as [col, normalized text] pairs (labels
     * only — no project values), so a later file from the same template
     * matches even with reworded values or small header typos elsewhere.
     *
     * Called by: deriveAutoColumnMap().
     */
    private function headerSignature(array $rows, array $det): array
    {
        // Single-row detections store the ORIGINAL row key (toArray keys are
        // 1-based); two-row detections store 0-based positions — resolve each
        // shape in its own space (see findHeaderWith).
        if ($det['lastRow'] === $det['firstRow']) {
            $merged = $rows[$det['firstRow']] ?? [];
        } else {
            $list = array_values($rows);
            $merged = $this->mergeHeaderRows(
                $list[$det['firstRow']] ?? [],
                $list[$det['lastRow']] ?? []
            );
        }

        $sig = [];
        foreach (array_values($merged) as $col => $value) {
            $text = strtolower(trim((string) ($value ?? '')));
            if ($text !== '') {
                $sig[] = [$col, $text];
            }
        }

        return $sig;
    }

    /**
     * E1-A — build a versioned layout map from reviewer-taught columns.
     *
     * The Phase-A desk sends column LETTERS; the caller converts to 0-based
     * indices first. $mergedHeaderCells is the 0-based value list of the taught
     * header row(s), already merged for two-row headers. Validation mirrors the
     * consult gates plus hygiene the reader assumes: disjoint spans, no label
     * column inside a month span, strictly increasing months (catches swapped
     * P1/P12 teaching), and a sig the consult can actually anchor (≥2 cells
     * incl. a non-empty desc cell). Returns null when unteachable.
     *
     * Called by: ScurveHeaderProposalController::teachColumns().
     */
    /**
     * 0-based column index -> the letter the teach grid shows for it (0 = A).
     *
     * Refusals name the column the reviewer actually clicked, not an index they
     * never see. Beyond Z it keeps counting (AA, AB…) the way the legend does.
     *
     * Pure. Called by: buildTaughtMap() refusal reasons.
     */
    public static function letterFor(int $index): string
    {
        if ($index < 0) {
            return '?';
        }
        $out = '';
        $n = $index;
        while (true) {
            $out = chr(65 + ($n % 26)) . $out;
            $n = intdiv($n, 26) - 1;
            if ($n < 0) {
                break;
            }
        }

        return $out;
    }

    /**
     * Row-role teaching — the ONE rule for turning a row into a matchable signature.
     *
     * Taught row roles are stored as TEXT, never as R-numbers. R-numbers are
     * kept-ordinals over the captured slice (empties compressed), so they shift the
     * moment a contractor inserts a row, and a taught exclusion that silently slid
     * onto a neighbouring row would drop a real activity out of the contract total
     * with nothing to show for it. The header is already matched by text (`sig`)
     * rather than by rowHint for exactly this reason; this follows house style.
     *
     * Numeric cells are dropped because the numbers are what change between
     * revisions of the same schedule — a "Cash Flow" row keeps its label while its
     * pesos move every re-issue. What remains is the label, lowercased and
     * whitespace-collapsed, which is what isCumulativeRow already matches on.
     *
     * ONE RULE, TWO CALLERS, ON PURPOSE: the teach endpoint computes signatures
     * from the stored block and the reader computes them from the live workbook.
     * The moment those two normalizations differ, every taught role stops matching
     * and does so silently — the same failure mode keptRowKeys was unified to
     * prevent. Returns '' for a row with no usable text; callers MUST refuse to
     * store an empty signature, since it would match every blank row in the file.
     *
     * Pure. Called by: ScurveHeaderProposalController::teachColumns() and the
     * activity loop / summary reader in buildMonths().
     */
    public static function rowSignature(array $cells): string
    {
        $words = [];
        foreach ($cells as $cell) {
            $text = trim((string) ($cell ?? ''));
            if ($text === '' || is_numeric($text)) {
                continue;
            }
            $words[] = $text;
        }

        return trim(preg_replace('/\s+/', ' ', mb_strtolower(implode(' ', $words))));
    }

    public function buildTaughtMap(
        array $mergedHeaderCells,
        int $descCol,
        ?int $codeCol,
        ?int $weightCol,
        ?int $contractCol,
        array $monthSpans,
        ?string &$reason = null,
        array $excludeSigs = [],
        ?string $cumulativeSig = null
    ): ?array {
        $fail = function (string $code) use (&$reason) { $reason = $code; return null; };
        if ($descCol < 0) return $fail('no-description');
        foreach (['code' => $codeCol, 'weight' => $weightCol, 'contract' => $contractCol] as $name => $col) {
            if ($col !== null && $col < 0) return $fail("bad-{$name}");
        }

        // Normalize month keys (JSON arrives string-keyed) and span cols.
        $spans = [];
        foreach ($monthSpans as $m => $cols) {
            $mi = (int) $m;
            if ($mi < 1 || $mi > 12 || isset($spans[$mi])) return $fail('bad-month-number');
            $cols = array_map('intval', (array) $cols);
            if (count($cols) === 0) return $fail("empty-month:{$mi}");
            foreach ($cols as $c) {
                if ($c < 0 || $c > 79) return $fail("bad-month-column:{$mi}");
            }
            $spans[$mi] = array_values(array_unique($cols));
        }
        if (count($spans) < 2) return $fail('too-few-months');
        ksort($spans);

        // Span cells pairwise disjoint; label cols outside every span.
        $used = [];
        foreach ($spans as $mi => $cols) {
            foreach ($cols as $c) {
                if (isset($used[$c])) return $fail('month-shares-column:' . self::letterFor($c));
                $used[$c] = $mi;
            }
        }
        foreach (['description' => $descCol, 'item code' => $codeCol, 'weight' => $weightCol, 'contract amount' => $contractCol] as $label => $col) {
            if ($col !== null && isset($used[$col])) {
                return $fail("label-in-month:{$label}:" . self::letterFor($col) . ":" . $used[$col]);
            }
        }

        // Months run left-to-right (first col of each span strictly increasing).
        $prev = -1;
        foreach ($spans as $cols) {
            $first = min($cols);
            if ($first <= $prev) return $fail('months-out-of-order');
            $prev = $first;
        }

        // Signature mirrors auto maps: all non-empty normalized header cells.
        $sig = [];
        foreach (array_values($mergedHeaderCells) as $col => $value) {
            $text = strtolower(trim((string) ($value ?? '')));
            if ($text !== '') {
                $sig[] = [$col, $text];
            }
        }
        if (count($sig) < 2) return $fail('header-row-too-empty');
        $descText = strtolower(trim((string) ($mergedHeaderCells[$descCol] ?? '')));
        if ($descText === '') return $fail('description-cell-empty');

        // Row roles are OPTIONAL and additive. Marking the row cumulative implies
        // excluding it — the two are one decision for the reviewer, and the skip
        // vocabulary already lumps cumulat\w* in with cost/cash flow/duration, so
        // a cumulative row that still summed would contradict the rule it lives
        // beside.
        $excludeSigs = array_values(array_unique(array_filter(
            array_map(fn ($s) => trim((string) $s), $excludeSigs),
            fn ($s) => $s !== ''
        )));
        $cumulativeSig = ($cumulativeSig !== null && trim($cumulativeSig) !== '') ? trim($cumulativeSig) : null;
        if ($cumulativeSig !== null && !in_array($cumulativeSig, $excludeSigs, true)) {
            $excludeSigs[] = $cumulativeSig;
        }

        $map = [
            'v' => self::MAP_VERSION,
            'rowHint' => null,
            'codeCol' => $codeCol,
            'descCol' => $descCol,
            'weightCol' => $weightCol,
            'contractCol' => $contractCol,
            'monthSpans' => $spans,
            'sig' => $sig,
        ];
        // Emitted only when taught, so every map built before row roles existed —
        // and every map taught without using them — stays byte-identical. An older
        // map round-trips unchanged because findHeaderViaMaps reads only the keys
        // it knows.
        if (count($excludeSigs) > 0) {
            $map['excludeSigs'] = $excludeSigs;
        }
        if ($cumulativeSig !== null) {
            $map['cumulativeSig'] = $cumulativeSig;
        }

        return $map;
    }

    /**
     * E1-B — derive a layout map from an AI-reported layout (learn-from-AI).
     *
     * $layout comes sanitized from the AI service (headerRow R-number as shown,
     * monthColumns {m: [letters]}, desc/contract/weight/code letters, plus
     * activityBased + mirrored flags which are LOGGED for transparency but never
     * consulted). REVERSAL (2026-09-10, user order): column trust ≠ month trust —
     * maps store POSITIONS and buildMonths recomputes from rows, so correct columns
     * yield correct curves whatever the months claimed. Mirror/adopted status
     * describes months (handled: mirror note, prefer-math swap, fallback flag),
     * never columns. R-numbers index the default-40 slice exactly like
     * serializeForAi (kept-ordinals, NOT sheet rows), so the walk below mirrors
     * that walk cell-for-cell. Letters become 0-based indices; the header row's
     * own cells feed buildTaughtMap (same gates as hand teaching);
     * mapSpansHoldNumbers proves the spans against REAL bytes. Single-header B v1
     * only — two-row headers honestly yield null. Best-effort: any failure → null
     * with $reason naming the gate (never silent).
     *
     * Called by: SCurveImportController LearnMap step.
     */
    public function deriveMapFromAiLayout(array $layout, string $path, ?string &$reason = null): ?array
    {
        $refuse = function (string $why) use (&$reason): ?array {
            $reason = $why;

            return null;
        };

        try {
            // headerRow may be ABSENT (GAP 2, 2026-09-12): when the rule detector
            // cannot find a header, serializeForAi tags every row [DATA], so the
            // slice shows the model no HEADER line and it rightly omits the key —
            // and those are exactly the files that most need a learned layout.
            // 0 now means "infer it from the sheet below" instead of refusing.
            $headerR = (int) ($layout['headerRow'] ?? 0);
            $monthColumns = $layout['monthColumns'] ?? null;
            if (!is_array($monthColumns) || count($monthColumns) < 2) return $refuse('no-month-columns');
            $descLetter = $layout['descCol'] ?? null;
            if (!is_string($descLetter) || $descLetter === '') return $refuse('no-desc-col');
            if (!is_file($path)) return $refuse('unreadable-file');

            $spreadsheet = IOFactory::load($path);
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $grid = $sheet->toArray(null, true, false, true);
                $grid = $this->resolveFormulas($sheet, $grid);
                // ROLES MUST BE PASSED HERE TOO. This resolves an AI-reported R#
                // back to a sheet row, and the slice the AI was shown came from
                // serializeForAi — which keeps summary rows past the budget. Omit
                // the roles and the two walks disagree about which rows exist, so
                // every R# past the cap resolves to the wrong row without a word.
                $keptKeys = $this->keptRowKeys($grid, 40, $this->labelSliceRows($grid, $sheet));
                if (count($keptKeys) < 2) continue;

                $toIndex = function ($letter): ?int {
                    if (!is_string($letter) || !preg_match('/^[A-Z]{1,3}$/', $letter)) {
                        return null;
                    }
                    try {
                        return Coordinate::columnIndexFromString($letter) - 1;
                    } catch (\Throwable $e) {
                        return null;
                    }
                };

                $descCol = $toIndex($descLetter);
                if ($descCol === null) {
                    $reason = 'bad-desc-letter';

                    continue;
                }
                $codeCol = isset($layout['codeCol']) ? $toIndex($layout['codeCol']) : null;
                $weightCol = isset($layout['weightCol']) ? $toIndex($layout['weightCol']) : null;
                $contractCol = isset($layout['contractCol']) ? $toIndex($layout['contractCol']) : null;
                // A null taught code/weight/contract means "absent" (sanitizer drops
                // junk to null); garbage MONTH letters fail the whole derivation.
                $spans = [];
                $bad = false;
                foreach ($monthColumns as $m => $letters) {
                    $mi = (int) $m;
                    $cols = [];
                    foreach ((array) $letters as $letter) {
                        $idx = $toIndex($letter);
                        if ($idx === null) {
                            $bad = true;
                            break;
                        }
                        $cols[] = $idx;
                    }
                    if ($bad) break;
                    $spans[$mi] = $cols;
                }
                if ($bad) {
                    $reason = 'bad-month-letters';

                    continue;
                }

                // Header row: the model's R-number when it gave one, otherwise
                // inferred from the sheet's own cells (GAP 2). Inference trusts
                // bytes, not the model: the row must read like LABELS at the very
                // columns the model named — text (not numbers) in the description
                // column and in at least two month columns.
                if ($headerR >= 1) {
                    if (!isset($keptKeys[$headerR - 1])) {
                        $reason = 'header-row-unresolved';

                        continue;
                    }
                    $sheetKey = $keptKeys[$headerR - 1];
                } else {
                    $sheetKey = $this->inferHeaderRowKey($grid, $keptKeys, $descCol, $spans);
                    if ($sheetKey === null) {
                        $reason = 'header-row-unfindable';

                        continue;
                    }
                }

                $headerCells = array_values($grid[$sheetKey] ?? []);
                $map = $this->buildTaughtMap($headerCells, $descCol, $codeCol, $weightCol, $contractCol, $spans);
                if ($map === null) {
                    $reason = 'invalid-map';

                    continue;
                }
                if (!$this->mapSpansHoldNumbers($grid, $sheetKey, $map['monthSpans'])) {
                    $reason = 'unproven-spans';

                    continue;
                }

                return $map;
            }
        } catch (\Throwable $e) {
            return $refuse('error');
        }

        return $refuse($reason ?? 'no-matching-sheet');
    }

    /**
     * GAP 1 / derived-math — read the sheet with the layout the AI reported.
     *
     * Asking the model for names did not work: it reliably reports layout keys
     * (descCol/monthColumns — the logs show them on every reply) but keeps omitting
     * per-month "activities", even with the worked example fixed. It does not need
     * to: those keys plus the file are enough for the SERVER to read the sheet
     * itself, from real cells, which is deterministic and impossible to
     * hallucinate. Reuses deriveMapFromAiLayout (already validated against real
     * bytes) and the ordinary map-parse path, so the result is identical to what a
     * learned-layout import produces — names AND curve.
     *
     * The curve matters as much as the names: on the 10-month file the model
     * returned 40/60/70 for months 5-7 where the sheet's own rows say 50/70/80,
     * and its "Checked" verdict still passed because the amounts it invented
     * coincided with budget-cost figures elsewhere in the sheet. The caller
     * compares the two and prefers these bytes (user decision 2026-09-12).
     *
     * Best-effort by construction: any failure returns null and the caller keeps
     * the AI's answer exactly as it arrived.
     *
     * Called by: SCurveImportController::applyDerivedLayout() after an AI read.
     *
     * @return array|null  the ordinary map-parse result (months carry names AND
     *                     the recomputed curve), or null when nothing derivable.
     */
    public function parseWithAiLayout(array $layout, string $path): ?array
    {
        try {
            $reason = null;
            $map = $this->deriveMapFromAiLayout($layout, $path, $reason);
            if ($map === null) {
                return null;
            }

            // A synthetic live-map entry: same shape loadLiveMaps() hands parse().
            $parsed = $this->parse($path, [[
                'id' => 0,
                'contractor_id' => null,
                'column_map' => $map,
            ]]);
            if (($parsed['meta']['mapUsed'] ?? false) !== true) {
                return null;
            }

            return $parsed;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * E1-B / GAP 2 — find the header row from the sheet when the AI named none.
     *
     * The model omits "headerRow" whenever the slice showed it no HEADER-tagged
     * line, which happens precisely when our own detector could not recognise the
     * table — so the files that most need a learned layout were the ones that
     * could never get one. Rather than trust a guess, this reads the REAL cells at
     * the columns the model DID name and keeps the first row that looks like
     * labels there: text (never a number) in the description column and in at
     * least two of the month columns. A data row fails on sight, because its month
     * cells hold amounts. Returns the grid key, or null when nothing qualifies.
     *
     * Called by: deriveMapFromAiLayout() when headerRow is absent.
     * @return int|string|null  the grid row key, in the same form keptRowKeys uses.
     */
    private function inferHeaderRowKey(array $grid, array $keptKeys, int $descCol, array $spans): mixed
    {
        $isLabel = function ($v): bool {
            if (!is_string($v) && !is_numeric($v)) {
                return false;
            }
            $s = trim((string) $v);
            if ($s === '' || is_numeric(str_replace([',', ' '], '', $s))) {
                return false;
            }

            return true;
        };

        foreach ($keptKeys as $key) {
            $cells = array_values($grid[$key] ?? []);
            if (!isset($cells[$descCol]) || !$isLabel($cells[$descCol])) {
                continue;
            }
            $labelled = 0;
            foreach ($spans as $cols) {
                foreach ((array) $cols as $idx) {
                    if (isset($cells[$idx]) && $isLabel($cells[$idx])) {
                        $labelled++;
                        break;
                    }
                }
            }
            if ($labelled >= 2) {
                return $key;
            }
        }

        return null;
    }

    /**
     * E1-B — kept-ordinal walk mirroring serializeForAi (R-numbers, NOT sheet rows).
     *
     * Returns sheet keys of kept rows in order (trailing-trimmed, empties skipped,
     * capped) so an AI-reported headerRow R# resolves to the same row the slice
     * showed. Must stay identical to the serialize walk by construction.
     */
    private function keptRowKeys(array $grid, int $maxRows, ?array $roles = null): array
    {
        $keys = [];
        $seen = 0;
        foreach ($grid as $rowKey => $row) {
            $cells = array_slice(array_values($row ?? []), 0, 80);
            while (count($cells) > 0 && trim((string) end($cells)) === '') {
                array_pop($cells);
            }
            if (count($cells) === 0) {
                continue;
            }
            // SUMMARY-AWARE BUDGET (2026-09-14). The budget buys the HEAD of the
            // table; a summary row is then kept wherever it fell, past the cap.
            //
            // This was claimed in the teach-grid plan's §4 as applying to "both
            // sides" and only ever landed on the two PDF builders — the evidence
            // was in plain sight and unread for a day: BUHI and the roof workbook
            // both captured as "15 rows, no TOTAL row", which is not a coincidence
            // of size, it IS this bug. Their summary blocks sit past row 15, so the
            // reviewer never saw the cumulative row a baseline is adopted from, and
            // the AI slice never saw the totals its prompt tells it not to copy.
            //
            // Mirrors the PDF rule exactly (scurve-pdf-parser-core.js :1888/:2214):
            // index over NON-EMPTY rows, keep when under the cap OR summary-ish.
            // Ordering is preserved and summary rows sit at the bottom, so R-numbers
            // of earlier rows do not shift — which matters because keptRowKeys is
            // how an AI-reported R# is resolved back to a sheet row.
            $role = ($roles && isset($roles[$rowKey])) ? $roles[$rowKey] : null;
            $keep = ($seen < $maxRows) || $role === 'SUMMARY' || $role === 'CUMULATIVE';
            $seen++;
            if ($keep) {
                $keys[] = $rowKey;
            }
        }

        return $keys;
    }

    /**
     * E1-3 — consult live learned maps after rule detection missed.
     *
     * Tries each map in controller order (contractor's first, then global):
     * version-gated to MAP_VERSION, signature row located with typo tolerance,
     * desc-cell anchored, and month spans proven to hold numbers below. First
     * hit wins; anything else falls through to the next map (or the honest
     * "No S-curve table found" error when none hit).
     *
     * Called by: parse() pass 2 only.
     */
    private function findHeaderViaMaps(array $rows, array $liveMaps): ?array
    {
        foreach ($liveMaps as $entry) {
            if (!is_array($entry)) continue;
            $cm = $entry['column_map'] ?? null;
            if (!is_array($cm)) continue;
            // Version flag: stale-semantics maps never apply (see MAP_VERSION).
            if (($cm['v'] ?? null) !== self::MAP_VERSION) continue;
            $sig = $cm['sig'] ?? null;
            if (!is_array($sig) || count($sig) < 2) continue;
            $descCol = $cm['descCol'] ?? null;
            if (!is_int($descCol)) continue;
            $spans = $cm['monthSpans'] ?? null;
            if (!is_array($spans) || count($spans) < 2) continue;

            // Normalize JSON-decoded spans (string keys → int months/cols).
            $monthSpans = [];
            foreach ($spans as $m => $cols) {
                $monthSpans[(int) $m] = array_map('intval', (array) $cols);
            }

            $rowIdx = $this->findSignatureRow($rows, $sig, $descCol);
            if ($rowIdx === null) continue;
            if (!$this->mapSpansHoldNumbers($rows, $rowIdx, $monthSpans)) continue;

            return [
                'row' => $rowIdx,
                'codeCol' => isset($cm['codeCol']) ? (int) $cm['codeCol'] : null,
                'descCol' => $descCol,
                'weightCol' => isset($cm['weightCol']) ? (int) $cm['weightCol'] : null,
                'contractCol' => isset($cm['contractCol']) ? (int) $cm['contractCol'] : null,
                'monthSpans' => $monthSpans,
                '_mapId' => $entry['id'] ?? null,
            ];
        }

        return null;
    }

    /**
     * E1-3 — locate a stored header signature in a new workbook.
     *
     * Scores every single row and every adjacent merged pair (same shapes the
     * detector tries) by matched signature cells. A row wins only with the
     * description cell matched (exact/fuzzy) plus ≥2 matched cells at ≥0.5
     * coverage — data rows cannot reach this (their desc cells hold activity
     * names, never header labels).
     *
     * Called by: findHeaderViaMaps().
     */
    private function findSignatureRow(array $rows, array $sig, int $descCol): ?int
    {
        $keys = array_keys($rows);
        $list = array_values($rows);

        $best = null;
        $bestScore = 0.0;
        $total = count($sig);

        $consider = function (array $cells, int $rowKey) use ($sig, $descCol, $total, &$best, &$bestScore) {
            $matched = 0;
            $descOk = false;
            foreach ($sig as $pair) {
                [$col, $text] = $pair;
                $cell = strtolower(trim((string) ($cells[$col] ?? '')));
                if ($cell === '' || $text === '') continue;
                if ($this->sigCellMatches($cell, (string) $text)) {
                    $matched++;
                    if ($col === $descCol) $descOk = true;
                }
            }
            $score = $total > 0 ? $matched / $total : 0;
            if ($descOk && $matched >= 2 && $score >= 0.5 && $score > $bestScore) {
                $bestScore = $score;
                $best = $rowKey;
            }
        };

        foreach ($list as $i => $row) {
            $consider(array_values($row), $keys[$i]);
            if (isset($list[$i + 1])) {
                $consider(array_values($this->mergeHeaderRows($row, $list[$i + 1])), $keys[$i + 1]);
            }
        }

        return $best;
    }

    /**
     * E1-3 — typo-tolerant single-cell match for signature search.
     *
     * Exact, \b-bounded substring (shorter side ≥4 chars — bounded so "Month 1"
     * never matches "Month 12"), or Levenshtein ≤2 on long words (the R2a
     * headerFuzzyMatch rule, both directions). Mirrors the user-approved
     * typo-tolerant choice without touching rule detection.
     *
     * Called by: findSignatureRow().
     */
    private function sigCellMatches(string $cell, string $text): bool
    {
        if ($cell === $text) return true;

        $short = mb_strlen($cell) <= mb_strlen($text) ? $cell : $text;
        $long = $short === $cell ? $text : $cell;
        if (mb_strlen($short) >= 4
            && preg_match('/\b' . preg_quote($short, '/') . '\b/u', $long)) {
            return true;
        }

        return $this->headerFuzzyMatch($cell, [$text])
            || $this->headerFuzzyMatch($text, [$cell]);
    }

    /**
     * E1-3 — false-positive guard: mapped month spans must hold real numbers.
     *
     * Scans up to 60 rows below the signature row for any numeric cell under
     * the mapped spans. A map applied to the wrong sheet shape finds only
     * text/empties and is skipped instead of building a corrupt curve.
     *
     * Called by: findHeaderViaMaps().
     */
    private function mapSpansHoldNumbers(array $rows, int $rowIdx, array $monthSpans): bool
    {
        $spanCols = [];
        foreach ($monthSpans as $cols) {
            foreach ((array) $cols as $col) {
                $spanCols[(int) $col] = true;
            }
        }
        if (count($spanCols) === 0) return false;

        $scanned = 0;
        foreach ($rows as $idx => $row) {
            if ($idx <= $rowIdx) continue;
            if (++$scanned > 60) break;
            $cells = array_values($row);
            foreach (array_keys($spanCols) as $col) {
                if (is_numeric($cells[$col] ?? null)) return true;
            }
        }

        return false;
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
     *
     * E1-3: $relaxed additionally accepts contractor shorthand "P1"–"P12"
     * ("Period/Phase N" spellings free) — third-family files label months
     * that way. Relaxed-only so the main path never learns new synonyms
     * without evidence; hits become scoped auto-maps.
     */
    private function matchMonthLabel(string $label, bool $relaxed = false): ?int
    {
        if (preg_match('/^(?:month|m)\s*(\d{1,2})$/i', $label, $m)) {
            $n = (int) $m[1];
            return ($n >= 1 && $n <= 12) ? $n : null;
        }

        // E1-3 relaxed: "P1"–"P12" / "Period N" / "Phase N" (third family).
        if ($relaxed && preg_match('/^(?:p|period|phase)\s*(\d{1,2})$/i', $label, $m)) {
            $n = (int) $m[1];
            return ($n >= 1 && $n <= 12) ? $n : null;
        }

        // P1-follow-up relaxed: "STAGE 1"–"STAGE 6" / "Quarter|QTR|Q N" (fourth
        // family). Relaxed-only like P-months — hits become scoped auto-maps,
        // never strict-path behavior.
        if ($relaxed && preg_match('/^(?:stage|stg|quarter|qtr|q)\s*(\d{1,2})$/i', $label, $m)) {
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

        // R2b (redesigned 2026-09-08: reference + warn — the plan curve above NEVER moves
        // while activity math exists; the summary-fallback branch below is the sole exception).
        // Read the author's cumulative summary row and compare each value against OUR
        // cumulative at the attributed month. A summary value sitting in month m's span
        // covers the interval up to the month before the next summary value (BUHI: I in
        // span 1 → month 3, O in span 4 → month 6 …). Fractions (< 1.5) scale to percent.
        // Gaps past 1.0 warn with the cause: the block tracks ACCOMPLISHED % (often on
        // another divisor, e.g. BUHI's pre-discount bid), so it informs review but never
        // replaces planned values — UNLESS activity math is absent (see fallback below).
        $summaryReference = $this->readSummaryReference($rows, $header, $dataStart, $months);

        // Summary fallback: activity rows exist but carry NO usable month values, while the
        // author block does (client practice bases monthly cumulative on it). Adopt the
        // summary as the curve — flagged, never silent. Disagreement never triggers this:
        // it fires only when activity math is absent ($lastNonZero === 0) with at least 2
        // reference points (a single point is not a curve). Sanitized like any plan curve
        // (monotonic clamp + force-last-100) because the controller rejects anything else.
        $curveSource = 'activities';
        if ($lastNonZero === 0 && count($summaryReference) >= 2) {
            $curveSource = 'summary';
            $adopted = [];
            foreach ($months as $m => $mo) {
                $adopted[$m] = 0.0;
            }
            foreach ($summaryReference as $ref) {
                if (isset($adopted[$ref['month']])) {
                    $adopted[$ref['month']] = (float) $ref['summary_percent'];
                }
            }
            // Jumble flag: adopted raw values must already read cumulative — a scrambled
            // summary is the least trustworthy input in the system.
            $prev = -1.0;
            $jumbled = false;
            foreach ($adopted as $v) {
                if ($v < $prev) {
                    $jumbled = true;
                    break;
                }
                $prev = $v;
            }
            $running = 0.0;
            foreach ($months as $m => $mo) {
                $v = $adopted[$m] ?? 0.0;
                if ($v < $running) {
                    $v = $running;
                }
                $v = min($v, 100.0);
                $running = $v;
                $months[$m]['planned_progress_percent'] = round($v, 2);
            }
            $months[$maxMonth]['planned_progress_percent'] = 100.0;
            // No activity math exists, so reference gaps are meaningless — null them instead
            // of comparing the curve against itself.
            foreach ($summaryReference as $i => $ref) {
                $summaryReference[$i]['activity_percent'] = null;
                $summaryReference[$i]['gap'] = null;
            }
            $warnings[] = 'Heads-up: no usable activity values were found, so the baseline below'
                . ' is built from the file\u2019s own summary column (source: summary).'
                . ' Please verify carefully before locking.';
            if ($jumbled) {
                $warnings[] = 'Heads-up: the summary values were not in cumulative order — review carefully.';
            }
        } else {
            // Gap warnings need activity math to compare against — with none (lastNonZero
            // === 0) any comparison is against a degenerate curve, so stay silent here; the
            // fallback branch above already warned (or the file truly has nothing to say).
            // Plain-language wording (user-approved): numbers kept for verification, meaning
            // first — shown inside the setup modal (moved from the beside-button box).
            if ($lastNonZero > 0) {
                foreach ($summaryReference as $ref) {
                    if ($ref['gap'] > 1.0) {
                        $warnings[] = "Heads-up (Month {$ref['month']}): the file's own summary"
                            . " says {$ref['summary_percent']}%, but its activity rows add up to"
                            . " {$ref['activity_percent']}%. The summary was likely computed against"
                            . " a different total. Your baseline below uses the activity math —"
                            . " no action needed.";
                    }
                }
            }
        }

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
            // R2b: author-block reference points ({month, summary_percent,
            // activity_percent, gap}); empty when the file carries no readable block.
            // activity_percent/gap are null when the curve itself came from the summary.
            'summaryReference' => $summaryReference,
            // R2b fallback provenance: 'activities' normally, 'summary' when activity math
            // was absent and the author block was adopted (explicit verify warning raised).
            'curveSource' => $curveSource,
        ];

        return [
            'months' => array_values($months),
            'meta' => $meta,
        ];
    }

    /**
     * R2b: read the author's cumulative summary row as REFERENCE points.
     *
     * Prefers a /cumulat|commulat/ row (the true cumulative series), else the detected
     * cumulativeRow. Each numeric value found in a month span is attributed to the last
     * month before the next summary value (or the final month). Fractions scale to
     * percent. Returns [{month, summary_percent, activity_percent, gap}]; empty when the
     * file carries no readable block. Never moves the plan curve — callers only warn.
     *
     * Called by: buildMonths() after months are final (force-final included).
     */
    private function readSummaryReference(array $rows, array $header, int $dataStart, array $months): array
    {
        $monthSpans = $header['monthSpans'] ?? [];
        if (count($monthSpans) === 0) {
            return [];
        }

        $summaryIdx = null;
        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex < $dataStart || !is_array($row)) {
                continue;
            }
            $text = implode(' ', $this->rowTexts($row));
            if ($text !== '' && preg_match('/cumulat\w*|commulat\w*/i', $text)) {
                $summaryIdx = $rowIndex;
                break;
            }
        }

        if ($summaryIdx === null) {
            return [];
        }

        $row = $rows[$summaryIdx];
        $found = [];
        foreach ($monthSpans as $m => $cols) {
            foreach ((array) $cols as $col) {
                $raw = $row[$this->letter($col)] ?? null;
                if (is_numeric($raw)) {
                    $found[$m] = (float) $raw;
                    break;
                }
            }
        }

        // R2b-GRID: values in a parallel display grid (roof G:AG) rather than under month
        // spans — pair positionally ONLY on exact count match (7 values → 7 months).
        // Mismatched counts stay honestly empty; no curve moves anywhere either way.
        if (count($found) === 0 && is_array($row)) {
            $ordered = [];
            foreach (array_values($row) as $raw) {
                if (is_numeric($raw)) {
                    $ordered[] = (float) $raw;
                }
            }
            $spanKeys = array_keys($monthSpans);
            sort($spanKeys);
            if (count($ordered) === count($spanKeys) && count($ordered) > 0) {
                foreach ($spanKeys as $i => $m) {
                    $found[$m] = $ordered[$i];
                }
            }
        }

        if (count($found) === 0) {
            return [];
        }

        ksort($found);
        $sMonths = array_keys($found);
        $maxMonth = max(array_keys($monthSpans));
        $out = [];
        foreach ($sMonths as $i => $sm) {
            $attr = ($i + 1 < count($sMonths)) ? $sMonths[$i + 1] - 1 : $maxMonth;
            if (!isset($months[$attr])) {
                continue;
            }
            $v = $found[$sm];
            if ($v < 0) {
                continue;
            }
            $pct = ($v > 0 && $v < 1.5) ? round($v * 100, 2) : round($v, 2);
            $act = (float) $months[$attr]['planned_progress_percent'];
            $out[] = [
                'month' => $attr,
                'summary_percent' => $pct,
                'activity_percent' => $act,
                'gap' => round(abs($pct - $act), 2),
            ];
        }

        return $out;
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
