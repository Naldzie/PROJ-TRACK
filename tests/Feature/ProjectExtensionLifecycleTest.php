<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectExtension;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Tests\TestCase;
/**
 * ============================================================================
 * ProjectExtensionLifecycleTest
 * ============================================================================
 *
 * Validates the extension-management upgrade (extension_management_plan.md):
 *   - Proposal queue: multiple pendings allowed; approving re-anchors valid
 *     pendings and supersedes obsolete ones (Decision 1/2).
 *   - Admin after: rule — never approve a date before the live deadline
 *     (Decision 8).
 *   - Revert of ACTIVE approved rolls back the deadline to the previous
 *     approved (or the original) AND restores the baseline snapshot
 *     (Phase 2.5 / §4); reverting a NON-active approved is blocked (Decision 11).
 *   - No approved extension is directly deletable — must be reverted first
 *     (Decision 10). Soft delete + archived panel (Decision 12).
 *   - Resubmit creates a NEW row, never mutates the rejected one (Decision 6).
 *   - Partial approval via approved_new_end_date (Decision 7).
 *   - Reason category + evidence attachments (Decision 3/4).
 *   - extension_count reflects the approved count, not the highest number
 *     (Decision 6).
 *
 * Run With
 * --------
 *   php artisan test --filter=ProjectExtensionLifecycleTest
 */
