<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AiSolution;
use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Issue;
use App\Models\PredictionHistory;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectExtension;
use App\Models\ProjectMilestone;
use App\Models\ProjectProgressHistory;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * ProjectArchiveTest
 * ============================================================================
 *
 * Locks down the project archive / restore / permanent-delete flow:
 *   - Archive moves a project out of the active list (reversible, admin/staff).
 *   - The archived-projects page lists archived projects only.
 *   - Restore returns a project to the active list (admin/staff).
 *   - Permanent deletion is ADMIN-ONLY and ONLY allowed on ARCHIVED projects,
 *     and cascades through every related record (no orphan rows).
 *
 * Run With
 * --------
 *   php artisan test --filter=ProjectArchiveTest
 */
class ProjectArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $contractorUser;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'      => 'Archive Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name'      => 'Archive Staff',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->contractorUser = User::create([
            'name'      => 'Archive Contractor',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'contractor',
            'is_active' => true,
        ]);

        $this->contractor = Contractor::create([
            'user_id'         => $this->contractorUser->id,
            'contractor_name' => 'Archive Builders',
            'company_name'    => 'Archive Builders Inc',
        ]);
    }

    public function test_admin_can_archive_project(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Admin Archive Me',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.projects.archive', $project->id))
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertSessionHas('toast_success');

        $this->assertDatabaseHas('projects', [
            'id'          => $project->id,
            'is_archived' => true,
        ]);
        $this->assertNotNull($project->fresh()->archived_at);

        // Audit trail entry exists.
        $this->assertDatabaseHas('activity_logs', [
            'user_id'    => $this->admin->id,
            'project_id' => $project->id,
            'action'     => 'Archive Project',
        ]);
    }

    public function test_staff_can_archive_project(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Staff Archive Me',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->staff)
            ->post(route('staff.projects.archive', $project->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('projects', [
            'id'          => $project->id,
            'is_archived' => true,
        ]);
    }

    public function test_contractor_cannot_archive_project(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Not For Contractor',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->contractorUser)
            ->post(route('admin.projects.archive', $project->id))
            ->assertRedirect(route('unauthorized'));

        $this->assertDatabaseHas('projects', [
            'id'          => $project->id,
            'is_archived' => false,
        ]);
    }

    public function test_archived_projects_page_lists_only_archived_projects(): void
    {
        $archived = Project::factory()->create([
            'title'      => 'In The Vault',
            'status'     => 'Completed',
            'is_archived' => true,
            'archived_at' => now(),
        ]);
        $active = Project::factory()->create([
            'title'  => 'Still Active',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.projects.archived'))
            ->assertOk()
            ->assertSee('In The Vault')
            ->assertSee('Archived Projects')
            ->assertSee('class="projects-table show"', false)
            ->assertDontSee('Still Active');
    }

    public function test_active_projects_index_excludes_archived_projects(): void
    {
        Project::factory()->create([
            'title'      => 'Hidden From Index',
            'status'     => 'Completed',
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.projects'))
            ->assertOk()
            ->assertDontSee('Hidden From Index');
    }

    public function test_admin_can_restore_archived_project(): void
    {
        $project = Project::factory()->create([
            'title'      => 'Return Me',
            'status'     => 'Ongoing',
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.projects.restore', $project->id))
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertSessionHas('toast_success');

        $fresh = $project->fresh();
        $this->assertFalse($fresh->is_archived);
        $this->assertNull($fresh->archived_at);

        $this->assertDatabaseHas('activity_logs', [
            'user_id'    => $this->admin->id,
            'project_id' => $project->id,
            'action'     => 'Restore Project',
        ]);
    }

    public function test_staff_can_restore_archived_project(): void
    {
        $project = Project::factory()->create([
            'title'      => 'Staff Return Me',
            'status'     => 'Ongoing',
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->staff)
            ->post(route('staff.projects.restore', $project->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('projects', [
            'id'          => $project->id,
            'is_archived' => false,
        ]);
    }

    public function test_cannot_permanently_delete_active_project(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Still Alive',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->admin)
            ->deleteJson(route('projects.destroy.shared', $project->id))
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_admin_permanent_delete_archived_project_cascades_all_relations(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Deep Cleanup',
            'status' => 'Completed',
        ]);

        $issue = Issue::factory()->create(['project_id' => $project->id]);
        $risk = Risk::factory()->create(['project_id' => $project->id]);
        $extension = ProjectExtension::factory()->approved()->create(['project_id' => $project->id]);
        $ai = AiSolution::factory()->create(['project_id' => $project->id]);
        $progress = ProjectProgressHistory::factory()->create(['project_id' => $project->id]);
        $baseline = ProjectBaselineSchedule::factory()->create(['project_id' => $project->id]);
        $activity = ProjectActivity::create([
            'project_id'           => $project->id,
            'baseline_schedule_id' => $baseline->id,
            'activity_name'        => 'Foundation',
            'description'          => 'Pour foundation',
            'month_label'          => '2026-09',
            'is_completed'         => false,
        ]);
        $milestone = ProjectMilestone::create([
            'project_id'          => $project->id,
            'name'                => 'Site Handover',
            'weight_percentage'   => 10,
            'progress_percentage' => 0,
            'status'              => 'Not Started',
        ]);
        $documentType = DocumentType::create(['name' => 'Report']);
        $document = Document::create([
            'project_id'       => $project->id,
            'document_type_id' => $documentType->id,
            'document_name'    => 'cleanup.pdf',
            'file_path'        => 'projects/documents/' . $project->code . '/cleanup.pdf',
        ]);
        $attachment = Attachment::create([
            'project_id'      => $project->id,
            'attachable_type' => Issue::class,
            'attachable_id'   => $issue->id,
            'context'         => 'issue',
            'original_name'   => 'photo.jpg',
            'file_path'       => 'projects/attachments/' . $project->code . '/issue/missing.jpg',
        ]);
        $prediction = PredictionHistory::create([
            'project_id'         => $project->id,
            'month_label'        => '2026-09',
            'regression_method'  => 'polynomial',
            'data_source'        => 'own',
            'current_progress'   => 50.0,
            'predicted_next'     => 55.0,
            'r_squared'          => 0.95,
            'regression_snapshot' => [
                'current_state' => ['progress' => 50.0, 'month_index' => 1],
            ],
        ]);

        // Permanent deletion is archived-only — archive first.
        $this->actingAs($this->admin)
            ->post(route('admin.projects.archive', $project->id))
            ->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson(route('projects.destroy.shared', $project->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        // The project and every related record are gone (no orphans).
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('issues', ['id' => $issue->id]);
        $this->assertDatabaseMissing('risks', ['id' => $risk->id]);
        $this->assertDatabaseMissing('project_extensions', ['id' => $extension->id]);
        $this->assertDatabaseMissing('ai_solutions', ['id' => $ai->id]);
        $this->assertDatabaseMissing('project_progress_history', ['id' => $progress->id]);
        $this->assertDatabaseMissing('project_baseline_schedules', ['id' => $baseline->id]);
        $this->assertDatabaseMissing('project_activities', ['id' => $activity->id]);
        $this->assertDatabaseMissing('project_milestones', ['id' => $milestone->id]);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        $this->assertDatabaseMissing('prediction_history', ['id' => $prediction->id]);
    }

    public function test_staff_cannot_permanently_delete_archived_project(): void
    {
        $project = Project::factory()->create([
            'title'      => 'Staff Cannot Purge',
            'status'     => 'Completed',
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->staff)
            ->deleteJson(route('projects.destroy.shared', $project->id))
            ->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_archive_does_not_emit_generic_update_project_log(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Clean Audit',
            'status' => 'Ongoing',
        ]);

        // Remove the auto-created "Create Project" log so assertions are precise.
        ActivityLog::where('project_id', $project->id)
            ->where('action', 'Create Project')
            ->delete();

        $this->actingAs($this->admin)
            ->post(route('admin.projects.archive', $project->id))
            ->assertOk();

        $actions = ActivityLog::where('project_id', $project->id)
            ->pluck('action')
            ->all();

        $this->assertSame(['Archive Project'], $actions);
    }
}
