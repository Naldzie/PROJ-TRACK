<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectExtension;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use App\Services\ProjectOverviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ============================================================================
 * OverviewTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Phase B — Overview Real Data:
 *   - ProjectOverviewService::getOpenIssues() — top 3 by severity + totals
 *   - ProjectOverviewService::getAlerts() — 4 derived alert signals
 *   - ProjectOverviewService::getRecentActivity() — project-scoped timeline
 *   - activity_logs.project_id is populated by the project-scoped log calls
 *   - overview.blade.php renders without any hardcoded "Mockup" content
 *
 * Conventions
 * -----------
 *   - Services invoked DIRECTLY (route-based auth does not authenticate in
 *     this test environment — see Phase A/B plan §8).
 *   - Users created via User::create() (User::factory() sets email_verified_at
 *     which the users table lacks).
 *
 * Run With
 * --------
 *   php artisan test --filter=OverviewTest
 */
class OverviewTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'  => 'Overview Admin',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'admin',
        ]);

        $this->actingAs($this->admin);

        $this->project = Project::factory()->create(['status' => 'Ongoing']);
    }

    private function service(): ProjectOverviewService
    {
        return app(ProjectOverviewService::class);
    }

    private function baseline(string $monthLabel, string $plannedDate, float $pct): ProjectBaselineSchedule
    {
        return ProjectBaselineSchedule::create([
            'project_id'                       => $this->project->id,
            'month_label'                      => $monthLabel,
            'planned_date'                     => Carbon::parse($plannedDate)->format('Y-m-d'),
            'planned_progress_percent'         => $pct,
            'original_planned_progress_percent'=> $pct,
        ]);
    }

    private function openIssue(string $name, string $severity, int $daysOld = 0): Issue
    {
        $issue = Issue::create([
            'project_id' => $this->project->id,
            'issue_name' => $name,
            'description' => 'Test issue',
            'status'      => 'Open',
            'severity'    => $severity,
        ]);

        if ($daysOld > 0) {
            $issue->forceFill(['created_at' => now()->subDays($daysOld)])->save();
        }

        return $issue;
    }

    private function pendingExtension(int $number): ProjectExtension
    {
        return ProjectExtension::create([
            'project_id'       => $this->project->id,
            'extension_number' => $number,
            'original_end_date' => now()->addMonths(6)->toDateString(),
            'new_end_date'     => now()->addMonths(9)->toDateString(),
            'duration_days'    => 90,
            'reason'           => 'Test extension request reason',
            'status'           => 'pending',
        ]);
    }

    private function activityLog(string $action, array $overrides = [], int $minutesAgo = 0): ActivityLog
    {
        $log = ActivityLog::create(array_merge([
            'user_id'     => $this->admin->id,
            'project_id'  => $this->project->id,
            'action'      => $action,
            'description' => 'Test activity ' . $action,
            'ip_address'  => '127.0.0.1',
            'visibility'  => 'all',
        ], $overrides));

        if ($minutesAgo > 0) {
            $log->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
        }

        return $log;
    }

    // === getOpenIssues ===

    public function test_get_open_issues_returns_top3_by_severity_plus_total(): void
    {
        $this->openIssue('Minor', 'Low');
        $this->openIssue('Major', 'High');
        $this->openIssue('Critical one', 'Critical');
        $this->openIssue('Medium one', 'Medium');
        $this->openIssue('Other critical', 'Critical');

        $result = $this->service()->getOpenIssues($this->project);

        $this->assertEquals(5, $result['total_open']);
        $this->assertEquals('Critical', $result['worst_severity']);
        $this->assertCount(3, $result['items']);
        $this->assertEquals('Critical', $result['items'][0]['severity']);
        $this->assertEquals('Critical', $result['items'][1]['severity']);
        $this->assertEquals('High', $result['items'][2]['severity']);
        $this->assertArrayHasKey('days_open', $result['items'][0]);
        $this->assertArrayHasKey('badge_color', $result['items'][0]);
    }

    public function test_get_open_issues_ignores_resolved_and_closed(): void
    {
        $this->openIssue('Open one', 'Critical');
        Issue::create([
            'project_id' => $this->project->id,
            'issue_name' => 'Resolved one',
            'description' => 'Done',
            'status'      => 'Resolved',
            'severity'    => 'Critical',
        ]);
        Issue::create([
            'project_id' => $this->project->id,
            'issue_name' => 'Closed one',
            'description' => 'Done',
            'status'      => 'Closed',
            'severity'    => 'High',
        ]);

        $result = $this->service()->getOpenIssues($this->project);

        $this->assertEquals(1, $result['total_open']);
    }

    public function test_get_open_issues_counts_aging_issues(): void
    {
        $this->openIssue('Recent', 'Low', 2);
        $this->openIssue('Aging one', 'Medium', 40);
        $this->openIssue('Very old', 'High', 90);

        $result = $this->service()->getOpenIssues($this->project);

        $this->assertEquals(2, $result['aging_count']);
        $this->assertEquals(3, $result['total_open']);
    }

    // === getAlerts ===

    public function test_alerts_next_pending_month_when_baseline_unrecorded(): void
    {
        $this->baseline('2026-08', '2026-08-31', 10);
        $this->baseline('2026-09', '2026-09-30', 25);

        $alerts = collect($this->service()->getAlerts($this->project));

        $pending = $alerts->firstWhere('type', 'inspection_pending');
        $this->assertNotNull($pending);
        $this->assertStringContainsString('Aug 2026', $pending['title']);
    }

    public function test_alerts_no_pending_month_once_all_baselines_recorded(): void
    {
        $baseline = $this->baseline('2026-08', '2026-08-31', 10);
        ProjectProgressHistory::factory()->create([
            'project_id' => $this->project->id,
            'month_label' => '2026-08',
            'actual_progress_percent' => 10,
            'inspection_date' => '2026-08-15',
        ]);

        $alerts = collect($this->service()->getAlerts($this->project));

        $this->assertNull($alerts->firstWhere('type', 'inspection_pending'));
    }

    public function test_alerts_pending_month_suppressed_for_completed_project(): void
    {
        $this->baseline('2026-08', '2026-08-31', 10);
        $this->project->update(['status' => 'Completed']);

        $alerts = collect($this->service()->getAlerts($this->project));

        $this->assertNull($alerts->firstWhere('type', 'inspection_pending'));
    }

    public function test_alerts_open_issues_with_worst_severity(): void
    {
        $this->openIssue('Minor', 'Low');
        $this->openIssue('Critical', 'Critical');

        $alerts = collect($this->service()->getAlerts($this->project));

        $issueAlert = $alerts->firstWhere('type', 'open_issues');
        $this->assertNotNull($issueAlert);
        $this->assertStringContainsString('2 open issues', $issueAlert['title']);
        $this->assertStringContainsString('Critical', $issueAlert['title']);
        $this->assertEquals('var(--red)', $issueAlert['color']);
    }

    public function test_alerts_pending_extensions(): void
    {
        $this->pendingExtension(1);
        $this->pendingExtension(2);

        $alerts = collect($this->service()->getAlerts($this->project));

        $extensionAlert = $alerts->firstWhere('type', 'pending_extensions');
        $this->assertNotNull($extensionAlert);
        $this->assertStringContainsString('2 extension requests', $extensionAlert['title']);
    }

    public function test_alerts_document_coverage_reminder_when_zero_documents(): void
    {
        $alerts = collect($this->service()->getAlerts($this->project));

        $this->assertNotNull($alerts->firstWhere('type', 'document_coverage'));
    }

    public function test_alerts_empty_when_no_signals(): void
    {
        $this->baseline('2026-08', '2026-08-31', 10);
        ProjectProgressHistory::factory()->create([
            'project_id' => $this->project->id,
            'month_label' => '2026-08',
            'actual_progress_percent' => 10,
            'inspection_date' => '2026-08-15',
        ]);
        $documentType = \App\Models\DocumentType::create(['name' => 'Report']);
        \App\Models\Document::create([
            'project_id'       => $this->project->id,
            'document_type_id' => $documentType->id,
            'document_name'    => 'report.pdf',
            'file_path'        => 'projects/documents/' . $this->project->code . '/report.pdf',
        ]);

        $alerts = collect($this->service()->getAlerts($this->project));

        $this->assertCount(0, $alerts);
    }

    // === getRecentActivity ===

    public function test_recent_activity_returns_latest_six_project_scoped(): void
    {
        ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Create Project')
            ->delete();

        for ($i = 1; $i <= 8; $i++) {
            $this->activityLog('Action ' . $i, [], 8 - $i);
        }
        ActivityLog::create([
            'user_id'     => $this->admin->id,
            'action'      => 'Other project action',
            'description' => 'Should not appear',
            'visibility'  => 'all',
        ]);

        $activity = $this->service()->getRecentActivity($this->project);

        $this->assertCount(6, $activity);
        $this->assertEquals('Action 8', $activity->first()->action);
        $this->assertEquals('Action 3', $activity->last()->action);
        $this->assertEquals($this->admin->name, $activity->first()->user->name);
        $this->assertTrue($activity->every(fn ($log) => (int) $log->project_id === (int) $this->project->id));
    }

    public function test_recent_activity_excludes_admin_only_for_non_admin_role(): void
    {
        ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Create Project')
            ->delete();

        $this->activityLog('Visible action');
        $this->activityLog('Hidden action', ['visibility' => 'admin_only']);

        $staff = User::create([
            'name'  => 'Overview Staff',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'staff',
        ]);
        $this->actingAs($staff);

        $activity = $this->service()->getRecentActivity($this->project);

        $this->assertCount(1, $activity);
        $this->assertEquals('Visible action', $activity->first()->action);
    }

    // === project_id population ===

    public function test_activity_log_project_id_populated_on_monthly_progress_record(): void
    {
        $this->baseline('2026-08', '2026-08-31', 10);
        $this->baseline('2026-09', '2026-09-30', 25);

        $request = Request::create('/progress', 'POST', [
            'inspection_date'         => '2026-08-15',
            'month_label'             => '2026-08',
            'actual_progress_percent' => 10,
            'remarks'                 => null,
        ]);

        $response = app(\App\Http\Controllers\ProjectProgressController::class)
            ->recordMonthlyProgress($request, $this->project);

        $this->assertTrue($response->getData()->success);

        $log = ActivityLog::where('action', 'Record Monthly Progress')
            ->where('project_id', $this->project->id)
            ->first();

        $this->assertNotNull($log, 'Monthly progress should create a project-scoped activity log');
    }

    public function test_activity_log_project_id_populated_on_document_upload(): void
    {
        Storage::fake('public');

        $documentType = \App\Models\DocumentType::create(['name' => 'Report']);

        $request = Request::create('/documents/upload', 'POST', [
            'project_id'       => $this->project->id,
            'document_type_id' => $documentType->id,
            'description'      => 'Quarterly report',
        ]);
        $request->files->set('file', UploadedFile::fake()->createWithContent(
            'quarterly-report.pdf',
            "%PDF-1.4\n%test\ntrailer<</Root 1 0 R>>\n%%EOF",
            'application/pdf'
        ));

        $response = app(\App\Http\Controllers\DocumentController::class)->upload($request);

        $this->assertTrue($response->getData()->success);

        $log = ActivityLog::where('action', 'Upload Document')
            ->where('project_id', $this->project->id)
            ->first();

        $this->assertNotNull($log, 'Document upload should create a project-scoped activity log');
        $this->assertEquals($this->admin->id, $log->user_id);
        $this->assertStringContainsString('quarterly-report.pdf', $log->description);
    }

    // === view ===

    public function test_overview_view_has_no_mockup_strings(): void
    {
        $data = [
            'overview' => [
                'progress' => 10.0,
                'totalActivities' => 0,
                'completedActivities' => 0,
                'activityCompletionRate' => 0,
                'latestProgressValue' => null,
                'latestProgressDate' => null,
            ],
            'status' => [
                'planned_percent' => 10,
                'actual_percent' => 10,
                'variance' => 0,
                'message' => 'On track',
            ],
            'ai_prediction' => [],
            'documents' => collect(),
            'documents_by_type' => collect(),
            'total_documents' => 0,
            'recent_activity' => collect(),
            'alerts' => [],
            'open_issues' => ['total_open' => 0, 'worst_severity' => null, 'aging_count' => 0, 'items' => []],
        ];

        $html = view('projects.project_detail.components.tabs.overview', [
            'project' => $this->project,
            'data' => $data,
        ])->render();

        $this->assertStringNotContainsString('Mockup', $html);
        $this->assertStringContainsString('Issues & Risks', $html);
        $this->assertStringContainsString('Notifications & Alerts', $html);
        $this->assertStringContainsString('Recent Activity', $html);
    }
}
