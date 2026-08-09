<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectExtension;
use App\Models\User;
use App\Services\ProjectOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ============================================================================
 * ExtensionActivityLogTest
 * ============================================================================
 *
 * Validates Phase 8 of the extension-management plan (Decision 16) — the
 * extension lifecycle is wired into the activity log with a THREE-tier
 * visibility model:
 *   - 'all'         → Request, Resubmit, Approve, Update, Reject (action only)
 *   - 'staff' (new) → Supersede, Delete/Archive, Revert (internal)
 *   - 'admin_only'  → Restore, Force-Delete, bulk ops (admin housekeeping)
 *
 * Also verifies:
 *   - The rejection reason is NOT baked into the shared 'all' description.
 *   - Attachment evidence upload/delete logs carry 'all' visibility (they were
 *     NULL before → invisible to non-admins) and name the extension when the
 *     context is 'extension'.
 *   - scopeVisibleTo: staff sees all+staff, contractor sees all only, admin
 *     sees everything.
 *
 * Run With
 * --------
 *   php artisan test --filter=ExtensionActivityLogTest
 */
class ExtensionActivityLogTest extends TestCase
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

        $this->admin = User::create(['name' => 'Log Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->staff = User::create(['name' => 'Log Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
        $this->contractorUser = User::create(['name' => 'Log Contractor', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor', 'is_active' => true]);
        $this->contractor = Contractor::create([
            'user_id'         => $this->contractorUser->id,
            'contractor_name' => 'Log Builders',
            'company_name'    => 'Log Builders Inc',
        ]);

        $this->project = Project::factory()->create([
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);
    }

    /** Create an extension for this project owned by staff with explicit dates. */
    private function extension(int $number, string $status, int $monthsAfterDeadline): ProjectExtension
    {
        return ProjectExtension::factory()->for($this->project)->create([
            'extension_number'   => $number,
            'status'             => $status,
            'original_end_date'  => $this->project->planned_end_date,
            'new_end_date'       => $this->project->planned_end_date->copy()->addMonths($monthsAfterDeadline),
            'created_by_user_id' => $this->staff->id,
        ]);
    }

    private function logForProject(string $action, string $visibility): ActivityLog
    {
        return ActivityLog::create([
            'user_id'    => $this->admin->id,
            'project_id' => $this->project->id,
            'action'     => $action,
            'description' => 'Test ' . $action,
            'visibility' => $visibility,
        ]);
    }

    private function projectActivityActions(string $role): array
    {
        return ActivityLog::with('user')
            ->where('project_id', $this->project->id)
            ->visibleTo($role)
            ->latest()
            ->get()
            ->pluck('action')
            ->all();
    }

    // === Lifecycle → action labels + visibility ===

    public function test_staff_request_logs_request_extension_with_all_visibility(): void
    {
        $this->actingAs($this->staff)->postJson(
            route('staff.projects.extensions.store', $this->project),
            [
                'new_end_date'    => $this->project->planned_end_date->copy()->addMonths(1)->format('Y-m-d'),
                'reason'          => 'Weather delay in January',
                'reason_category' => 'Weather',
            ]
        )->assertCreated();

        $log = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Request Extension')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame('all', $log->visibility);
        $this->assertStringContainsString('requested extension #1', $log->description);
    }

    public function test_admin_approve_logs_approve_extension_with_all_visibility(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $pending]),
            ['status' => 'approved']
        )->assertOk();

        $log = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Approve Extension')->first();

        $this->assertNotNull($log);
        $this->assertSame('all', $log->visibility);
    }

    public function test_admin_reject_log_keeps_reason_out_of_description(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $pending]),
            ['status' => 'rejected', 'rejection_reason' => 'Insufficient schedule analysis']
        )->assertOk();

        $log = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Reject Extension')->first();

        $this->assertNotNull($log);
        $this->assertSame('all', $log->visibility);
        // Decision 16: the reason lives on the record + notification, NOT in the
        // shared feed description (contractors must not see internal rationale).
        $this->assertStringNotContainsString('Insufficient schedule analysis', $log->description);
        $this->assertStringContainsString('Reject Extension', $log->description);
    }

    public function test_admin_reverting_active_approved_logs_revert_extension_staff_visibility(): void
    {
        $approved = $this->extension(1, 'approved', 2);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $approved]),
            ['status' => 'pending']
        )->assertOk();

        $log = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Revert Extension')->first();

        $this->assertNotNull($log, 'Reverting the active approved must log "Revert Extension"');
        $this->assertSame('staff', $log->visibility);
    }

    public function test_approval_supersedes_obsolete_pending_and_logs_supersede_extension(): void
    {
        // #1 pending for +1 month (will become obsolete), #2 pending for +2 months.
        $pending = $this->extension(1, 'pending', 1);
        $second  = $this->extension(2, 'pending', 2);

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $second]),
            ['status' => 'approved']
        )->assertOk();

        $this->assertSame('superseded', $pending->fresh()->status);

        $log = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Supersede Extension')->first();

        $this->assertNotNull($log, 'A superseded pending must produce a Supersede Extension log');
        $this->assertSame('staff', $log->visibility);
        $this->assertStringContainsString('#1', $log->description);
        $this->assertStringContainsString('#2', $log->description);
    }

    public function test_staff_update_and_resubmit_log_with_all_visibility(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        $this->actingAs($this->staff)->putJson(
            route('staff.projects.extensions.update', [$this->project, $pending]),
            ['reason' => 'Revised justification']
        )->assertOk();

        $this->assertSame('all', ActivityLog::where('action', 'Update Extension')->first()?->visibility);

        // Reject then resubmit → Resubmit Extension.
        $pending->update(['status' => 'rejected', 'rejection_reason' => 'Needs more detail']);
        $this->actingAs($this->staff)->postJson(
            route('staff.projects.extensions.resubmit', [$this->project, $pending]),
            [
                'new_end_date' => $this->project->planned_end_date->copy()->addMonths(2)->format('Y-m-d'),
                'reason'       => 'Revised justification with more detail',
            ]
        )->assertCreated();

        $resubmitLog = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Resubmit Extension')->first();

        $this->assertNotNull($resubmitLog);
        $this->assertSame('all', $resubmitLog->visibility);
    }

    public function test_archive_restore_force_delete_visibility_tiers(): void
    {
        $pending = $this->extension(1, 'pending', 1);

        // Staff archives own pending → staff tier.
        $this->actingAs($this->staff)->deleteJson(
            route('staff.projects.extensions.destroy', [$this->project, $pending])
        )->assertOk();

        $archiveLog = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Delete Extension')->first();
        $this->assertSame('staff', $archiveLog?->visibility);

        // Admin restores → admin_only tier.
        $this->actingAs($this->admin)->postJson(
            route('admin.projects.extensions.restore', [$this->project, $pending])
        )->assertOk();

        $restoreLog = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Restore Extension')->first();
        $this->assertSame('admin_only', $restoreLog?->visibility);

        // Admin force-deletes → admin_only tier.
        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.forceDestroy', [$this->project, $pending])
        )->assertOk();

        $forceLog = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Delete Extension')->latest('id')->first();
        $this->assertSame('admin_only', $forceLog?->visibility);
        $this->assertStringContainsString('permanently deleted', $forceLog?->description);
    }

    // === Role visibility scopes (Decision 16) ===

    public function test_staff_scope_sees_all_and_staff_but_not_admin_only(): void
    {
        $this->logForProject('Request Extension', 'all');
        $this->logForProject('Revert Extension', 'staff');
        $this->logForProject('Restore Extension', 'admin_only');

        $actions = $this->projectActivityActions('staff');

        $this->assertContains('Request Extension', $actions);
        $this->assertContains('Revert Extension', $actions);
        $this->assertNotContains('Restore Extension', $actions);
    }

    public function test_contractor_scope_sees_all_only(): void
    {
        $this->logForProject('Request Extension', 'all');
        $this->logForProject('Revert Extension', 'staff');
        $this->logForProject('Restore Extension', 'admin_only');

        $actions = $this->projectActivityActions('contractor');

        $this->assertContains('Request Extension', $actions);
        $this->assertNotContains('Revert Extension', $actions);
        $this->assertNotContains('Restore Extension', $actions);
    }

    public function test_admin_scope_sees_everything(): void
    {
        $this->logForProject('Request Extension', 'all');
        $this->logForProject('Revert Extension', 'staff');
        $this->logForProject('Restore Extension', 'admin_only');

        $actions = $this->projectActivityActions('admin');

        $this->assertContains('Request Extension', $actions);
        $this->assertContains('Revert Extension', $actions);
        $this->assertContains('Restore Extension', $actions);
    }

    // === Attachment evidence logging (Phase 8) ===

    public function test_extension_evidence_upload_log_is_all_visibility_and_names_extension(): void
    {
        Storage::fake('public');

        $ext = $this->extension(3, 'pending', 1);

        $request = Request::create('/attachments/upload', 'POST', [
            'project_id'      => $this->project->id,
            'attachable_type' => ProjectExtension::class,
            'attachable_id'   => $ext->id,
            'context'         => 'extension',
        ]);
        $request->files->set('file', UploadedFile::fake()->createWithContent(
            'delay-evidence.pdf',
            'PDF content',
            'application/pdf'
        ));

        $this->actingAs($this->staff);
        $response = app(\App\Http\Controllers\AttachmentController::class)->upload($request);

        $this->assertTrue($response->getData()->success);

        $log = ActivityLog::where('project_id', $this->project->id)
            ->where('action', 'Upload Attachment')->first();

        $this->assertNotNull($log);
        $this->assertSame('all', $log->visibility, 'Attachment logs must be visible to non-admins (was NULL before)');
        $this->assertStringContainsString('evidence for extension #3', $log->description);

        // Both staff and contractor see it through the role scopes.
        $this->assertContains('Upload Attachment', $this->projectActivityActions('staff'));
        $this->assertContains('Upload Attachment', $this->projectActivityActions('contractor'));
    }

    public function test_overview_feed_respects_staff_tier_for_extension_entries(): void
    {
        // A real approve → 'all', a real revert → 'staff'; overview via service.
        $pending = $this->extension(1, 'pending', 1);
        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $pending]),
            ['status' => 'approved']
        )->assertOk();

        $this->actingAs($this->admin)->putJson(
            route('admin.projects.extensions.update', [$this->project, $pending]),
            ['status' => 'pending']
        )->assertOk();

        $this->actingAs($this->contractorUser);
        $overview = app(ProjectOverviewService::class)->getRecentActivity($this->project);

        $this->assertTrue($overview->contains('action', 'Approve Extension'));
        $this->assertFalse($overview->contains('action', 'Revert Extension'));
    }
}
