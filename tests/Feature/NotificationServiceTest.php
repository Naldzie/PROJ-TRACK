<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contractor;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectExtension;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Notifications\ExtensionStatusChanged;
use App\Notifications\IssueReported;
use App\Notifications\OverdueInspectionReminder;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

/**
 * ============================================================================
 * NotificationServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Phase N1 of the notification system (gaps #12):
 *   - notifyUnique() dedupes by type + tracking key per user.
 *   - notifyMany() fans out while still deduping per user.
 *   - Audience resolvers (admins, allStaff, contractorUserFor) resolve correctly.
 *   - Inactive users are never notified.
 *   - All four notification classes produce the expected database payload
 *     (key + contextual fields).
 *   - The extension submitter column is persisted on creation.
 *
 * Run With
 * --------
 *   php artisan test --filter=NotificationServiceTest
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NotificationService::class);
    }

    private function makeUser(string $role, bool $active = true): User
    {
        return User::create([
            'name'      => "{$role} " . fake()->unique()->name(),
            'email'     => fake()->unique()->safeEmail(),
            'role'      => $role,
            'is_active' => $active,
        ]);
    }

    // === notifyUnique ===

    public function test_notify_unique_creates_one_notification(): void
    {
        $user = $this->makeUser('staff');

        $delivered = $this->service->notifyUnique(
            $user,
            new DeadlineReminder(1, 'Test Project', '30', 25, '2026-12-31')
        );

        $this->assertTrue($delivered);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, $user->unreadNotifications()->count());
    }

    public function test_notify_unique_skips_duplicate_key(): void
    {
        $user = $this->makeUser('staff');

        $this->service->notifyUnique(
            $user,
            new DeadlineReminder(1, 'Test Project', '30', 25, '2026-12-31')
        );
        $delivered = $this->service->notifyUnique(
            $user,
            new DeadlineReminder(1, 'Test Project', '30', 24, '2026-12-31')
        );

        $this->assertFalse($delivered);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_notify_unique_different_bucket_creates_new_notification(): void
    {
        $user = $this->makeUser('staff');

        $this->service->notifyUnique(
            $user,
            new DeadlineReminder(1, 'Test Project', '30', 25, '2026-12-31')
        );
        $delivered = $this->service->notifyUnique(
            $user,
            new DeadlineReminder(1, 'Test Project', '14', 12, '2026-12-31')
        );

        $this->assertTrue($delivered);
        $this->assertSame(2, $user->notifications()->count());
    }

    public function test_notify_unique_same_key_for_different_users_both_notified(): void
    {
        $a = $this->makeUser('admin');
        $b = $this->makeUser('staff');

        $this->service->notifyUnique($a, new IssueReported(1, 'P', 9, 'X', 'High'));
        $this->service->notifyUnique($b, new IssueReported(1, 'P', 9, 'X', 'High'));

        $this->assertSame(1, $a->notifications()->count());
        $this->assertSame(1, $b->notifications()->count());
    }

    public function test_notify_unique_ignores_inactive_user(): void
    {
        $user = $this->makeUser('staff', active: false);

        $delivered = $this->service->notifyUnique($user, new IssueReported(1, 'P', 9, 'X', 'High'));

        $this->assertFalse($delivered);
        $this->assertSame(0, $user->notifications()->count());
    }

    // === notifyMany ===

    public function test_notify_many_fans_out_with_dedupe(): void
    {
        $admins = collect([$this->makeUser('admin'), $this->makeUser('admin')]);
        $project = Project::factory()->create();

        $delivered = $this->service->notifyMany(
            $admins,
            new ExtensionStatusChanged($project->id, 'P', 3, 1, ExtensionStatusChanged::SUBMITTED)
        );

        $this->assertSame(2, $delivered);
        $this->assertSame(1, $admins->get(0)->notifications()->count());
        $this->assertSame(1, $admins->get(1)->notifications()->count());
        // The admin ActivityLog entry (NotificationService) carries a real project id.
        $this->assertSame(1, ActivityLog::where('project_id', $project->id)->count());
    }

    // === Audience resolvers ===

    public function test_admins_resolver_returns_only_active_admins(): void
    {
        $this->makeUser('admin');
        $this->makeUser('admin', active: false);
        $this->makeUser('staff');
        $this->makeUser('contractor');

        $admins = $this->service->admins();

        $this->assertCount(1, $admins);
        $this->assertSame('admin', $admins->first()->role);
    }

    public function test_all_staff_resolver_returns_only_active_staff(): void
    {
        $this->makeUser('staff');
        $this->makeUser('staff', active: false);
        $this->makeUser('admin');

        $staff = $this->service->allStaff();

        $this->assertCount(1, $staff);
        $this->assertSame('staff', $staff->first()->role);
    }

    public function test_contractor_user_for_returns_project_contractor_user(): void
    {
        $contractorUser = $this->makeUser('contractor');
        $contractor = Contractor::create([
            'user_id'        => $contractorUser->id,
            'contractor_name' => 'ACME Builders',
            'company_name'    => 'ACME Builders Inc',
        ]);
        $project = Project::factory()->create(['contractor_id' => $contractor->id]);

        $result = $this->service->contractorUserFor($project);

        $this->assertNotNull($result);
        $this->assertSame($contractorUser->id, $result->id);
    }

    public function test_contractor_user_for_returns_null_when_no_contractor(): void
    {
        $project = Project::factory()->create();

        $this->assertNull($this->service->contractorUserFor($project));
    }

    public function test_contractor_user_for_returns_null_for_inactive_contractor(): void
    {
        $contractorUser = $this->makeUser('contractor', active: false);
        $contractor = Contractor::create([
            'user_id'        => $contractorUser->id,
            'contractor_name' => 'ACME Builders',
            'company_name'    => 'ACME Builders Inc',
        ]);
        $project = Project::factory()->create(['contractor_id' => $contractor->id]);

        $this->assertNull($this->service->contractorUserFor($project));
    }

    // === Notification payloads ===

    public function test_deadline_reminder_payload(): void
    {
        $user = $this->makeUser('staff');
        $notification = new DeadlineReminder(7, 'Bridge', 'overdue', -2, '2026-08-01');

        $payload = $notification->toDatabase($user);

        $this->assertSame('deadline:7:overdue', $payload['key']);
        $this->assertSame(7, $payload['project_id']);
        $this->assertSame('Bridge', $payload['project_title']);
        $this->assertSame('overdue', $payload['bucket']);
        $this->assertSame(-2, $payload['days_remaining']);
        $this->assertSame('2026-08-01', $payload['planned_end_date']);
    }

    public function test_overdue_inspection_reminder_payload(): void
    {
        $user = $this->makeUser('staff');
        $notification = new OverdueInspectionReminder(7, 'Bridge', '2026-07', 'July-2026', '2026-07-31');

        $payload = $notification->toDatabase($user);

        $this->assertSame('overdue_inspection:7:2026-07', $payload['key']);
        $this->assertSame('2026-07', $payload['month_label']);
        $this->assertSame('July-2026', $payload['month_display']);
        $this->assertSame('2026-07-31', $payload['planned_date']);
    }

    public function test_extension_status_changed_payload(): void
    {
        $user = $this->makeUser('staff');
        $notification = new ExtensionStatusChanged(
            7, 'Bridge', 3, 1, ExtensionStatusChanged::REJECTED, 'Insufficient justification'
        );

        $payload = $notification->toDatabase($user);

        $this->assertSame('extension:7:3:rejected', $payload['key']);
        $this->assertSame('rejected', $payload['status']);
        $this->assertSame('Insufficient justification', $payload['rejection_reason']);
    }

    public function test_issue_reported_payload(): void
    {
        $user = $this->makeUser('staff');
        $notification = new IssueReported(7, 'Bridge', 12, 'Crack found', 'Critical');

        $payload = $notification->toDatabase($user);

        $this->assertSame('issue:7:12', $payload['key']);
        $this->assertSame('Crack found', $payload['issue_name']);
        $this->assertSame('Critical', $payload['severity']);
    }

    public function test_extension_submitter_is_persisted(): void
    {
        $staff = $this->makeUser('staff');
        $project = Project::factory()->create();

        $extension = ProjectExtension::create([
            'project_id'          => $project->id,
            'extension_number'    => 1,
            'original_end_date'   => '2026-12-31',
            'new_end_date'        => '2027-06-30',
            'duration_days'       => 181,
            'reason'              => 'Weather delay',
            'status'              => 'pending',
            'created_by_user_id'  => $staff->id,
        ]);

        $this->assertSame($staff->id, $extension->created_by_user_id);
    }
}
