<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectBaselineSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * BaselineScheduleDateBoundsTest
 * ============================================================================
 *
 * The baseline IS the plan, so every month target's planned_date must fall
 * within the project's timeline: planned_start_date <= planned_date <=
 * planned_end_date (using the CURRENT, post-extension planned_end_date).
 *
 * Covers every entry point that can create a target:
 *   - storeBaselineSchedule()          (Add Month Target modal)
 *   - storeInitialBaselineSetup()      (Set Up Baseline modal)
 *   - ProjectController::store()       (create-project modal baseline targets)
 *
 * Run With
 * --------
 *   php artisan test --filter=BaselineScheduleDateBoundsTest
 */
class BaselineScheduleDateBoundsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Bounds Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->staff = User::create(['name' => 'Bounds Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
    }

    // â”€â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function projectBetween(string $start, string $end, array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'status'                     => 'Ongoing',
            'planned_start_date'         => $start,
            'planned_end_date'           => $end,
            'original_planned_start_date' => $start,
            'original_planned_end_date'   => $end,
            'extension_count'            => 0,
        ], $overrides));
    }

    private function addTargetPayload(Project $project, string $date, float $percent): array
    {
        return [
            'planned_date'             => $date,
            'month_label'              => substr($date, 0, 7),
            'planned_progress_percent' => $percent,
            'description'              => 'test target',
            'activities'               => [],
        ];
    }

    private function setupPlanPayload(array $months): array
    {
        return ['months' => $months];
    }

    // â”€â”€â”€ storeBaselineSchedule: Add Month Target â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_add_month_target_before_project_start_is_rejected(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.store', $project),
            $this->addTargetPayload($project, '2025-12-20', 25)
        );

        $response->assertStatus(422);
        $this->assertStringContainsString(
            "before the project's planned start date",
            $response->json('message')
        );
        $this->assertDatabaseMissing('project_baseline_schedules', [
            'planned_date' => \Carbon\Carbon::parse('2025-12-20'),
        ]);
    }

    public function test_add_month_target_after_project_end_is_rejected(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.store', $project),
            $this->addTargetPayload($project, '2026-11-05', 25)
        );

        $response->assertStatus(422);
        $this->assertStringContainsString(
            "after the project's planned end date",
            $response->json('message')
        );
        $this->assertDatabaseMissing('project_baseline_schedules', [
            'planned_date' => \Carbon\Carbon::parse('2026-11-05'),
        ]);
    }

    public function test_add_month_target_on_start_boundary_is_accepted(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.store', $project),
            $this->addTargetPayload($project, '2026-01-15', 25)
        );

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('project_baseline_schedules', [
            'project_id'   => $project->id,
            'planned_date' => \Carbon\Carbon::parse('2026-01-15'),
        ]);
    }

    public function test_add_month_target_on_end_boundary_is_accepted(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.store', $project),
            $this->addTargetPayload($project, '2026-10-15', 25)
        );

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('project_baseline_schedules', [
            'project_id'   => $project->id,
            'planned_date' => \Carbon\Carbon::parse('2026-10-15'),
        ]);
    }

    public function test_add_month_target_requires_project_dates(): void
    {
        $project = Project::factory()->create([
            'status'             => 'Ongoing',
            'planned_start_date' => null,
            'planned_end_date'   => null,
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.store', $project),
            $this->addTargetPayload($project, '2026-05-15', 25)
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('planned start and end dates', $response->json('message'));
    }

    public function test_add_month_target_respects_extended_end_date(): void
    {
        // Extension approved: current end date moved to 2027-03-15.
        $project = $this->projectBetween('2026-01-15', '2027-03-15', ['extension_count' => 1]);

        // Within the extended end date â€” allowed.
        $within = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.store', $project),
            $this->addTargetPayload($project, '2027-03-15', 25)
        );
        $within->assertOk()->assertJson(['success' => true]);

        // Past the extended end date â€” rejected.
        $past = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.store', $project),
            $this->addTargetPayload($project, '2027-04-02', 30)
        );
        $past->assertStatus(422);
        $this->assertStringContainsString("after the project's planned end date", $past->json('message'));
    }

    // â”€â”€â”€ storeInitialBaselineSetup: Set Up Baseline â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_setup_plan_before_project_start_is_rejected(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.setup', $project),
            $this->setupPlanPayload([
                ['month_label' => '2025-12', 'planned_date' => '2025-12-20', 'planned_progress_percent' => 100],
            ])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString(
            "before the project's planned start date",
            $response->json('message')
        );
        $this->assertDatabaseMissing('project_baseline_schedules', ['project_id' => $project->id]);
    }

    public function test_setup_plan_after_project_end_is_rejected(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.setup', $project),
            $this->setupPlanPayload([
                ['month_label' => '2026-11', 'planned_date' => '2026-11-05', 'planned_progress_percent' => 100],
            ])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString(
            "after the project's planned end date",
            $response->json('message')
        );
        $this->assertDatabaseMissing('project_baseline_schedules', ['project_id' => $project->id]);
    }

    public function test_setup_plan_within_timeline_is_accepted(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.setup', $project),
            $this->setupPlanPayload([
                ['month_label' => '2026-02', 'planned_date' => '2026-02-10', 'planned_progress_percent' => 50],
                ['month_label' => '2026-10', 'planned_date' => '2026-10-15', 'planned_progress_percent' => 100],
            ])
        );

        $response->assertOk()->assertJson(['success' => true]);

        $rows = ProjectBaselineSchedule::where('project_id', $project->id)
            ->orderBy('planned_date')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('2026-02-10', $rows[0]->planned_date->format('Y-m-d'));
        $this->assertSame(50.0, (float) $rows[0]->planned_progress_percent);
        $this->assertSame('2026-10-15', $rows[1]->planned_date->format('Y-m-d'));
        $this->assertSame(100.0, (float) $rows[1]->planned_progress_percent);
    }

    public function test_setup_plan_requires_project_dates(): void
    {
        $project = Project::factory()->create([
            'status'             => 'Ongoing',
            'planned_start_date' => null,
            'planned_end_date'   => null,
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.setup', $project),
            $this->setupPlanPayload([
                ['month_label' => '2026-05', 'planned_date' => '2026-05-10', 'planned_progress_percent' => 100],
            ])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('planned start and end dates', $response->json('message'));
    }

    // â”€â”€â”€ ProjectController::store: create-project baseline targets â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_create_project_rejects_out_of_bound_baseline_targets(): void
    {
        $payload = [
            'title'                    => 'Out of bounds project ' . fake()->unique()->word(),
            'approved_budget'          => 500000,
            'planned_start_date'       => '2026-01-15',
            'planned_end_date'         => '2026-10-15',
            'status'                   => 'Planned',
            'baseline_schedule_targets' => json_encode([
                ['month_label' => '2026-11', 'planned_date' => '2026-11-05', 'planned_progress_percent' => 100],
            ]),
        ];

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.store'),
            $payload
        );

        $response->assertStatus(302);
        $response->assertSessionHasErrors('baseline_schedule_targets');
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_baseline_schedules', 0);
    }

    public function test_create_project_rejects_before_start_baseline_targets(): void
    {
        $payload = [
            'title'                    => 'Before start project ' . fake()->unique()->word(),
            'approved_budget'          => 500000,
            'planned_start_date'       => '2026-01-15',
            'planned_end_date'         => '2026-10-15',
            'status'                   => 'Planned',
            'baseline_schedule_targets' => json_encode([
                ['month_label' => '2025-12', 'planned_date' => '2025-12-20', 'planned_progress_percent' => 100],
            ]),
        ];

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.store'),
            $payload
        );

        $response->assertStatus(302);
        $response->assertSessionHasErrors('baseline_schedule_targets');
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_create_project_accepts_in_bound_baseline_targets(): void
    {
        $payload = [
            'title'                    => 'In bounds project ' . fake()->unique()->word(),
            'approved_budget'          => 500000,
            'planned_start_date'       => '2026-01-15',
            'planned_end_date'         => '2026-10-15',
            'status'                   => 'Planned',
            'baseline_schedule_targets' => json_encode([
                ['month_label' => '2026-02', 'planned_date' => '2026-02-10', 'planned_progress_percent' => 50],
                ['month_label' => '2026-10', 'planned_date' => '2026-10-15', 'planned_progress_percent' => 100],
            ]),
        ];

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.store'),
            $payload
        );

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_baseline_schedules', 2);

        $project = Project::first();
        $rows = ProjectBaselineSchedule::where('project_id', $project->id)->orderBy('planned_date')->get();
        $this->assertSame('2026-02-10', $rows[0]->planned_date->format('Y-m-d'));
        $this->assertSame('2026-10-15', $rows[1]->planned_date->format('Y-m-d'));
    }

    public function test_create_project_requires_project_dates_for_baseline_targets(): void
    {
        $payload = [
            'title'                    => 'No dates project ' . fake()->unique()->word(),
            'approved_budget'          => 500000,
            'status'                   => 'Planned',
            'baseline_schedule_targets' => json_encode([
                ['month_label' => '2026-05', 'planned_date' => '2026-05-10', 'planned_progress_percent' => 100],
            ]),
        ];

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.store'),
            $payload
        );

        $response->assertStatus(302);
        $response->assertSessionHasErrors('planned_start_date');
        $this->assertDatabaseCount('projects', 0);
    }

    // ──── Adjust-baseline endpoint: existing activities must round-trip ────

    public function test_adjust_endpoint_returns_existing_activities(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');

        $schedule = ProjectBaselineSchedule::factory()->create([
            'project_id'                     => $project->id,
            'month_label'                    => '2026-03',
            'planned_date'                   => '2026-03-15',
            'planned_progress_percent'       => 50,
            'original_planned_progress_percent' => 50,
            'original_planned_date'          => '2026-03-15',
        ]);

        ProjectActivity::create([
            'project_id'           => $project->id,
            'baseline_schedule_id' => $schedule->id,
            'month_label'          => '2026-03',
            'activity_name'        => 'Site clearing',
            'description'          => 'Prepare the site',
            'is_completed'         => false,
        ]);

        ProjectActivity::create([
            'project_id'           => $project->id,
            'baseline_schedule_id' => $schedule->id,
            'month_label'          => '2026-03',
            'activity_name'        => 'Survey',
            'description'          => '',
            'is_completed'         => false,
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            route('admin.projects.baseline-schedules.adjust', $project)
        );

        $response->assertOk()->assertJson(['success' => true]);
        $months = $response->json('months');
        $this->assertCount(1, $months);
        $this->assertSame([
            ['activity_name' => 'Site clearing', 'description' => 'Prepare the site'],
            ['activity_name' => 'Survey', 'description' => ''],
        ], $months[0]['activities']);
    }

    public function test_adjust_endpoint_returns_empty_activities_for_month_without_any(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');

        ProjectBaselineSchedule::factory()->create([
            'project_id'                     => $project->id,
            'month_label'                    => '2026-03',
            'planned_date'                   => '2026-03-15',
            'planned_progress_percent'       => 50,
            'original_planned_progress_percent' => 50,
            'original_planned_date'          => '2026-03-15',
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            route('admin.projects.baseline-schedules.adjust', $project)
        );

        $response->assertOk();
        $this->assertSame([], $response->json('months.0.activities'));
    }
}
