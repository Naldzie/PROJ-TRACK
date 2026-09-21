<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * CreateProjectEntryTest
 * ============================================================================
 *
 * The dashboard's "New Project" buttons open the Create Project modal
 * (2026-09-21). They link to the projects page with ?create=1, and a script in
 * admin/_create_project_modal.blade.php opens the modal on arrival. The flag is a
 * one-shot UI signal, so ProjectController::index() drops it before the
 * paginator's withQueryString() and the filter links copy it — otherwise the next
 * page or filter click would open the modal again.
 *
 * Run With
 * --------
 *   php artisan test --filter=CreateProjectEntryTest
 */
class CreateProjectEntryTest extends TestCase
{
    use RefreshDatabase;

    // One admin for the requests below.
    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Entry Admin',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    // Both dashboard "New Project" buttons (hero + timeline widget) carry the flag.
    // Called by: resources/views/admin/dashboard.blade.php, dashboard/partials/timeline-widget.blade.php.
    public function test_dashboard_new_project_buttons_open_the_create_modal(): void
    {
        $response = $this->actingAs($this->makeAdmin())->get(route('admin.dashboard'));

        $response->assertOk();
        $href = 'href="' . route('admin.projects', ['create' => 1]) . '"';
        $this->assertSame(2, substr_count($response->getContent(), $href),
            'the hero button and the timeline widget button both link with ?create=1');
    }

    // The projects page arrived at with ?create=1 still renders the modal, but no link
    // on it (pagination, filters) carries the flag onward.
    // Called by: ProjectController::index() via the dashboard link.
    public function test_the_create_flag_is_not_copied_into_the_page_links(): void
    {
        Project::factory()->count(12)->create(['is_archived' => false]);

        $response = $this->actingAs($this->makeAdmin())
            // per_page accepts only 10/15/25/50; 10 with 12 projects forces a page 2.
            ->get(route('admin.projects', ['create' => 1, 'per_page' => 10]));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('id="addProjectModal"', $html, 'the modal is on the page to be opened');
        $this->assertMatchesRegularExpression('/href="[^"]*page=2[^"]*"/', $html, 'pagination links are rendered');
        $this->assertSame(0, preg_match_all('/href="[^"]*create=1[^"]*"/', $html),
            'no link may carry ?create=1 onward');
    }
}
