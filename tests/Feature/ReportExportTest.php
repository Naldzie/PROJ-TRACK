<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportExportController;
use App\Models\ActivityLog;
use App\Models\Contractor;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectExtension;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ============================================================================
 * ReportExportTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the report-generation feature (gap #10) Excel exports:
 *   - progress history / baseline schedule / extensions / issues spreadsheets
 *   - each streamed response carries the correct Content-Type + filename and
 *     produces a valid XLSX payload (PK zip magic) containing the data rows
 *   - an ActivityLog audit entry is written per export
 *   - admin / staff may export any project; contractors are scoped to their
 *     own assigned project only (403 otherwise)
 *
 * Conventions
 * -----------
 *   - Controllers are invoked DIRECTLY (route-based auth does not authenticate
 *     in this environment — see AttachmentTest).
 *   - Users via User::create() (no factory — users table lacks email_verified_at).
 *   - StreamedResponse bodies are captured with output buffering so the raw
 *     file payload can be asserted.
 *
 * Run With
 * --------
 *   php artisan test --filter=ReportExportTest
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['status' => 'Ongoing']);
    }

    // === HELPERS ===

    private function admin(): User
    {
        return User::create([
            'name'  => 'Test Admin',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'admin',
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name'  => 'Test Staff',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'staff',
        ]);
    }

    private function contractorFor(Project $project): User
    {
        $user = User::create([
            'name'  => 'Test Contractor',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'contractor',
        ]);

        Contractor::create([
            'user_id'        => $user->id,
            'contractor_id'  => 'CON-' . $user->id,
            'company_name'   => fake()->company(),
            'contractor_name'=> $user->name,
        ]);

        $project->update(['contractor_id' => $user->contractor->id]);

        return $user;
    }

    /** Execute a streamed export and return [StreamedResponse, raw body]. */
    private function runExport(callable $call): array
    {
        ob_start();
        $response = $call();
        $response->sendContent();
        $body = ob_get_clean();

        return [$response, $body];
    }

    /** Load an XLSX body into a PhpSpreadsheet workbook for cell assertions. */
    private function loadWorkbook(string $body): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'pmu_xlsx_');
        file_put_contents($path, $body);

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    private function progressRow(): ProjectProgressHistory
    {
        return ProjectProgressHistory::factory()->create([
            'project_id'              => $this->project->id,
            'month_label'             => '2026-06',
            'inspection_date'         => '2026-06-15',
            'actual_progress_percent' => 35,
            'progress_percentage'     => 35,
            'planned_progress_percent'=> 40,
            'variance_percent'        => -5,
            'remarks'                 => 'Phase D inspection',
        ]);
    }

    private function baselineRow(): ProjectBaselineSchedule
    {
        return ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-06',
            'planned_date'             => '2026-06-30',
            'planned_progress_percent' => 40,
            'description'              => 'Baseline for June',
        ]);
    }

    private function extensionRow(): ProjectExtension
    {
        return ProjectExtension::factory()->approved()->create([
            'project_id'        => $this->project->id,
            'extension_number'  => 1,
            'original_end_date' => '2026-12-15',
            'new_end_date'      => '2027-03-15',
            'duration_days'     => 90,
            'reason'            => 'Weather delay due to typhoon season',
        ]);
    }

    private function issueRow(): Issue
    {
        return Issue::factory()->create([
            'project_id'        => $this->project->id,
            'issue_name'        => 'Concrete quality concern',
            'issue_type'        => 'Quality',
            'severity'          => 'High',
            'status'            => 'Open',
            'resolution_notes'  => null,
        ]);
    }

    // === PROGRESS HISTORY EXPORT ===

    public function test_progress_excel_streams_valid_xlsx_with_data_and_logs_audit(): void
    {
        $this->actingAs($this->admin());
        $this->progressRow();

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->progressExcel($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('progress.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("PK\x03\x04", $body, 'XLSX payload should be a zip archive');

        $sheet = $this->loadWorkbook($body)->getActiveSheet();
        $this->assertSame('Phase D inspection', $sheet->getCell('F2')->getValue());
        $this->assertSame(35.0, $sheet->getCell('D2')->getValue());
        $this->assertSame('2026-06-15', $sheet->getCell('B2')->getValue());

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Export Report',
        ]);
    }

    // === BASELINE SCHEDULE EXPORT ===

    public function test_baseline_excel_streams_valid_xlsx_with_data(): void
    {
        $this->actingAs($this->staff());
        $this->baselineRow();

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->baselineExcel($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('baseline.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("PK\x03\x04", $body);

        $sheet = $this->loadWorkbook($body)->getActiveSheet();
        $this->assertSame('Baseline for June', $sheet->getCell('D2')->getValue());
        $this->assertSame(40.0, $sheet->getCell('C2')->getValue());
    }

    // === EXTENSIONS EXPORT ===

    public function test_extensions_excel_streams_valid_xlsx_with_data(): void
    {
        $this->actingAs($this->admin());
        $this->extensionRow();

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->extensionsExcel($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('extensions.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("PK\x03\x04", $body);

        $sheet = $this->loadWorkbook($body)->getActiveSheet();
        $this->assertSame('Weather delay due to typhoon season', $sheet->getCell('F2')->getValue());
        $this->assertSame(1, $sheet->getCell('A2')->getValue());
    }

    // === ISSUES EXPORT ===

    public function test_issues_excel_streams_valid_xlsx_with_data(): void
    {
        $this->actingAs($this->admin());
        $this->issueRow();

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->issuesExcel($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('issues.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("PK\x03\x04", $body);

        $sheet = $this->loadWorkbook($body)->getActiveSheet();
        $this->assertSame('Concrete quality concern', $sheet->getCell('A2')->getValue());
        $this->assertSame('Open', $sheet->getCell('D2')->getValue());
    }

    // === EMPTY PROJECT EXPORT ===

    public function test_progress_excel_handles_project_without_records(): void
    {
        $this->actingAs($this->admin());

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->progressExcel($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertStringStartsWith("PK\x03\x04", $body);
    }

    // === AUTHORIZATION ===

    public function test_contractor_can_export_own_assigned_project(): void
    {
        $this->actingAs($this->contractorFor($this->project));
        $this->progressRow();

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->progressExcel($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertStringStartsWith("PK\x03\x04", $body);
    }

    public function test_contractor_cannot_export_unassigned_project(): void
    {
        $otherProject = Project::factory()->create();
        $this->actingAs($this->contractorFor($this->project));

        try {
            app(ReportExportController::class)->progressExcel($otherProject);
            $this->fail('Expected a 403 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_each_export_writes_a_distinct_audit_entry(): void
    {
        $this->actingAs($this->admin());
        $this->progressRow();
        $this->baselineRow();
        $this->extensionRow();
        $this->issueRow();

        $this->runExport(fn () => app(ReportExportController::class)->progressExcel($this->project));
        $this->runExport(fn () => app(ReportExportController::class)->baselineExcel($this->project));
        $this->runExport(fn () => app(ReportExportController::class)->extensionsExcel($this->project));
        $this->runExport(fn () => app(ReportExportController::class)->issuesExcel($this->project));

        $this->assertSame(4, ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Export Report')
            ->count());
    }

    // === PDF SUMMARY ===

    public function test_summary_pdf_streams_valid_pdf_with_data(): void
    {
        $this->actingAs($this->admin());
        $this->progressRow();
        $this->issueRow();

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->summaryPdf($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/_project-report_\d{4}-\d{2}\.pdf/', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $body, 'PDF payload should start with the PDF magic header');
        $this->assertGreaterThan(500, strlen($body), 'PDF body should be non-trivial');

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Export Report',
        ]);
    }

    public function test_summary_pdf_handles_project_without_records(): void
    {
        $this->actingAs($this->admin());

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->summaryPdf($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertStringStartsWith('%PDF-', $body);
    }

    // === PDF SUMMARY CONTENT FIXES ===

    /**
     * Render the PDF layout of the Project Monitoring Report (overhauled
     * 2026-09-21: reports/project-report, fed by ProjectReportDataService —
     * it replaced reports/project-summary).
     */
    private function renderSummaryView(?string $period = null): string
    {
        $report = app(\App\Services\Reports\ProjectReportDataService::class)
            ->build($this->project, $period, 'Test Admin');

        return view('reports.project-report', [
            'report'   => $report,
            'chartImg' => app(\App\Services\Reports\ScurveChartRenderer::class)->svgImgTag($report['chart']),
        ])->render();
    }

    public function test_summary_view_renders_peso_sign_with_unicode_font(): void
    {
        $this->project->update(['contract_price'  => 10000000.00]);

        $html = $this->renderSummaryView();

        $this->assertStringContainsString('₱ 10,000,000.00', $html, 'Peso sign must be present in the markup.');
        $this->assertStringContainsString('DejaVu Sans', $html, 'A Unicode font supporting U+20B1 must be declared.');
    }

    public function test_summary_view_status_uses_latest_inspection_not_first(): void
    {
        ProjectProgressHistory::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-06',
            'inspection_date'          => '2026-06-15',
            'actual_progress_percent'  => 35,
            'progress_percentage'      => 35,
            'planned_progress_percent' => 40,
            'remarks'                  => 'June inspection',
        ]);
        ProjectProgressHistory::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-07',
            'inspection_date'          => '2026-07-15',
            'actual_progress_percent'  => 50,
            'progress_percentage'      => 50,
            'planned_progress_percent' => 55,
            'remarks'                  => 'July inspection',
        ]);

        $html = $this->renderSummaryView();

        // The report is "as of" the latest inspection (July), not the first
        $this->assertStringContainsString('Reporting period: <b>July 2026</b>', $html);
        $this->assertStringContainsString('As of: <b>July 15, 2026</b>', $html);
        // Status at a glance uses July's planned 55% / actual 50% / slippage -5%
        $this->assertMatchesRegularExpression('/class="v">55%<\/td>\s*<td class="v">50%<\/td>/', $html);
        // …while the monthly table lists every inspection
        $this->assertStringContainsString('June inspection', $html);
        $this->assertStringContainsString('July inspection', $html);
    }

    public function test_summary_view_embeds_scurve_as_data_uri_image(): void
    {
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-06',
            'planned_date'             => '2026-06-30',
            'planned_progress_percent' => 40,
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-07',
            'planned_date'             => '2026-07-31',
            'planned_progress_percent' => 55,
        ]);

        $html = $this->renderSummaryView();

        $this->assertStringContainsString('data:image/svg+xml;base64,', $html, 'S-Curve must be embedded as a base64 data-URI <img> for dompdf.');
        $this->assertStringContainsString('<img', $html, 'S-Curve must be rendered as an <img> element.');
        $this->assertStringNotContainsString('<svg', $html, 'Inline <svg> markup is not supported by dompdf and must not be emitted.');
    }

    public function test_summary_scurve_is_non_flat_and_has_legend_and_month_names(): void
    {
        $this->project->update(['extension_count' => 1]);

        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-04',
            'planned_date'             => '2026-04-30',
            'planned_progress_percent' => 2,
            'original_planned_progress_percent' => 2,
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-05',
            'planned_date'             => '2026-05-31',
            'planned_progress_percent' => 5,
            'original_planned_progress_percent' => 5,
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-06',
            'planned_date'             => '2026-06-30',
            'planned_progress_percent' => 40,
            'original_planned_progress_percent' => 40,
        ]);

        $this->progressRow();

        $html = $this->renderSummaryView();

        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);

        preg_match('/base64,([A-Za-z0-9+\/=]+)/', $html, $match);
        $svg = base64_decode($match[1]);

        $this->assertStringContainsString('Original Plan', $svg, 'Legend must label the frozen-planned series.');
        $this->assertStringContainsString('Adjusted Plan', $svg, 'Legend must label the adjusted-planned series.');
        $this->assertStringContainsString('Actual Progress', $svg, 'Legend must label the actual series.');

        $this->assertStringContainsString('April 2026', $svg, 'X-axis labels must use month-name + year format.');
        $this->assertStringContainsString('June 2026', $svg, 'X-axis labels must use month-name + year format.');

        preg_match_all("/points='([^']+)'/", $svg, $polys);
        $this->assertNotEmpty($polys[1], 'Chart must emit at least one polyline.');

        $ys = [];
        foreach ($polys[1] as $poly) {
            foreach (explode(' ', trim($poly)) as $pt) {
                [, $y] = explode(',', $pt);
                $ys[] = (float) $y;
            }
        }
        $this->assertGreaterThan(1, count(array_unique($ys)),
            'Polyline y-coordinates must vary (the S-Curve must not collapse to a flat line).');
    }

    public function test_summary_scurve_actual_beyond_baseline_extends_x_axis(): void
    {
        // Baselines end in 2026-07; an actual record lands a month later (2026-08)
        // with NO extension. The actual line must extend past the last planned
        // column instead of clamping onto it.
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-04',
            'planned_date'             => '2026-04-30',
            'planned_progress_percent' => 10,
            'original_planned_progress_percent' => 10,
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-05',
            'planned_date'             => '2026-05-31',
            'planned_progress_percent' => 30,
            'original_planned_progress_percent' => 30,
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-06',
            'planned_date'             => '2026-06-30',
            'planned_progress_percent' => 60,
            'original_planned_progress_percent' => 60,
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-07',
            'planned_date'             => '2026-07-31',
            'planned_progress_percent' => 100,
            'original_planned_progress_percent' => 100,
        ]);
        ProjectProgressHistory::factory()->create([
            'project_id'              => $this->project->id,
            'month_label'             => '2026-08',
            'inspection_date'         => '2026-08-15',
            'actual_progress_percent' => 95,
            'progress_percentage'     => 95,
            'planned_progress_percent'=> 100,
            'remarks'                 => 'Inspection one month after baseline end',
        ]);

        $html = $this->renderSummaryView();

        preg_match('/base64,([A-Za-z0-9+\/=]+)/', $html, $match);
        $svg = base64_decode($match[1]);

        // Parse polylines by their stroke color to inspect per-series geometry.
        preg_match_all("/<polyline points='([^']+)' fill='none' stroke='([^']+)'/", $svg, $polys, PREG_SET_ORDER);
        $this->assertNotEmpty($polys, 'Chart must emit at least one polyline.');

        $frozenMaxX = null;
        $actualMaxX = null;
        foreach ($polys as $poly) {
            $xs = array_map(fn ($pt) => (float) explode(',', $pt)[0], explode(' ', trim($poly[1])));
            if (str_contains($poly[2], '#3b82f6')) {
                $frozenMaxX = max($xs);
            }
            if (str_contains($poly[2], '#10b981')) {
                $actualMaxX = max($xs);
            }
        }

        $this->assertNotNull($frozenMaxX, 'Frozen polyline must be present.');
        $this->assertNotNull($actualMaxX, 'Actual polyline must be present.');
        $this->assertGreaterThan(
            $frozenMaxX,
            $actualMaxX,
            'Actual progress line must extend past the last planned month (no X-axis clamping).'
        );
    }

    public function test_summary_scurve_frozen_line_stops_at_last_original_month(): void
    {
        // Extension month (2026-07) has original_planned_progress_percent = null.
        // The frozen (Original Plan) line must stop at 2026-06 instead of being
        // drawn down to 0% at the extension month.
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-04',
            'planned_date'             => '2026-04-30',
            'planned_progress_percent' => 10,
            'original_planned_progress_percent' => 10,
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-05',
            'planned_date'             => '2026-05-31',
            'planned_progress_percent' => 30,
            'original_planned_progress_percent' => 30,
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-06',
            'planned_date'             => '2026-06-30',
            'planned_progress_percent' => 60,
            'original_planned_progress_percent' => 60,
        ]);
        // Extension month: planned continues, original is null.
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-07',
            'planned_date'             => '2026-07-31',
            'planned_progress_percent' => 100,
            'original_planned_progress_percent' => null,
        ]);
        $this->project->update(['extension_count' => 1]);

        $html = $this->renderSummaryView();

        preg_match('/base64,([A-Za-z0-9+\/=]+)/', $html, $match);
        $svg = base64_decode($match[1]);

        preg_match_all("/<polyline points='([^']+)' fill='none' stroke='([^']+)'/", $svg, $polys, PREG_SET_ORDER);

        $frozenPts = null;
        $adjustedPts = null;
        foreach ($polys as $poly) {
            if (str_contains($poly[2], '#3b82f6')) {
                $frozenPts = $poly[1];
            }
            if (str_contains($poly[2], '#f59e0b')) {
                $adjustedPts = $poly[1];
            }
        }

        $this->assertNotNull($frozenPts, 'Frozen polyline must be present.');
        $this->assertNotNull($adjustedPts, 'Adjusted polyline must be present when the project has extensions.');

        // Frozen line: exactly one point per non-null original month (3), so it
        // stops at 2026-06 at 60% — never drawn down to 0% at 2026-07.
        $frozenCount = count(explode(' ', trim($frozenPts)));
        $this->assertSame(3, $frozenCount, 'Frozen line must stop at the last original month.');

        // Adjusted line: includes the extension month (4 points total).
        $adjustedCount = count(explode(' ', trim($adjustedPts)));
        $this->assertSame(4, $adjustedCount, 'Adjusted line must continue into the extension month.');
    }

    public function test_summary_scurve_frozen_plan_uses_original_dates(): void
    {
        // Live dates were later moved (manual edit / adjusted baseline) but the
        // frozen Original Plan line must keep plotting on the ORIGINAL dates.
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-01',
            'planned_date'             => '2026-01-15',
            'original_planned_date'    => '2026-01-05',
            'planned_progress_percent' => 40,
            'original_planned_progress_percent' => 40,
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => '2026-02',
            'planned_date'             => '2026-02-20',
            'original_planned_date'    => '2026-02-15',
            'planned_progress_percent' => 70,
            'original_planned_progress_percent' => 70,
        ]);

        $chartData = app(\App\Services\ProjectDetailService::class)->prepareChartData($this->project);

        $frozen = $chartData['frozen_planned_data'] ?? [];
        $adjusted = $chartData['adjusted_planned_data'] ?? [];

        $this->assertCount(2, $frozen);
        $this->assertCount(2, $adjusted);

        // Frozen line plots on the ORIGINAL dates, not the moved live dates.
        $this->assertSame(\Carbon\Carbon::parse('2026-01-05')->timestamp * 1000, $frozen[0]['x']);
        $this->assertSame(\Carbon\Carbon::parse('2026-02-15')->timestamp * 1000, $frozen[1]['x']);

        // Adjusted line keeps using the live planned dates.
        $this->assertSame(\Carbon\Carbon::parse('2026-01-15')->timestamp * 1000, $adjusted[0]['x']);
        $this->assertSame(\Carbon\Carbon::parse('2026-02-20')->timestamp * 1000, $adjusted[1]['x']);
    }

    public function test_summary_pdf_inline_view_serves_pdf_without_audit_log(): void
    {
        $this->actingAs($this->admin());
        $this->progressRow();

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->summaryPdfView($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $body);

        $this->assertDatabaseMissing('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Export Report',
        ]);
    }

    public function test_summary_pdf_download_still_logs_audit(): void
    {
        $this->actingAs($this->admin());
        $this->progressRow();

        [$response, $body] = $this->runExport(
            fn () => app(ReportExportController::class)->summaryPdf($this->project)
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $body);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Export Report',
        ]);
    }

    // =====================================================================
    // AI Analysis + Recommendations in PDF (Phase 11)
    // =====================================================================

    public function test_summary_view_includes_ai_analysis_when_present(): void
    {
        \App\Models\AiSolution::create([
            'project_id'           => $this->project->id,
            'analysis_text'        => 'The project is currently at 45% progress with a declining trend.',
            'model_used'           => 'gemini-1.5-flash',
            'regression_snapshot'  => ['r_squared' => 0.95],
            'recommendations'      => null,
        ]);

        $html = $this->renderSummaryView();

        $this->assertStringContainsString('9. Analysis', $html);
        $this->assertStringContainsString('The project is currently at 45% progress with a declining trend.', $html);
        $this->assertStringContainsString('System-generated analysis dated', $html);
    }

    public function test_summary_view_includes_recommendations_when_present(): void
    {
        \App\Models\AiSolution::create([
            'project_id'           => $this->project->id,
            'analysis_text'        => 'Test analysis.',
            'model_used'           => 'gemini-1.5-flash',
            'regression_snapshot'  => ['r_squared' => 0.95],
            'recommendations' => [
                [
                    'message'      => 'Address open material issues immediately',
                    'category'     => 'corrective',
                    'reason'       => 'There are 2 open material issues.',
                    'priority'     => 1,
                    'ai_generated' => true,
                ],
                [
                    'message'      => 'Escalate chronic slippage to management',
                    'category'     => 'escalation',
                    'reason'       => 'Contractor rating is 1.00/5.',
                    'priority'     => 2,
                    'ai_generated' => true,
                ],
            ],
        ]);

        $html = $this->renderSummaryView();

        $this->assertStringContainsString('10. Recommendations', $html);
        $this->assertStringContainsString('Address open material issues immediately', $html);
        $this->assertStringContainsString('Escalate chronic slippage to management', $html);
        $this->assertStringContainsString('Corrective', $html);
        $this->assertStringContainsString('Escalation', $html);
        $this->assertStringContainsString('There are 2 open material issues.', $html);
    }

    public function test_summary_view_says_so_when_no_analysis_exists(): void
    {
        $html = $this->renderSummaryView();

        // The sections stay (the report layout is fixed) but say plainly
        // that nothing was generated
        $this->assertStringContainsString('No analysis has been generated for this period.', $html);
        $this->assertStringContainsString('No recommendations for this period.', $html);
        $this->assertStringNotContainsString('System-generated analysis dated', $html);
    }

    public function test_summary_view_omits_ai_sections_when_empty_analysis(): void
    {
        \App\Models\AiSolution::create([
            'project_id'           => $this->project->id,
            'analysis_text'        => '',
            'model_used'           => 'gemini-1.5-flash',
            'regression_snapshot'  => ['r_squared' => 0.95],
            'recommendations'      => null,
        ]);

        $html = $this->renderSummaryView();

        $this->assertStringContainsString('No analysis has been generated for this period.', $html);
        $this->assertStringNotContainsString('System-generated analysis dated', $html);
    }

    /**
     * The analysis is split into headed sections — the raw [SECTION:] markers
     * are never printed (the old PDF printed them; parked defect (a) of
     * 2026-09-21, fixed by the report overhaul).
     */
    public function test_summary_view_renders_analysis_sections_not_raw_markers(): void
    {
        \App\Models\AiSolution::create([
            'project_id'          => $this->project->id,
            'analysis_text'       => "[SECTION:Report]\nParagraph one.\n\nParagraph two.\n[/SECTION]\n\n"
                . "[SECTION:Possible Risk]\n- Material delay\n- Rain\n[/SECTION]\n\n"
                . "[SECTION:AI Comment]\nBottom line.\n[/SECTION]",
            'model_used'          => 'gemini-1.5-flash',
            'regression_snapshot' => ['r_squared' => 0.95],
        ]);

        $html = $this->renderSummaryView();

        $this->assertStringNotContainsString('[SECTION:', $html);
        $this->assertStringNotContainsString('[/SECTION]', $html);
        $this->assertStringContainsString('<h3>Report</h3>', $html);
        $this->assertStringContainsString('<p>Paragraph one.</p>', $html);
        $this->assertStringContainsString('<p>Paragraph two.</p>', $html);
        $this->assertStringContainsString('<li>Material delay</li>', $html);
        // "AI Comment" reads "Overall Comment" in the report
        $this->assertStringContainsString('<h3>Overall Comment</h3>', $html);
    }

    public function test_summary_view_renders_recommendation_priority_and_reason(): void
    {
        \App\Models\AiSolution::create([
            'project_id'           => $this->project->id,
            'analysis_text'        => 'Test.',
            'model_used'           => 'gemini-1.5-flash',
            'regression_snapshot'  => ['r_squared' => 0.95],
            'recommendations' => [
                [
                    'message'      => 'Monitor weekly progress',
                    'category'     => 'monitoring',
                    'reason'       => 'Declining trend observed.',
                    'priority'     => 3,
                    'ai_generated' => true,
                ],
            ],
        ]);

        $html = $this->renderSummaryView();

        $this->assertStringContainsString('3', $html);
        $this->assertStringContainsString('Declining trend observed.', $html);
        $this->assertStringContainsString('Monitoring', $html);
    }
}
