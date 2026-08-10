<?php

namespace Tests\Feature\Services\AI;

use App\Http\Controllers\ProjectActivityController;
use App\Http\Controllers\ProjectProgressController;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectBaselineSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ============================================================================
 * ProgressedMonthActivityGuardTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Fix C: once a month has recorded progress, its activity checklist
 * is frozen — no activities may be ADDED or DELETED on that month for ANY role.
 * Leftover work leaves via Carry Over; new scope goes to future months.
 *
 * Run With
 * --------
 *   php artisan test --filter=ProgressedMonthActivityGuardTest
 */
class ProgressedMonthActivityGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Frozen Month Admin',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Frozen Month Staff',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
    }

    private function makeProject(): Project
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        foreach (['2026-02', '2026-03'] as $i => $month) {
            ProjectBaselineSchedule::create([
                'project_id' => $project->id,
                'month_label' => $month,
                'planned_date' => Carbon::parse($month.'-01')->endOfMonth()->format('Y-m-d'),
                'planned_progress_percent' => ($i + 1) * 5,
                'original_planned_progress_percent' => ($i + 1) * 5,
            ]);
        }

        return $project;
    }

    private function recordMonthlyProgress(Project $project, string $month, float $pct): void
    {
        $request = Request::create('/progress', 'POST', [
            'inspection_date' => Carbon::parse($month.'-01')->format('Y-m-d'),
            'month_label' => $month,
            'actual_progress_percent' => $pct,
            'remarks' => null,
        ]);

        $response = app(ProjectProgressController::class)->recordMonthlyProgress($request, $project);

        $this->assertTrue($response->getData()->success, "recordMonthlyProgress failed for {$month} / {$pct}%");
    }

    private function attemptStoreActivity(User $user, Project $project, string $month): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/activities', 'POST', [
            'activity_name' => 'New Activity',
            'description' => 'test',
            'month_label' => $month,
        ]);

        return app(ProjectActivityController::class)->storeActivity($request, $project);
    }

    private function attemptDeleteActivity(User $user, Project $project, ProjectActivity $activity): \Illuminate\Http\JsonResponse
    {
        return app(ProjectActivityController::class)->deleteActivity($project, $activity->id);
    }

    /**
     * An admin CANNOT add an activity to a month that already has progress.
     */
    public function test_admin_cannot_add_activity_on_progressed_month(): void
    {
        $project = $this->makeProject();
        $this->recordMonthlyProgress($project, '2026-02', 1.0);

        $this->actingAs($this->admin);
        $response = $this->attemptStoreActivity($this->admin, $project, '2026-02');

        $this->assertFalse($response->getData()->success);
        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals(0, ProjectActivity::where('project_id', $project->id)->count());
    }

    /**
     * A staff member CANNOT add an activity to a progressed month either
     * (uniform block — no admin override).
     */
    public function test_staff_cannot_add_activity_on_progressed_month(): void
    {
        $project = $this->makeProject();
        $this->recordMonthlyProgress($project, '2026-02', 1.0);

        $this->actingAs($this->staff);
        $response = $this->attemptStoreActivity($this->staff, $project, '2026-02');

        $this->assertFalse($response->getData()->success);
        $this->assertEquals(422, $response->getStatusCode());
    }

    /**
     * An admin CANNOT delete an activity (even a pending one) on a progressed month.
     */
    public function test_admin_cannot_delete_activity_on_progressed_month(): void
    {
        $project = $this->makeProject();
        $activity = ProjectActivity::create([
            'project_id' => $project->id,
            'baseline_schedule_id' => $project->baselineSchedules()->where('month_label', '2026-02')->first()->id,
            'month_label' => '2026-02',
            'activity_name' => 'Pending Task',
            'description' => '',
            'is_completed' => false,
        ]);
        $this->recordMonthlyProgress($project, '2026-02', 1.0);

        $this->actingAs($this->admin);
        $response = $this->attemptDeleteActivity($this->admin, $project, $activity);

        $this->assertFalse($response->getData()->success);
        $this->assertEquals(422, $response->getStatusCode());
        $this->assertNotNull(ProjectActivity::find($activity->id));
    }

    /**
     * On a month WITHOUT progress, activities can still be added normally.
     */
    public function test_activity_can_be_added_on_month_without_progress(): void
    {
        $project = $this->makeProject();
        $this->recordMonthlyProgress($project, '2026-02', 1.0);

        $this->actingAs($this->admin);
        $response = $this->attemptStoreActivity($this->admin, $project, '2026-03');

        $this->assertTrue($response->getData()->success);
        $this->assertEquals(1, ProjectActivity::where('project_id', $project->id)
            ->where('month_label', '2026-03')
            ->count());
    }
}
