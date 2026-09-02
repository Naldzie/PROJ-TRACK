<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ============================================================================
 * ReportExportService
 * ============================================================================
 *
 * Purpose
 * -------
 * Generate on-demand Excel / PDF exports of project-monitoring data for the
 * PMU. Covers gap #10 (report generation) — progress history, baseline
 * schedule, extensions, issues, and a stakeholder PDF project summary.
 *
 * Called by
 * ---------
 * ReportExportController (all three roles; authorization enforced inside).
 *
 * Calls
 * -----
 * ProjectDetailService::prepareProjectData() / prepareChartData() — reused as
 * the single source of truth for PDF summary + S-Curve SVG.
 *
 * Notes
 * -----
 * - No storage writes: every file is streamed to the client (php://output).
 * - Each export records an ActivityLog entry (audit trail).
 * - Excel via PhpOffice\PhpSpreadsheet; PDF via Dompdf\Dompdf.
 */
class ReportExportService
{
    // === EXCEL: PROGRESS HISTORY ===

    public function progressExcel(Project $project): StreamedResponse
    {
        $this->authorize($project);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Progress History');

        $this->writeHeaderRow($sheet, [
            'Month', 'Inspection Date', 'Planned %', 'Actual %', 'Variance %',
            'Remarks', 'Recorded By', 'Evidence Files',
        ]);

        $row = 2;
        foreach ($project->progressHistory as $record) {
            $planned = $record->planned_progress_percent !== null ? (float) $record->planned_progress_percent : null;
            $actual  = $record->actual_progress_percent !== null
                ? (float) $record->actual_progress_percent
                : ($record->progress_percentage !== null ? (float) $record->progress_percentage : null);

            $variance = $record->variance_percent !== null
                ? (float) $record->variance_percent
                : ($planned !== null && $actual !== null ? round($actual - $planned, 2) : null);

            $sheet->fromArray([
                $record->month_label,
                $record->inspection_date?->format('Y-m-d'),
                $planned,
                $actual,
                $variance,
                $record->remarks,
                $record->recorded_by_name,
                $record->attachments_count ?? $record->attachments()->count(),
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $this->logExport($project, 'Excel Progress History');

        return $this->streamSpreadsheet($spreadsheet, $this->fileName($project, 'progress.xlsx'));
    }

    // === EXCEL: BASELINE SCHEDULE ===

    public function baselineExcel(Project $project): StreamedResponse
    {
        $this->authorize($project);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Baseline Schedule');

        $this->writeHeaderRow($sheet, [
            'Month Label', 'Planned Date', 'Planned Progress %', 'Description',
        ]);

        $row = 2;
        foreach ($project->baselineSchedules as $schedule) {
            $sheet->fromArray([
                $schedule->month_label,
                $schedule->planned_date?->format('Y-m-d'),
                $schedule->planned_progress_percent !== null
                    ? (float) $schedule->planned_progress_percent
                    : null,
                $schedule->description,
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $this->logExport($project, 'Excel Baseline Schedule');

        return $this->streamSpreadsheet($spreadsheet, $this->fileName($project, 'baseline.xlsx'));
    }

    // === EXCEL: EXTENSIONS ===

    public function extensionsExcel(Project $project): StreamedResponse
    {
        $this->authorize($project);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Extensions');

        $this->writeHeaderRow($sheet, [
            'Extension #', 'Original End Date', 'New End Date', 'Duration (days)',
            'Status', 'Reason', 'Rejection Reason', 'Reviewed At', 'Reviewed By',
        ]);

        $row = 2;
        foreach ($project->extensions as $extension) {
            $sheet->fromArray([
                $extension->extension_number,
                $extension->original_end_date?->format('Y-m-d'),
                $extension->new_end_date?->format('Y-m-d'),
                $extension->duration_days,
                ucfirst($extension->status ?? ''),
                $extension->reason,
                $extension->rejection_reason,
                // B1 — show reviewer timestamp/name for both approved & rejected; fallback to approved_* for old rows
                ($extension->reviewed_at ?? $extension->approved_at)?->format('Y-m-d H:i'),
                ($extension->reviewer?->name ?? $extension->approver?->name),
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $this->logExport($project, 'Excel Extensions');

        return $this->streamSpreadsheet($spreadsheet, $this->fileName($project, 'extensions.xlsx'));
    }

    // === EXCEL: ISSUES ===

    public function issuesExcel(Project $project): StreamedResponse
    {
        $this->authorize($project);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Issues');

        $this->writeHeaderRow($sheet, [
            'Name', 'Type', 'Severity', 'Status', 'Linked Month',
            'Created', 'Resolved', 'Resolution Notes',
        ]);

        $row = 2;
        foreach ($project->issues as $issue) {
            $sheet->fromArray([
                $issue->issue_name,
                $issue->issue_type,
                $issue->severity,
                $issue->status,
                $issue->baselineSchedule?->month_label,
                $issue->created_at?->format('Y-m-d'),
                $issue->resolved_at?->format('Y-m-d'),
                $issue->resolution_notes,
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $this->logExport($project, 'Excel Issues');

        return $this->streamSpreadsheet($spreadsheet, $this->fileName($project, 'issues.xlsx'));
    }

    // === PDF: PROJECT SUMMARY ===

    public function summaryPdf(Project $project): StreamedResponse
    {
        $this->authorize($project);

        $dompdf = $this->buildSummaryDompdf($project);

        $this->logExport($project, 'PDF Summary Report');

        $filename = $this->fileName($project, 'project-summary.pdf');

        return Response::streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Preview the project-summary PDF inline (no audit-log entry; the viewer
     * modal iframe loads this, and only an actual download via summaryPdf()
     * records the audit trail).
     */
    public function summaryPdfView(Project $project): StreamedResponse
    {
        $this->authorize($project);

        $dompdf = $this->buildSummaryDompdf($project);

        $filename = $this->fileName($project, 'project-summary.pdf');

        return Response::streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ], 'inline');
    }

    // === PRIVATE HELPERS ===

    /**
     * Assemble the shared dompdf instance for the project-summary PDF.
     */
    private function buildSummaryDompdf(Project $project): Dompdf
    {
        // Reuse the project-detail assembly as the single source of truth.
        $detailService = app(ProjectDetailService::class);
        $data = $detailService->prepareProjectData($project);
        $chartData = $detailService->prepareChartData($project);

        $svgChart = $this->buildSvgChart($chartData);

        // Load latest AI analysis and recommendations (Phase 11)
        $latestAi = $project->aiSolutions()->first();

        $html = view('reports.project-summary', [
            'project'   => $project,
            'data'      => $data,
            'chartData' => $chartData,
            'svgChart'  => $svgChart,
            'generatedAt' => now()->format('F j, Y g:i A'),
            'latestAi'  => $latestAi,
        ])->render();

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf;
    }

    /**
     * Stream an Xlsx workbook to the browser as a download.
     */
    private function streamSpreadsheet(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return Response::streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Write a bold, styled header row to a worksheet.
     */
    private function writeHeaderRow($sheet, array $headers): void
    {
        $sheet->fromArray($headers, null, 'A1');

        $style = $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1');
        $style->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2563EB');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * Build a sanitized download filename: {project_code}_{suffix}.
     */
    private function fileName(Project $project, string $suffix): string
    {
        return Str::slug($project->code ?? $project->id) . '_' . $suffix;
    }

    /**
     * Record an audit-trail entry for the export.
     */
    private function logExport(Project $project, string $kind): void
    {
        ActivityLog::create([
            'user_id'     => auth()->id(),
            'project_id'  => $project->id,
            'action'      => 'Export Report',
            'description' => (auth()->user()->name ?? 'System') . " exported {$kind} for project: {$project->title}",
            'ip_address'  => request()->ip(),
            'visibility'  => 'all',
        ]);
    }

    /**
     * Enforce the same authorization as ProjectController::show(): admin/staff
     * see any project; a contractor may only export their own assigned project.
     */
    private function authorize(Project $project): void
    {
        $user = Auth::user();

        if (in_array($user->role, ['admin', 'staff'])) {
            return;
        }

        if ($user->role === 'contractor') {
            $contractor = $user->contractor;
            if ($contractor && $project->contractor_id === $contractor->id) {
                return;
            }
        }

        abort(403, 'Unauthorized: You can only export your assigned projects.');
    }

    /**
     * Build an inline SVG S-Curve (planned vs actual vs adjusted planned) from
     * ProjectChartService chart data. Returns an empty container div when the
     * project has no baseline data (the view hides it).
     *
     * Called by: summaryPdf()
     */
    private function buildSvgChart(array $chartData): string
    {
        if (empty($chartData['ready']) || empty($chartData['labels'])) {
            return '<div class="no-chart">No baseline schedule data for chart.</div>';
        }

        $width = 820;
        $height = 340;
        $padLeft = 55;
        $padRight = 25;
        $padTop = 45;
        $padBottom = 45;

        $labels = $chartData['labels'];
        $n = count($labels);
        $plotW = $width - $padLeft - $padRight;
        $plotH = $height - $padTop - $padBottom;

        // Determine value range (0..100 unless data exceeds it).
        $maxY = 100;
        $maxY = max($maxY, (float) ($chartData['y_max'] ?? 0));

        // Linear millisecond-timestamp X-axis spanning the baseline ticks AND
        // every plotted point (frozen / adjusted / actual), mirroring the tab's
        // Chart.js linear scale. A raw timestamp scale (instead of a fixed
        // 0..n-1 index grid) lets actual records that fall after the last
        // baseline month render at their true calendar position instead of
        // clamping onto the final planned column.
        $ticks = $chartData['tick_timestamps'] ?? [];
        $allXs = $ticks;
        foreach (['frozen_planned_data', 'adjusted_planned_data', 'actual_data'] as $seriesKey) {
            foreach (($chartData[$seriesKey] ?? []) as $pt) {
                if (is_array($pt) && isset($pt['x'])) {
                    $allXs[] = (float) $pt['x'];
                }
            }
        }
        $xMin = !empty($allXs) ? min($allXs) : 0;
        $xMax = !empty($allXs) ? max($allXs) : 0;
        if ($xMax <= $xMin) {
            $xMax = $xMin + 1;
        }

        $xFor = function (float $ts) use ($padLeft, $plotW, $xMin, $xMax) {
            return $padLeft + (($ts - $xMin) / ($xMax - $xMin)) * $plotW;
        };
        $yFor = fn (float $v) => $padTop + (1 - min(max($v, 0), $maxY) / $maxY) * $plotH;

        // Convert {x: ms-timestamp, y: value} points to SVG coordinates. A null
        // y (e.g. extension months in the frozen plan) is a gap — skip it like
        // Chart.js spanGaps:false instead of drawing a 0% point that plunges the
        // line to the bottom of the chart.
        $points = function (array $values) use ($xFor, $yFor, $ticks) {
            $pts = [];
            foreach ($values as $i => $v) {
                if ($v === null) {
                    continue;
                }
                if (is_array($v)) {
                    if (!isset($v['y']) || $v['y'] === null) {
                        continue;
                    }
                    $y = (float) $v['y'];
                    $x = $xFor((float) ($v['x'] ?? ($ticks[$i] ?? 0)));
                } else {
                    $y = (float) $v;
                    $x = $xFor((float) ($ticks[$i] ?? 0));
                }
                $pts[] = round($x, 1) . ',' . round($yFor($y), 1);
            }
            return implode(' ', $pts);
        };

        $gridLines = '';
        for ($g = 0; $g <= 4; $g++) {
            $val = ($maxY / 4) * $g;
            $y = $yFor($val);
            $gridLines .= "<line x1='{$padLeft}' y1='{$y}' x2='" . ($width - $padRight) . "' y2='{$y}' stroke='#e2e8f0' stroke-width='1'/>";
            $gridLines .= "<text x='" . ($padLeft - 8) . "' y='" . ($y + 4) . "' font-size='11' fill='#64748b' text-anchor='end'>" . round($val) . "%</text>";
        }

        $monthLabel = function (string $label) {
            try {
                $parsed = Carbon::createFromFormat('Y-m', $label);
                return $parsed ? $parsed->format('F Y') : $label;
            } catch (\Throwable $e) {
                return $label;
            }
        };

        // X labels sit at the baseline tick timestamps; if an actual record
        // lands after the last baseline month, add its month as an extra label
        // so the extended tail has a readable axis label (matches the tab's
        // auto-generated ticks).
        $labelTicks = [];
        foreach ($labels as $i => $label) {
            $labelTicks[] = [$label, isset($ticks[$i]) ? (float) $ticks[$i] : null];
        }
        $lastTickTs = !empty($ticks) ? (float) end($ticks) : null;
        foreach (($chartData['actual_data'] ?? []) as $pt) {
            if (!is_array($pt) || !isset($pt['x'], $pt['month_label']) || $lastTickTs === null) {
                continue;
            }
            if ((float) $pt['x'] > $lastTickTs) {
                $labelTicks[] = [$pt['month_label'], (float) $pt['x']];
            }
        }
        // Also add any adjusted/frozen point that stretches past the last tick
        // (extension months), keeping labels unique and chronological.
        foreach (['adjusted_planned_data', 'frozen_planned_data'] as $seriesKey) {
            foreach (($chartData[$seriesKey] ?? []) as $pt) {
                if (!is_array($pt) || !isset($pt['x']) || $lastTickTs === null) {
                    continue;
                }
                if ((float) $pt['x'] <= $lastTickTs) {
                    continue;
                }
                $existingLabels = array_map(fn ($lt) => $lt[0], $labelTicks);
                $labelForTs = Carbon::createFromTimestampMs((int) $pt['x'])->format('Y-m');
                if (!in_array($labelForTs, $existingLabels, true)) {
                    $labelTicks[] = [$labelForTs, (float) $pt['x']];
                }
            }
        }
        usort($labelTicks, fn ($a, $b) => ($a[1] ?? 0) <=> ($b[1] ?? 0));

        $xLabels = '';
        $labelCount = count($labelTicks);
        $skipEvery = max(1, intdiv($labelCount, 6));
        foreach ($labelTicks as $i => [$label, $ts]) {
            if ($i % $skipEvery !== 0 && $i !== $labelCount - 1) {
                continue;
            }
            $xPos = $ts !== null ? $xFor($ts) : $padLeft + ($i / max($labelCount - 1, 1)) * $plotW;
            $xLabels .= "<text x='" . round($xPos, 1) . "' y='" . ($height - $padBottom + 18) . "' font-size='10' fill='#64748b' text-anchor='middle'>" . htmlspecialchars($monthLabel($label)) . "</text>";
        }

        // Legend mirrors the Progress tab exactly: Original Plan (blue), Actual
        // Progress (green), Adjusted Plan (amber/yellow, only when extensions).
        $hasExtension = (bool) ($chartData['has_extension'] ?? false);
        $hasActual = !empty($chartData['actual_data']);
        $legendItems = [$this->legendItem($padLeft, 18, '#3b82f6', false, 'Original Plan')];
        $legendWidth = 160;
        if ($hasActual) {
            $legendItems[] = $this->legendItem($padLeft + $legendWidth, 18, '#10b981', false, 'Actual Progress');
            $legendWidth += 160;
        }
        if ($hasExtension) {
            $legendItems[] = $this->legendItem($padLeft + $legendWidth, 18, '#f59e0b', false, 'Adjusted Plan');
            $legendWidth += 150;
        }
        $legend = implode('', [
            "<rect x='" . ($padLeft - 6) . "' y='8' width='" . ($legendWidth + 10) . "' height='20' rx='4' fill='#f8fafc' stroke='#e2e8f0'/>",
            implode('', $legendItems),
        ]);

        $polyline = function (array $values, string $stroke, float $strokeWidth) use ($points) {
            $pts = $points($values);
            if ($pts === '') {
                return '';
            }
            return "<polyline points='{$pts}' fill='none' stroke='{$stroke}' stroke-width='{$strokeWidth}'/>";
        };

        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='{$width}' height='{$height}' viewBox='0 0 {$width} {$height}'>";
        $svg .= $legend;
        $svg .= $gridLines;
        $svg .= $polyline($chartData['frozen_planned_data'] ?? [], '#3b82f6', 2);
        if ($hasExtension) {
            $svg .= $polyline($chartData['adjusted_planned_data'] ?? [], '#f59e0b', 2);
        }
        $svg .= $polyline($chartData['actual_data'] ?? [], '#10b981', 2);
        $svg .= $xLabels;
        $svg .= "</svg>";

        // dompdf renders SVG only as an <img> (php-svg-lib), not as inline
        // markup, so embed it as a base64 data URI sized to fit the A4 page.
        $fitWidth = 680;
        $fitHeight = (int) round($fitWidth * ($height / $width));

        return "<img src='data:image/svg+xml;base64," . base64_encode($svg)
            . "' style='width:{$fitWidth}px;height:{$fitHeight}px;' />";
    }

    /**
     * A single legend entry: a short color swatch line + label text.
     */
    private function legendItem(float $x, float $y, string $color, bool $dashed, string $label): string
    {
        $dash = $dashed ? " stroke-dasharray='5,3'" : '';
        $swatch = "<line x1='{$x}' y1='{$y}' x2='" . ($x + 16) . "' y2='{$y}' stroke='{$color}' stroke-width='3'{$dash}/>";
        $text = "<text x='" . ($x + 22) . "' y='" . ($y + 4) . "' font-size='10' fill='#334155' font-family='DejaVu Sans'>" . htmlspecialchars($label) . "</text>";
        return $swatch . $text;
    }
}
