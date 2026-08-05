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
}
