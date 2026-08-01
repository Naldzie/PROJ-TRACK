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
 * ProgressDeleteGuardTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Fix A: only the LATEST remaining progress record may be deleted.
 * The UI hides the delete button on all but the newest row, and the backend
 * `destroyProgress()` guard rejects any deletion that is not the latest.
 *
 * Run With
 * --------
 *   php artisan test --filter=ProgressDeleteGuardTest
 */
class ProgressDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name'  => 'Test Admin',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'admin',
        ]));
    }

    private function makeProject(): Project
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        foreach (['2026-02', '2026-03', '2026-04'] as $i => $month) {
            ProjectBaselineSchedule::create([
                'project_id'                       => $project->id,
                'month_label'                      => $month,
                'planned_date'                     => Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d'),
                'planned_progress_percent'         => ($i + 1) * 5,
                'original_planned_progress_percent'=> ($i + 1) * 5,
            ]);
        }

        return $project;
    }

    private function recordMonthlyProgress(Project $project, string $month, float $pct): void
    {
        $request = Request::create('/progress', 'POST', [
            'inspection_date'         => Carbon::parse($month . '-01')->format('Y-m-d'),
            'month_label'             => $month,
            'actual_progress_percent' => $pct,
            'remarks'                 => null,
        ]);

        $response = app(ProjectProgressController::class)->recordMonthlyProgress($request, $project);

        $this->assertTrue($response->getData()->success, "recordMonthlyProgress failed for {$month} / {$pct}%");
    }

    /**
     * A NON-latest record (a middle month while a newer month exists) cannot be
     * deleted — deleting out of order would leave gaps in the history.
     */
    public function test_delete_rejects_non_latest_record(): void
    {
        $project = $this->makeProject();
        $this->recordMonthlyProgress($project, '2026-02', 1.0);
        $this->recordMonthlyProgress($project, '2026-03', 1.5);
        $this->recordMonthlyProgress($project, '2026-04', 2.0);

        $march = ProjectProgressHistory::where('project_id', $project->id)
            ->where('month_label', '2026-03')
            ->first();

        $response = app(ProjectProgressController::class)->destroyProgress($project, $march->id);

        $this->assertFalse($response->getData()->success);
        $this->assertEquals(422, $response->getStatusCode());

        $this->assertEquals(3, ProjectProgressHistory::where('project_id', $project->id)->count());
        $this->assertNotNull(ProjectProgressHistory::find($march->id));
    }

    /**
     * The LATEST remaining record can be deleted and is removed from the history.
     */
    public function test_delete_latest_record_succeeds(): void
    {
        $project = $this->makeProject();
        $this->recordMonthlyProgress($project, '2026-02', 1.0);
        $this->recordMonthlyProgress($project, '2026-03', 1.5);
        $this->recordMonthlyProgress($project, '2026-04', 2.0);

        $april = ProjectProgressHistory::where('project_id', $project->id)
            ->where('month_label', '2026-04')
            ->first();

        $response = app(ProjectProgressController::class)->destroyProgress($project, $april->id);

        $this->assertTrue($response->getData()->success);

        $this->assertEquals(2, ProjectProgressHistory::where('project_id', $project->id)->count());
        $this->assertNull(ProjectProgressHistory::find($april->id));
    }

    /**
     * When there is only ONE record, it is by definition the latest and can be
     * deleted.
     */
    public function test_delete_sole_record_succeeds(): void
    {
        $project = $this->makeProject();
        $this->recordMonthlyProgress($project, '2026-02', 1.0);

        $record = ProjectProgressHistory::where('project_id', $project->id)->first();

        $response = app(ProjectProgressController::class)->destroyProgress($project, $record->id);

        $this->assertTrue($response->getData()->success);
        $this->assertEmpty(ProjectProgressHistory::where('project_id', $project->id)->get());
    }
}
