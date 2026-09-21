<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ReportExportService;
use Illuminate\Http\Request;

/**
 * ============================================================================
 * ReportExportController
 * ============================================================================
 *
 * Purpose
 * -------
 * Thin HTTP layer for report generation (gap #10). Serves all three roles
 * (admin / staff / contractor) from a single controller, mirroring the shared
 * ProjectController pattern. Authorization is enforced inside
 * ReportExportService::authorize() (admin/staff any project; contractor only
 * their own project).
 *
 * Controllers stay HTTP-only (Rule 5): receive Request → call service → return
 * StreamedResponse.
 */
class ReportExportController extends Controller
{
    private ReportExportService $service;

    public function __construct(ReportExportService $service)
    {
        $this->service = $service;
    }

    public function progressExcel(Project $project)
    {
        return $this->service->progressExcel($project);
    }

    public function baselineExcel(Project $project)
    {
        return $this->service->baselineExcel($project);
    }

    public function extensionsExcel(Project $project)
    {
        return $this->service->extensionsExcel($project);
    }

    public function issuesExcel(Project $project)
    {
        return $this->service->issuesExcel($project);
    }

    // Project report as PDF download; ?period=YYYY-MM (default: latest month)
    public function summaryPdf(Project $project)
    {
        return $this->service->summaryPdf($project, $this->period(request()));
    }

    // Project report as inline PDF for the viewer modal (not audit-logged)
    public function summaryPdfView(Project $project)
    {
        return $this->service->summaryPdfView($project, $this->period(request()));
    }

    // Project report as an editable Word document; same ?period
    public function reportDocx(Project $project)
    {
        return $this->service->projectReportDocx($project, $this->period(request()));
    }

    // The requested reporting month; an unknown month falls back to the latest
    // inside ProjectReportDataService::resolvePeriod()
    private function period(Request $request): ?string
    {
        $period = $request->query('period');

        return is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period) ? $period : null;
    }
}
