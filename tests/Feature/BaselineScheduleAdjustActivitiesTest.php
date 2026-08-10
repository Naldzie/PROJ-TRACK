<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectActivityCarryover;
use App\Models\ProjectBaselineSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * BaselineScheduleAdjustActivitiesTest
 * ============================================================================
 *
 * The adjust-baseline save (saveAdjustedBaseline) must PRESERVE activity
 * identity when round-tripping existing months:
 *   - matched activity rows keep their id (no delete+recreate churn)
 *   - is_completed is never reset for activities that already exist
 *   - carryover logs keep pointing at the same activity row
 *   - only activities the user actually removed are deleted
 *   - genuinely new activities are created
 *
 * Run With
 * --------
 *   php artisan test --filter=BaselineScheduleAdjustActivitiesTest
 */
class BaselineScheduleAdjustActivitiesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'       => 'Adjust Activities Admin',
            'email'      => fake()->unique()->safeEmail(),
            'role'       => 'admin',
            'is_active'  => true,
        ]);
    }

    // ──── Helpers ───────────────────────────────────────────────────────────

    private function projectBetween(string $start, string $end): Project
    {
        return Project::factory()->create([
            'status'                     => 'Ongoing',
            'planned_start_date'         => $start,
            'planned_end_date'           => $end,
            'original_planned_start_date' => $start,
            'original_planned_end_date'   => $end,
            'extension_count'            => 0,
        ]);
    }

    private function baselineRow(Project $project, string $month, string $date, float $percent): ProjectBaselineSchedule
    {
        return ProjectBaselineSchedule::factory()->create([
            'project_id'                     => $project->id,
            'month_label'                    => $month,
            'planned_date'                   => $date,
            'planned_progress_percent'       => $percent,
            'original_planned_progress_percent' => $percent,
            'original_planned_date'          => $date,
        ]);
    }

    private function activity(Project $project, ProjectBaselineSchedule $schedule, string $name, bool $completed = false): ProjectActivity
    {
        return ProjectActivity::create([
            'project_id'           => $project->id,
            'baseline_schedule_id' => $schedule->id,
            'month_label'          => $schedule->month_label,
            'planned_date'         => $schedule->planned_date,
            'activity_name'        => $name,
            'description'          => '',
            'is_completed'         => $completed,
        ]);
    }

    private function adjustSave(Project $project, array $months)
    {
        return $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.adjust.save', $project),
            ['months' => $months]
        );
    }

    private function monthPayload(ProjectBaselineSchedule $schedule, float $percent, array $activities = []): array
    {
        return [
            'id'                        => $schedule->id,
            'month_label'               => $schedule->month_label,
            'planned_date'              => $schedule->planned_date->format('Y-m-d'),
            'planned_progress_percent'  => $percent,
            'description'               => null,
            'activities'                => $activities,
        ];
    }

    // ──── Tests ─────────────────────────────────────────────────────────────

    public function test_adjust_save_preserves_activity_ids_and_completion(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');
        $m1 = $this->baselineRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->baselineRow($project, '2026-02', '2026-02-15', 100);

        $act = $this->activity($project, $m1, 'Site clearing', true);

        $this->adjustSave($project, [
            $this->monthPayload($m1, 40, [
                ['id' => $act->id, 'activity_name' => 'Site clearing', 'description' => ''],
            ]),
            $this->monthPayload($m2, 100),
        ])->assertOk();

        $fresh = $act->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($act->id, $fresh->id);
        $this->assertTrue($fresh->is_completed);
        $this->assertSame('Site clearing', $fresh->activity_name);
    }

    public function test_adjust_save_preserves_carried_over_activity_and_its_log(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');
        $m1 = $this->baselineRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->baselineRow($project, '2026-02', '2026-02-15', 100);

        // Carry "Survey" from m1 to m2 (mirrors carryOverActivity: mutates the
        // row to live in the target month and logs the immutable carryover).
        $act = $this->activity($project, $m1, 'Survey');
        ProjectActivityCarryover::create([
            'activity_id'               => $act->id,
            'project_id'                => $project->id,
            'from_baseline_schedule_id' => $m1->id,
            'from_month_label'          => '2026-01',
            'to_baseline_schedule_id'   => $m2->id,
            'to_month_label'            => '2026-02',
            'carryover_number'          => 1,
            'carried_over_by'           => $this->admin->id,
        ]);
        $act->update([
            'baseline_schedule_id' => $m2->id,
            'month_label'          => '2026-02',
        ]);

        $this->adjustSave($project, [
            $this->monthPayload($m1, 40),
            $this->monthPayload($m2, 100, [
                ['id' => $act->id, 'activity_name' => 'Survey', 'description' => ''],
            ]),
        ])->assertOk();

        // The activity row must NOT have been recreated — same id survives.
        $this->assertDatabaseHas('project_activities', [
            'id'         => $act->id,
            'month_label' => '2026-02',
        ]);

        // Its carryover log must still reference the same activity row.
        $this->assertDatabaseHas('project_activity_carryovers', [
            'activity_id' => $act->id,
        ]);
        $this->assertSame(1, ProjectActivityCarryover::where('activity_id', $act->id)->count());
    }

    public function test_adjust_save_renamed_activity_keeps_its_id(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');
        $m1 = $this->baselineRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->baselineRow($project, '2026-02', '2026-02-15', 100);

        $act = $this->activity($project, $m1, 'Old name');

        $this->adjustSave($project, [
            $this->monthPayload($m1, 40, [
                ['id' => $act->id, 'activity_name' => 'New name', 'description' => ''],
            ]),
            $this->monthPayload($m2, 100),
        ])->assertOk();

        $this->assertDatabaseHas('project_activities', [
            'id'            => $act->id,
            'activity_name' => 'New name',
        ]);
        $this->assertDatabaseCount('project_activities', 1);
    }

    public function test_adjust_save_deletes_only_dropped_activities(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');
        $m1 = $this->baselineRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->baselineRow($project, '2026-02', '2026-02-15', 100);

        $keep = $this->activity($project, $m1, 'Keep me');
        $drop = $this->activity($project, $m1, 'Drop me');

        $this->adjustSave($project, [
            $this->monthPayload($m1, 40, [
                ['id' => $keep->id, 'activity_name' => 'Keep me', 'description' => ''],
            ]),
            $this->monthPayload($m2, 100),
        ])->assertOk();

        $this->assertNotNull($keep->fresh());
        $this->assertNull($drop->fresh());
        $this->assertDatabaseCount('project_activities', 1);
    }

    public function test_adjust_save_creates_new_activity_on_existing_month(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-10-15');
        $m1 = $this->baselineRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->baselineRow($project, '2026-02', '2026-02-15', 100);

        $this->adjustSave($project, [
            $this->monthPayload($m1, 40, [
                ['activity_name' => 'Brand new', 'description' => ''],
            ]),
            $this->monthPayload($m2, 100),
        ])->assertOk();

        $this->assertDatabaseHas('project_activities', [
            'project_id'           => $project->id,
            'baseline_schedule_id' => $m1->id,
            'activity_name'        => 'Brand new',
            'is_completed'         => false,
        ]);
    }
}
