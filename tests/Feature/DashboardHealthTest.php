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
 * DashboardHealthTest
 * ============================================================================
 *
 * Locks down the unified gap-based project health on the admin/staff
 * dashboard table (dashboard-table-rows partial + ProjectHealthService):
 *   - behind-schedule project  → "AT RISK"
 *   - on-plan project          → "ON TRACK"
 *   - overdue project          → "OVERDUE"
 *   - zero-progress project    → "NO DATA" (grey — never green)
 *   - on-plan but open critical issue → escalated to "AT RISK"
 *
 * Run With
 * --------
 *   php artisan test --filter=DashboardHealthTest
 */
class DashboardHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'      => 'Health Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function getDashboard()
    {
        return $this->actingAs($this->admin)->get(route('admin.dashboard'));
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

    public function test_behind_schedule_project_renders_at_risk(): void
    {
        $project = $this->makeOngoing();
        // planned 80, actual 40 → gap 40 → Critical → AT RISK
        $this->addCurrentMonthData($project, actual: 40, plannedBaseline: 80);

        $this->getDashboard()
            ->assertOk()
            ->assertSee('AT RISK')
            ->assertDontSee('ON TRACK');
    }

    public function test_on_plan_project_renders_on_track(): void
    {
        $project = $this->makeOngoing();
        $this->addCurrentMonthData($project, actual: 50, plannedBaseline: 50);

        $this->getDashboard()
            ->assertOk()
            ->assertSee('ON TRACK')
            ->assertDontSee('AT RISK');
    }

    public function test_overdue_project_renders_overdue(): void
    {
        $project = $this->makeOngoing([
            'planned_end_date' => now()->subDays(5),
        ]);
        $this->addCurrentMonthData($project, actual: 40, plannedBaseline: 80);

        $this->getDashboard()
            ->assertOk()
            ->assertSee('OVERDUE')
            ->assertSee('5d overdue');
    }

    public function test_project_with_no_progress_records_renders_no_data(): void
    {
        $this->makeOngoing();

        $this->getDashboard()
            ->assertOk()
            ->assertSee('NO DATA')
            ->assertDontSee('ON TRACK');
    }

    public function test_on_plan_project_with_open_critical_issue_escalates_to_at_risk(): void
    {
        $project = $this->makeOngoing();
        $this->addCurrentMonthData($project, actual: 50, plannedBaseline: 50);

        Issue::factory()->create([
            'project_id' => $project->id,
            'status'     => 'Open',
            'severity'   => 'Critical',
        ]);

        $this->getDashboard()
            ->assertOk()
            ->assertSee('AT RISK')
            ->assertSee('Open High/Critical issue')
            ->assertDontSee('ON TRACK');
    }

    public function test_resolved_high_issue_does_not_escalate_on_track_project(): void
    {
        $project = $this->makeOngoing();
        $this->addCurrentMonthData($project, actual: 50, plannedBaseline: 50);

        Issue::factory()->create([
            'project_id' => $project->id,
            'status'     => 'Resolved',
            'severity'   => 'Critical',
        ]);

        $this->getDashboard()
            ->assertOk()
            ->assertSee('ON TRACK')
            ->assertDontSee('AT RISK');
    }

    public function test_at_risk_stat_card_counts_gap_based_not_deadline(): void
    {
        // 1 gap-based at-risk (40 vs 80 planned, deadline far out).
        $atRisk = $this->makeOngoing();
        $this->addCurrentMonthData($atRisk, actual: 40, plannedBaseline: 80);

        // 2 on-plan projects whose deadline is within 14 days — the OLD
        // deadline-based count flagged these as at risk; the gap-based count
        // must ignore them (they're on track).
        foreach ([now()->addDays(7), now()->addDays(10)] as $deadline) {
            $p = $this->makeOngoing(['planned_end_date' => $deadline]);
            $this->addCurrentMonthData($p, actual: 50, plannedBaseline: 50);
        }

        $this->getDashboard()
            ->assertOk()
            ->assertSeeInOrder([
                '<div class="db-stat-num">1</div>',
                '<div class="db-stat-label">At Risk</div>',
            ], false);
    }

    public function test_dashboard_atrisk_filter_returns_only_gap_at_risk_projects(): void
    {
        $atRisk = $this->makeOngoing(['title' => 'Flt Risky Build']);
        $this->addCurrentMonthData($atRisk, actual: 40, plannedBaseline: 80);

        // Near-deadline but on-plan — must NOT appear in the at-risk bucket.
        $onPlan = $this->makeOngoing([
            'title'           => 'Flt Near Deadline',
            'planned_end_date' => now()->addDays(7),
        ]);
        $this->addCurrentMonthData($onPlan, actual: 50, plannedBaseline: 50);

        // Overdue — its own bucket, must NOT appear here either.
        $overdue = $this->makeOngoing([
            'title'            => 'Flt Overdue Job',
            'planned_end_date' => now()->subDays(2),
        ]);
        $this->addCurrentMonthData($overdue, actual: 40, plannedBaseline: 80);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.filter', ['type' => 'atrisk']))
            ->assertOk();

        $response->assertSee('Flt Risky Build')
            ->assertDontSee('Flt Near Deadline')
            ->assertDontSee('Flt Overdue Job');
    }
}