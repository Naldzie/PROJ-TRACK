<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\User;
use App\Services\DashboardCalendarService;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project start / due / inspection dates in Google Calendar + dashboard.
 *
 * Covers the no-Google parts deterministically (feed shape, recipients,
 * template links, .ics). Live Google pushes are never hit in tests —
 * syncProject() without consent must skip cleanly instead.
 */
class ProjectCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role) . ' User',
            'email' => $email,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeProjectWithDates(User $staff, Contractor $contractor): Project
    {
        $project = Project::factory()->create([
            'planned_start_date' => '2026-03-02',
            'planned_end_date' => '2026-09-30',
            'original_planned_start_date' => '2026-03-02',
            'original_planned_end_date' => '2026-09-30',
            'status' => 'Ongoing',
            'is_archived' => false,
            'assigned_project_manager' => (string) $staff->id,
            'contractor_id' => $contractor->id,
        ]);

        ProjectBaselineSchedule::factory()->forMonth(1, '2026-03', '2026-03-31', 10.0)->create(['project_id' => $project->id]);
        ProjectBaselineSchedule::factory()->forMonth(2, '2026-04', '2026-04-30', 25.0)->create(['project_id' => $project->id]);

        return $project->refresh();
    }

    public function test_dashboard_calendar_includes_start_due_and_inspections(): void
    {
        $staff = $this->makeUser('staff', 'cal-staff@example.com');
        $contractorUser = $this->makeUser('contractor', 'cal-contractor@example.com');
        $contractor = Contractor::create([
            'user_id' => $contractorUser->id,
            'contractor_id' => 'CTC-001',
            'company_name' => 'Cal Builders',
        ]);
        $project = $this->makeProjectWithDates($staff, $contractor);

        $events = DashboardCalendarService::forProjects(collect([$project]), 'admin');
        $kinds = collect($events)->pluck('kind')->all();

        $this->assertContains('start', $kinds);
        $this->assertContains('deadline', $kinds);
        $this->assertCount(2, collect($events)->where('kind', 'inspection'));

        $start = collect($events)->firstWhere('kind', 'start');
        $due = collect($events)->firstWhere('kind', 'deadline');

        $this->assertSame('2026-03-02', $start['date']);
        $this->assertSame('2026-09-30', $due['date']);
        $this->assertSame($project->id, $start['project_id']);
        $this->assertNotEmpty($due['detailUrl']);

        $inspections = collect($events)->where('kind', 'inspection')->sortBy('date')->values();
        $this->assertSame('2026-03-31', $inspections[0]['date']);
        $this->assertSame('2026-04-30', $inspections[1]['date']);
    }

    public function test_recipients_include_admins_assigned_staff_and_contractor(): void
    {
        $adminA = $this->makeUser('admin', 'cal-admin-a@example.com');
        $adminB = $this->makeUser('admin', 'cal-admin-b@example.com');
        $staff = $this->makeUser('staff', 'cal-pm@example.com');
        $otherStaff = $this->makeUser('staff', 'cal-other@example.com');
        $contractorUser = $this->makeUser('contractor', 'cal-co@example.com');
        $contractor = Contractor::create([
            'user_id' => $contractorUser->id,
            'contractor_id' => 'CTC-002',
            'company_name' => 'Cal Co',
        ]);
        $project = $this->makeProjectWithDates($staff, $contractor);

        $ids = app(GoogleCalendarService::class)->resolveRecipients($project)->pluck('id')->all();

        $this->assertContains($adminA->id, $ids);
        $this->assertContains($adminB->id, $ids);
        $this->assertContains($staff->id, $ids);
        $this->assertContains($contractorUser->id, $ids);
        $this->assertNotContains($otherStaff->id, $ids);
    }

    public function test_desired_events_follow_deadline_moves(): void
    {
        $staff = $this->makeUser('staff', 'cal-move@example.com');
        $contractorUser = $this->makeUser('contractor', 'cal-move-co@example.com');
        $contractor = Contractor::create([
            'user_id' => $contractorUser->id,
            'contractor_id' => 'CTC-003',
            'company_name' => 'Move Co',
        ]);
        $project = $this->makeProjectWithDates($staff, $contractor);

        $service = app(GoogleCalendarService::class);
        $before = collect($service->desiredEvents($project))->firstWhere('key', 'due');
        $this->assertSame('2026-09-30', $before['date']);

        // Extension approvals flow through Project::update() — the observer
        // re-syncs off exactly this change.
        $project->update(['planned_end_date' => '2026-12-15']);

        $after = collect($service->desiredEvents($project->refresh()))->firstWhere('key', 'due');
        $this->assertSame('2026-12-15', $after['date']);
    }

    public function test_google_template_url_format(): void
    {
        $url = GoogleCalendarService::googleTemplateUrl(
            'Due: Hall (PRJ-2026-00001)',
            '2026-09-30',
            'Details here',
            'Nabua'
        );

        $this->assertStringStartsWith('https://calendar.google.com/calendar/render?action=TEMPLATE', $url);
        $this->assertStringContainsString('dates=20260930/20261001', $url);
        $this->assertStringContainsString(rawurlencode('Due: Hall (PRJ-2026-00001)'), $url);
    }

    public function test_ics_contains_start_due_and_inspections(): void
    {
        $staff = $this->makeUser('staff', 'cal-ics@example.com');
        $contractorUser = $this->makeUser('contractor', 'cal-ics-co@example.com');
        $contractor = Contractor::create([
            'user_id' => $contractorUser->id,
            'contractor_id' => 'CTC-004',
            'company_name' => 'ICS Co',
        ]);
        $project = $this->makeProjectWithDates($staff, $contractor);

        $ics = app(GoogleCalendarService::class)->icsForProject($project);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertSame(4, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260302', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260930', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260430', $ics);
    }

    public function test_sync_without_consent_skips_cleanly(): void
    {
        $staff = $this->makeUser('staff', 'cal-skip@example.com');
        $contractorUser = $this->makeUser('contractor', 'cal-skip-co@example.com');
        $contractor = Contractor::create([
            'user_id' => $contractorUser->id,
            'contractor_id' => 'CTC-005',
            'company_name' => 'Skip Co',
        ]);
        $project = $this->makeProjectWithDates($staff, $contractor);

        // Nobody connected a calendar: every recipient skips, nothing fails,
        // nothing throws (project saves must never break on Google state).
        $summary = app(GoogleCalendarService::class)->syncProject($project);

        $this->assertSame(0, $summary['synced']);
        $this->assertSame(0, $summary['failed']);
        $this->assertGreaterThan(0, $summary['skipped']);
        $this->assertSame(0, \App\Models\ProjectCalendarEvent::count());
    }
}
