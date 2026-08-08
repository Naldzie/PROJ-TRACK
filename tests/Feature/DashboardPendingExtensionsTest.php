<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectExtension;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * DashboardPendingExtensionsTest
 * ============================================================================
 *
 * Covers the "Extension Requests" pill in the admin dashboard "Needs
 * attention" bar:
 *   - ongoing, non-archived projects with ≥1 extension request in 'pending'
 *     status (mirrors ProjectExtension::scopePending()).
 *   - Admin-only: staff cannot review/approve extensions, so the pill is
 *     hidden for them (shared dashboard view).
 *   - Count badge == number of projects with a pending request (distinct
 *     projects, even when a project has multiple pending rows).
 *
 * Run With
 * --------
 *   php artisan test --filter=DashboardPendingExtensionsTest
 */
class DashboardPendingExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'      => 'Dash Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name'      => 'Dash Staff',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->travelTo(Carbon::parse('2026-08-06 12:00:00'));
    }

    private function ongoingProject(string $title): Project
    {
        return Project::factory()->create([
            'title'  => $title,
            'status' => 'Ongoing',
        ]);
    }

    private function pendingExtension(Project $project, int $number = 1): ProjectExtension
    {
        return ProjectExtension::factory()->create([
            'project_id'       => $project->id,
            'extension_number' => $number,
            'status'           => 'pending',
        ]);
    }

    public function test_pill_is_hidden_when_no_pending_requests(): void
    {
        $this->ongoingProject('No Requests');

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Extension Requests');
    }

    public function test_pill_shows_for_admin_when_request_is_pending(): void
    {
        $this->pendingExtension($this->ongoingProject('Needs Review'));

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Extension Requests');
    }

    public function test_approved_or_rejected_requests_do_not_show_pill(): void
    {
        $proj = $this->ongoingProject('Already Decided');

        ProjectExtension::factory()->create([
            'project_id' => $proj->id,
            'status'     => 'approved',
        ]);
        ProjectExtension::factory()->create([
            'project_id' => $proj->id,
            'status'     => 'rejected',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Extension Requests');
    }

    public function test_pill_is_hidden_for_staff(): void
    {
        $this->pendingExtension($this->ongoingProject('Staff View'));

        $this->actingAs($this->staff)
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertDontSee('Extension Requests');
    }

    public function test_archived_and_non_ongoing_projects_are_excluded(): void
    {
        $archived = $this->ongoingProject('Archived Proj');
        $this->pendingExtension($archived);
        $archived->update(['is_archived' => true]);

        $completed = $this->ongoingProject('Completed Proj');
        $this->pendingExtension($completed);
        $completed->update(['status' => 'Completed']);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Extension Requests');
    }

    public function test_count_counts_distinct_projects_not_request_rows(): void
    {
        $one = $this->ongoingProject('One Request');
        $this->pendingExtension($one);

        $two = $this->ongoingProject('Two Requests');
        $this->pendingExtension($two, 1);
        $this->pendingExtension($two, 2);

        $ok = $this->ongoingProject('No Requests');

        $dashboard = $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk();

        // 2 distinct projects, even though one has two pending rows.
        $this->assertEquals(2, $dashboard->viewData('pendingExtensionCount'));
    }

    public function test_ajax_filter_returns_only_projects_with_pending_requests(): void
    {
        $one = $this->ongoingProject('Pending One');
        $this->pendingExtension($one);

        $two = $this->ongoingProject('Pending Two');
        $this->pendingExtension($two);

        $ok = $this->ongoingProject('All Clear');

        $list = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.filter', ['type' => 'pending_extensions']))
            ->assertOk()
            ->assertSee('Pending One')
            ->assertSee('Pending Two')
            ->assertDontSee('All Clear');

        // Badge count == number of rows returned by the AJAX filter.
        $this->assertEquals(2, substr_count($list->getContent(), 'data-overdue="'));
    }
}
