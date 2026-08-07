<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * ProjectsRenderTest
 * ============================================================================
 *
 * Locks down the Phase C UI-polish consistency fixes on the shared projects
 * index + project detail pages (all three roles render the same Blade files):
 *   - Admin/staff deep-link filter banner labels + "Clear filter" targets
 *     (admin/staff → their own index; contractor must NOT be pointed at
 *     admin.projects).
 *   - The table-view empty state spans all 13 columns (was colspan="9").
 *   - Project detail header renders the Project Manager meta-item with a
 *     label + value (was an icon with no meta-info).
 *
 * Run With
 * --------
 *   php artisan test --filter=ProjectsRenderTest
 */
class ProjectsRenderTest extends TestCase
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
            'name'      => 'Render Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name'      => 'Render Staff',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->contractorUser = User::create([
            'name'      => 'Render Contractor',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'contractor',
            'is_active' => true,
        ]);

        $this->contractor = Contractor::create([
            'user_id'         => $this->contractorUser->id,
            'contractor_name' => 'Render Builders',
            'company_name'    => 'Render Builders Inc',
        ]);
    }

    public function test_admin_index_renders_with_project_cards(): void
    {
        Project::factory()->create([
            'title' => 'Render Index Project',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.projects'))
            ->assertOk()
            ->assertSee('Project Monitor')
            ->assertSee('Render Index Project');
    }

    public function test_admin_index_renders_delete_loading_overlay(): void
    {
        Project::factory()->create([
            'title'  => 'Render Overlay Project',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.projects'))
            ->assertOk()
            ->assertSee('deleteProjectLoadingOverlay', false)
            ->assertSee('Deleting project…')
            ->assertSee('Almost done…');
    }

    public function test_admin_index_shows_filter_banner_with_clear_link_to_admin_index(): void
    {
        Project::factory()->create([
            'status'           => 'Ongoing',
            'planned_end_date' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.projects', ['filter' => 'overdue']))
            ->assertOk();

        $response->assertSee('Overdue Projects')
            ->assertSee(route('admin.projects'))
            ->assertSee('Clear filter');
    }

    public function test_contractor_index_clear_filter_targets_contractor_index_not_admin(): void
    {
        Project::factory()->create([
            'title'         => 'My Contracted Job',
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);

        // Contractor + deep-link query: controller ignores the filter param for
        // contractors, so no banner should render — and even if it did, the
        // clear target must never be admin.projects.
        $response = $this->actingAs($this->contractorUser)
            ->get(route('contractor.projects', ['filter' => 'overdue']))
            ->assertOk();

        $response->assertDontSee('Clear filter');
        $response->assertDontSee(route('admin.projects'));
    }

    public function test_index_table_empty_state_spans_all_columns(): void
    {
        // No projects → table view shows the empty row.
        $response = $this->actingAs($this->admin)
            ->get(route('admin.projects'))
            ->assertOk();

        $response->assertSee('No projects found');
        $response->assertSee('colspan="13"', false);
    }

    public function test_admin_project_detail_renders_header_stats_and_tabs(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Detail Project Alpha',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.projects.detail', $project->id))
            ->assertOk()
            ->assertSee('Detail Project Alpha')
            ->assertSee('Overview')
            ->assertSee('Progress Tracking')
            ->assertSee('Issues & Risks', false)
            ->assertSee('Back to Projects');
    }

    public function test_project_detail_header_shows_project_manager_meta_when_assigned(): void
    {
        $project = Project::factory()->create([
            'title'                    => 'Managed Project',
            'status'                   => 'Ongoing',
            'assigned_project_manager' => 'Jane Doe',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.projects.detail', $project->id))
            ->assertOk();

        $response->assertSee('<strong>Project Manager</strong>', false)
            ->assertSee('Jane Doe');
    }

    public function test_project_detail_header_omits_manager_meta_when_unassigned(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Unmanaged Project',
            'status' => 'Ongoing',
        ]);

        // The create/edit modal always ships a "Select Project Manager" option,
        // so assert against the header's own markup (the <strong> inside .meta-info).
        $this->actingAs($this->admin)
            ->get(route('admin.projects.detail', $project->id))
            ->assertOk()
            ->assertDontSee('<strong>Project Manager</strong>', false);
    }

    public function test_contractor_can_view_their_assigned_project_detail(): void
    {
        $project = Project::factory()->create([
            'title'         => 'My Own Detail',
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);

        $this->actingAs($this->contractorUser)
            ->get(route('projects.show', $project->id))
            ->assertOk()
            ->assertSee('My Own Detail')
            ->assertSee('Back to Projects');
    }

    public function test_contractor_cannot_view_unassigned_project_detail(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Not Yours',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->contractorUser)
            ->get(route('projects.show', $project->id))
            ->assertForbidden();
    }

    public function test_admin_delete_removes_project_and_related_records(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Doomed Project',
            'status' => 'Ongoing',
        ]);

        $progress = \App\Models\ProjectProgressHistory::factory()->create([
            'project_id' => $project->id,
        ]);
        $baseline = \App\Models\ProjectBaselineSchedule::factory()->create([
            'project_id' => $project->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson(route('projects.destroy.shared', $project->id));

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertSessionHas('toast_success');

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('project_progress_history', ['id' => $progress->id]);
        $this->assertDatabaseMissing('project_baseline_schedules', ['id' => $baseline->id]);

        // The flashed toast must be rendered by the index page on the reload.
        // (Note: @json() escapes apostrophes, so match the un-escaped tail.)
        $this->actingAs($this->admin)
            ->get(route('admin.projects'))
            ->assertOk()
            ->assertSee('has been permanently deleted.', false);
    }

    public function test_staff_cannot_delete_project(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Untouchable',
            'status' => 'Ongoing',
        ]);

        $response = $this->actingAs($this->staff)
            ->deleteJson(route('projects.destroy.shared', $project->id));

        $response->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }
}
