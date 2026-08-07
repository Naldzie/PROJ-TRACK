<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectExtension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * ProjectExtensionLifecycleTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the deadline-recompute behavior for extension lifecycle changes:
 *   - Admin reverting an approved extension (approved -> pending/rejected)
 *     rolls the project's planned_end_date/extension_count back to the
 *     previous approved extension (or the original deadline when none).
 *   - Admin editing an approved extension's new_end_date moves the deadline.
 *   - Deleting a non-active approved extension keeps the deadline correct.
 *   - The active approved extension cannot be deleted (guard).
 *   - Staff may delete pending/rejected extensions but never approved ones.
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
        ]);
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

    // === Delete: admin ===

    public function test_admin_deleting_non_active_approved_keeps_deadline(): void
    {
        $first = $this->extension(1, 'approved', 1);
        $second = $this->extension(2, 'approved', 2);
        $this->project->update([
            'planned_end_date' => $second->new_end_date,
            'extension_count'  => 2,
        ]);

        $this->actingAs($this->admin)->deleteJson(
            route('admin.projects.extensions.destroy', [$this->project, $first])
        )->assertOk();

        $this->assertDatabaseMissing('project_extensions', ['id' => $first->id]);
        $this->assertTrue($this->project->fresh()->planned_end_date->equalTo($second->new_end_date));
        $this->assertSame(2, $this->project->fresh()->extension_count);
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
            ->assertJsonFragment(['message' => 'Cannot delete the active approved extension. Create a new one to replace it.']);

        $this->assertDatabaseHas('project_extensions', ['id' => $second->id]);
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
        $this->assertDatabaseMissing('project_extensions', ['id' => $pending->id]);

        $this->actingAs($this->staff)->deleteJson(
            route('staff.projects.extensions.destroy', [$this->project, $rejected])
        )->assertOk();
        $this->assertDatabaseMissing('project_extensions', ['id' => $rejected->id]);

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
}
