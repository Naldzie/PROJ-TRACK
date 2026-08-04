<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectExtension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * NotificationTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Phase N3 of the notification system (gaps #12) — the event-driven
 * hooks wired into the existing controllers:
 *   - Staff extension submission → admins notified (ExtensionStatusChanged/submitted).
 *   - Admin extension auto-approval → project contractor notified (approved).
 *   - Admin approves a pending extension → requester + contractor + admins notified.
 *   - Admin rejects an extension → requester notified with the rejection reason.
 *   - Staff resubmission of a rejected request → admins re-notified.
 *   - Issue creation → admins + project contractor notified (all severities).
 *   - Dedupe prevents duplicate notifications for the same event.
 *
 * Run With
 * --------
 *   php artisan test --filter=NotificationTest
 */
class NotificationTest extends TestCase
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

        $this->admin = User::create(['name' => 'Hook Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->staff = User::create(['name' => 'Hook Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
        $this->contractorUser = User::create(['name' => 'Hook Contractor', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor', 'is_active' => true]);
        $this->contractor = Contractor::create([
            'user_id'        => $this->contractorUser->id,
            'contractor_name' => 'Hook Builders',
            'company_name'    => 'Hook Builders Inc',
        ]);

        $this->project = Project::factory()->create([
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);
    }

    private function notificationsFor(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => [
            'type' => class_basename($n->type),
            'data' => $n->data,
        ])->values()->all();
    }

    private function staffSubmitsExtension(): array
    {
        return $this->actingAs($this->staff)->postJson(
            route('staff.projects.extensions.store', $this->project),
            ['new_end_date' => $this->project->planned_end_date->copy()->addMonths(2)->format('Y-m-d'), 'reason' => 'Weather delay on site']
        )->assertCreated()->json('extension');
    }

    private function latestExtension(): ProjectExtension
    {
        return ProjectExtension::latest('id')->firstOrFail();
    }

    // === Staff submission → admins ===

    public function test_staff_extension_submission_notifies_admins(): void
    {
        $this->staffSubmitsExtension();

        $this->assertSame(1, $this->admin->notifications()->count());
        $data = $this->admin->notifications()->first()->data;
        $this->assertSame('ExtensionStatusChanged', class_basename($this->admin->notifications()->first()->type));
        $this->assertSame('submitted', $data['status']);
        $this->assertSame($this->project->id, $data['project_id']);
    }

    public function test_staff_extension_submission_does_not_notify_contractor(): void
    {
        $this->staffSubmitsExtension();

        $this->assertSame(0, $this->contractorUser->notifications()->count());
    }

    // === Admin auto-approval → contractor ===

    public function test_admin_extension_autocreate_notifies_contractor(): void
    {
        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.store', $this->project),
            ['new_end_date' => $this->project->planned_end_date->copy()->addMonths(2)->format('Y-m-d'), 'reason' => 'Design change scope']
        )->assertCreated();

        $this->assertSame(1, $this->contractorUser->notifications()->count());
        $data = $this->contractorUser->notifications()->first()->data;
        $this->assertSame('approved', $data['status']);
        $this->assertSame($this->project->id, $data['project_id']);
    }

    // === Admin approval of staff-pending → requester + contractor + admins ===

    public function test_admin_approval_notifies_requester_contractor_and_admins(): void
    {
        $this->staffSubmitsExtension();
        $extension = $this->latestExtension();

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $extension]),
            ['status' => 'approved']
        )->assertOk();

        $this->assertSame(1, $this->staff->notifications()->count());
        $this->assertSame('approved', $this->staff->notifications()->first()->data['status']);

        $this->assertSame(1, $this->contractorUser->notifications()->count());
        $this->assertSame('approved', $this->contractorUser->notifications()->first()->data['status']);
    }

    // === Admin rejection → requester with reason ===

    public function test_admin_rejection_notifies_requester_with_reason(): void
    {
        $this->staffSubmitsExtension();
        $extension = $this->latestExtension();

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $extension]),
            ['status' => 'rejected', 'rejection_reason' => 'Missing permit documentation']
        )->assertOk();

        $this->assertSame(1, $this->staff->notifications()->count());
        $data = $this->staff->notifications()->first()->data;
        $this->assertSame('rejected', $data['status']);
        $this->assertSame('Missing permit documentation', $data['rejection_reason']);
    }

    // === Staff resubmission → admins re-notified (distinct event) ===

    public function test_staff_resubmission_re_notifies_admins(): void
    {
        $this->staffSubmitsExtension();
        $extension = $this->latestExtension();
        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $extension]),
            ['status' => 'rejected', 'rejection_reason' => 'Incomplete']
        )->assertOk();

        // Rejected → then resubmit via staff update.
        $this->actingAs($this->staff)->putJson(
            route('staff.projects.extensions.update', [$this->project, $extension]),
            ['new_end_date' => $this->project->planned_end_date->copy()->addMonths(3)->format('Y-m-d'), 'reason' => 'Revised weather justification']
        )->assertOk();

        // 1 submission + 1 resubmission = 2 admins notifications (submitted events).
        $submitted = collect($this->notificationsFor($this->admin))
            ->where('data.status', 'submitted');
        $this->assertCount(2, $submitted);
    }

    // === Dedupe ===

    public function test_same_extension_event_is_not_duplicated(): void
    {
        $this->staffSubmitsExtension();
        $extension = $this->latestExtension();

        // Approve twice — second call should not create a second approval notice.
        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $extension]),
            ['status' => 'approved']
        )->assertOk();
        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $extension]),
            ['status' => 'approved']
        )->assertOk();

        $approved = collect($this->notificationsFor($this->staff))
            ->where('data.status', 'approved');
        $this->assertCount(1, $approved);
    }

    // === Issue creation ===

    public function test_issue_creation_notifies_admins_and_contractor(): void
    {
        $this->actingAs($this->staff)->postJson(
            route('staff.projects.issues.store', $this->project),
            [
                'issue_name'  => 'Crack in foundation',
                'description' => 'Visible structural crack on column A3',
                'status'      => 'Open',
                'issue_type'  => 'material',
                'severity'    => 'High',
            ]
        )->assertOk();

        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame('IssueReported', class_basename($this->admin->notifications()->first()->type));
        $this->assertSame('High', $this->admin->notifications()->first()->data['severity']);

        $this->assertSame(1, $this->contractorUser->notifications()->count());
        $data = $this->contractorUser->notifications()->first()->data;
        $this->assertSame($this->project->id, $data['project_id']);
        $this->assertSame('Crack in foundation', $data['issue_name']);
    }

    public function test_issue_notifies_all_severities_including_low(): void
    {
        $this->actingAs($this->staff)->postJson(
            route('staff.projects.issues.store', $this->project),
            [
                'issue_name'  => 'Minor paint defect',
                'description' => 'Cosmetic paint chip near entrance',
                'status'      => 'Open',
                'issue_type'  => 'other',
                'severity'    => 'Low',
            ]
        )->assertOk();

        $this->assertSame(1, $this->contractorUser->notifications()->count());
        $this->assertSame('Low', $this->contractorUser->notifications()->first()->data['severity']);
    }

    public function test_issue_creation_does_not_notify_unrelated_contractor(): void
    {
        $otherContractor = User::create(['name' => 'Other', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor', 'is_active' => true]);
        Contractor::create(['user_id' => $otherContractor->id, 'contractor_name' => 'Other Co', 'company_name' => 'Other Co']);

        $this->actingAs($this->staff)->postJson(
            route('staff.projects.issues.store', $this->project),
            [
                'issue_name'  => 'Issue A',
                'description' => 'Description A',
                'status'      => 'Open',
                'severity'    => 'Medium',
            ]
        )->assertOk();

        $this->assertSame(1, $this->contractorUser->notifications()->count());
        $this->assertSame(0, $otherContractor->notifications()->count());
    }
}
