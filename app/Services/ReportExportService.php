<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
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
            'Status', 'Reason', 'Rejection Reason', 'Approved At', 'Approved By',
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
                $extension->approved_at?->format('Y-m-d H:i'),
                $extension->approver?->name,
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

        // Reuse the project-detail assembly as the single source of truth.
        $detailService = app(ProjectDetailService::class);
        $data = $detailService->prepareProjectData($project);
        $chartData = $detailService->prepareChartData($project);

        $svgChart = $this->buildSvgChart($chartData);

        $html = view('reports.project-summary', [
            'project'   => $project,
            'data'      => $data,
            'chartData' => $chartData,
            'svgChart'  => $svgChart,
            'generatedAt' => now()->format('F j, Y g:i A'),
        ])->render();

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $this->logExport($project, 'PDF Summary Report');

        $filename = $this->fileName($project, 'project-summary.pdf');

        return Response::streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // === PRIVATE HELPERS ===

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
        $padTop = 25;
        $padBottom = 45;

        $labels = $chartData['labels'];
        $n = count($labels);
        $plotW = $width - $padLeft - $padRight;
        $plotH = $height - $padTop - $padBottom;

        // Determine value range (0..100 unless data exceeds it).
        $maxY = 100;
        $maxY = max($maxY, (float) ($chartData['y_max'] ?? 0));

        $xFor = fn (int $i) => $padLeft + ($i / max($n - 1, 1)) * $plotW;
        $yFor = fn (float $v) => $padTop + (1 - min(max($v, 0), $maxY) / $maxY) * $plotH;

        $points = function (array $values) use ($xFor, $yFor) {
            $pts = [];
            foreach ($values as $i => $v) {
                if ($v === null) {
                    continue;
                }
                $pts[] = round($xFor($i), 1) . ',' . round($yFor((float) $v), 1);
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

        $xLabels = '';
        foreach ($labels as $i => $label) {
            if ($i % max(1, intdiv($n, 6)) !== 0 && $i !== $n - 1) {
                continue;
            }
            $xLabels .= "<text x='" . round($xFor($i), 1) . "' y='" . ($height - $padBottom + 18) . "' font-size='10' fill='#64748b' text-anchor='middle'>" . htmlspecialchars($label) . "</text>";
        }

        $polyline = function (array $values, string $stroke, float $width, string $dash = '') use ($points) {
            $pts = $points($values);
            if ($pts === '') {
                return '';
            }
            return "<polyline points='{$pts}' fill='none' stroke='{$stroke}' stroke-width='{$width}'" . ($dash ? " stroke-dasharray='{$dash}'" : '') . "/>";
        };

        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' width='{$width}' height='{$height}'>";
        $svg .= $gridLines;
        $svg .= $polyline($chartData['frozen_planned_data'] ?? [], '#3b82f6', 2.5);
        $svg .= $polyline($chartData['adjusted_planned_data'] ?? [], '#8b5cf6', 2);
        $svg .= $polyline($chartData['actual_data'] ?? [], '#10b981', 3);
        $svg .= $xLabels;
        $svg .= "</svg>";

        return $svg;
    }
}
