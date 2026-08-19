<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
            $header = $this->findHeader($rows);
            if ($header) {
                $sheetData = ['rows' => $rows, 'header' => $header];
                break;
            }
        }

        if (!$sheetData) {
            throw new RuntimeException(
                'No S-curve table found. Expected a sheet with an "ITEM", "% WEIGHT" ' .
                'and "Month 1..12" header (CONSTRUCTION SCHEDULE AND S-CURVE layout).'
            );
        }

        return $this->buildMonths($sheetData['rows'], $sheetData['header']);
    }

    // ─── HEADER DETECTION ─────────────────────────────────────────────────────

    /**
     * Locate the header row and its key columns.
     *
     * @return array{row: int, codeCol: ?int, descCol: int, weightCol: ?int,
     *               contractCol: ?int, monthCols: array<int,int>}|null
     */
    private function findHeader(array $rows): ?array
    {
        foreach ($rows as $rowIndex => $row) {
            $cells = array_values($row); // keep numeric order
            $labels = array_map(
                fn ($v) => strtolower(trim((string) ($v ?? ''))),
                $cells
            );

            $hasItem = false;
            $hasWeight = false;
            $monthCols = [];
            $descCol = null;
            $codeCol = null;
            $contractCol = null;

            foreach ($cells as $colIndex => $value) {
                $label = strtolower(trim((string) ($value ?? '')));

                if ($label === 'item' || $label === 'item no.') {
                    $hasItem = true;
                    $codeCol = $colIndex + 1; // item codes sit one column right
                }
                if ($label === 'description' || $label === 'particulars') {
                    $descCol = $colIndex;
                }
                if (preg_match('/%?\s*weight/i', $label)) {
                    $hasWeight = true;
                    $weightCol = $colIndex;
                }
                if (preg_match('/contract/i', $label)) {
                    $contractCol = $colIndex;
                }
                if (preg_match('/^month\s*(\d+)$/i', $label, $m)) {
                    $monthCols[(int) $m[1]] = $colIndex;
                }
            }

            if (!$hasItem || !$hasWeight || $descCol === null || count($monthCols) < 2) {
                continue;
            }

            // Every-other-column months → each month spans [col, col+1].
            $sorted = $monthCols;
            ksort($sorted);
            $paired = true;
            $prev = null;
            foreach ($sorted as $col) {
                if ($prev !== null && $col - $prev !== 2) {
                    $paired = false;
                    break;
                }
                $prev = $col;
            }
            $monthSpans = [];
            foreach ($sorted as $monthIndex => $col) {
                $monthSpans[$monthIndex] = $paired ? [$col, $col + 1] : [$col];
            }

            return [
                'row' => $rowIndex,
                'codeCol' => $codeCol,
                'descCol' => $descCol,
                'weightCol' => $weightCol ?? null,
                'contractCol' => $contractCol,
                'monthSpans' => $monthSpans,
            ];
        }

        return null;
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

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex < $dataStart) continue;

            $description = trim((string) ($row[$this->letter($descCol)] ?? ''));
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
            if (preg_match('/%?\s*(accomplished|complete)|cumulative|cost\b/i', $description)
                && $weight === null && $contractAmt === null) {
                continue;
            }

            // TOTAL row — keep its contract amount for validation.
            if (preg_match('/^total$/i', $description)) {
                $totalRowContract = $contractAmt;
                continue;
            }

            // Section-header rows (PART A-H / DIVISION I-II), the sub-header row
            // under the two-row header (day markers / "(IN PESOS)"), and other
            // non-activity rows carry no weight and no description → skip.
            $isActivity = $weight !== null
                || ($hasMonthValue && $description !== '')
                || ($contractAmt !== null && $description !== '');

            if (!$isActivity) {
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
