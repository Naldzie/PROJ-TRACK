<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * ContractorDashboardTest
 * ============================================================================
 *
 * Validates the contractor dashboard (Phase A UI polish) — real data now
 * replaces the previously hardcoded placeholder layout:
 *   - reachable by contractors only (other roles are redirected away).
 *   - data is scoped strictly to the logged-in contractor's own projects.
 *   - stat cards / timeline / budget / donut / deadlines / activity feed are
 *     driven by live models.
 *
 * Run With
 * --------
 *   php artisan test --filter=ContractorDashboardTest
 */
class ContractorDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $contractorUser;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractorUser = User::create([
            'name'     => 'Dash Contractor',
            'email'    => fake()->unique()->safeEmail(),
            'role'     => 'contractor',
            'is_active' => true,
        ]);

        $this->contractor = Contractor::create([
            'user_id'         => $this->contractorUser->id,
            'contractor_name' => 'Dash Builders',
            'company_name'    => 'Dash Builders Inc',
        ]);
    }

    private function assignProject(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'contractor_id' => $this->contractor->id,
            'status'        => 'Ongoing',
        ], $overrides));
    }

    public function test_contractor_can_view_their_dashboard_with_real_data(): void
    {
        $project = $this->assignProject(['title' => 'Bridge Renovation Alpha']);

        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'inspection_date'         => now()->subDay(),
            'month_label'             => now()->format('Y-m'),
            'actual_progress_percent' => 45,
            'progress_percentage'     => 45,
        ]);

        ActivityLog::create([
            'user_id'     => $this->contractorUser->id,
            'project_id'  => $project->id,
            'action'      => 'Create Progress Record',
            'description' => 'Recorded 45% progress for Bridge Renovation Alpha',
            'visibility'  => 'all',
        ]);

        $this->actingAs($this->contractorUser)
            ->get(route('contractor.dashboard'))
            ->assertOk()
            ->assertSee('My Projects Dashboard')
            ->assertSee('My Projects')
            ->assertSee('This Month')
            ->assertSee('Health')
            ->assertSee('Bridge Renovation Alpha')
            ->assertSee('45%')
            ->assertSee('Recorded 45% progress for Bridge Renovation Alpha')
            ->assertSee('View all');
    }

    public function test_contractor_only_sees_their_own_projects(): void
    {
        $this->assignProject(['title' => 'My Own Project']);

        $otherUser = User::create([
            'name'     => 'Other Contractor',
            'email'    => fake()->unique()->safeEmail(),
            'role'     => 'contractor',
            'is_active' => true,
        ]);
        $otherContractor = Contractor::create([
            'user_id'         => $otherUser->id,
            'contractor_name' => 'Other Builders',
            'company_name'    => 'Other Builders Inc',
        ]);
        Project::factory()->create([
            'title'         => 'Secret Project Beta',
            'contractor_id' => $otherContractor->id,
            'status'        => 'Ongoing',
        ]);

        $this->actingAs($this->contractorUser)
            ->get(route('contractor.dashboard'))
            ->assertOk()
            ->assertSee('My Own Project')
            ->assertDontSee('Secret Project Beta');
    }

    public function test_status_stat_cards_reflect_real_counts(): void
    {
        $this->assignProject(['status' => 'Completed']);
        $this->assignProject(['status' => 'Completed']);
        $this->assignProject(['status' => 'Ongoing']);
        $this->assignProject(['status' => 'Planned']);

        $this->actingAs($this->contractorUser)
            ->get(route('contractor.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Total assigned', '4'])
            ->assertSeeInOrder(['Completed', '2', '50% of total'])
            ->assertSeeInOrder(['In progress', '1', '25% of total'])
            ->assertSeeInOrder(['Not yet started', '1', '25% of total']);
    }

    public function test_contractor_with_no_projects_sees_empty_states(): void
    {
        $this->actingAs($this->contractorUser)
            ->get(route('contractor.dashboard'))
            ->assertOk()
            ->assertSee('No ongoing projects at the moment')
            ->assertSee('No upcoming deadlines.')
            ->assertSee('No recent activity.');
    }

    public function test_non_contractor_roles_are_redirected_away(): void
    {
        $admin = User::create([
            'name'     => 'Dash Admin',
            'email'    => fake()->unique()->safeEmail(),
            'role'     => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('contractor.dashboard'))
            ->assertRedirect(route('unauthorized'));
    }
}