class ProjectExtensionLifecycleTest extends TestCase
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

        $this->admin = User::create(['name' => 'Lifecycle Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->staff = User::create(['name' => 'Lifecycle Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
        $this->contractorUser = User::create(['name' => 'Lifecycle Contractor', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor', 'is_active' => true]);
        $this->contractor = Contractor::create([
            'user_id'         => $this->contractorUser->id,
            'contractor_name' => 'Lifecycle Builders',
            'company_name'    => 'Lifecycle Builders Inc',
        ]);

        $this->project = Project::factory()->create([
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);
    }

    private function extension(int $number, string $status, int $monthsAfterDeadline): ProjectExtension
    {
        return ProjectExtension::factory()->for($this->project)->create([
            'extension_number'  => $number,
            'status'            => $status,
            'original_end_date' => $this->project->planned_end_date,
            'new_end_date'      => $this->project->planned_end_date->copy()->addMonths($monthsAfterDeadline),
            'created_by_user_id' => $this->staff->id,
        ]);
    }

    // === Phase 1: proposal queue + re-anchor / supersede ===

    public function test_staff_cannot_submit_second_pending_extension(): void
    {
        $payload = fn () => [
            'new_end_date' => $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d'),
            'reason'       => 'Weather delay in January',
            'reason_category' => 'Weather',
        ];

        $this->actingAs($this->staff)->postJson(
            route('staff.projects.extensions.store', $this->project), $payload()
        )->assertCreated();

        $this->actingAs($this->staff)->postJson(
            route('staff.projects.extensions.store', $this->project), $payload()
        )->assertStatus(422);

        $this->assertSame(1, $this->project->extensions()->pending()->count());
        $this->assertSame(0, $this->project->fresh()->extension_count);
    }

    // === Admin create with explicit status (pending / rejected / partial) ===

    public function test_admin_default_create_is_auto_approved_and_moves_deadline(): void
    {
        $newDate = $this->project->planned_end_date->copy()->addMonths(2)->format('Y-m-d');

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.store', $this->project),
            ['new_end_date' => $newDate, 'reason' => 'Design change scope']
        )->assertCreated();

        $this->assertSame(1, $this->project->extensions()->approved()->count());
        $this->assertSame(1, $this->project->fresh()->extension_count);
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo(Carbon::parse($newDate)));
    }

    public function test_admin_can_create_pending_extension_that_does_not_move_deadline(): void
    {
        $newDate = $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d');
        $before = $this->project->planned_end_date;

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.store', $this->project),
            ['new_end_date' => $newDate, 'reason' => 'Awaiting procurement confirmation', 'status' => 'pending']
        )->assertCreated();

        $this->assertSame(1, $this->project->extensions()->pending()->count());
        $this->assertSame(0, $this->project->fresh()->extension_count);
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($before));
    }

    public function test_admin_can_create_rejected_extension_with_reason(): void
    {
        $newDate = $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d');

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.store', $this->project),
            [
                'new_end_date'    => $newDate,
                'reason'          => 'Unsupported scope change',
                'status'          => 'rejected',
                'rejection_reason' => 'Insufficient justification for the requested extension',
            ]
        )->assertCreated();

        $ext = $this->project->extensions()->first();
        $this->assertSame('rejected', $ext->status);
        $this->assertSame('Insufficient justification for the requested extension', $ext->rejection_reason);
        $this->assertSame(0, $this->project->fresh()->extension_count);
    }

    public function test_admin_create_rejected_requires_rejection_reason(): void
    {
        $newDate = $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d');

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.store', $this->project),
            ['new_end_date' => $newDate, 'reason' => 'Unsupported', 'status' => 'rejected']
        )->assertStatus(422);
    }

    public function test_admin_can_create_partially_approved_extension(): void
    {
        $requested = $this->project->planned_end_date->copy()->addMonths(2);
        $granted   = $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d');

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.store', $this->project),
            [
                'new_end_date'       => $requested->format('Y-m-d'),
                'reason'             => 'Partial grant for weather delay',
                'status'             => 'approved',
                'approved_new_end_date' => $granted,
            ]
        )->assertCreated();

        $ext = $this->project->extensions()->first();
        $this->assertSame('approved', $ext->status);
        $this->assertTrue($ext->approved_new_end_date->equalTo(Carbon::parse($granted)));
        // Effective deadline = granted date (Decision 7).
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo(Carbon::parse($granted)));
    }

    public function test_admin_create_granted_date_cannot_exceed_requested_date(): void
    {
        $requested = $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d');
        $granted   = $this->project->planned_end_date->copy()->addMonths(3)->format('Y-m-d');

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.store', $this->project),
            [
                'new_end_date'       => $requested,
                'reason'             => 'Over-grant attempt',
                'status'             => 'approved',
                'approved_new_end_date' => $granted,
            ]
        )->assertStatus(422);
    }

    public function test_approving_one_extension_supersedes_obsolete_pending_and_reanchors_valid_one(): void
    {
        // Pending A: wants +3 months (still valid after approval below).
        $pendingA = $this->extension(1, 'pending', 3);
        // Pending B: wants +1 month (obsolete once the +2 month one is approved).
        $pendingB = $this->extension(2, 'pending', 1);

        // Staff submits a third proposal the admin will approve (+2 months).
        $approving = ProjectExtension::factory()->for($this->project)->create([
            'extension_number'  => 3,
            'status'            => 'pending',
            'original_end_date' => $this->project->planned_end_date,
            'new_end_date'      => $this->project->planned_end_date->copy()->addMonths(2),
            'created_by_user_id' => $this->staff->id,
        ]);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $approving]),
            ['status' => 'approved']
        )->assertOk();

        // Approving #3 (-> +2 months) supersedes pending B (+1 <= +2) and
        // re-anchors pending A to the new deadline.
        $this->assertSame('superseded', $pendingB->fresh()->status);
        $this->assertSame('pending', $pendingA->fresh()->status);
        $this->assertTrue($pendingA->fresh()->original_end_date->equalTo($approving->fresh()->effectiveEndDate()));
        // extension_count = number of APPROVED rows (Decision 6).
        $this->assertSame(1, $this->project->fresh()->extension_count);
    }

    public function test_admin_cannot_set_new_end_date_before_live_deadline(): void
    {
        $only = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $only->new_end_date,
            'extension_count'  => 1,
        ]);

        $earlier = $this->project->planned_end_date->copy()->subMonth()->format('Y-m-d');

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $only]),
            ['new_end_date' => $earlier]
        )->assertStatus(422);
    }

    public function test_admin_editing_active_approved_can_keep_its_own_date_equal_to_live_deadline(): void
    {
        // The active approved extension's new_end_date IS the live deadline
        // (recomputeProjectDeadline pointed planned_end_date at it). Editing
        // that row without changing the date must be allowed — equality is not
        // "before the deadline".
        $only = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $only->new_end_date,
            'extension_count'  => 1,
        ]);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $only]),
            [
                'new_end_date' => $only->new_end_date->format('Y-m-d'),
                'reason'       => 'Revised justification for the same deadline',
            ]
        )->assertOk();

        $this->assertSame('approved', $only->fresh()->status);
        $this->assertSame('Revised justification for the same deadline', $only->fresh()->reason);
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($only->fresh()->new_end_date));
    }

    // === Revert approved -> rejected/pending (deadline rollback) ===

    public function test_admin_reverting_active_approved_to_rejected_rolls_back_to_previous_approved(): void
    {
        $first = $this->extension(1, 'approved', 1);
        $second = $this->extension(2, 'approved', 2);
        $this->project->update([
            'planned_end_date' => $second->new_end_date,
            'extension_count'  => 2,
        ]);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $second]),
            ['status' => 'rejected', 'rejection_reason' => 'Scope justification missing']
        )->assertOk();

        $this->assertSame('rejected', $second->fresh()->status);
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($first->new_end_date));
        $this->assertSame(1, $this->project->fresh()->extension_count);
    }

    public function test_admin_reverting_only_approved_restores_original_deadline(): void
    {
        $only = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $only->new_end_date,
            'extension_count'  => 1,
        ]);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $only]),
            ['status' => 'rejected', 'rejection_reason' => 'Requested dates conflict with procurement']
        )->assertOk();

        $this->assertSame('rejected', $only->fresh()->status);
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($this->project->original_planned_end_date));
        $this->assertSame(0, $this->project->fresh()->extension_count);
    }

    public function test_admin_reverting_approved_to_pending_rolls_back_deadline(): void
    {
        $first = $this->extension(1, 'approved', 1);
        $second = $this->extension(2, 'approved', 2);
        $this->project->update([
            'planned_end_date' => $second->new_end_date,
            'extension_count'  => 2,
        ]);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $second]),
            ['status' => 'pending']
        )->assertOk();

        $this->assertSame('pending', $second->fresh()->status);
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($first->new_end_date));
        $this->assertSame(1, $this->project->fresh()->extension_count);
    }

    // === Edit approved extension's new_end_date moves the deadline ===

    public function test_admin_editing_active_approved_new_end_date_moves_deadline(): void
    {
        $only = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $only->new_end_date,
            'extension_count'  => 1,
        ]);

        $newDate = $this->project->planned_end_date->copy()->addMonths(3)->format('Y-m-d');

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $only]),
            ['new_end_date' => $newDate]
        )->assertOk();

        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($only->fresh()->new_end_date));
        $this->assertSame(1, $this->project->fresh()->extension_count);
    }

    // === Delete: approved never directly deletable (Decision 10) ===

    public function test_admin_cannot_delete_non_active_approved_extension(): void
    {
        $first = $this->extension(1, 'approved', 1);
        $second = $this->extension(2, 'approved', 2);
        $this->project->update([
            'planned_end_date' => $second->new_end_date,
            'extension_count'  => 2,
        ]);

        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $first])
        )->assertStatus(400);

        $this->assertDatabaseHas('project_extensions', ['id' => $first->id]);
    }

    public function test_delete_active_approved_extension_is_blocked(): void
    {
        $this->extension(1, 'approved', 1);
        $second = $this->extension(2, 'approved', 2);
        $this->project->update([
            'planned_end_date' => $second->new_end_date,
            'extension_count'  => 2,
        ]);

        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $second])
        )->assertStatus(400)
            ->assertJsonFragment(['message' => 'Cannot delete an approved extension directly. Revert it first, then delete.']);

        $this->assertDatabaseHas('project_extensions', ['id' => $second->id]);
    }

    public function test_deletion_is_soft_and_restorable(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $pending])
        )->assertOk();

        $this->assertSoftDeleted('project_extensions', ['id' => $pending->id]);

        // Archived panel lists it.
        $this->actingAs($this->admin)->getJson(
            route('admin.projects.extensions.archived', $this->project)
        )->assertOk()->assertJsonCount(1, 'extensions');

        // Restore brings it back.
        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.restore', [$this->project, $pending])
        )->assertOk();
        $this->assertDatabaseHas('project_extensions', ['id' => $pending->id, 'deleted_at' => null]);
    }

    public function test_force_delete_permanently_removes_extension(): void
    {
        Storage::fake('public');
        $rejected = $this->extension(1, 'rejected', 1);

        // Attach evidence so the permanent delete also cleans up the file.
        $file = UploadedFile::fake()->image('evidence.jpg');
        $storagePath = Attachment::generateStoragePath($this->project->code, 'extension', 'jpg');
        $file->storeAs(dirname($storagePath), basename($storagePath), 'public');

        Attachment::create([
            'project_id'      => $this->project->id,
            'attachable_type' => ProjectExtension::class,
            'attachable_id'   => $rejected->id,
            'context'         => 'extension',
            'original_name'   => 'evidence.jpg',
            'file_path'       => $storagePath,
            'file_size'       => 2048,
            'mime_type'       => 'image/jpeg',
            'uploaded_by'     => 'test',
        ]);

        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $rejected])
        )->assertOk();

        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.forceDestroy', [$this->project, $rejected])
        )->assertOk();

        $this->assertDatabaseMissing('project_extensions', ['id' => $rejected->id]);
        Storage::disk('public')->assertMissing($storagePath);
    }

    public function test_deletion_logs_activity(): void
    {
        $rejected = $this->extension(1, 'rejected', 1);

        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $rejected])
        )->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id'    => $this->admin->id,
            'project_id' => $this->project->id,
            'action'     => 'Delete Extension',
        ]);
    }

    // === Delete: staff ===

    public function test_staff_can_delete_pending_and_rejected_extensions_without_changing_deadline(): void
    {
        $pending = $this->extension(1, 'pending', 1);
        $rejected = $this->extension(2, 'rejected', 2);
        $deadlineBefore = $this->project->fresh()->planned_end_date;

        $this->actingAs($this->staff)->deleteJson(
            route('staff.projects.extensions.destroy', [$this->project, $pending])
        )->assertOk();
        $this->assertSoftDeleted('project_extensions', ['id' => $pending->id]);

        $this->actingAs($this->staff)->deleteJson(
            route('staff.projects.extensions.destroy', [$this->project, $rejected])
        )->assertOk();
        $this->assertSoftDeleted('project_extensions', ['id' => $rejected->id]);

        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($deadlineBefore));
        $this->assertSame(0, $this->project->fresh()->extension_count);
    }

    public function test_staff_cannot_delete_approved_extension(): void
    {
        $approved = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $approved->new_end_date,
            'extension_count'  => 1,
        ]);

        $this->actingAs($this->staff)->deleteJson(
            route('staff.projects.extensions.destroy', [$this->project, $approved])
        )->assertStatus(403);

        $this->assertDatabaseHas('project_extensions', ['id' => $approved->id]);
    }

    // === Contractor has no delete route at all ===

    public function test_contractor_has_no_extension_delete_route(): void
    {
        $this->assertFalse(\Route::has('contractor.projects.extensions.destroy'));
    }

    // === Phase 3: resubmit-as-new-row (Decision 6) ===

    public function test_resubmit_rejected_extension_creates_new_row_without_mutating_original(): void
    {
        $rejected = $this->extension(1, 'rejected', 1);
        $rejected->update(['rejection_reason' => 'Needs more justification']);

        $this->actingAs($this->staff)->postJson(
            route('staff.projects.extensions.resubmit', [$this->project, $rejected]),
            [
                'new_end_date'    => $this->project->planned_end_date->copy()->addMonths(2)->format('Y-m-d'),
                'reason'          => 'Resubmitted with more justification',
                'reason_category' => 'Weather',
            ]
        )->assertCreated();

        // Original row untouched, still rejected with its reason.
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('Needs more justification', $rejected->fresh()->rejection_reason);

        // New row is pending, next number, and shares no state.
        $new = ProjectExtension::where('extension_number', 2)->first();
        $this->assertNotNull($new);
        $this->assertSame('pending', $new->status);
        $this->assertNull($new->rejection_reason);
        $this->assertSame(2, $this->project->extensions()->count());
    }

    public function test_resubmit_only_allowed_for_rejected(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->staff)->postJson(
            route('staff.projects.extensions.resubmit', [$this->project, $pending]),
            [
                'new_end_date' => $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d'),
                'reason'       => 'Still pending, cannot resubmit',
            ]
        )->assertStatus(422);
    }

    // === Phase 3: non-active approved revert blocked (Decision 11) ===

    public function test_reverting_non_active_approved_is_blocked(): void
    {
        $first = $this->extension(1, 'approved', 1);
        $second = $this->extension(2, 'approved', 2);
        $this->project->update([
            'planned_end_date' => $second->new_end_date,
            'extension_count'  => 2,
        ]);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $first]),
            ['status' => 'rejected', 'rejection_reason' => 'Trying to revert the older one']
        )->assertStatus(403);

        // The non-active approved row is untouched.
        $this->assertSame('approved', $first->fresh()->status);
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($second->new_end_date));
    }

    // === Phase 4: partial approval (Decision 7) ===

    public function test_partial_approval_granted_date_drives_deadline(): void
    {
        $pending = $this->extension(1, 'pending', 3);
        $granted = $this->project->planned_end_date->copy()->addMonth()->format('Y-m-d');

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $pending]),
            [
                'status'               => 'approved',
                'approved_new_end_date' => $granted,
            ]
        )->assertOk();

        $this->assertSame('approved', $pending->fresh()->status);
        $this->assertSame($granted, $pending->fresh()->approved_new_end_date->format('Y-m-d'));
        // Deadline uses the GRANTED date, not the requested +3 month one.
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($pending->fresh()->approved_new_end_date));
    }

    public function test_partial_approval_cannot_exceed_requested_date(): void
    {
        $pending = $this->extension(1, 'pending', 1);
        $beyond = $pending->new_end_date->copy()->addMonth()->format('Y-m-d');

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $pending]),
            [
                'status'               => 'approved',
                'approved_new_end_date' => $beyond,
            ]
        )->assertStatus(422);
    }

    // === Phase 2: reason category + evidence (Decisions 3/4) ===

    public function test_extension_persists_reason_category(): void
    {
        $this->actingAs($this->staff)->postJson(
            route('staff.projects.extensions.store', $this->project),
            [
                'new_end_date'    => $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d'),
                'reason'          => 'Materials shortage delayed the pour',
                'reason_category' => 'Materials',
            ]
        )->assertCreated();

        $extension = $this->project->extensions()->first();
        $this->assertSame('Materials', $extension->reason_category);
    }

    public function test_extension_reason_category_rejects_unknown_value(): void
    {
        $this->actingAs($this->staff)->postJson(
            route('staff.projects.extensions.store', $this->project),
            [
                'new_end_date'    => $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d'),
                'reason'          => 'Something else entirely',
                'reason_category' => 'NotARealCategory',
            ]
        )->assertStatus(422);
    }

    public function test_extension_index_includes_attachments_count_and_aging(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->staff)->getJson(
            route('staff.projects.extensions.index', $this->project)
        )->assertOk()
            ->assertJsonPath('extensions.0.status', 'pending')
            ->assertJsonPath('extensions.0.attachments_count', 0)
            ->assertJsonPath('extensions.0.reason_category', null)
            ->assertJsonPath('extensions.0.days_awaiting_review', 0);
    }

    // === Phase 2.5: baseline snapshot + restore ===

    private function baselineRow(string $label, string $date, float $planned, ?float $original): ProjectBaselineSchedule
    {
        return ProjectBaselineSchedule::factory()->create([
            'project_id'                       => $this->project->id,
            'month_label'                      => $label,
            'planned_date'                     => $date,
            'planned_progress_percent'         => $planned,
            'original_planned_progress_percent' => $original,
            'original_planned_date'            => $original === null ? null : $date,
        ]);
    }

    public function test_reverting_active_approved_restores_previous_approved_snapshot(): void
    {
        // Original plan: 3 months.
        $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 100, 100);

        // Ext #1 approved with a snapshot stretched to April (4 rows).
        $first = $this->extension(1, 'approved', 1);
        $first->update(['baseline_snapshot' => [
            ['month_label' => '2026-01', 'planned_date' => '2026-01-15', 'planned_progress_percent' => '40', 'original_planned_progress_percent' => '40', 'description' => null, 'activities' => []],
            ['month_label' => '2026-02', 'planned_date' => '2026-02-15', 'planned_progress_percent' => '70', 'original_planned_progress_percent' => '70', 'description' => null, 'activities' => []],
            ['month_label' => '2026-03', 'planned_date' => '2026-03-15', 'planned_progress_percent' => '100', 'original_planned_progress_percent' => '100', 'description' => null, 'activities' => []],
            ['month_label' => '2026-04', 'planned_date' => '2026-04-15', 'planned_progress_percent' => '100', 'original_planned_progress_percent' => null, 'description' => null, 'activities' => []],
        ]]);

        // Ext #2 approved (active), snapshot stretched to May (5 rows).
        $second = $this->extension(2, 'approved', 2);
        $second->update(['baseline_snapshot' => [
            ['month_label' => '2026-01', 'planned_date' => '2026-01-15', 'planned_progress_percent' => '40', 'original_planned_progress_percent' => '40', 'description' => null, 'activities' => []],
            ['month_label' => '2026-02', 'planned_date' => '2026-02-15', 'planned_progress_percent' => '70', 'original_planned_progress_percent' => '70', 'description' => null, 'activities' => []],
            ['month_label' => '2026-03', 'planned_date' => '2026-03-15', 'planned_progress_percent' => '100', 'original_planned_progress_percent' => '100', 'description' => null, 'activities' => []],
            ['month_label' => '2026-04', 'planned_date' => '2026-04-15', 'planned_progress_percent' => '100', 'original_planned_progress_percent' => null, 'description' => null, 'activities' => []],
            ['month_label' => '2026-05', 'planned_date' => '2026-05-15', 'planned_progress_percent' => '100', 'original_planned_progress_percent' => null, 'description' => null, 'activities' => []],
        ]]);

        // Live table is currently in ext #2's adjusted state (5 rows) — include
        // the April row which must survive the restore.
        $this->baselineRow('2026-04', '2026-04-15', 100, null);
        $this->baselineRow('2026-05', '2026-05-15', 100, null);

        $this->project->update([
            'planned_end_date' => $second->new_end_date,
            'extension_count'  => 2,
        ]);

        // Revert the ACTIVE approved (ext #2) → restore ext #1's snapshot.
        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $second]),
            ['status' => 'rejected', 'rejection_reason' => 'Reverted after review']
        )->assertOk();

        $this->assertSame('rejected', $second->fresh()->status);
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($first->new_end_date));

        // May row is gone (was only in ext #2's state); April row restored from
        // ext #1's snapshot.
        $this->assertDatabaseMissing('project_baseline_schedules', [
            'project_id' => $this->project->id,
            'month_label' => '2026-05',
        ]);
        $this->assertDatabaseHas('project_baseline_schedules', [
            'project_id' => $this->project->id,
            'month_label' => '2026-04',
            'planned_progress_percent' => '100.00',
        ]);
    }

    public function test_reverting_active_approved_without_snapshot_restores_original_plan(): void
    {
        // Original plan: 3 frozen months.
        $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 100, 100);

        // Ext #1 approved but NEVER adjusted (no snapshot). Live plan still has
        // an extension-only month added later.
        $only = $this->extension(1, 'approved', 1);
        $this->baselineRow('2026-04', '2026-04-15', 100, null);

        $this->project->update([
            'planned_end_date' => $only->new_end_date,
            'extension_count'  => 1,
        ]);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $only]),
            ['status' => 'rejected', 'rejection_reason' => 'Reverted']
        )->assertOk();

        // Extension-only row removed; original rows unchanged (date + % intact).
        $this->assertDatabaseMissing('project_baseline_schedules', [
            'project_id' => $this->project->id,
            'month_label' => '2026-04',
        ]);
        $march = ProjectBaselineSchedule::where('project_id', $this->project->id)
            ->where('month_label', '2026-03')
            ->first();
        $this->assertNotNull($march);
        $this->assertSame('2026-03-15', $march->planned_date->toDateString());
        $this->assertSame('100.00', $march->planned_progress_percent);
    }

    public function test_reverting_restores_original_planned_date_after_manual_edit(): void
    {
        // Original plan with non-trivial target dates (Jan 5, not the 15th).
        $m1 = $this->baselineRow('2026-01', '2026-01-05', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 100, 100);

        $only = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $only->new_end_date,
            'extension_count'  => 1,
        ]);

        // Manual Manage Month edit moves the target date within its month.
        $this->actingAs($this->admin)->patchJson(
            route('admin.projects.baseline-schedules.update', [$this->project, $m1->id]),
            [
                'planned_date' => '2026-01-20',
                'planned_progress_percent' => 55,
                'description' => null,
            ]
        )->assertOk();

        // Revert the only approved extension -> restores the original plan.
        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $only]),
            ['status' => 'rejected', 'rejection_reason' => 'Reverted']
        )->assertOk();

        // The target date is frozen back to the original day AND the % too.
        $jan = ProjectBaselineSchedule::where('project_id', $this->project->id)
            ->where('month_label', '2026-01')
            ->first();
        $this->assertNotNull($jan);
        $this->assertSame('2026-01-05', $jan->planned_date->toDateString());
        $this->assertSame('40.00', $jan->planned_progress_percent);
        $this->assertDatabaseMissing('project_baseline_schedules', [
            'project_id' => $this->project->id,
            'month_label' => '2026-01',
            'planned_date' => '2026-01-20 00:00:00',
        ]);
    }

    public function test_revert_is_blocked_when_restore_would_delete_month_with_progress(): void
    {
        // Original plan: 3 frozen months.
        $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 100, 100);

        $only = $this->extension(1, 'approved', 1);

        // An extension-only month that now HAS recorded actual progress.
        $extMonth = $this->baselineRow('2026-04', '2026-04-15', 100, null);
        ProjectProgressHistory::factory()->create([
            'project_id'            => $this->project->id,
            'baseline_schedule_id'  => $extMonth->id,
            'month_label'           => '2026-04',
            'actual_progress_percent' => 100,
            'progress_percentage'   => 100,
        ]);

        $this->project->update([
            'planned_end_date' => $only->new_end_date,
            'extension_count'  => 1,
        ]);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $only]),
            ['status' => 'rejected', 'rejection_reason' => 'Reverted']
        )->assertStatus(422);

        // Nothing reverted — the extension stays approved and the month stays.
        $this->assertSame('approved', $only->fresh()->status);
        $this->assertDatabaseHas('project_baseline_schedules', [
            'project_id' => $this->project->id,
            'month_label' => '2026-04',
        ]);
    }

    public function test_save_adjusted_baseline_captures_snapshot_on_latest_approved(): void
    {
        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $m2 = $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $m3 = $this->baselineRow('2026-03', '2026-03-15', 100, 100);

        $approved = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $approved->new_end_date,
            'extension_count'  => 1,
        ]);

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.adjust.save', $this->project),
            [
                'months' => [
                    ['id' => $m1->id, 'month_label' => '2026-01', 'planned_date' => '2026-01-15', 'planned_progress_percent' => 40, 'description' => null],
                    ['id' => $m2->id, 'month_label' => '2026-02', 'planned_date' => '2026-02-15', 'planned_progress_percent' => 70, 'description' => null],
                    ['id' => $m3->id, 'month_label' => '2026-03', 'planned_date' => '2026-03-15', 'planned_progress_percent' => 90, 'description' => null],
                    ['month_label' => '2026-04', 'planned_date' => '2026-04-15', 'planned_progress_percent' => 100, 'description' => null],
                ],
            ]
        )->assertOk();

        $snapshot = $approved->fresh()->baseline_snapshot;
        $this->assertNotNull($snapshot);
        $this->assertCount(4, $snapshot);
        $this->assertSame('2026-04', $snapshot[3]['month_label']);
    }

    public function test_store_baseline_schedule_captures_snapshot_on_latest_approved(): void
    {
        // Anchor the project timeline to the fixed months used below so the
        // new target (2026-04-15) falls within the (extended) planned end date.
        $this->project->update([
            'planned_start_date'         => '2026-01-01',
            'planned_end_date'           => '2026-03-31',
            'original_planned_start_date' => '2026-01-01',
            'original_planned_end_date'   => '2026-03-31',
        ]);

        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 100, 100);

        $approved = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $approved->new_end_date,
            'extension_count'  => 1,
        ]);

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.store', $this->project),
            [
                'planned_date' => '2026-04-15',
                'month_label' => '2026-04',
                'planned_progress_percent' => 100,
                'description' => null,
                'activities' => [],
            ]
        )->assertOk();

        $snapshot = $approved->fresh()->baseline_snapshot;
        $this->assertNotNull($snapshot);
        $this->assertCount(4, $snapshot);
        $this->assertSame('2026-04', $snapshot[3]['month_label']);
    }

    public function test_update_baseline_schedule_captures_snapshot_on_latest_approved(): void
    {
        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);

        $approved = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $approved->new_end_date,
            'extension_count'  => 1,
        ]);

        $this->actingAs($this->admin)->patchJson(
            route('admin.projects.baseline-schedules.update', [$this->project, $m1->id]),
            [
                'planned_date' => '2026-01-20',
                'planned_progress_percent' => 55,
                'description' => null,
            ]
        )->assertOk();

        $snapshot = $approved->fresh()->baseline_snapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame(55.0, (float) $snapshot[0]['planned_progress_percent']);
        // The frozen original column is never overwritten by the edit.
        $this->assertSame(40.0, (float) $snapshot[0]['original_planned_progress_percent']);
    }

    public function test_delete_baseline_schedule_captures_snapshot_on_latest_approved(): void
    {
        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 100, 100);

        $approved = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $approved->new_end_date,
            'extension_count'  => 1,
        ]);

        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.baseline-schedules.destroy', [$this->project, $m1->id])
        )->assertOk();

        $snapshot = $approved->fresh()->baseline_snapshot;
        $this->assertNotNull($snapshot);
        $labels = array_column($snapshot, 'month_label');
        $this->assertSame(['2026-02', '2026-03'], $labels);
    }

    public function test_baseline_mutations_do_not_write_snapshot_without_approved_extension(): void
    {
        // Anchor the project timeline to the fixed months used below.
        $this->project->update([
            'planned_start_date'         => '2026-01-01',
            'planned_end_date'           => '2026-03-31',
            'original_planned_start_date' => '2026-01-01',
            'original_planned_end_date'   => '2026-03-31',
        ]);

        $this->baselineRow('2026-01', '2026-01-15', 40, 40);

        // Only a pending extension exists — not approved, so no snapshot target.
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.store', $this->project),
            [
                'planned_date' => '2026-02-15',
                'month_label' => '2026-02',
                'planned_progress_percent' => 70,
                'description' => null,
                'activities' => [],
            ]
        )->assertOk();

        $this->assertNull($pending->fresh()->baseline_snapshot);
    }

    // === Phase 2.6: ordering lock on manual target edits ===

    private function withApprovedExtension(): ProjectExtension
    {
        $approved = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $approved->new_end_date,
            'extension_count'  => 1,
        ]);
        return $approved;
    }

    private function editTarget(ProjectBaselineSchedule $row, float $percent): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->patchJson(
            route('admin.projects.baseline-schedules.update', [$this->project, $row->id]),
            [
                'planned_date' => $row->planned_date->toDateString(),
                'planned_progress_percent' => $percent,
                'description' => null,
            ]
        );
    }

    public function test_update_baseline_schedule_rejects_progress_below_previous_month(): void
    {
        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $m2 = $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 100, 100);
        $this->withApprovedExtension();

        $this->editTarget($m2, 30)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Target progress % must be higher than the previous month (40%). You entered: 30%');

        $this->assertSame('70.00', $m2->fresh()->planned_progress_percent);
    }

    public function test_update_baseline_schedule_rejects_progress_equal_to_previous_month(): void
    {
        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $m2 = $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 100, 100);
        $this->withApprovedExtension();

        $this->editTarget($m2, 40)->assertStatus(422);

        $this->assertSame('70.00', $m2->fresh()->planned_progress_percent);
    }

    public function test_update_baseline_schedule_rejects_progress_above_next_month(): void
    {
        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $m2 = $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 90, 90);
        $this->baselineRow('2026-04', '2026-04-15', 100, 100);
        $this->withApprovedExtension();

        $this->editTarget($m2, 95)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Target progress % must be lower than the next month (90%). You entered: 95%');

        $this->assertSame('70.00', $m2->fresh()->planned_progress_percent);
    }

    public function test_update_baseline_schedule_rejects_progress_equal_to_next_month(): void
    {
        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $m2 = $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 90, 90);
        $this->baselineRow('2026-04', '2026-04-15', 100, 100);
        $this->withApprovedExtension();

        $this->editTarget($m2, 90)->assertStatus(422);

        $this->assertSame('70.00', $m2->fresh()->planned_progress_percent);
    }

    public function test_update_baseline_schedule_accepts_progress_strictly_between_neighbors(): void
    {
        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $m2 = $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 90, 90);
        $this->baselineRow('2026-04', '2026-04-15', 100, 100);
        $approved = $this->withApprovedExtension();

        $this->editTarget($m2, 85)->assertOk();

        $this->assertSame('85.00', $m2->fresh()->planned_progress_percent);

        $snapshot = $approved->fresh()->baseline_snapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame(85.0, (float) $snapshot[1]['planned_progress_percent']);
        // The frozen original column is never overwritten by the edit.
        $this->assertSame(70.0, (float) $snapshot[1]['original_planned_progress_percent']);
    }

    public function test_update_baseline_schedule_first_month_has_no_lower_bound(): void
    {
        $m1 = $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->withApprovedExtension();

        // No previous month -> only the upper bound applies.
        $this->editTarget($m1, 65)->assertOk();
        $this->assertSame('65.00', $m1->fresh()->planned_progress_percent);

        // Equal to the next month is still rejected.
        $this->editTarget($m1, 70)->assertStatus(422);
        $this->assertSame('65.00', $m1->fresh()->planned_progress_percent);
    }

    public function test_update_baseline_schedule_last_month_has_no_upper_bound(): void
    {
        $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $m3 = $this->baselineRow('2026-03', '2026-03-15', 100, 100);
        $this->withApprovedExtension();

        // No next month -> only the lower bound applies.
        $this->editTarget($m3, 95)->assertOk();
        $this->assertSame('95.00', $m3->fresh()->planned_progress_percent);

        // Equal to the previous month is still rejected.
        $this->editTarget($m3, 70)->assertStatus(422);
        $this->assertSame('95.00', $m3->fresh()->planned_progress_percent);
    }

    public function test_update_baseline_schedule_rejects_progress_below_original_previous_month(): void
    {
        // Original plan reaches 100% before the extension period starts.
        $this->baselineRow('2026-01', '2026-01-15', 40, 40);
        $this->baselineRow('2026-02', '2026-02-15', 70, 70);
        $this->baselineRow('2026-03', '2026-03-15', 100, 100);
        $extMonth = $this->baselineRow('2026-04', '2026-04-15', 100, null);
        $this->withApprovedExtension();

        // Regression: the previous bound must include ORIGINAL (pre-extension)
        // months too, so a manual edit cannot dip below the 100% original plan.
        $this->editTarget($extMonth, 85)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Target progress % must be higher than the previous month (100%). You entered: 85%');

        $this->assertSame('100.00', $extMonth->fresh()->planned_progress_percent);
    }

    // === Phase 6: contractor read-only (no Request button) ===

    public function test_contractor_extensions_section_hides_request_button(): void
    {
        // The extensions-section partial hides the Request Extension button
        // for contractors (no write routes) and hides the archived panel.
        $this->actingAs($this->contractorUser);
        $html = view('projects.project_detail.components.tabs.partials.extensions-section', [
            'project' => $this->project,
        ])->render();

        // The Request button (identified by its openRequestExtensionModal
        // handler) must not render for contractors.
        $this->assertStringNotContainsString('openRequestExtensionModal()', $html);
        $this->assertStringNotContainsString('loadArchivedExtensions()', $html);
        $this->assertStringNotContainsString('archivedExtensionsPanel', $html);
    }

    public function test_admin_extensions_section_hides_archived_button_when_none_archived(): void
    {
        // Same partial for admins shows the Request button and the admin-only
        // archived panel, but the Archived Extensions toggle button must be
        // hidden while there are no soft-deleted extensions.
        $this->actingAs($this->admin);
        $html = view('projects.project_detail.components.tabs.partials.extensions-section', [
            'project' => $this->project,
        ])->render();

        $this->assertStringContainsString('openRequestExtensionModal()', $html);
        $this->assertStringContainsString('archivedExtensionsPanel', $html);
        $this->assertStringNotContainsString('archivedExtensionsBtn', $html);
    }

    public function test_admin_extensions_section_shows_archived_button_when_one_archived(): void
    {
        // With at least one soft-deleted extension the Archived Extensions
        // toggle button renders for admins.
        $archived = $this->extension(1, 'approved', 1);
        $archived->delete(); // soft-delete -> appears in the archived panel

        $this->actingAs($this->admin);
        $html = view('projects.project_detail.components.tabs.partials.extensions-section', [
            'project' => $this->project,
        ])->render();

        $this->assertStringContainsString('openRequestExtensionModal()', $html);
        $this->assertStringContainsString('archivedExtensionsPanel', $html);
        $this->assertStringContainsString('archivedExtensionsBtn', $html);
    }

    // === Phase 7: audit-trail-clean workflow (Decision 14) ===

    private function otherStaff(): User
    {
        return User::create([
            'name' => 'Lifecycle Other Staff',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'staff',
            'is_active' => true,
        ]);
    }

    public function test_staff_can_edit_own_pending_extension(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->staff)->putJson(
            route('staff.projects.extensions.update', [$this->project, $pending]),
            ['reason' => 'Revised justification for the delay']
        )->assertOk();

        $this->assertSame('Revised justification for the delay', $pending->fresh()->reason);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_staff_cannot_edit_another_staffs_pending_extension(): void
    {
        $pending = $this->extension(1, 'pending', 1);
        $other = $this->otherStaff();

        $this->actingAs($other)->putJson(
            route('staff.projects.extensions.update', [$this->project, $pending]),
            ['reason' => 'Attempted to hijack this request']
        )->assertStatus(403)
            ->assertJsonPath('message', 'You can only edit your own extension requests.');

        $this->assertSame($this->staff->id, $pending->fresh()->created_by_user_id);
    }

    public function test_staff_cannot_edit_own_rejected_extension_in_place(): void
    {
        // Decision 14: a rejected row is immutable — the ONLY way back to
        // review is Resubmit (new row). In-place editing would wipe the
        // rejection reason and break the audit trail.
        $rejected = $this->extension(1, 'rejected', 1);
        $rejected->update(['rejection_reason' => 'Needs more justification']);

        $this->actingAs($this->staff)->putJson(
            route('staff.projects.extensions.update', [$this->project, $rejected]),
            ['new_end_date' => $this->project->planned_end_date->copy()->addMonths(2)->format('Y-m-d')]
        )->assertStatus(403)
            ->assertJsonPath('message', 'A rejected extension cannot be edited directly. Use "Resubmit" to send a revised request.');

        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('Needs more justification', $rejected->fresh()->rejection_reason);
    }

    public function test_staff_cannot_resubmit_another_staffs_rejected_extension(): void
    {
        $rejected = $this->extension(1, 'rejected', 1);
        $rejected->update(['rejection_reason' => 'Needs more justification']);
        $other = $this->otherStaff();

        $this->actingAs($other)->postJson(
            route('staff.projects.extensions.resubmit', [$this->project, $rejected]),
            ['new_end_date' => $this->project->planned_end_date->copy()->addMonths(2)->format('Y-m-d')]
        )->assertStatus(403)
            ->assertJsonPath('message', 'You can only resubmit your own rejected extension requests.');

        $this->assertSame('rejected', $rejected->fresh()->status);
    }

    public function test_staff_cannot_archive_another_staffs_extension(): void
    {
        $pending = $this->extension(1, 'pending', 1);
        $other = $this->otherStaff();

        $this->actingAs($other)->deleteJson(
            route('staff.projects.extensions.destroy', [$this->project, $pending])
        )->assertStatus(403)
            ->assertJsonPath('message', 'You can only archive your own extension requests.');

        $this->assertDatabaseHas('project_extensions', ['id' => $pending->id]);
    }

    public function test_admin_can_review_pending_extension_through_update(): void
    {
        // The Review modal drives the existing admin update route — approving
        // a pending request applies the deadline and records the approver.
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $pending]),
            ['status' => 'approved']
        )->assertOk();

        $this->assertSame('approved', $pending->fresh()->status);
        $this->assertSame($this->admin->id, $pending->fresh()->approved_by_user_id);
        $this->assertSame(1, $this->project->fresh()->extension_count);
    }

    public function test_admin_can_reject_pending_extension_with_reason_through_update(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $pending]),
            ['status' => 'rejected', 'rejection_reason' => 'Insufficient schedule analysis']
        )->assertOk();

        $this->assertSame('rejected', $pending->fresh()->status);
        $this->assertSame('Insufficient schedule analysis', $pending->fresh()->rejection_reason);
    }

    public function test_admin_can_partially_approve_through_update(): void
    {
        $pending = $this->extension(1, 'pending', 1);
        $granted = $this->project->planned_end_date->copy()->addDays(10)->format('Y-m-d');

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $pending]),
            ['status' => 'approved', 'approved_new_end_date' => $granted]
        )->assertOk();

        $this->assertSame('approved', $pending->fresh()->status);
        $this->assertSame(Carbon::parse($granted)->format('Y-m-d'), $pending->fresh()->approved_new_end_date?->format('Y-m-d'));
    }

    public function test_extension_index_payload_includes_requester_and_approver(): void
    {
        $approved = $this->extension(1, 'approved', 1);
        $approved->update(['approved_by_user_id' => $this->admin->id, 'approved_at' => now()]);

        $response = $this->actingAs($this->staff)->getJson(
            route('staff.projects.extensions.index', $this->project)
        )->assertOk();

        $data = $response->json('extensions.0');
        $this->assertSame($this->staff->id, $data['created_by_user_id']);
        $this->assertSame('Lifecycle Staff', $data['created_by']);
        $this->assertSame('Lifecycle Admin', $data['approved_by_name']);
    }

    // === §8 bulk operations (admin only): bulk archive + bulk force-delete ===

    public function test_bulk_archive_soft_deletes_selected_non_approved_extensions(): void
    {
        $pending = $this->extension(1, 'pending', 1);
        $rejected = $this->extension(2, 'rejected', 2);

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.bulkDestroy', $this->project),
            ['ids' => [$pending->id, $rejected->id]]
        )->assertOk();

        $this->assertSoftDeleted('project_extensions', ['id' => $pending->id]);
        $this->assertSoftDeleted('project_extensions', ['id' => $rejected->id]);

        // Both show up in the archived panel.
        $this->actingAs($this->admin)->getJson(
            route('admin.projects.extensions.archived', $this->project)
        )->assertOk()->assertJsonCount(2, 'extensions');
    }

    public function test_bulk_archive_skips_approved_extensions(): void
    {
        $approved = $this->extension(1, 'approved', 1);
        $this->project->update([
            'planned_end_date' => $approved->new_end_date,
            'extension_count'  => 1,
        ]);
        $pending = $this->extension(2, 'pending', 2);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.bulkDestroy', $this->project),
            ['ids' => [$approved->id, $pending->id]]
        )->assertOk();

        // Approved row untouched; pending archived; response mentions the skip.
        $this->assertDatabaseHas('project_extensions', ['id' => $approved->id, 'deleted_at' => null]);
        $this->assertSoftDeleted('project_extensions', ['id' => $pending->id]);
        $this->assertSame(1, $response->json('skipped'));
        $this->assertSame(1, $response->json('archived'));
    }

    public function test_bulk_archive_is_admin_only(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        // Admin route group middleware redirects non-admins away (302),
        // so the controller's 403 guard is defense-in-depth here.
        $this->actingAs($this->staff)->postJson(
            route('admin.projects.extensions.bulkDestroy', $this->project),
            ['ids' => [$pending->id]]
        )->assertStatus(302);

        $this->assertDatabaseHas('project_extensions', ['id' => $pending->id]);
    }

    public function test_bulk_archive_requires_at_least_one_id(): void
    {
        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.bulkDestroy', $this->project),
            ['ids' => []]
        )->assertStatus(422);
    }

    public function test_bulk_force_delete_permanently_removes_archived_extensions(): void
    {
        Storage::fake('public');
        $rejectedA = $this->extension(1, 'rejected', 1);
        $rejectedB = $this->extension(2, 'rejected', 2);

        // Archive both first.
        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $rejectedA])
        )->assertOk();
        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $rejectedB])
        )->assertOk();

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.bulkForceDestroy', $this->project),
            ['ids' => [$rejectedA->id, $rejectedB->id]]
        )->assertOk();

        $this->assertDatabaseMissing('project_extensions', ['id' => $rejectedA->id]);
        $this->assertDatabaseMissing('project_extensions', ['id' => $rejectedB->id]);
        $this->assertSame(0, $this->project->extensions()->withTrashed()->count());
    }

    public function test_bulk_force_delete_only_touches_soft_deleted_rows(): void
    {
        $archived = $this->extension(1, 'rejected', 1);
        $live = $this->extension(2, 'pending', 2);

        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $archived])
        )->assertOk();

        // The live pending row is NOT soft-deleted, so bulk force-delete
        // must leave it alone even if its id is passed.
        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.bulkForceDestroy', $this->project),
            ['ids' => [$archived->id, $live->id]]
        )->assertOk();

        $this->assertDatabaseMissing('project_extensions', ['id' => $archived->id]);
        $this->assertDatabaseHas('project_extensions', ['id' => $live->id]);
    }

    public function test_bulk_force_delete_is_admin_only(): void
    {
        $rejected = $this->extension(1, 'rejected', 1);
        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $rejected])
        )->assertOk();

        $this->actingAs($this->staff)->postJson(
            route('admin.projects.extensions.bulkForceDestroy', $this->project),
            ['ids' => [$rejected->id]]
        )->assertStatus(302);

        $this->assertDatabaseHas('project_extensions', ['id' => $rejected->id]);
    }

    public function test_bulk_archive_logs_activity(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.bulkDestroy', $this->project),
            ['ids' => [$pending->id]]
        )->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id'    => $this->admin->id,
            'project_id' => $this->project->id,
            'action'     => 'Delete Extension',
        ]);
    }
}
