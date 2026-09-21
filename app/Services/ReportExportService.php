<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Services\Reports\ProjectReportDataService;
use App\Services\Reports\ProjectReportDocxRenderer;
use App\Services\Reports\ScurveChartRenderer;
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
 * schedule, extensions, issues — and the per-project Project Monitoring
 * Report in PDF and editable Word (overhauled 2026-09-21,
 * textfiles/project_report_overhaul_plan.md).
 *
 * Called by
 * ---------
 * ReportExportController (all three roles; authorization enforced inside).
 *
 * Calls
 * -----
 * Reports\ProjectReportDataService — the report content for a period
 * Reports\ProjectReportDocxRenderer — Word layout
 * Reports\ScurveChartRenderer — the S-curve (SVG for PDF, PNG for Word)
 *
 * Notes
 * -----
 * - No storage writes: every file is streamed to the client (the Word file
 *   passes through a temp file that is deleted after streaming).
 * - Each download records an ActivityLog entry (audit trail); previews do not.
 * - Excel via PhpOffice\PhpSpreadsheet; PDF via Dompdf\Dompdf; Word via
 *   PhpOffice\PhpWord.
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

    // === PROJECT MONITORING REPORT (PDF + WORD) ===
    //
    // One report, two formats (textfiles/project_report_overhaul_plan.md):
    // ProjectReportDataService builds the content for a reporting period;
    // the PDF (Blade + dompdf) and the Word file (PhpWord) only lay it out.
    // The routes keep their August names (…exports.summary / .summary.view)
    // so existing links keep working; ?period=YYYY-MM picks the month.

    /**
     * Download the project report as PDF (audit-logged).
     */
    public function summaryPdf(Project $project, ?string $period = null): StreamedResponse
    {
        $this->authorize($project);

        $report = $this->buildReport($project, $period);
        $dompdf = $this->buildReportDompdf($report);

        $this->logExport($project, 'PDF Project Report (' . $report['meta']['period_label'] . ')');

        return Response::streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $this->reportFileName($report, 'pdf'), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Preview the project report PDF inline (no audit-log entry; the viewer
     * modal iframe loads this, and only an actual download records the audit
     * trail).
     */
    public function summaryPdfView(Project $project, ?string $period = null): StreamedResponse
    {
        $this->authorize($project);

        $report = $this->buildReport($project, $period);
        $dompdf = $this->buildReportDompdf($report);

        return Response::streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $this->reportFileName($report, 'pdf'), [
            'Content-Type' => 'application/pdf',
        ], 'inline');
    }

    /**
     * Download the project report as an editable Word document (audit-logged).
     * The temp file is removed once it has been streamed.
     */
    public function projectReportDocx(Project $project, ?string $period = null): StreamedResponse
    {
        $this->authorize($project);

        $report = $this->buildReport($project, $period);
        $path = app(ProjectReportDocxRenderer::class)->render($report);

        $this->logExport($project, 'Word Project Report (' . $report['meta']['period_label'] . ')');

        return Response::streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, $this->reportFileName($report, 'docx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    // === PRIVATE HELPERS ===

    /**
     * The report content for a project + period, stamped with the current user.
     */
    private function buildReport(Project $project, ?string $period): array
    {
        return app(ProjectReportDataService::class)->build($project, $period, Auth::user()?->name);
    }

    /**
     * Render the report array to a dompdf instance: the Blade layout, the
     * shared vector S-curve, and a footer with page X of Y on every page.
     */
    private function buildReportDompdf(array $report): Dompdf
    {
        $html = view('reports.project-report', [
            'report'   => $report,
            'chartImg' => app(ScurveChartRenderer::class)->svgImgTag($report['chart']),
        ])->render();

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        // Header (office — from page 2; page 1 carries it in the title block)
        // and footer (project · period · page X of Y) on every page, drawn on
        // the canvas so they repeat without HTML tricks.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $grey = [0.39, 0.45, 0.55];
        $m = $report['meta'];
        $office = $m['office'];
        $canvas->page_script(function ($pageNumber, $pageCount, $canvas) use ($office, $font, $grey) {
            if ($pageNumber > 1) {
                $canvas->text(48, 28, $office, $font, 7, $grey);
            }
        });
        $canvas->page_text(48, $canvas->get_height() - 34,
            $m['project_code'] . '  ·  ' . $m['title'] . '  ·  ' . $m['period_label'] . '  ·  Page {PAGE_NUM} of {PAGE_COUNT}',
            $font, 7, $grey);

        return $dompdf;
    }

    /**
     * {project_code}_project-report_{YYYY-MM}.{ext}
     */
    private function reportFileName(array $report, string $ext): string
    {
        return Str::slug($report['meta']['project_code'] ?: 'project') . '_project-report_' . $report['meta']['period'] . '.' . $ext;
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
     * Inline SVG S-Curve as a dompdf-safe <img> tag.
     *
     * The chart code moved to Reports\ScurveChartRenderer (2026-09-21) so the
     * PDF (SVG) and the Word report (PNG) share one geometry; this wrapper
     * keeps the old entry point (byte-identical output, checked on all 30
     * live projects before the move).
     */
    private function buildSvgChart(array $chartData): string
    {
        return app(ScurveChartRenderer::class)->svgImgTag($chartData);
    }
}
