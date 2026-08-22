<?php

namespace Tests\Feature\Services\AI;

use App\Http\Controllers\BaselineScheduleController;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * BaselineDeleteGuardTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Fix D: non-admin (staff) users may only delete month targets that
 * are extension-only (no frozen original_planned_progress_percent) AND have no
 * recorded progress. Original-baseline months and progressed months are
 * protected on the server, regardless of button visibility.
 *
 * Run With
 * --------
 *   php artisan test --filter=BaselineDeleteGuardTest
 */
class BaselineDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Delete Guard Admin',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Delete Guard Staff',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'staff',
            'is_active' => true,
        ]);
    }

    private function makeProject(): Project
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);
        $project->update(['assigned_project_manager' => (string) $this->staff->id]);
        return $project;
    }

    private function makeSchedule(
        Project $project,
        string $month,
        ?float $originalPercent,
        ?string $originalDate
    ): ProjectBaselineSchedule {
        return ProjectBaselineSchedule::create([
            'project_id' => $project->id,
            'month_label' => $month,
            'planned_date' => Carbon::parse($month.'-01')->endOfMonth()->format('Y-m-d'),
            'planned_progress_percent' => 5,
            'original_planned_progress_percent' => $originalPercent,
            'original_planned_date' => $originalDate,
        ]);
    }

    private function recordProgress(Project $project, ProjectBaselineSchedule $schedule): void
    {
        ProjectProgressHistory::create([
            'project_id' => $project->id,
            'baseline_schedule_id' => $schedule->id,
            'inspection_date' => Carbon::parse($schedule->month_label.'-01')->format('Y-m-d'),
            'month_label' => $schedule->month_label,
            'progress_percentage' => 1.0,
            'actual_progress_percent' => 1.0,
            'planned_progress_percent' => $schedule->planned_progress_percent,
        ]);
    }

    private function attemptDelete(User $user, Project $project, ProjectBaselineSchedule $schedule): \Illuminate\Http\JsonResponse
    {
        return app(BaselineScheduleController::class)->deleteBaselineSchedule($project, $schedule->id);
    }

    /**
     * A staff member CANNOT delete an original-baseline month (frozen values).
     */
    public function test_staff_cannot_delete_original_baseline_month(): void
    {
        $project = $this->makeProject();
        $schedule = $this->makeSchedule($project, '2026-02', 5.0, '2026-02-28');

        $this->actingAs($this->staff);
        $response = $this->attemptDelete($this->staff, $project, $schedule);

        $this->assertFalse($response->getData()->success);
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertNotNull(ProjectBaselineSchedule::find($schedule->id));
    }

    /**
     * A staff member CANNOT delete an extension-only month that has progress.
     */
    public function test_staff_cannot_delete_progressed_month(): void
    {
        $project = $this->makeProject();
        $schedule = $this->makeSchedule($project, '2026-02', null, null);
        $this->recordProgress($project, $schedule);

        $this->actingAs($this->staff);
        $response = $this->attemptDelete($this->staff, $project, $schedule);

        $this->assertFalse($response->getData()->success);
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertNotNull(ProjectBaselineSchedule::find($schedule->id));
        $this->assertEquals(1, ProjectProgressHistory::where('project_id', $project->id)->count());
    }

    /**
     * A staff member CAN delete an extension-only month with no progress.
     */
    public function test_staff_can_delete_extension_only_month_without_progress(): void
    {
        $project = $this->makeProject();
        $schedule = $this->makeSchedule($project, '2026-02', null, null);

        $this->actingAs($this->staff);
        $response = $this->attemptDelete($this->staff, $project, $schedule);

        $this->assertTrue($response->getData()->success);
        $this->assertNull(ProjectBaselineSchedule::find($schedule->id));
    }

    /**
     * An admin can delete an original-baseline month.
     */
    public function test_admin_can_delete_original_baseline_month(): void
    {
        $project = $this->makeProject();
        $schedule = $this->makeSchedule($project, '2026-02', 5.0, '2026-02-28');

        $this->actingAs($this->admin);
        $response = $this->attemptDelete($this->admin, $project, $schedule);

        $this->assertTrue($response->getData()->success);
        $this->assertNull(ProjectBaselineSchedule::find($schedule->id));
    }

    /**
     * An admin can delete a progressed month (full override).
     */
    public function test_admin_can_delete_progressed_month(): void
    {
        $project = $this->makeProject();
        $schedule = $this->makeSchedule($project, '2026-02', null, null);
        $this->recordProgress($project, $schedule);

        $this->actingAs($this->admin);
        $response = $this->attemptDelete($this->admin, $project, $schedule);

        $this->assertTrue($response->getData()->success);
        $this->assertNull(ProjectBaselineSchedule::find($schedule->id));
        $this->assertEquals(0, ProjectProgressHistory::where('project_id', $project->id)->count());
    }
}
