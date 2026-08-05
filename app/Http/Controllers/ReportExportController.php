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

    public function summaryPdf(Project $project)
    {
        return $this->service->summaryPdf($project);
    }
}
