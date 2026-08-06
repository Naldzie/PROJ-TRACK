<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * DashboardOverdueInspectionTest
 * ============================================================================
 *
 * Covers the new "Overdue Inspections" pill in the admin/staff dashboard
 * "Needs attention" bar:
 *   - ongoing, non-archived projects that have ≥1 baseline month whose planned
 *     target date is past the notification grace window (default 7 days) AND
 *     with no recorded progress for that month.
 *
 * This intentionally mirrors SendNotifications@sendOverdueInspectionReminders
 * so the dashboard and the notification alerts stay in sync, and it is
 * deliberately distinct from "Pending Updates" (which is current-month based
 * and date-agnostic).
 *
 * Run With
 * --------
 *   php artisan test --filter=DashboardOverdueInspectionTest
 */
class DashboardOverdueInspectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'      => 'Dash Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        // Fixed "today" so the 7-day grace window (cutoff = 2026-07-30) is deterministic.
        $this->travelTo(Carbon::parse('2026-08-06 12:00:00'));
    }

    private function ongoingProject(string $title = 'Inspection Proj'): Project
    {
        return Project::factory()->create([
            'title'  => $title,
            'status' => 'Ongoing',
        ]);
    }

    private function baseline(Project $project, string $monthLabel, string $plannedDate): ProjectBaselineSchedule
    {
        return ProjectBaselineSchedule::factory()->create([
            'project_id'                        => $project->id,
            'month_label'                       => $monthLabel,
            'planned_date'                      => $plannedDate,
            'planned_progress_percent'          => 10,
            'original_planned_progress_percent' => 10,
        ]);
    }

    private function record(Project $project, string $monthLabel): void
    {
        ProjectProgressHistory::factory()->create([
            'project_id'              => $project->id,
            'month_label'             => $monthLabel,
            'actual_progress_percent' => 10,
            'inspection_date'         => Carbon::parse($monthLabel . '-01'),
        ]);
    }

    public function test_future_inspection_target_is_not_overdue(): void
    {
        $proj = $this->ongoingProject('Future Target');
        $this->baseline($proj, '2026-08', '2026-08-31');

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Overdue Inspections');
    }

    public function test_target_past_grace_without_record_is_overdue(): void
    {
        $proj = $this->ongoingProject('Past Due');
        $this->baseline($proj, '2026-07', '2026-07-20');

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Overdue Inspections');
    }

    public function test_target_within_grace_window_is_not_overdue(): void
    {
        $proj = $this->ongoingProject('Within Grace');
        $this->baseline($proj, '2026-08', '2026-08-03'); // 3 days past, grace is 7

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Overdue Inspections');
    }

    public function test_recorded_month_is_not_overdue(): void
    {
        $proj = $this->ongoingProject('Recorded');
        $this->baseline($proj, '2026-07', '2026-07-20');
        $this->record($proj, '2026-07');

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Overdue Inspections');
    }

    public function test_project_without_baseline_is_not_overdue(): void
    {
        $this->ongoingProject('No Baseline');

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Overdue Inspections');
    }

    public function test_backdated_uninspected_month_is_overdue_even_when_current_month_recorded(): void
    {
        // Proves the distinction from "Pending Updates": current month (Aug) is
        // recorded, but June is still uninspected and past grace → still overdue.
        $proj = $this->ongoingProject('Backlog');
        $this->baseline($proj, '2026-06', '2026-06-15');
        $this->baseline($proj, '2026-08', '2026-08-31');
        $this->record($proj, '2026-08');

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Overdue Inspections');
    }

    public function test_count_badge_matches_ajax_filter_list(): void
    {
        $p1 = $this->ongoingProject('Overdue One');
        $this->baseline($p1, '2026-07', '2026-07-20');

        $p2 = $this->ongoingProject('Overdue Two');
        $this->baseline($p2, '2026-06', '2026-06-10');

        $ok = $this->ongoingProject('On Track');
        $this->baseline($ok, '2026-08', '2026-08-31');

        $dashboard = $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk();

        $this->assertEquals(2, $dashboard->viewData('overdueInspectionCount'));

        $list = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.filter', ['type' => 'inspection_overdue']))
            ->assertOk()
            ->assertSee('Overdue One')
            ->assertSee('Overdue Two')
            ->assertDontSee('On Track');

        // Count badge == number of rows returned by the AJAX filter.
        $this->assertEquals(2, substr_count($list->getContent(), 'data-overdue="'));
    }

    public function test_archived_and_non_ongoing_projects_are_excluded(): void
    {
        $archived = $this->ongoingProject('Archived');
        $this->baseline($archived, '2026-07', '2026-07-20');
        $archived->update(['is_archived' => true]);

        $completed = $this->ongoingProject('Completed Proj');
        $this->baseline($completed, '2026-07', '2026-07-20');
        $completed->update(['status' => 'Completed']);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Overdue Inspections');
    }
}
