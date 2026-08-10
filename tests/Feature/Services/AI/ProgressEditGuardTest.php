<?php

namespace Tests\Feature\Services\AI;

use App\Http\Controllers\ProjectProgressController;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ============================================================================
 * ProgressEditGuardTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Fix B: only the LATEST progress record may be edited by non-admin
 * users. Historical actuals stay immutable so trend/AI regression data cannot
 * be rewritten retroactively. Admin retains full edit access.
 *
 * Run With
 * --------
 *   php artisan test --filter=ProgressEditGuardTest
 */
class ProgressEditGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Edit Guard Admin',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Edit Guard Staff',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
    }

    private function makeProject(): Project
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        foreach (['2026-02', '2026-03', '2026-04'] as $i => $month) {
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

    private function attemptEdit(User $user, Project $project, ProjectProgressHistory $record): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/progress/'.$record->id, 'PUT', [
            'inspection_date' => $record->inspection_date->format('Y-m-d'),
            'progress_percentage' => (float) $record->actual_progress_percent,
            'remarks' => 'test edit',
        ]);

        return app(ProjectProgressController::class)->editProgressRecord($request, $project, $record->id);
    }

    /**
     * A non-admin cannot edit a NON-latest (older) progress record.
     */
    public function test_staff_cannot_edit_non_latest_record(): void
    {
        $project = $this->makeProject();
        $this->recordMonthlyProgress($project, '2026-02', 1.0);
        $this->recordMonthlyProgress($project, '2026-03', 1.5);
        $this->recordMonthlyProgress($project, '2026-04', 2.0);

        $march = ProjectProgressHistory::where('project_id', $project->id)
            ->where('month_label', '2026-03')
            ->first();

        $this->actingAs($this->staff);
        $response = $this->attemptEdit($this->staff, $project, $march);

        $this->assertFalse($response->getData()->success);
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * A non-admin CAN edit the latest progress record.
     */
    public function test_staff_can_edit_latest_record(): void
    {
        $project = $this->makeProject();
        $this->recordMonthlyProgress($project, '2026-02', 1.0);
        $this->recordMonthlyProgress($project, '2026-03', 1.5);
        $this->recordMonthlyProgress($project, '2026-04', 2.0);

        $april = ProjectProgressHistory::where('project_id', $project->id)
            ->where('month_label', '2026-04')
            ->first();

        $this->actingAs($this->staff);
        $response = $this->attemptEdit($this->staff, $project, $april);

        $this->assertTrue($response->getData()->success);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * An admin CAN edit any record, including an older one.
     */
    public function test_admin_can_edit_non_latest_record(): void
    {
        $project = $this->makeProject();
        $this->recordMonthlyProgress($project, '2026-02', 1.0);
        $this->recordMonthlyProgress($project, '2026-03', 1.5);
        $this->recordMonthlyProgress($project, '2026-04', 2.0);

        $march = ProjectProgressHistory::where('project_id', $project->id)
            ->where('month_label', '2026-03')
            ->first();

        $this->actingAs($this->admin);
        $response = $this->attemptEdit($this->admin, $project, $march);

        $this->assertTrue($response->getData()->success);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
