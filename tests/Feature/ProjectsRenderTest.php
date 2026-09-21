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

    public function test_admin_index_renders_archive_loading_overlay(): void
    {
        Project::factory()->create([
            'title'  => 'Render Overlay Project',
            'status' => 'Ongoing',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.projects'))
            ->assertOk()
            ->assertSee('archiveProjectLoadingOverlay', false)
            ->assertSee('Working…')
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
            ->assertSee('Issues', false)
            ->assertDontSee('Issues & Risks', false)
            ->assertSee('Back to Projects');
    }

    /**
     * The risk register is hidden (2026-09-21, textfiles/risk_register_hide_plan.md):
     * the client records current issues and problems only, not prospective
     * risks. The Issues tab still renders; nothing of the register reaches the
     * page — its section, toggle, modals, CSS and script are Blade-commented.
     */
    public function test_project_detail_hides_the_risk_register(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.projects.detail', $project->id))
            ->assertOk()
            ->getContent();

        // The Issues tab is still there
        $this->assertStringContainsString('id="issuesSection"', $html);
        $this->assertStringContainsString('Track issues and problems encountered on the project', $html);

        // …and no part of the register is sent to the browser
        foreach (['id="risksSection"', 'Risk Register', 'risk-toggle-btn', 'id="riskModal"',
                  'id="riskToIssueModal"', 'risk-register.js', 'risk-register.css', 'switchToRisksTab()',
                  'RISK REGISTER HIDDEN'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "Hidden risk-register markup leaked: {$needle}");
        }
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

        // Permanent deletion only applies to ARCHIVED projects — archive first.
        $this->actingAs($this->admin)
            ->post(route('admin.projects.archive', $project->id))
            ->assertOk();

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

        // The flashed toast must be rendered by the archived page on the reload.
        // (Note: @json() escapes apostrophes, so match the un-escaped tail.)
        $this->actingAs($this->admin)
            ->get(route('admin.projects.archived'))
            ->assertOk()
            ->assertSee('has been permanently deleted.', false);
    }

    public function test_staff_cannot_delete_project(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Untouchable',
            'status' => 'Ongoing',
        ]);

        // Archive it first so the staff request reaches the role check
        // (staff may archive but never permanently delete).
        $this->actingAs($this->staff)
            ->post(route('staff.projects.archive', $project->id))
            ->assertOk();

        $response = $this->actingAs($this->staff)
            ->deleteJson(route('projects.destroy.shared', $project->id));

        $response->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_admin_index_renders_create_modal_stepper_and_auto_add_documents(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.projects'))
            ->assertOk()
            ->assertSee('step-wizard', false)
            ->assertSee('step1-indicator', false)
            // Step labels carry ids so edit mode can renumber them (Documents removed)
            ->assertSee('step1-label', false)
            ->assertSee('step5-label', false)
            ->assertSee('documentFileInput', false)
            // No "Add Document" button — files auto-add on selection
            ->assertDontSee('addDocumentBtn', false)
            // Edit mode no longer shows the existing-documents section on page 1
            ->assertDontSee('existingDocumentsSection', false)
            ->assertSee('Choose the document type first', false);
    }

    public function test_project_detail_renders_toast_success_script_when_flashed(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Flashed Toast Project',
            'status' => 'Ongoing',
        ]);

        session()->flash('toast_success', 'Project created successfully.');

        $this->actingAs($this->admin)
            ->get(route('admin.projects.detail', $project->id))
            ->assertOk()
            ->assertSee('Project created successfully.', false)
            ->assertSee('showToast', false);
    }

    public function test_project_detail_baseline_tab_consumes_pending_toast(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Pending Toast Project',
            'status' => 'Ongoing',
        ]);

        // The baseline tab loads the shared utils (queueToastAfterReload /
        // consumePendingToast), consumes any queued toast on DOMContentLoaded,
        // and the delete-month flow queues its toast via sessionStorage so it
        // shows AFTER the reload (fixing the toast-before-reload flicker).
        $this->actingAs($this->admin)
            ->get(route('admin.projects.detail', $project->id))
            ->assertOk()
            ->assertSee('baseline-schedule-utils.js', false)
            ->assertSee('consumePendingToast()', false)
            ->assertSee('queueToastAfterReload', false);
    }

    public function test_project_detail_month_badge_carries_machine_month_label(): void
    {
        $project = Project::factory()->create([
            'title'  => 'Month Label Project',
            'status' => 'Ongoing',
        ]);

        \App\Models\ProjectBaselineSchedule::factory()->forMonth(0, '2026-09', '2026-09-01', 25)
            ->create(['project_id' => $project->id]);

        // The month card badge shows the human-readable "September - 2026" but
        // must ALSO carry the machine month_label in data-month-label so the
        // carry-over / inline-refresh JS can match cards reliably (the old
        // textContent === month_label compare never matched).
        $this->actingAs($this->admin)
            ->get(route('admin.projects.detail', $project->id))
            ->assertOk()
            ->assertSee('September - 2026', false)
            ->assertSee('data-month-label="2026-09"', false);
    }
}
