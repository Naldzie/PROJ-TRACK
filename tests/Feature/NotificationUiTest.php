<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Notifications\IssueReported;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * NotificationUiTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Phase N4 of the notification system (gaps #12) — the UI layer:
 *   - The full Notifications page is reachable for each role via its own route
 *     (admin.notifications / staff.notifications / contractor.notifications).
 *   - The dropdown recent() endpoint returns the latest unread notifications
 *     plus the unread count.
 *   - read() marks a single notification as read (owner-only).
 *   - readAll() marks every notification for the current user as read.
 *   - A user cannot mark another user's notification as read (403).
 *
 * Run With
 * --------
 *   php artisan test --filter=NotificationUiTest
 */
class NotificationUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $contractorUser;
    private Contractor $contractor;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'UI Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->staff = User::create(['name' => 'UI Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
        $this->contractorUser = User::create(['name' => 'UI Contractor', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor', 'is_active' => true]);
        $this->contractor = Contractor::create([
            'user_id'        => $this->contractorUser->id,
            'contractor_name' => 'UI Builders',
            'company_name'    => 'UI Builders Inc',
        ]);

        $this->project = Project::factory()->create([
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);
    }

    private function createNotification(User $user, array $overrides = []): object
    {
        $data = array_merge([
            'project_id'       => $this->project->id,
            'project_title'    => $this->project->name ?? 'Test Project',
            'bucket'           => '7',
            'days_remaining'   => 7,
            'planned_end_date' => $this->project->planned_end_date?->format('Y-m-d') ?? now()->addDays(7)->format('Y-m-d'),
        ], $overrides);

        $user->notify(new DeadlineReminder(
            $data['project_id'],
            $data['project_title'],
            $data['bucket'],
            $data['days_remaining'],
            $data['planned_end_date'],
        ));

        return $user->notifications()->latest('id')->firstOrFail();
    }

    // === Page access per role ===

    public function test_admin_can_view_notifications_page(): void
    {
        $this->createNotification($this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.notifications'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Mark all read');
    }

    public function test_staff_can_view_notifications_page(): void
    {
        $this->actingAs($this->staff)
            ->get(route('staff.notifications'))
            ->assertOk()
            ->assertSee('Notifications');
    }

    public function test_contractor_can_view_notifications_page(): void
    {
        $this->actingAs($this->contractorUser)
            ->get(route('contractor.notifications'))
            ->assertOk()
            ->assertSee('Notifications');
    }

    public function test_notifications_page_shows_unread_items(): void
    {
        $notif = $this->createNotification($this->staff);

        $this->actingAs($this->staff)
            ->get(route('staff.notifications'))
            ->assertOk()
            ->assertSee('1 unread')
            ->assertSee('Mark read');
    }

    // === recent() dropdown endpoint ===

    public function test_recent_returns_unread_notifications(): void
    {
        $this->createNotification($this->staff);
        $this->createNotification($this->staff);

        $response = $this->actingAs($this->staff)
            ->getJson(route('staff.notifications.recent'))
            ->assertOk()
            ->assertJson(['success' => true, 'unread_count' => 2]);

        $this->assertCount(2, $response->json('notifications'));
        $this->assertSame('Deadline Reminder', $response->json('notifications.0.type'));
    }

    public function test_recent_limits_to_five(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->createNotification($this->staff, ['days_remaining' => $i + 1]);
        }

        $response = $this->actingAs($this->staff)
            ->getJson(route('staff.notifications.recent'))
            ->assertOk();

        $this->assertCount(5, $response->json('notifications'));
    }

    // === read() single notification ===

    public function test_read_marks_notification_as_read(): void
    {
        $notif = $this->createNotification($this->staff);

        $this->actingAs($this->staff)
            ->postJson(route('staff.notifications.read', $notif->id))
            ->assertOk()
            ->assertJson(['success' => true, 'unread_count' => 0]);

        $this->assertNotNull($notif->fresh()->read_at);
    }

    public function test_read_is_owner_only(): void
    {
        $notif = $this->createNotification($this->staff);

        $this->actingAs($this->admin)
            ->postJson(route('admin.notifications.read', $notif->id))
            ->assertForbidden();

        $this->assertNull($notif->fresh()->read_at);
    }

    public function test_read_by_non_owner_does_not_leak_unread_count(): void
    {
        $notif = $this->createNotification($this->staff);

        $this->actingAs($this->admin)
            ->postJson(route('admin.notifications.read', $notif->id))
            ->assertForbidden()
            ->assertJson(['success' => false]);
    }

    // === readAll() ===

    public function test_read_all_marks_every_notification_read(): void
    {
        $this->createNotification($this->contractorUser);
        $this->createNotification($this->contractorUser);

        $this->actingAs($this->contractorUser)
            ->postJson(route('contractor.notifications.readAll'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(0, $this->contractorUser->unreadNotifications()->count());
    }

    public function test_read_all_only_affects_current_user(): void
    {
        $this->createNotification($this->staff);
        $this->createNotification($this->contractorUser);

        $this->actingAs($this->staff)
            ->postJson(route('staff.notifications.readAll'))
            ->assertOk();

        $this->assertSame(0, $this->staff->unreadNotifications()->count());
        $this->assertSame(1, $this->contractorUser->unreadNotifications()->count());
    }

    // === IssueReported payload renders ===

    public function test_issue_notification_payload_is_shown(): void
    {
        $this->contractorUser->notify(new IssueReported(
            projectId: $this->project->id,
            projectTitle: 'UI Project',
            issueId: 99,
            issueName: 'Water leakage in Block A',
            severity: 'high',
        ));

        $this->actingAs($this->contractorUser)
            ->get(route('contractor.notifications'))
            ->assertOk()
            ->assertSee('Issue reported')
            ->assertSee('Water leakage in Block A');
    }

    // === Presenter / bell / inbox filters / clear read (restudy P-5/P-6, 2026-09-21) ===

    public function test_recent_json_carries_bell_label_link_and_read_url(): void
    {
        $this->createNotification($this->admin);

        $json = $this->actingAs($this->admin)->getJson(route('admin.notifications.recent'))->assertOk()->json();

        $item = $json['notifications'][0];
        $this->assertSame('Deadline in 7 days', $item['label']);
        $this->assertStringContainsString('/projects/' . $this->project->id, $item['url']);
        $this->assertSame(route('admin.notifications.read', $item['id']), $item['read_url']);
    }

    public function test_bell_labels_a_risk_notice_correctly_not_as_an_issue(): void
    {
        $this->admin->notify(new \App\Notifications\RiskReported($this->project->id, 'UI Project', 5, 'Slope failure'));

        $json = $this->actingAs($this->admin)->getJson(route('admin.notifications.recent'))->json();

        $this->assertSame('High risk reported', $json['notifications'][0]['label']);
    }

    public function test_inbox_shows_a_monitoring_notice_with_its_detail(): void
    {
        $this->admin->notify(new \App\Notifications\ProjectNotStartedReminder($this->project->id, 'Late Start Hall', '2026-09-01', 20));

        $this->actingAs($this->admin)->get(route('admin.notifications'))
            ->assertOk()
            ->assertSee('Not yet started — Late Start Hall', false)
            ->assertSee('Planned start: Sep 01, 2026');
    }

    public function test_inbox_filters_by_unread_type_and_project(): void
    {
        $other = Project::factory()->create(['title' => 'Other Hall']);
        $this->createNotification($this->admin, ['project_title' => 'First Hall']);
        $this->createNotification($this->admin, ['project_title' => 'First Hall', 'bucket' => '14', 'days_remaining' => 14]);
        // createNotification() returns latest('id') — a random row with UUID keys; pick by data
        $this->admin->notifications()->where('data->days_remaining', 14)->firstOrFail()->markAsRead();
        $this->admin->notify(new IssueReported($other->id, 'Other Hall', 3, 'Crack', 'Low'));

        $this->actingAs($this->admin);

        $this->get(route('admin.notifications', ['status' => 'unread']))
            ->assertOk()->assertSee('Deadline in 7 days — First Hall')->assertDontSee('Deadline in 14 days');

        $this->get(route('admin.notifications', ['type' => 'issue']))
            ->assertOk()->assertSee('Crack on Other Hall')->assertDontSee('Deadline in 7 days — First Hall');

        $this->get(route('admin.notifications', ['project' => $other->id]))
            ->assertOk()->assertSee('Crack on Other Hall')->assertDontSee('Deadline in 7 days — First Hall');
    }

    public function test_clear_read_hides_read_notices_but_keeps_unread(): void
    {
        $this->createNotification($this->admin);
        $this->createNotification($this->admin, ['bucket' => '14', 'days_remaining' => 14]);
        $this->admin->notifications()->where('data->days_remaining', 14)->firstOrFail()->markAsRead();

        $this->actingAs($this->admin)->postJson(route('admin.notifications.clearRead'))
            ->assertOk()->assertJson(['success' => true, 'cleared' => 1]);

        $this->get(route('admin.notifications'))->assertSee('Deadline in 7 days')->assertDontSee('Deadline in 14 days');
        $this->assertSame(2, $this->admin->notifications()->count(), 'rows are kept, only hidden');
    }

    public function test_a_cleared_notice_is_not_resent_by_the_next_scan(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-09-21 07:00:00'));
        Project::factory()->create(['status' => 'Planned', 'planned_start_date' => '2026-09-01']);

        // Count only this type — setUp's factory project has random dates and may
        // draw its own deadline / inspection notices
        $notStarted = fn () => $this->admin->notifications()
            ->where('type', \App\Notifications\ProjectNotStartedReminder::class)->count();

        $this->artisan('notifications:scan')->run();
        $this->assertSame(1, $notStarted());

        $this->admin->unreadNotifications()->update(['read_at' => now()]);
        $this->actingAs($this->admin)->postJson(route('admin.notifications.clearRead'))->assertOk();
        $this->artisan('notifications:scan')->run();

        $this->assertSame(1, $notStarted(), 'dedupe still sees the cleared row');
        $this->assertSame(0, $this->admin->notifications()->whereNull('cleared_at')
            ->where('type', \App\Notifications\ProjectNotStartedReminder::class)->count(), 'and it stays hidden');
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_clear_read_only_touches_the_current_user(): void
    {
        $mine = $this->createNotification($this->admin);
        $theirs = $this->createNotification($this->staff);
        $mine->markAsRead();
        $theirs->markAsRead();

        $this->actingAs($this->admin)->postJson(route('admin.notifications.clearRead'))->assertOk();

        $this->assertNull($theirs->fresh()->cleared_at);
        $this->assertNotNull($mine->fresh()->cleared_at);
    }
}
