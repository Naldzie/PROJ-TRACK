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
}
