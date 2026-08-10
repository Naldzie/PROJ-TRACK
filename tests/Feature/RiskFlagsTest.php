<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use App\Services\ProjectChartService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * ============================================================================
 * RiskFlagsTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the real-data Risk Flags & Alerts feature on the Progress tab:
 *   - ProjectChartService::prepareQualityChecks() returns ready=false when
 *     there are no baseline schedules.
 *   - Critical flags: overdue missing months, decreasing-progress anomalies,
 *     and the AI projected-deadline-miss warning.
 *   - Warning flags: missing (not-yet-overdue) months and a next pending month
 *     with a high target.
 *   - A healthy project yields has_flags = false.
 *   - The chart-data AJAX endpoint exposes quality_checks to the frontend.
 *
 * Conventions
 * -----------
 *   - Services invoked DIRECTLY with seeded data.
 *   - Route test uses actingAs(admin) + getJson (route auth works here).
 *
 * Run With
 * --------
 *   php artisan test --filter=RiskFlagsTest
 */
class RiskFlagsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'      => 'Risk Flags Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function service(): ProjectChartService
    {
        return app(ProjectChartService::class);
    }

    private function baseline(
        Project $project,
        string $monthLabel,
        string $plannedDate,
        float $pct
    ): ProjectBaselineSchedule {
        return ProjectBaselineSchedule::create([
            'project_id'                        => $project->id,
            'month_label'                       => $monthLabel,
            'planned_date'                      => Carbon::parse($plannedDate)->format('Y-m-d'),
            'planned_progress_percent'          => $pct,
            'original_planned_progress_percent' => $pct,
        ]);
    }

    private function progress(
        Project $project,
        string $monthLabel,
        string $inspectionDate,
        float $actual
    ): ProjectProgressHistory {
        return ProjectProgressHistory::create([
            'project_id'              => $project->id,
            'month_label'             => $monthLabel,
            'inspection_date'         => Carbon::parse($inspectionDate)->format('Y-m-d'),
            'actual_progress_percent' => $actual,
            'progress_percentage'     => $actual,
        ]);
    }

    // -- ready=false when no baseline schedule exists --

    public function test_quality_checks_not_ready_without_baseline(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $checks = $this->service()->prepareQualityChecks($project, []);

        $this->assertFalse($checks['ready']);
        $this->assertFalse($checks['has_flags']);
        $this->assertEmpty($checks['critical']);
        $this->assertEmpty($checks['warnings']);
    }

    // -- Critical: overdue missing month --

    public function test_overdue_missing_month_raises_critical_flag(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $this->baseline($project, '2026-01', '2026-01-31', 10);
        $this->baseline($project, '2026-02', '2026-02-28', 20);

        // Jan has a record, Feb is overdue (past date, no record)
        $this->progress($project, '2026-01', '2026-01-20', 8);

        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $checks = $this->service()->prepareQualityChecks($project, []);

        $this->assertTrue($checks['ready']);
        $this->assertTrue($checks['has_flags']);

        $titles = array_column($checks['critical'], 'title');
        $this->assertContains('Overdue Inspections', $titles);
        $this->assertStringContainsString('Feb 2026', $checks['critical'][0]['message']);

        Carbon::setTestNow();
    }

    // -- Critical: decreasing-progress anomaly --

    public function test_decreasing_progress_raises_critical_flag(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $this->baseline($project, '2026-01', '2026-01-31', 10);
        $this->baseline($project, '2026-02', '2026-02-28', 20);

        // Actual drops from 15% -> 10% (decreasing anomaly)
        $this->progress($project, '2026-01', '2026-01-20', 15);
        $this->progress($project, '2026-02', '2026-02-20', 10);

        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $checks = $this->service()->prepareQualityChecks($project, []);

        $titles = array_column($checks['critical'], 'title');
        $this->assertContains('Progress Regression Detected', $titles);

        Carbon::setTestNow();
    }

    // -- Critical: AI projected deadline miss --

    public function test_ai_predicted_delay_raises_critical_flag(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $this->baseline($project, '2026-01', '2026-01-31', 10);
        $this->baseline($project, '2026-02', '2026-02-28', 20);

        $checks = $this->service()->prepareQualityChecks($project, [
            'predicted_delay_days' => 45,
        ]);

        $titles = array_column($checks['critical'], 'title');
        $this->assertContains('Projected Deadline Miss', $titles);

        $deadlineMiss = collect($checks['critical'])->firstWhere('title', 'Projected Deadline Miss');
        $this->assertStringContainsString('45 days late', $deadlineMiss['message']);
    }

    // -- AI forecast: no delay -> no deadline-miss flag --

    public function test_no_ai_delay_produces_no_deadline_miss_flag(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $this->baseline($project, '2026-01', '2026-01-31', 10);
        $this->baseline($project, '2026-02', '2026-02-28', 20);
        $this->progress($project, '2026-01', '2026-01-20', 10);
        $this->progress($project, '2026-02', '2026-02-20', 20);

        $checks = $this->service()->prepareQualityChecks($project, [
            'predicted_delay_days' => -3,
        ]);

        $titles = array_column($checks['critical'], 'title');
        $this->assertNotContains('Projected Deadline Miss', $titles);
    }

    // -- Warning: missing month not yet overdue --

    public function test_missing_future_month_raises_warning_flag(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $this->baseline($project, '2026-01', '2026-01-31', 10);
        $this->baseline($project, '2026-02', '2026-02-28', 20);
        $this->progress($project, '2026-01', '2026-01-20', 10);

        Carbon::setTestNow(Carbon::parse('2026-02-05'));

        $checks = $this->service()->prepareQualityChecks($project, []);

        $titles = array_column($checks['warnings'], 'title');
        $this->assertContains('Missing Monthly Records', $titles);

        Carbon::setTestNow();
    }

    // -- Warning: next pending month with high target --

    public function test_high_target_next_month_raises_warning_flag(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $this->baseline($project, '2026-01', '2026-01-31', 10);
        $this->baseline($project, '2026-02', '2026-02-28', 50);
        $this->progress($project, '2026-01', '2026-01-20', 10);

        Carbon::setTestNow(Carbon::parse('2026-02-05'));

        $checks = $this->service()->prepareQualityChecks($project, []);

        $titles = array_column($checks['warnings'], 'title');
        $this->assertContains('Critical Phase Approaching', $titles);

        Carbon::setTestNow();
    }

    // -- Healthy project: no flags --

    public function test_healthy_project_has_no_flags(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $this->baseline($project, '2026-01', '2026-01-31', 10);
        $this->baseline($project, '2026-02', '2026-02-28', 20);
        $this->progress($project, '2026-01', '2026-01-20', 10);
        $this->progress($project, '2026-02', '2026-02-20', 20);

        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $checks = $this->service()->prepareQualityChecks($project, [
            'predicted_delay_days' => 0,
        ]);

        $this->assertTrue($checks['ready']);
        $this->assertFalse($checks['has_flags']);
        $this->assertEmpty($checks['critical']);
        $this->assertEmpty($checks['warnings']);

        Carbon::setTestNow();
    }

    // -- AJAX endpoint exposes quality_checks --

    public function test_chart_data_endpoint_returns_quality_checks(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $this->baseline($project, '2026-01', '2026-01-31', 10);
        $this->baseline($project, '2026-02', '2026-02-28', 20);
        $this->progress($project, '2026-01', '2026-01-20', 10);
        $this->progress($project, '2026-02', '2026-02-20', 20);

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.projects.chart-data', $project->id));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['quality_checks' => [
                'ready',
                'has_flags',
                'critical',
                'warnings',
            ]]);
    }
}
