<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * ProjectsHealthIndexTest
 * ============================================================================
 *
 * Locks down the unified gap-based project health on the shared projects
 * index (grid + table + metric card + ?filter=atrisk deep link), driven by
 * ProjectHealthService via the controller's pre-computed $healthMap:
 *   - behind-schedule project  → "At Risk" badge
 *   - on-plan project          → "On Track" badge
 *   - zero-progress project    → "No Data" badge (grey)
 *   - on-plan but open critical issue → escalated "At Risk" badge
 *   - metric card counts ALL ongoing at-risk projects (not just the page)
 *   - ?filter=atrisk returns only gap-based at-risk projects
 *
 * Run With
 * --------
 *   php artisan test --filter=ProjectsHealthIndexTest
 */
class ProjectsHealthIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'      => 'Projects Health Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function getIndex(array $query = [])
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.projects', $query));
    }

    private function makeOngoing(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'status'             => 'Ongoing',
            'planned_start_date' => now()->subMonths(2),
            'planned_end_date'   => now()->addMonths(2),
        ], $overrides));
    }

    /**
     * Current-month actual + (optionally) a current-month baseline target.
     */
    private function addCurrentMonthData(Project $project, float $actual, ?float $plannedBaseline = null): void
    {
        ProjectProgressHistory::factory()->forMonth(
            1,
            now()->format('Y-m'),
            now()->format('Y-m-d'),
            $actual
        )->create([
            'project_id'              => $project->id,
            // Force the planned lookup to use the baseline (branch 2), not a
            // random factory value on the progress record (branch 1).
            'planned_progress_percent' => null,
        ]);

        if ($plannedBaseline !== null) {
            ProjectBaselineSchedule::factory()->forMonth(
                1,
                now()->format('Y-m'),
                now()->endOfMonth()->format('Y-m-d'),
                $plannedBaseline
            )->create(['project_id' => $project->id]);
        }
    }

    public function test_index_at_risk_project_renders_at_risk_badge(): void
    {
        $project = $this->makeOngoing(['title' => 'Risky Road']);
        $this->addCurrentMonthData($project, actual: 40, plannedBaseline: 80);

        $this->getIndex()
            ->assertOk()
            ->assertSee('Risky Road')
            ->assertSee('<i class="bi bi-exclamation-diamond-fill"></i> At Risk', false)
            ->assertDontSee('<i class="bi bi-check-circle-fill"></i> On Track', false);
    }

    public function test_index_on_plan_project_renders_on_track_badge(): void
    {
        $project = $this->makeOngoing(['title' => 'Smooth Sail']);
        $this->addCurrentMonthData($project, actual: 50, plannedBaseline: 50);

        $this->getIndex()
            ->assertOk()
            ->assertSee('Smooth Sail')
            ->assertSee('<i class="bi bi-check-circle-fill"></i> On Track', false)
            ->assertDontSee('health-badge at-risk', false);
    }

    public function test_index_no_data_project_renders_no_data_badge(): void
    {
        $project = $this->makeOngoing(['title' => 'Quiet Site']);
        // No progress history, no baseline → NO DATA (grey, never green).

        $this->getIndex()
            ->assertOk()
            ->assertSee('Quiet Site')
            ->assertSee('<i class="bi bi-hourglass-split"></i> No Data', false)
            ->assertDontSee('<i class="bi bi-check-circle-fill"></i> On Track', false);
    }

    public function test_index_on_plan_project_with_open_critical_issue_escalates(): void
    {
        $project = $this->makeOngoing(['title' => 'Escalating Build']);
        $this->addCurrentMonthData($project, actual: 50, plannedBaseline: 50);

        Issue::factory()->create([
            'project_id' => $project->id,
            'status'     => 'Open',
            'severity'   => 'Critical',
        ]);

        $this->getIndex()
            ->assertOk()
            ->assertSee('Escalating Build')
            ->assertSee('<i class="bi bi-exclamation-diamond-fill"></i> At Risk', false)
            ->assertDontSee('<i class="bi bi-check-circle-fill"></i> On Track', false);
    }

    public function test_index_metric_counts_all_at_risk_not_just_page(): void
    {
        // 5 at-risk projects (OLDEST via created_at) + 20 no-data projects
        // (newest). Page 1 (15 newest) therefore shows zero at-risk badges,
        // yet the metric card must still read 5 (gap-based, across ALL).
        for ($i = 0; $i < 5; $i++) {
            $p = $this->makeOngoing(['created_at' => now()->subDays(10)]);
            $this->addCurrentMonthData($p, actual: 40, plannedBaseline: 80);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->makeOngoing(['created_at' => now()]);
        }

        $response = $this->getIndex()->assertOk();

        // Metric card: At Risk label immediately followed by value 5.
        $response->assertSeeInOrder([
            '<i class="bi bi-exclamation-diamond-fill"></i> At Risk',
            '>5<',
        ], false);

        // Page 1 must not render any at-risk BADGE (they live on page 2).
        $response->assertDontSee('health-badge at-risk', false);
    }

    public function test_index_filter_atrisk_returns_only_gap_at_risk_projects(): void
    {
        $atRisk = $this->makeOngoing(['title' => 'Filter Risky']);
        $this->addCurrentMonthData($atRisk, actual: 40, plannedBaseline: 80);

        $onTrack = $this->makeOngoing(['title' => 'Filter Fine']);
        $this->addCurrentMonthData($onTrack, actual: 50, plannedBaseline: 50);

        $noData = $this->makeOngoing(['title' => 'Filter Quiet']);
        // No records → NO DATA, must NOT appear in the at-risk bucket.

        $response = $this->getIndex(['filter' => 'atrisk'])->assertOk();

        $response->assertSee('Filter Risky')
            ->assertSee('At Risk Projects')
            ->assertDontSee('Filter Fine')
            ->assertDontSee('Filter Quiet');
    }

    public function test_index_metric_cards_match_dashboard_and_hide_budget(): void
    {
        // 3 ongoing (1 on-track, 1 at-risk, 1 overdue) + 1 completed.
        $onTrack = $this->makeOngoing(['title' => 'Metric Fine']);
        $this->addCurrentMonthData($onTrack, actual: 50, plannedBaseline: 50);

        $atRisk = $this->makeOngoing(['title' => 'Metric Risky']);
        $this->addCurrentMonthData($atRisk, actual: 40, plannedBaseline: 80);

        $overdue = $this->makeOngoing(['title' => 'Metric Late', 'planned_end_date' => now()->subDays(3)]);
        $this->addCurrentMonthData($overdue, actual: 40, plannedBaseline: 80);

        $this->makeOngoing(['title' => 'Metric Done', 'status' => 'Completed']);

        $response = $this->getIndex()->assertOk();

        // Same card set as the admin dashboard: Total / Ongoing / Overdue /
        // At Risk / Completed. Budget card removed.
        $response->assertSeeInOrder([
            'Total Projects', '>4<',
            'Ongoing',        '>3<',
            'Overdue',        '>1<',
            'At Risk',        '>1<',
            'Completed',      '>1<',
        ], false);

        $response->assertDontSee('Total Budget', false)
            ->assertDontSee('bi-cash-stack', false);
    }
}
