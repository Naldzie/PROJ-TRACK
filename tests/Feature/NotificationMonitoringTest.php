<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectExtension;
use App\Models\User;
use App\Models\Warranty;
use App\Notifications\IssueAgingReminder;
use App\Notifications\PendingExtensionReminder;
use App\Notifications\ProjectNotStartedReminder;
use App\Notifications\ProjectUpdateNotice;
use App\Notifications\WarrantyReminder;
use App\Services\ProjectOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ============================================================================
 * NotificationMonitoringTest
 * ============================================================================
 *
 * The in-app-only monitoring notices added 2026-09-21
 * (textfiles/notification_monitoring_restudy_plan.md, P-7..P-10, user decision
 * N-D4): audience is admins + the ASSIGNED staff, never the contractor, and
 * never the person who caused the event.
 *
 *   Scan  — WarrantyReminder, ProjectNotStartedReminder,
 *           PendingExtensionReminder (30+ days), IssueAgingReminder (30+ days)
 *   Event — ProjectUpdateNotice: issue resolved, assignment, original baseline
 *           revised, AI report staged for review
 *   Page  — the project-page alerts for not-started / warranty
 *
 * Run: php artisan test --filter=NotificationMonitoringTest
 */
class NotificationMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $otherStaff;
    private User $contractorUser;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Mon Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->staff = User::create(['name' => 'Mon Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
        $this->otherStaff = User::create(['name' => 'Other Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
        $this->contractorUser = User::create(['name' => 'Mon Contractor', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor', 'is_active' => true]);
        $this->contractor = Contractor::create([
            'user_id'         => $this->contractorUser->id,
            'contractor_name' => 'Mon Builders',
            'company_name'    => 'Mon Builders Inc',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // === HELPERS ===

    private function runScan(): void
    {
        $this->artisan('notifications:scan')->run();
    }

    private function project(array $attrs = []): Project
    {
        return Project::factory()->create(array_merge([
            'status'                   => 'Ongoing',
            'contractor_id'            => $this->contractor->id,
            'assigned_project_manager' => (string) $this->staff->id,
        ], $attrs));
    }

    /** Count of one notification class for a user. */
    private function countFor(User $user, string $class): int
    {
        return $user->notifications()->where('type', $class)->count();
    }

    /** Admin + assigned staff got exactly $n; contractor and other staff got none. */
    private function assertInternalOnly(string $class, int $n = 1): void
    {
        $this->assertSame($n, $this->countFor($this->admin, $class), 'admin');
        $this->assertSame($n, $this->countFor($this->staff, $class), 'assigned staff');
        $this->assertSame(0, $this->countFor($this->otherStaff, $class), 'unassigned staff');
        $this->assertSame(0, $this->countFor($this->contractorUser, $class), 'contractor');
    }

    // === WARRANTY ===

    public function test_warranty_expiring_within_30_days_notifies_admins_and_assigned_staff_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00'));
        $project = $this->project(['status' => 'Completed']);
        Warranty::create([
            'project_id' => $project->id, 'contractor_id' => $this->contractor->id,
            'title' => 'W', 'start_date' => '2026-01-01', 'file_path' => 'warranties/test.pdf', 'end_date' => '2026-10-10', 'status' => 'Active',
        ]);

        $this->runScan();
        $this->runScan();

        $this->assertInternalOnly(WarrantyReminder::class);
        $row = $this->admin->notifications()->first();
        $this->assertSame('expiring', $row->data['kind']);
        $this->assertSame(19, $row->data['days']);
    }

    public function test_warranty_far_from_expiry_sends_nothing_and_expired_sends_once_more(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 07:00:00'));
        $project = $this->project(['status' => 'Completed']);
        Warranty::create([
            'project_id' => $project->id, 'contractor_id' => $this->contractor->id,
            'title' => 'W', 'start_date' => '2026-01-01', 'file_path' => 'warranties/test.pdf', 'end_date' => '2026-09-01', 'status' => 'Active',
        ]);

        $this->runScan();
        $this->assertSame(0, $this->countFor($this->admin, WarrantyReminder::class), '92 days out → nothing');

        Carbon::setTestNow(Carbon::parse('2026-09-05 07:00:00'));
        $this->runScan();
        $this->assertSame(1, $this->countFor($this->admin, WarrantyReminder::class));
        $this->assertSame('expired', $this->admin->notifications()->first()->data['kind']);
    }

    public function test_warranty_inspection_due_within_14_days_notifies_but_a_passed_date_does_not(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00'));
        $project = $this->project(['status' => 'Completed']);
        Warranty::create([
            'project_id' => $project->id, 'contractor_id' => $this->contractor->id, 'title' => 'W',
            'start_date' => '2026-01-01', 'file_path' => 'warranties/test.pdf', 'end_date' => '2027-09-01', 'mid_year_inspection' => '2026-09-01',
            'final_inspection' => '2026-09-30', 'status' => 'Active',
        ]);

        $this->runScan();

        $kinds = $this->admin->notifications()->get()->pluck('data.kind')->all();
        $this->assertSame(['final'], $kinds, 'only the final inspection (9 days out); the passed mid-year date sends nothing');
    }

    public function test_warranty_record_page_shows_the_due_and_expiring_pills(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00'));
        $project = $this->project(['status' => 'Completed']);
        Warranty::create([
            'project_id' => $project->id, 'contractor_id' => $this->contractor->id, 'title' => 'W',
            'start_date' => '2026-01-01', 'file_path' => 'warranties/test.pdf', 'end_date' => '2026-10-10',
            'mid_year_inspection' => '2026-09-01', 'final_inspection' => '2026-09-30', 'status' => 'Active',
        ]);

        $this->actingAs($this->admin)->get(route('admin.warranty.preview'))
            ->assertOk()
            ->assertSee('Warranty ends in 19 days')
            ->assertSee('Inspection in 9 days')
            ->assertSee('Date passed');
    }

    // === NOT YET STARTED ===

    public function test_planned_project_past_its_start_date_notifies_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00'));
        $this->project(['status' => 'Planned', 'planned_start_date' => '2026-09-01']);
        $this->project(['status' => 'Planned', 'planned_start_date' => '2026-10-01']); // future
        $this->project(['status' => 'Ongoing', 'planned_start_date' => '2026-08-01']); // started
        $this->project(['status' => 'Planned', 'planned_start_date' => '2026-08-01', 'is_archived' => true]);

        $this->runScan();
        $this->runScan();

        $this->assertInternalOnly(ProjectNotStartedReminder::class);
        $this->assertSame(20, $this->admin->notifications()->where('type', ProjectNotStartedReminder::class)->first()->data['days_late']);
    }

    public function test_moving_the_start_date_realerts_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00'));
        $project = $this->project(['status' => 'Planned', 'planned_start_date' => '2026-09-01']);
        $this->runScan();

        $project->update(['planned_start_date' => '2026-09-10']);
        $this->runScan();

        $this->assertSame(2, $this->countFor($this->admin, ProjectNotStartedReminder::class));
    }

    public function test_project_page_shows_the_not_started_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00'));
        $late = $this->project(['status' => 'Planned', 'planned_start_date' => '2026-09-01']);
        $onTime = $this->project(['status' => 'Planned', 'planned_start_date' => '2026-10-01']);

        $types = fn (Project $p) => array_column(app(ProjectOverviewService::class)->getAlerts($p), 'type');

        $this->assertContains('not_started', $types($late));
        $this->assertNotContains('not_started', $types($onTime));
    }

    public function test_projects_list_shows_the_not_yet_started_badge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00'));
        $this->project(['status' => 'Planned', 'planned_start_date' => '2026-09-01', 'title' => 'Late Hall']);

        $this->actingAs($this->admin)->get(route('admin.projects'))
            ->assertOk()
            ->assertSee('Not yet started')
            ->assertSee('Planned start Sep 01, 2026 has passed', false);
    }

    public function test_projects_list_has_no_badge_for_a_future_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00'));
        $this->project(['status' => 'Planned', 'planned_start_date' => '2026-10-01', 'title' => 'Future Hall']);

        $this->actingAs($this->admin)->get(route('admin.projects'))
            ->assertOk()
            ->assertDontSee('Not yet started');
    }

    public function test_project_page_shows_a_warranty_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00'));
        $project = $this->project(['status' => 'Completed']);
        Warranty::create([
            'project_id' => $project->id, 'contractor_id' => $this->contractor->id,
            'title' => 'W', 'start_date' => '2026-01-01', 'file_path' => 'warranties/test.pdf', 'end_date' => '2026-10-01', 'status' => 'Active',
        ]);

        $alerts = collect(app(ProjectOverviewService::class)->getAlerts($project))->keyBy('type');

        $this->assertTrue($alerts->has('warranty_expiry'));
        $this->assertSame('Warranty ends in 10 days', $alerts['warranty_expiry']['title']);
    }

    // === PENDING EXTENSION ===

    public function test_extension_pending_a_month_notifies_and_again_at_two_months(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00:00'));
        $project = $this->project();
        ProjectExtension::factory()->create(['project_id' => $project->id, 'status' => 'pending']);
        ProjectExtension::factory()->create(['project_id' => $project->id, 'status' => 'approved']);

        Carbon::setTestNow(Carbon::parse('2026-08-20 07:00:00'));
        $this->runScan();
        $this->assertSame(0, $this->countFor($this->admin, PendingExtensionReminder::class), '19 days → nothing yet');

        Carbon::setTestNow(Carbon::parse('2026-09-02 07:00:00'));
        $this->runScan();
        $this->runScan();
        $this->assertInternalOnly(PendingExtensionReminder::class);

        Carbon::setTestNow(Carbon::parse('2026-10-01 07:00:00'));
        $this->runScan();
        $this->assertSame(2, $this->countFor($this->admin, PendingExtensionReminder::class), '60 days → second reminder');
    }

    // === ISSUE AGING ===

    public function test_issue_open_30_days_notifies_once_resolved_or_young_issues_do_not(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00:00'));
        $project = $this->project();
        Issue::factory()->create(['project_id' => $project->id, 'status' => 'Open', 'issue_name' => 'Old leak']);
        Issue::factory()->create(['project_id' => $project->id, 'status' => 'Resolved', 'resolution_notes' => 'fixed']);

        Carbon::setTestNow(Carbon::parse('2026-08-25 09:00:00'));
        Issue::factory()->create(['project_id' => $project->id, 'status' => 'In Progress']);

        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00'));
        $this->runScan();
        $this->runScan();

        $this->assertInternalOnly(IssueAgingReminder::class);
        $row = $this->admin->notifications()->where('type', IssueAgingReminder::class)->first();
        $this->assertSame('Old leak', $row->data['issue_name']);
        $this->assertSame(35, $row->data['days_open']);
    }

    // === EVENT NOTICES ===

    public function test_resolving_an_issue_notifies_admins_and_staff_but_not_the_resolver(): void
    {
        $project = $this->project();
        $issue = Issue::factory()->create(['project_id' => $project->id, 'status' => 'Open']);

        $this->actingAs($this->admin)->putJson(route('admin.projects.issues.update', [$project->id, $issue->id]), [
            'issue_name'       => $issue->issue_name,
            'description'      => $issue->description,
            'status'           => 'Resolved',
            'resolution_notes' => 'Patched the roof.',
        ])->assertOk();

        $this->assertSame(0, $this->countFor($this->admin, ProjectUpdateNotice::class), 'the resolver is not told');
        $this->assertSame(1, $this->countFor($this->staff, ProjectUpdateNotice::class));
        $this->assertSame(0, $this->countFor($this->contractorUser, ProjectUpdateNotice::class));
        $data = $this->staff->notifications()->first()->data;
        $this->assertSame(ProjectUpdateNotice::ISSUE_RESOLVED, $data['kind']);
        $this->assertSame('Patched the roof.', $data['detail']);
    }

    public function test_editing_an_issue_without_resolving_it_sends_no_update_notice(): void
    {
        $project = $this->project();
        $issue = Issue::factory()->create(['project_id' => $project->id, 'status' => 'Open']);

        $this->actingAs($this->admin)->putJson(route('admin.projects.issues.update', [$project->id, $issue->id]), [
            'issue_name'  => $issue->issue_name,
            'description' => $issue->description,
            'status'      => 'In Progress',
        ])->assertOk();

        $this->assertSame(0, $this->countFor($this->staff, ProjectUpdateNotice::class));
    }

    public function test_ai_report_staged_for_review_notifies_admins_and_staff(): void
    {
        $project = $this->project();
        \App\Models\AiSolution::factory()->create(['project_id' => $project->id]);

        $result = app(\App\Services\AIReportSendingService::class)->stageProjectForReview($project);

        $this->assertTrue($result['success']);
        $this->assertInternalOnly(ProjectUpdateNotice::class);
        $this->assertSame(ProjectUpdateNotice::AI_REPORT_REVIEW, $this->admin->notifications()->first()->data['kind']);
    }

    public function test_changing_the_assigned_staff_notifies_the_new_staff(): void
    {
        $project = $this->project(['assigned_project_manager' => (string) $this->otherStaff->id]);

        // Only the assignment path is exercised here (the controller's private hook
        // is reached through update() in the browser; this mirrors its call)
        $project->update(['assigned_project_manager' => (string) $this->staff->id]);
        $this->actingAs($this->admin);
        $method = new \ReflectionMethod(\App\Http\Controllers\ProjectController::class, 'notifyAssignmentChanges');
        $method->setAccessible(true);
        $method->invoke(app(\App\Http\Controllers\ProjectController::class), $project->fresh(), (string) $this->otherStaff->id, $this->contractor->id);

        $this->assertSame(1, $this->countFor($this->staff, ProjectUpdateNotice::class), 'newly assigned staff');
        $this->assertSame(0, $this->countFor($this->admin, ProjectUpdateNotice::class), 'the admin did it');
        $this->assertSame(0, $this->countFor($this->otherStaff, ProjectUpdateNotice::class), 'previous staff no longer assigned');
        $this->assertStringContainsString('assigned as project staff', $this->staff->notifications()->first()->data['message']);
    }

    public function test_unchanged_assignment_sends_nothing(): void
    {
        $project = $this->project();
        $this->actingAs($this->admin);
        $method = new \ReflectionMethod(\App\Http\Controllers\ProjectController::class, 'notifyAssignmentChanges');
        $method->setAccessible(true);
        $method->invoke(app(\App\Http\Controllers\ProjectController::class), $project, (string) $this->staff->id, $this->contractor->id);

        $this->assertSame(0, $this->countFor($this->staff, ProjectUpdateNotice::class));
    }

    public function test_monitoring_notices_are_in_app_only(): void
    {
        foreach ([WarrantyReminder::class, ProjectNotStartedReminder::class, PendingExtensionReminder::class,
                  IssueAgingReminder::class, ProjectUpdateNotice::class] as $class) {
            $this->assertSame(['database'], (new \ReflectionClass($class))->newInstanceWithoutConstructor()->via($this->admin), $class);
        }
    }
}
