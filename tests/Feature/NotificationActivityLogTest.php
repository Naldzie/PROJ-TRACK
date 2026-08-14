<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Notifications\ExtensionStatusChanged;
use App\Notifications\IssueReported;
use App\Notifications\OverdueInspectionReminder;
use App\Notifications\RiskReported;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * NotificationActivityLogTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the ActivityLog integration added to NotificationService
 * (teammate merge on feature/teammate-layout-design):
 *   - notifyMany() writes ONE admin ActivityLog row when an admin was among
 *     the delivered recipients (action / description / project_id / user_id /
 *     visibility 'all').
 *   - No admin delivered -> no ActivityLog row.
 *   - A dedupe skip (notification already delivered) -> no new ActivityLog row.
 *   - Each notification type maps to its human-readable action label.
 *   - Decision 16: the Extension Update description omits the rejection reason.
 *
 * Run With
 * --------
 *   php artisan test --filter=NotificationActivityLogTest
 */
class NotificationActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $contractorUser;
    private Contractor $contractor;
    private Project $project;
    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Log Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->staff = User::create(['name' => 'Log Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
        $this->contractorUser = User::create(['name' => 'Log Contractor', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor', 'is_active' => true]);

        $this->contractor = Contractor::create([
            'user_id'        => $this->contractorUser->id,
            'contractor_name' => 'Log Builders',
            'company_name'    => 'Log Builders Inc',
        ]);

        $this->project = Project::factory()->create([
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);

        $this->service = app(NotificationService::class);
    }

    private function deadlineReminder(): DeadlineReminder
    {
        return new DeadlineReminder($this->project->id, $this->project->title, '30', 30, '2026-12-31');
    }

    private function logRows(): \Illuminate\Support\Collection
    {
        return ActivityLog::where('project_id', $this->project->id)->get();
    }

    public function test_notify_many_with_admin_logs_one_activity_entry(): void
    {
        $delivered = $this->service->notifyMany(collect([$this->admin, $this->staff]), $this->deadlineReminder());

        $this->assertSame(2, $delivered);
        $this->assertSame(1, $this->logRows()->count());

        $log = $this->logRows()->first();
        $this->assertSame('Deadline Reminder', $log->action);
        $this->assertStringContainsString($this->project->title, $log->description);
        $this->assertSame($this->project->id, $log->project_id);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('all', $log->visibility);
    }

    public function test_notify_many_without_admin_writes_no_activity_log(): void
    {
        $delivered = $this->service->notifyMany(collect([$this->staff, $this->contractorUser]), $this->deadlineReminder());

        $this->assertSame(2, $delivered);
        $this->assertSame(0, $this->logRows()->count());
    }

    public function test_dedupe_skip_writes_no_activity_log(): void
    {
        $notification = $this->deadlineReminder();

        $this->service->notifyMany(collect([$this->admin]), $notification);
        $this->assertSame(1, $this->logRows()->count());

        $delivered = $this->service->notifyMany(collect([$this->admin]), $notification);

        $this->assertSame(0, $delivered);
        $this->assertSame(1, $this->logRows()->count());
    }

    public function test_each_type_maps_to_its_action_label(): void
    {
        $cases = [
            [new DeadlineReminder($this->project->id, $this->project->title, 'overdue', -2, '2026-11-30'), 'Deadline Reminder'],
            [new OverdueInspectionReminder($this->project->id, $this->project->title, '2026-05', 'May 2026', '2026-05-31'), 'Overdue Inspection'],
            [new ExtensionStatusChanged($this->project->id, $this->project->title, 1, 1, ExtensionStatusChanged::SUBMITTED), 'Extension Update'],
            [new IssueReported($this->project->id, $this->project->title, 1, 'Foundation crack', 'High'), 'Issue Reported'],
            [new RiskReported($this->project->id, $this->project->title, 1, 'Slope instability'), 'High Risk Reported'],
        ];

        foreach ($cases as [$notification, $expectedAction]) {
            $this->service->notifyMany(collect([$this->admin]), $notification);
        }

        $actions = $this->logRows()->pluck('action')->all();

        $this->assertCount(5, $actions);
        $this->assertSame($cases[0][1], $actions[0]);
        $this->assertSame($cases[1][1], $actions[1]);
        $this->assertSame($cases[2][1], $actions[2]);
        $this->assertSame($cases[3][1], $actions[3]);
        $this->assertSame($cases[4][1], $actions[4]);
    }

    public function test_rejected_extension_log_omits_rejection_reason(): void
    {
        $notification = new ExtensionStatusChanged(
            $this->project->id,
            $this->project->title,
            2,
            1,
            ExtensionStatusChanged::REJECTED,
            'Insufficient justification'
        );

        $this->service->notifyMany(collect([$this->admin]), $notification);

        $log = $this->logRows()->first();
        $this->assertNotNull($log);
        $this->assertSame('Extension Update', $log->action);
        $this->assertStringNotContainsString('Insufficient justification', $log->description);
        $this->assertStringContainsString('rejected', $log->description);
    }
}