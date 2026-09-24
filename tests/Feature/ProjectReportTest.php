<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportExportController;
use App\Models\AiSolution;
use App\Models\Contractor;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectActivityCarryover;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectExtension;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use App\Services\AiReportParser;
use App\Services\Reports\ProjectReportDataService;
use App\Services\Reports\ScurveChartRenderer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ============================================================================
 * ProjectReportTest
 * ============================================================================
 *
 * Purpose
 * -------
 * The overhauled per-project Project Monitoring Report
 * (textfiles/project_report_overhaul_plan.md):
 *   · ProjectReportDataService — reporting periods and the "as of" cut-offs
 *     (inspections, issues, extensions, analysis, forecast, chart)
 *   · the Word download (.docx) — a valid document with every section, the
 *     S-curve image, audit log, period in the filename, contractor scope
 *   · ScurveChartRenderer::png() and AiReportParser::parseToBlocks()
 *
 * Conventions follow ReportExportTest: controllers invoked directly, users via
 * User::create(), streamed bodies captured with output buffering. HTTP is
 * faked with stray requests forbidden, so nothing can reach Gemini.
 *
 * Run With
 * --------
 *   php artisan test --filter=ProjectReportTest
 */
class ProjectReportTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('2026-09-21 10:00:00');

        $this->project = Project::factory()->create([
            'status'             => 'Ongoing',
            'title'              => 'Evacuation Center',
            'planned_start_date' => '2026-04-01',
            'planned_end_date'   => '2027-03-31',
            'original_planned_end_date' => '2027-03-31',
            'contract_price'     => 10000000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // === HELPERS ===

    private function admin(): User
    {
        return User::create(['name' => 'Report Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin']);
    }

    /** An inspection record for a month, recorded at $recordedAt. */
    private function inspection(string $month, float $planned, float $actual, ?string $recordedAt = null, ?string $remarks = null): ProjectProgressHistory
    {
        $row = ProjectProgressHistory::factory()->create([
            'project_id'               => $this->project->id,
            'month_label'              => $month,
            'inspection_date'          => $month . '-15',
            'planned_progress_percent' => $planned,
            'actual_progress_percent'  => $actual,
            'progress_percentage'      => $actual,
            'variance_percent'         => $actual - $planned,
            'remarks'                  => $remarks,
        ]);
        $row->created_at = Carbon::parse($recordedAt ?? $month . '-16 09:00:00');
        $row->save();

        return $row;
    }

    private function build(?string $period = null): array
    {
        return app(ProjectReportDataService::class)->build($this->project->fresh(), $period, 'Report Admin');
    }

    /** Execute a streamed export and return [response, raw body]. */
    private function runExport(callable $call): array
    {
        ob_start();
        $response = $call();
        $response->sendContent();
        $body = ob_get_clean();

        return [$response, $body];
    }

    /** word/document.xml of a .docx body. */
    private function documentXml(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pmu_docx_');
        file_put_contents($path, $docx);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The .docx must be a readable zip');
        $xml = $zip->getFromName('word/document.xml');
        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $media[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($path);

        $this->assertIsString($xml);

        return $xml . "\n<!--FILES:" . implode(',', $media) . '-->';
    }

    // === PERIODS ===

    public function test_periods_are_inspection_months_newest_first(): void
    {
        $this->inspection('2026-05', 5, 3);
        $this->inspection('2026-07', 18, 15);
        $this->inspection('2026-06', 10, 7);

        $periods = app(ProjectReportDataService::class)->availablePeriods($this->project);

        $this->assertSame(['2026-07', '2026-06', '2026-05'], array_column($periods, 'value'));
        $this->assertSame('July 2026', $periods[0]['label']);
    }

    public function test_project_without_inspections_offers_the_current_month(): void
    {
        $periods = app(ProjectReportDataService::class)->availablePeriods($this->project);

        $this->assertSame([['value' => '2026-09', 'label' => 'September 2026']], $periods);

        $report = $this->build();
        $this->assertFalse($report['meta']['has_inspection']);
        $this->assertSame([], $report['progress']);
        $this->assertNull($report['status']['level']);
    }

    public function test_unknown_period_falls_back_to_the_latest(): void
    {
        $this->inspection('2026-06', 10, 7);
        $this->inspection('2026-07', 18, 15);

        $this->assertSame('2026-07', $this->build('2025-01')['meta']['period']);
        $this->assertSame('2026-07', $this->build('garbage')['meta']['period']);
        $this->assertSame('2026-06', $this->build('2026-06')['meta']['period']);
    }

    // === "AS OF" CUT-OFFS ===

    public function test_past_period_stops_at_its_own_month(): void
    {
        $this->inspection('2026-06', 10, 7, null, 'June remarks');
        $this->inspection('2026-07', 18, 15, null, 'July remarks');

        $report = $this->build('2026-06');

        $this->assertFalse($report['meta']['is_latest']);
        $this->assertSame('June 15, 2026', $report['meta']['as_of']);
        $this->assertSame(['June 2026'], array_column($report['progress'], 'month'));
        $this->assertSame(10.0, $report['status']['planned']);
        $this->assertSame(7.0, $report['status']['actual']);
        $this->assertSame(-3.0, $report['status']['slippage']);
        // Forecast is for the latest period only
        $this->assertFalse($report['forecast']['available']);
        $this->assertStringContainsString('latest period', $report['forecast']['note']);
    }

    public function test_status_uses_the_app_risk_ladder(): void
    {
        $this->inspection('2026-07', 30, 12); // 18 behind → High Risk

        $status = $this->build()['status'];

        $this->assertSame('high_risk', $status['level']);
        $this->assertSame('High Risk', $status['label']);
        $this->assertSame(-18.0, $status['slippage']);
    }

    public function test_issue_resolved_after_the_period_counts_as_open_in_it(): void
    {
        $this->inspection('2026-06', 10, 7);
        $this->inspection('2026-07', 18, 15);

        $issue = Issue::factory()->create([
            'project_id' => $this->project->id, 'issue_name' => 'Rebar delay', 'status' => 'Resolved',
            'severity' => 'High', 'resolution_notes' => 'New supplier engaged',
            'resolved_at' => '2026-07-10 08:00:00',
        ]);
        $issue->created_at = Carbon::parse('2026-05-20');
        $issue->save();

        $late = Issue::factory()->create(['project_id' => $this->project->id, 'issue_name' => 'Later issue', 'status' => 'Open']);
        $late->created_at = Carbon::parse('2026-07-20');
        $late->save();

        $june = $this->build('2026-06')['issues'];
        $this->assertSame(['Rebar delay'], array_column($june['open'], 'name'));
        $this->assertSame('Open', $june['open'][0]['status'], 'Resolved later → still open as of June');
        $this->assertSame([], $june['resolved']);

        $july = $this->build('2026-07')['issues'];
        $this->assertSame(['Rebar delay'], array_column($july['resolved'], 'name'));
        $this->assertSame('New supplier engaged', $july['resolved'][0]['resolution']);
        $this->assertSame(['Later issue'], array_column($july['open'], 'name'));
    }

    public function test_extension_decided_after_the_period_reads_pending(): void
    {
        $this->inspection('2026-06', 10, 7);
        $this->inspection('2026-08', 28, 25);

        $ext = ProjectExtension::factory()->approved()->create([
            'project_id' => $this->project->id, 'extension_number' => 1, 'duration_days' => 30,
            'original_end_date' => '2027-03-31', 'new_end_date' => '2027-04-30',
            'reviewed_at' => '2026-07-05 10:00:00', 'approved_at' => '2026-07-05 10:00:00',
        ]);
        $ext->created_at = Carbon::parse('2026-06-20');
        $ext->save();

        $june = $this->build('2026-06');
        $this->assertSame('Pending', $june['extensions'][0]['status']);
        $this->assertSame(0, $june['status']['extensions_count']);
        $this->assertFalse($june['chart']['has_extension'] ?? false);

        $august = $this->build('2026-08');
        $this->assertSame('Approved', $august['extensions'][0]['status']);
        $this->assertSame(1, $august['status']['extensions_count']);
        $this->assertSame(30, $august['status']['extension_days']);
    }

    public function test_past_period_revised_completion_follows_extensions_approved_by_then(): void
    {
        $this->inspection('2026-06', 10, 7);
        $this->inspection('2026-09', 40, 38);

        $ext = ProjectExtension::factory()->approved()->create([
            'project_id' => $this->project->id, 'extension_number' => 1, 'duration_days' => 30,
            'original_end_date' => '2027-03-31', 'new_end_date' => '2027-04-30',
            'reviewed_at' => '2026-05-10', 'approved_at' => '2026-05-10',
        ]);
        $ext->created_at = Carbon::parse('2026-05-01');
        $ext->save();

        $rows = collect($this->build('2026-06')['project'])->pluck('value', 'label');
        $this->assertSame('April 30, 2027 (after approved extensions)', $rows['Revised Completion Date']);
        $this->assertSame('March 31, 2027', $rows['Original Completion Date']);
    }

    public function test_work_accomplished_lists_the_month_activities_and_carryovers(): void
    {
        $this->inspection('2026-07', 18, 15);

        ProjectActivity::create(['project_id' => $this->project->id, 'month_label' => '2026-07', 'activity_name' => 'Wall Construction', 'is_completed' => true]);
        ProjectActivity::create(['project_id' => $this->project->id, 'month_label' => '2026-07', 'activity_name' => 'Plumbing', 'is_completed' => false]);
        $old = ProjectActivity::create(['project_id' => $this->project->id, 'month_label' => '2026-06', 'activity_name' => 'Columns', 'is_completed' => false]);
        ProjectActivity::create(['project_id' => $this->project->id, 'month_label' => '2026-08', 'activity_name' => 'Roofing', 'is_completed' => false]);
        $june = ProjectBaselineSchedule::factory()->create(['project_id' => $this->project->id, 'month_label' => '2026-06', 'planned_date' => '2026-06-30']);
        $july = ProjectBaselineSchedule::factory()->create(['project_id' => $this->project->id, 'month_label' => '2026-07', 'planned_date' => '2026-07-31']);
        ProjectActivityCarryover::create([
            'activity_id' => $old->id, 'project_id' => $this->project->id,
            'from_baseline_schedule_id' => $june->id, 'from_month_label' => '2026-06',
            'to_baseline_schedule_id' => $july->id, 'to_month_label' => '2026-07', 'carryover_number' => 1,
            'carried_over_by' => $this->admin()->id,
        ]);

        $work = $this->build()['work'];

        $this->assertSame(['Wall Construction', 'Plumbing'], array_column($work['activities'], 'name'));
        $this->assertSame(1, $work['done_count']);
        $this->assertSame(2, $work['total']);
        $this->assertSame('Columns', $work['carried'][0]['name']);
        $this->assertSame('June 2026', $work['carried'][0]['from']);
    }

    public function test_past_period_uses_the_analysis_made_before_the_next_inspection(): void
    {
        $this->inspection('2026-06', 10, 7, '2026-06-16 09:00:00');
        $this->inspection('2026-07', 18, 15, '2026-07-16 09:00:00');

        $june = AiSolution::create(['project_id' => $this->project->id, 'analysis_text' => "[SECTION:Report]\nJune analysis.\n[/SECTION]", 'model_used' => 'x', 'regression_snapshot' => []]);
        $june->created_at = Carbon::parse('2026-06-25');
        $june->save();
        $july = AiSolution::create(['project_id' => $this->project->id, 'analysis_text' => "[SECTION:Report]\nJuly analysis.\n[/SECTION]", 'model_used' => 'x', 'regression_snapshot' => []]);
        $july->created_at = Carbon::parse('2026-07-25');
        $july->save();

        $text = fn (array $r) => $r['analysis']['sections'][0]['blocks'][0]['text'];

        $this->assertSame('June analysis.', $text($this->build('2026-06')));
        $this->assertSame('July analysis.', $text($this->build('2026-07')));
    }

    public function test_actual_line_is_cut_at_the_period_end(): void
    {
        foreach (['2026-04' => 2, '2026-05' => 5, '2026-06' => 10, '2026-07' => 18] as $m => $p) {
            ProjectBaselineSchedule::factory()->create([
                'project_id' => $this->project->id, 'month_label' => $m, 'planned_date' => Carbon::parse($m . '-01')->endOfMonth(),
                'planned_progress_percent' => $p, 'original_planned_progress_percent' => $p,
            ]);
        }
        $this->inspection('2026-05', 5, 3);
        $this->inspection('2026-06', 10, 7);
        $this->inspection('2026-07', 18, 15);

        $cutMs = Carbon::parse('2026-06-30 23:59:59')->getTimestamp() * 1000;
        $june = $this->build('2026-06')['chart']['actual_data'];
        $all = $this->build('2026-07')['chart']['actual_data'];

        $this->assertLessThan(count($all), count($june));
        foreach ($june as $pt) {
            $this->assertLessThanOrEqual($cutMs, $pt['x']);
        }
    }

    // === FORECAST, ANALYSIS, RECOMMENDATIONS ===

    public function test_latest_forecast_is_plain_language(): void
    {
        foreach (['2026-04' => 5, '2026-05' => 12, '2026-06' => 20, '2026-07' => 30, '2026-08' => 42, '2026-09' => 55] as $m => $p) {
            ProjectBaselineSchedule::factory()->create([
                'project_id' => $this->project->id, 'month_label' => $m, 'planned_date' => Carbon::parse($m . '-01')->endOfMonth(),
                'planned_progress_percent' => $p, 'original_planned_progress_percent' => $p,
            ]);
            $this->inspection($m, $p, $p - 2);
        }

        $forecast = $this->build()['forecast'];

        $this->assertTrue($forecast['available']);
        $this->assertStringStartsWith('Next inspection: progress is expected to reach about', $forecast['lines'][0]);
        $joined = implode(' ', $forecast['lines']);
        foreach (['R²', 'regression', 'polynomial', '/100', 'percentile'] as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $joined);
        }
    }

    public function test_recommendations_carry_their_source(): void
    {
        $this->inspection('2026-07', 18, 15);
        AiSolution::create([
            'project_id' => $this->project->id, 'analysis_text' => 'Text.', 'model_used' => 'x', 'regression_snapshot' => [],
            'recommendations' => [
                ['message' => 'AI action', 'category' => 'escalation', 'reason' => 'Why', 'priority' => 1, 'ai_generated' => true],
                ['message' => 'Rule action', 'category' => 'monitoring', 'reason' => 'Because', 'priority' => 3],
            ],
        ]);

        $recs = $this->build()['recommendations'];

        $this->assertSame(['AI-assisted', 'System rules'], array_column($recs, 'source'));
        $this->assertSame('Escalation', $recs[0]['category']);
        Http::assertNothingSent();
    }

    // === WORD DOWNLOAD ===

    public function test_word_report_is_a_valid_docx_with_every_section(): void
    {
        $this->actingAs($this->admin());
        foreach (['2026-04' => 5, '2026-05' => 12] as $m => $p) {
            ProjectBaselineSchedule::factory()->create([
                'project_id' => $this->project->id, 'month_label' => $m, 'planned_date' => Carbon::parse($m . '-01')->endOfMonth(),
                'planned_progress_percent' => $p, 'original_planned_progress_percent' => $p,
            ]);
        }
        $this->inspection('2026-05', 12, 9, null, 'Formworks & rebars <ok>');
        // Reported within the period (the report leaves out later issues)
        $issue = Issue::factory()->create(['project_id' => $this->project->id, 'issue_name' => 'Cement shortage', 'status' => 'Open']);
        $issue->created_at = Carbon::parse('2026-05-10');
        $issue->save();

        [$response, $body] = $this->runExport(fn () => app(ReportExportController::class)->reportDocx($this->project));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/_project-report_2026-05\.docx/', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("PK\x03\x04", $body, 'A .docx is a zip archive — nothing may precede it');

        $xml = $this->documentXml($body);
        foreach ([
            'Project Monitoring Report', '1. Project Information', '2. Status at a Glance', '3. Progress S-Curve',
            '4. Monthly Progress', '5. Work Accomplished', '6. Issues and Problems Encountered', '7. Time Extensions',
            '8. Forecast', '9. Analysis', '10. Recommendations', 'system-generated report',
            'Evacuation Center', 'May 2026', 'Cement shortage', '₱ 10,000,000.00',
        ] as $needle) {
            $this->assertStringContainsString($needle, $xml, "Word report is missing: {$needle}");
        }
        // User text is XML-escaped, not injected
        $this->assertStringContainsString('Formworks &amp; rebars &lt;ok&gt;', $xml);
        // The S-curve is embedded as an image
        $this->assertMatchesRegularExpression('/word\/media\/[^,]+\.png/', $xml);
        // Cells wrap (PhpWord defaults to noWrap) and tables keep their widths
        $this->assertStringNotContainsString('<w:noWrap/>', $xml);
        $this->assertStringContainsString('<w:tblLayout w:type="fixed"/>', $xml);

        $this->assertDatabaseHas('activity_logs', ['project_id' => $this->project->id, 'action' => 'Export Report']);
    }

    public function test_word_report_honours_the_period_query(): void
    {
        $this->actingAs($this->admin());
        $this->inspection('2026-06', 10, 7);
        $this->inspection('2026-07', 18, 15);

        request()->query->set('period', '2026-06');
        [$response, $body] = $this->runExport(fn () => app(ReportExportController::class)->reportDocx($this->project));
        request()->query->remove('period');

        $this->assertStringContainsString('_project-report_2026-06.docx', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('June 2026', $this->documentXml($body));
    }

    public function test_contractor_can_only_export_their_own_project_report(): void
    {
        $user = User::create(['name' => 'Con', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor']);
        Contractor::create(['user_id' => $user->id, 'contractor_id' => 'CON-' . $user->id, 'company_name' => 'ACME', 'contractor_name' => 'Con']);
        $this->project->update(['contractor_id' => $user->contractor->id]);
        $other = Project::factory()->create();

        $this->actingAs($user);

        [$response] = $this->runExport(fn () => app(ReportExportController::class)->reportDocx($this->project));
        $this->assertTrue($response->isSuccessful());

        try {
            app(ReportExportController::class)->reportDocx($other);
            $this->fail('A contractor must not export another project');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_report_routes_exist_for_all_roles(): void
    {
        foreach (['admin', 'staff', 'contractor'] as $role) {
            $this->assertStringEndsWith('/reports/report.docx', route($role . '.projects.exports.report.docx', $this->project->id));
            $this->assertStringEndsWith('/reports/summary.pdf', route($role . '.projects.exports.summary', $this->project->id));
        }
    }

    // === RENDERERS ===

    public function test_scurve_png_is_a_valid_image(): void
    {
        foreach (['2026-04' => 5, '2026-05' => 12, '2026-06' => 20] as $m => $p) {
            ProjectBaselineSchedule::factory()->create([
                'project_id' => $this->project->id, 'month_label' => $m, 'planned_date' => Carbon::parse($m . '-01')->endOfMonth(),
                'planned_progress_percent' => $p, 'original_planned_progress_percent' => $p,
            ]);
        }
        $this->inspection('2026-05', 12, 9);

        $renderer = new ScurveChartRenderer();
        $chart = app(\App\Services\ProjectDetailService::class)->prepareChartData($this->project);
        $png = $renderer->png($chart, 2);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        [$w, $h] = getimagesizefromstring($png);
        $this->assertSame([1640, 680], [$w, $h]);
        $this->assertSame([1640, 680], $renderer->pngSize(2));

        // No baseline → nothing to draw
        $this->assertNull($renderer->png(['ready' => false, 'labels' => []]));
    }

    public function test_parse_to_blocks_splits_sections_paragraphs_and_lists(): void
    {
        $blocks = (new AiReportParser())->parseToBlocks(
            "[SECTION:Report]\nFirst paragraph\ncontinues here.\n\nSecond paragraph.\n[/SECTION]\n"
            . "[SECTION:Mitigation]\n- Track deliveries\n- Weekly review\n1. Numbered step\n[/SECTION]"
        );

        $this->assertSame(['Report', 'Mitigation'], array_column($blocks, 'title'));
        $this->assertSame([
            ['type' => 'paragraph', 'text' => 'First paragraph continues here.'],
            ['type' => 'paragraph', 'text' => 'Second paragraph.'],
        ], $blocks[0]['blocks']);
        $this->assertSame([
            ['type' => 'list', 'ordered' => false, 'items' => ['Track deliveries', 'Weekly review']],
            ['type' => 'list', 'ordered' => true, 'items' => ['Numbered step']],
        ], $blocks[1]['blocks']);

        // Text without markers is one "Analysis" section; empty is nothing
        $this->assertSame('Analysis', (new AiReportParser())->parseToBlocks('Plain text.')[0]['title']);
        $this->assertSame([], (new AiReportParser())->parseToBlocks('  '));
    }
}
